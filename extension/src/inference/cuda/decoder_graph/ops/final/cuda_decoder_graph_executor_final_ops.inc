/*
 * CUDA decoder graph final-stage helpers for native King GPU inference.
 */

#include "../../device/compare/ffn/cuda_decoder_graph_executor_ffn_down_output_compare.inc"
#include "../../device/compare/norm/cuda_decoder_graph_executor_final_norm_compare.inc"
#include "../../device/compare/projection/cuda_decoder_graph_executor_logits_projection_compare.inc"

static zval *king_inference_cuda_decoder_graph_executor_final_norm_op_for_input(
    zval *graph,
    zend_string *input_id
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;

    if (ops == NULL || input_id == NULL) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *input;
        zval *id;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        input = king_inference_array_find(entry, "input");
        id = king_inference_array_find(entry, "id");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && zend_string_equals_literal(Z_STR_P(op_name), "rms_norm")
            && id != NULL
            && Z_TYPE_P(id) == IS_STRING
            && zend_string_equals_literal(Z_STR_P(id), "final_norm")
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(input, input_id)) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_ffn_output_residual_add(
    king_inference_model_object *model,
    zval *graph,
    zend_string *attention_residual_id,
    king_inference_cuda_device_ptr attention_residual_output,
    zend_ulong attention_residual_width,
    zend_string *down_id,
    king_inference_cuda_device_ptr down_output,
    zend_ulong down_width,
    zval *ffn_output_probe,
    king_inference_cuda_device_ptr *output,
    zend_ulong *output_width,
    zend_string **output_id_out,
    zend_ulong *executed_device_ops
) {
    zval *output_op = king_inference_cuda_decoder_graph_executor_add_op_for_inputs(graph, attention_residual_id, down_id);
    zend_string *output_id = NULL;

    *output = 0;
    *output_width = 0;
    if (output_id_out != NULL) {
        *output_id_out = NULL;
    }
    add_assoc_string(ffn_output_probe, "type", "gpu_decoder_ffn_output_residual_device_execution");
    add_assoc_bool(ffn_output_probe, "attempted", output_op != NULL);
    add_assoc_bool(ffn_output_probe, "available", model->cuda_decoder_graph_executor_ffn_output_residual_execution_available);
    add_assoc_bool(ffn_output_probe, "executed", false);
    add_assoc_bool(ffn_output_probe, "retained_device_output", false);
    if (output_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_ffn_output_residual_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_output_residual_execution_unavailable");
        return FAILURE;
    }
    if (attention_residual_output == 0
        || down_output == 0
        || attention_residual_width == 0
        || attention_residual_width != down_width
        || attention_residual_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || king_inference_graph_required_string(output_op, "id", &output_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_output_residual_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(model, (size_t) attention_residual_width * sizeof(float), output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_ffn_output_residual_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_vector_add(model, attention_residual_output, down_output, *output, attention_residual_width) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_ffn_output_residual_add_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_compare_block0_ffn_output_residual(model, attention_residual_id, attention_residual_output, attention_residual_width, down_id, down_output, down_width, output_id, *output, attention_residual_width, ffn_output_probe) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, output);
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_ffn_output_residual_execution_count++;
    model->cuda_decoder_graph_executor_last_ffn_output_residual_width = attention_residual_width;
    *output_width = attention_residual_width;
    if (output_id_out != NULL) {
        *output_id_out = output_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(ffn_output_probe, "residual", zend_string_copy(attention_residual_id));
    add_assoc_str(ffn_output_probe, "down", zend_string_copy(down_id));
    add_assoc_str(ffn_output_probe, "output", zend_string_copy(output_id));
    add_assoc_bool(ffn_output_probe, "executed", true);
    add_assoc_bool(ffn_output_probe, "retained_device_output", true);
    add_assoc_long(ffn_output_probe, "width", (zend_long) attention_residual_width);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_final_norm(
    king_inference_model_object *model,
    zval *graph,
    zend_string *input_id,
    king_inference_cuda_device_ptr input_output,
    zend_ulong input_width,
    zval *final_norm_probe,
    king_inference_cuda_device_ptr *final_norm_output,
    zend_ulong *final_norm_width,
    zend_string **final_norm_id_out,
    zend_ulong *executed_device_ops
) {
    zval *final_norm_op = king_inference_cuda_decoder_graph_executor_final_norm_op_for_input(graph, input_id);
    zend_string *final_norm_id = NULL;
    zend_string *weight = NULL;
    double epsilon = 0.000001;
    size_t bytes;

    *final_norm_output = 0;
    *final_norm_width = 0;
    if (final_norm_id_out != NULL) {
        *final_norm_id_out = NULL;
    }
    add_assoc_string(final_norm_probe, "type", "gpu_decoder_final_norm_device_execution");
    add_assoc_bool(final_norm_probe, "attempted", final_norm_op != NULL);
    add_assoc_bool(final_norm_probe, "available", model->cuda_decoder_graph_executor_final_norm_execution_available);
    add_assoc_bool(final_norm_probe, "executed", false);
    add_assoc_bool(final_norm_probe, "retained_device_output", false);
    if (final_norm_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_final_norm_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_final_norm_execution_unavailable");
        return FAILURE;
    }
    if (input_output == 0
        || input_width == 0
        || input_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || king_inference_graph_required_string(final_norm_op, "id", &final_norm_id) != SUCCESS
        || king_inference_graph_required_string(final_norm_op, "weight", &weight) != SUCCESS
        || king_inference_graph_finite_double_option(final_norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
        || epsilon <= 0.0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_final_norm_contract_invalid");
        return FAILURE;
    }
    bytes = (size_t) input_width * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(model, bytes, final_norm_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_final_norm_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_rms_norm_f32(model, weight, input_output, *final_norm_output, input_width, epsilon) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, final_norm_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_rms_norm_result,
            model->cuda_rms_norm_error[0] != '\0'
                ? model->cuda_rms_norm_error
                : "cuda_decoder_graph_final_norm_execution_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_compare_final_norm(model, input_id, final_norm_id, weight, input_output, input_width, *final_norm_output, input_width, epsilon, final_norm_probe) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, final_norm_output);
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_rms_norm_execution_count++;
    model->cuda_decoder_graph_executor_final_norm_execution_count++;
    model->cuda_decoder_graph_executor_last_rms_norm_width = input_width;
    model->cuda_decoder_graph_executor_last_final_norm_width = input_width;
    *final_norm_width = input_width;
    if (final_norm_id_out != NULL) {
        *final_norm_id_out = final_norm_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(final_norm_probe, "input", zend_string_copy(input_id));
    add_assoc_str(final_norm_probe, "final_norm", zend_string_copy(final_norm_id));
    add_assoc_bool(final_norm_probe, "executed", true);
    add_assoc_bool(final_norm_probe, "retained_device_output", true);
    add_assoc_long(final_norm_probe, "width", (zend_long) input_width);
    add_assoc_long(final_norm_probe, "bytes", (zend_long) bytes);
    add_assoc_double(final_norm_probe, "epsilon", epsilon);
    return SUCCESS;
}

static zval *king_inference_cuda_decoder_graph_executor_sampling_op_for_logits(
    zval *graph,
    zend_string *logits_id
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;

    if (ops == NULL || logits_id == NULL) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *logits;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        logits = king_inference_array_find(entry, "logits");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && (zend_string_equals_literal(Z_STR_P(op_name), "argmax_token")
                || zend_string_equals_literal(Z_STR_P(op_name), "sample_token"))
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(logits, logits_id)) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_ulong king_inference_cuda_decoder_graph_executor_candidate_limit(
    zval *sampling_op,
    zend_ulong fallback
) {
    zval *op_name;
    zend_ulong top_k;

    if (fallback == 0) {
        fallback = KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES;
    }
    if (sampling_op == NULL) {
        return fallback;
    }
    op_name = king_inference_array_find(sampling_op, "op");
    if (op_name != NULL
        && Z_TYPE_P(op_name) == IS_STRING
        && zend_string_equals_literal(Z_STR_P(op_name), "argmax_token")) {
        return 1;
    }
    top_k = king_inference_tensor_option_ulong(sampling_op, "top_k", fallback);
    return top_k == 0 ? fallback : top_k;
}

static zend_result king_inference_cuda_decoder_graph_executor_add_selected_bounded_token(
    zval *sampling_op,
    unsigned int *host_indices,
    float *host_logits,
    zend_ulong candidate_count,
    zval *logits_probe
) {
    zval *op_name;
    zend_ulong top_k = 0;
    zend_ulong active_count;
    zend_ulong selected_rank = 0;
    zend_ulong sample_index;
    zend_ulong token_offset;
    zend_ulong selected_token_id;
    zend_ulong i;
    double temperature = 0.0;
    double top_p = 1.0;
    double max_score = 0.0;
    double probability_sum = 0.0;
    double active_sum = 0.0;
    double probabilities[KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES];
    bool has_seed = false;
    zend_long seed = 0;

    if (sampling_op == NULL
        || candidate_count == 0
        || candidate_count > KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES
        || king_inference_graph_finite_double_option(sampling_op, "temperature", 0.0, &temperature) != SUCCESS
        || king_inference_graph_finite_double_option(sampling_op, "top_p", 1.0, &top_p) != SUCCESS
        || king_inference_graph_sampling_seed(sampling_op, &has_seed, &seed) != SUCCESS
        || king_inference_graph_sampling_top_k(sampling_op, &top_k) != SUCCESS) {
        return FAILURE;
    }
    if (temperature < 0.0 || top_p <= 0.0 || top_p > 1.0) {
        return FAILURE;
    }

    op_name = king_inference_array_find(sampling_op, "op");
    if (op_name != NULL
        && Z_TYPE_P(op_name) == IS_STRING
        && zend_string_equals_literal(Z_STR_P(op_name), "argmax_token")) {
        token_offset = king_inference_tensor_option_ulong(sampling_op, "token_offset", 0);
        if (token_offset > ZEND_ULONG_MAX - (zend_ulong) host_indices[0]) {
            return FAILURE;
        }
        selected_token_id = token_offset + (zend_ulong) host_indices[0];
        add_assoc_bool(logits_probe, "selected_token_ready", true);
        add_assoc_long(logits_probe, "selected_token_id", (zend_long) selected_token_id);
        add_assoc_double(logits_probe, "selected_token_probability", 1.0);
        add_assoc_double(logits_probe, "selected_token_logit", (double) host_logits[0]);
        add_assoc_double(logits_probe, "selected_token_rank", 0.0);
        add_assoc_string(logits_probe, "selected_token_source", "bounded_readback_argmax");
        return SUCCESS;
    }
    if (temperature == 0.0) {
        token_offset = king_inference_tensor_option_ulong(sampling_op, "token_offset", 0);
        if (token_offset > ZEND_ULONG_MAX - (zend_ulong) host_indices[0]) {
            return FAILURE;
        }
        selected_token_id = token_offset + (zend_ulong) host_indices[0];
        add_assoc_bool(logits_probe, "selected_token_ready", true);
        add_assoc_long(logits_probe, "selected_token_id", (zend_long) selected_token_id);
        add_assoc_double(logits_probe, "selected_token_probability", 1.0);
        add_assoc_double(logits_probe, "selected_token_logit", (double) host_logits[0]);
        add_assoc_double(logits_probe, "selected_token_rank", 0.0);
        add_assoc_string(logits_probe, "selected_token_source", "bounded_readback_temperature_zero");
        return SUCCESS;
    }

    for (i = 0; i < candidate_count; i++) {
        double score = (double) host_logits[i] / temperature;

        probabilities[i] = score;
        if (i == 0 || score > max_score) {
            max_score = score;
        }
    }
    for (i = 0; i < candidate_count; i++) {
        probabilities[i] = king_inference_graph_exp(probabilities[i] - max_score);
        probability_sum += probabilities[i];
    }
    if (probability_sum <= 0.0) {
        return FAILURE;
    }
    for (i = 0; i < candidate_count; i++) {
        probabilities[i] /= probability_sum;
    }

    active_count = top_k == 0 || top_k > candidate_count ? candidate_count : top_k;
    probability_sum = 0.0;
    for (i = 0; i < active_count; i++) {
        probability_sum += probabilities[i];
        if (probability_sum >= top_p) {
            active_count = i + 1;
            break;
        }
    }
    for (i = 0; i < active_count; i++) {
        active_sum += probabilities[i];
    }
    if (active_sum <= 0.0) {
        return FAILURE;
    }
    if (has_seed) {
        double target;
        double cumulative = 0.0;

        sample_index = king_inference_tensor_option_ulong(sampling_op, "sample_index", 0);
        target = king_inference_graph_sampling_unit(seed, candidate_count ^ (active_count << 7) ^ sample_index) * active_sum;
        selected_rank = active_count - 1;
        for (i = 0; i < active_count; i++) {
            cumulative += probabilities[i];
            if (target <= cumulative) {
                selected_rank = i;
                break;
            }
        }
    }

    token_offset = king_inference_tensor_option_ulong(sampling_op, "token_offset", 0);
    if (token_offset > ZEND_ULONG_MAX - (zend_ulong) host_indices[selected_rank]) {
        return FAILURE;
    }
    selected_token_id = token_offset + (zend_ulong) host_indices[selected_rank];
    add_assoc_bool(logits_probe, "selected_token_ready", true);
    add_assoc_long(logits_probe, "selected_token_id", (zend_long) selected_token_id);
    add_assoc_double(logits_probe, "selected_token_probability", probabilities[selected_rank] / active_sum);
    add_assoc_double(logits_probe, "selected_token_logit", (double) host_logits[selected_rank]);
    add_assoc_double(logits_probe, "selected_token_rank", (double) selected_rank);
    add_assoc_string(logits_probe, "selected_token_source", "bounded_readback_sampler");
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_add_bounded_logits_readback(
    king_inference_model_object *model,
    zval *graph,
    zend_string *logits_id,
    zend_string *weight_name,
    king_inference_cuda_device_ptr final_norm_output,
    zend_ulong final_norm_width,
    king_inference_cuda_device_ptr logits_output,
    zend_ulong logits_rows,
    zval *logits_probe
) {
    unsigned int host_indices[KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES];
    float host_logits[KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES];
    zend_ulong candidate_count = 0;
    zend_ulong requested_candidates;
    zval *sampling_op = king_inference_cuda_decoder_graph_executor_sampling_op_for_logits(graph, logits_id);
    zval candidates;
    zend_ulong i;
    bool materialize_candidates = true;

    requested_candidates = king_inference_cuda_decoder_graph_executor_candidate_limit(
        sampling_op,
        (zend_ulong) model->cuda_logits_readback_candidate_limit
    );
    materialize_candidates = king_inference_decode_graph_bool_option(
        sampling_op,
        "materialize_candidates",
        true
    );
    add_assoc_bool(logits_probe, "bounded_readback_attempted", true);
    add_assoc_bool(logits_probe, "bounded_readback_available", model->cuda_logits_readback_available);
    add_assoc_bool(logits_probe, "bounded_readback_executed", false);
    add_assoc_bool(logits_probe, "sampling_op_present", sampling_op != NULL);
    add_assoc_bool(logits_probe, "candidate_materialization_requested", materialize_candidates);
    if (sampling_op != NULL) {
        zval *op_name = king_inference_array_find(sampling_op, "op");

        if (op_name != NULL && Z_TYPE_P(op_name) == IS_STRING) {
            add_assoc_str(logits_probe, "sampling_op", zend_string_copy(Z_STR_P(op_name)));
        }
    }
    add_assoc_long(logits_probe, "requested_candidates", (zend_long) requested_candidates);
    if (king_inference_cuda_logits_readback_top_k(
            model,
            logits_output,
            logits_rows,
            requested_candidates,
            host_indices,
            host_logits,
            &candidate_count
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_logits_readback_result,
            model->cuda_logits_readback_error[0] != '\0'
                ? model->cuda_logits_readback_error
                : "cuda_decoder_graph_logits_readback_failed"
        );
        return FAILURE;
    }

    if (sampling_op != NULL) {
        if (king_inference_cuda_decoder_graph_executor_add_selected_bounded_token(
                sampling_op,
                host_indices,
                host_logits,
                candidate_count,
                logits_probe
        ) != SUCCESS) {
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_bounded_token_selection_failed");
            return FAILURE;
        }
    } else {
        add_assoc_bool(logits_probe, "selected_token_ready", false);
    }
    if (king_inference_cuda_decoder_graph_executor_compare_bounded_logits_projection(
            model,
            weight_name,
            final_norm_output,
            final_norm_width,
            logits_rows,
            host_indices,
            host_logits,
            candidate_count,
            logits_probe
    ) != SUCCESS) {
        return FAILURE;
    }

    add_assoc_bool(logits_probe, "bounded_readback_executed", true);
    add_assoc_long(logits_probe, "candidate_count", (zend_long) candidate_count);
    add_assoc_long(logits_probe, "readback_bytes", (zend_long) model->cuda_logits_readback_last_bytes);
    add_assoc_long(logits_probe, "full_logits_bytes", (zend_long) model->cuda_logits_readback_full_bytes);
    add_assoc_long(logits_probe, "saved_readback_bytes", (zend_long) model->cuda_logits_readback_saved_bytes);
    if (!materialize_candidates) {
        add_assoc_bool(logits_probe, "candidates_materialized", false);
        return SUCCESS;
    }

    array_init(&candidates);
    for (i = 0; i < candidate_count; i++) {
        zval candidate;

        array_init(&candidate);
        add_assoc_long(&candidate, "rank", (zend_long) i);
        add_assoc_long(&candidate, "token_id", (zend_long) host_indices[i]);
        add_assoc_double(&candidate, "logit", (double) host_logits[i]);
        add_next_index_zval(&candidates, &candidate);
    }
    add_assoc_bool(logits_probe, "candidates_materialized", true);
    add_assoc_zval(logits_probe, "candidates", &candidates);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_logits_projection(
    king_inference_model_object *model,
    zval *graph,
    zend_string *final_norm_id,
    king_inference_cuda_device_ptr final_norm_output,
    zend_ulong final_norm_width,
    zval *logits_probe,
    king_inference_cuda_device_ptr *logits_output,
    zend_ulong *logits_rows,
    zend_string **logits_id_out,
    zend_ulong *executed_device_ops
) {
    zval *logits_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, final_norm_id, "linear");
    zend_string *logits_id = NULL;
    zend_string *weight = NULL;
    zend_string *resolved_weight = NULL;
    const char *projection_status = NULL;
    size_t bytes;

    *logits_output = 0;
    *logits_rows = 0;
    if (logits_id_out != NULL) {
        *logits_id_out = NULL;
    }
    add_assoc_string(logits_probe, "type", "gpu_decoder_logits_projection_device_execution");
    add_assoc_bool(logits_probe, "attempted", logits_op != NULL);
    add_assoc_bool(logits_probe, "available", model->cuda_decoder_graph_executor_logits_projection_execution_available);
    add_assoc_bool(logits_probe, "executed", false);
    add_assoc_bool(logits_probe, "retained_device_output", false);
    if (logits_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_logits_projection_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_logits_projection_execution_unavailable");
        return FAILURE;
    }
    if (final_norm_output == 0
        || final_norm_width == 0
        || final_norm_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || king_inference_graph_required_string(logits_op, "id", &logits_id) != SUCCESS
        || !zend_string_equals_literal(logits_id, "logits")
        || king_inference_cuda_decoder_graph_executor_linear_shape(model, logits_op, final_norm_width, &weight, logits_rows) != SUCCESS
        || *logits_rows == 0
        || *logits_rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_logits_projection_contract_invalid");
        return FAILURE;
    }
    if (!king_inference_cuda_output_projection_resolve(model, &resolved_weight, NULL, &projection_status, NULL)
        || resolved_weight == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_output_projection_result,
            projection_status != NULL ? projection_status : "cuda_decoder_graph_logits_projection_weight_unresolved"
        );
        return FAILURE;
    }
    if (!zend_string_equals(resolved_weight, weight)) {
        zend_string_release(resolved_weight);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_logits_projection_weight_mismatch");
        return FAILURE;
    }
    zend_string_release(resolved_weight);

    bytes = (size_t) *logits_rows * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(model, bytes, logits_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_logits_projection_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_output_projection_q8_0(model, final_norm_output, final_norm_width, *logits_output, *logits_rows) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, logits_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_output_projection_result,
            model->cuda_output_projection_error[0] != '\0'
                ? model->cuda_output_projection_error
                : "cuda_decoder_graph_logits_projection_execution_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_add_bounded_logits_readback(
            model,
            graph,
            logits_id,
            weight,
            final_norm_output,
            final_norm_width,
            *logits_output,
            *logits_rows,
            logits_probe
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, logits_output);
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_logits_projection_execution_count++;
    model->cuda_decoder_graph_executor_last_logits_rows = *logits_rows;
    if (logits_id_out != NULL) {
        *logits_id_out = logits_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(logits_probe, "input", zend_string_copy(final_norm_id));
    add_assoc_str(logits_probe, "logits", zend_string_copy(logits_id));
    add_assoc_str(logits_probe, "weight", zend_string_copy(weight));
    add_assoc_bool(logits_probe, "executed", true);
    add_assoc_bool(logits_probe, "retained_device_output", true);
    add_assoc_long(logits_probe, "input_width", (zend_long) final_norm_width);
    add_assoc_long(logits_probe, "rows", (zend_long) *logits_rows);
    add_assoc_long(logits_probe, "bytes", (zend_long) bytes);
    return SUCCESS;
}
