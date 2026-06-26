/*
 * CUDA decoder graph final-stage helpers for native King GPU inference.
 */

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

static zend_result king_inference_cuda_decoder_graph_executor_add_bounded_logits_readback(
    king_inference_model_object *model,
    zval *graph,
    zend_string *logits_id,
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

    requested_candidates = king_inference_cuda_decoder_graph_executor_candidate_limit(
        sampling_op,
        (zend_ulong) model->cuda_logits_readback_candidate_limit
    );
    add_assoc_bool(logits_probe, "bounded_readback_attempted", true);
    add_assoc_bool(logits_probe, "bounded_readback_available", model->cuda_logits_readback_available);
    add_assoc_bool(logits_probe, "bounded_readback_executed", false);
    add_assoc_bool(logits_probe, "sampling_op_present", sampling_op != NULL);
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

    array_init(&candidates);
    for (i = 0; i < candidate_count; i++) {
        zval candidate;

        array_init(&candidate);
        add_assoc_long(&candidate, "rank", (zend_long) i);
        add_assoc_long(&candidate, "token_id", (zend_long) host_indices[i]);
        add_assoc_double(&candidate, "logit", (double) host_logits[i]);
        add_next_index_zval(&candidates, &candidate);
    }
    add_assoc_bool(logits_probe, "bounded_readback_executed", true);
    add_assoc_long(logits_probe, "candidate_count", (zend_long) candidate_count);
    add_assoc_long(logits_probe, "readback_bytes", (zend_long) model->cuda_logits_readback_last_bytes);
    add_assoc_long(logits_probe, "full_logits_bytes", (zend_long) model->cuda_logits_readback_full_bytes);
    add_assoc_long(logits_probe, "saved_readback_bytes", (zend_long) model->cuda_logits_readback_saved_bytes);
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
    if (king_inference_cuda_output_projection_resolve(model, &resolved_weight, NULL, &projection_status, NULL) != SUCCESS
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
