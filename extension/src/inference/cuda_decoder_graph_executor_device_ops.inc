/*
 * CUDA decoder graph executor device-op admission for native King GPU inference.
 */

static bool king_inference_cuda_decoder_graph_executor_device_op(zend_string *op_name)
{
    return zend_string_equals_literal(op_name, "embedding")
        || zend_string_equals_literal(op_name, "rms_norm")
        || zend_string_equals_literal(op_name, "linear")
        || zend_string_equals_literal(op_name, "add")
        || zend_string_equals_literal(op_name, "scale")
        || zend_string_equals_literal(op_name, "mul")
        || zend_string_equals_literal(op_name, "silu")
        || zend_string_equals_literal(op_name, "slice")
        || zend_string_equals_literal(op_name, "rope")
        || zend_string_equals_literal(op_name, "kv_write")
        || zend_string_equals_literal(op_name, "kv_attention")
        || zend_string_equals_literal(op_name, "stack");
}

static bool king_inference_cuda_decoder_graph_executor_host_sampling_op(zend_string *op_name)
{
    return zend_string_equals_literal(op_name, "argmax_token")
        || zend_string_equals_literal(op_name, "sample_token");
}

static bool king_inference_cuda_decoder_graph_executor_zval_string_equals(
    zval *value,
    zend_string *expected
) {
    return value != NULL
        && expected != NULL
        && Z_TYPE_P(value) == IS_STRING
        && zend_string_equals(Z_STR_P(value), expected);
}

static zval *king_inference_cuda_decoder_graph_executor_ops(zval *graph)
{
    zval *ops = king_inference_array_find(graph, "ops");

    if (ops == NULL) {
        ops = king_inference_array_find(graph, "steps");
    }
    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY) {
        return NULL;
    }
    return ops;
}

static zend_result king_inference_cuda_decoder_graph_executor_prepare_plan(
    king_inference_model_object *model,
    zval *graph,
    zval *result
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    zval plan;
    zend_ulong total_ops = 0;
    zend_ulong device_ops = 0;
    zend_ulong host_sampling_ops = 0;

    if (ops == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_plan_ops_missing");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zend_string *op_name = NULL;

        if (entry == NULL
            || Z_TYPE_P(entry) != IS_ARRAY
            || king_inference_graph_required_string(entry, "op", &op_name) != SUCCESS) {
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_plan_op_invalid");
            return FAILURE;
        }
        if (king_inference_cuda_decoder_graph_executor_device_op(op_name)) {
            device_ops++;
        } else if (king_inference_cuda_decoder_graph_executor_host_sampling_op(op_name)) {
            host_sampling_ops++;
        } else {
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_plan_op_unsupported");
            return FAILURE;
        }
        total_ops++;
    } ZEND_HASH_FOREACH_END();

    if (host_sampling_ops > 0 && !model->cuda_decoder_graph_executor_sampling_readback_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_plan_sampling_readback_unavailable"
        );
        return FAILURE;
    }

    array_init(&plan);
    add_assoc_string(&plan, "type", "gpu_decoder_graph_execution_plan");
    add_assoc_bool(&plan, "ready", true);
    add_assoc_bool(&plan, "device_execution_result_ready", false);
    add_assoc_bool(&plan, "token_result_ready", false);
    add_assoc_bool(&plan, "device_ops_ready", true);
    add_assoc_bool(&plan, "host_sampling_after_bounded_readback", host_sampling_ops > 0);
    add_assoc_long(&plan, "total_ops", (zend_long) total_ops);
    add_assoc_long(&plan, "device_ops", (zend_long) device_ops);
    add_assoc_long(&plan, "host_sampling_ops", (zend_long) host_sampling_ops);
    add_assoc_string(&plan, "next_bridge", "execute_device_ops_and_write_bounded_logits_result");
    add_assoc_zval(result, "execution_plan", &plan);
    add_assoc_bool(result, "execution_plan_ready", true);
    add_assoc_long(result, "planned_device_ops", (zend_long) device_ops);
    add_assoc_long(result, "planned_host_sampling_ops", (zend_long) host_sampling_ops);

    model->cuda_decoder_graph_executor_plan_count++;
    model->cuda_decoder_graph_executor_last_plan_op_count = total_ops;
    model->cuda_decoder_graph_executor_last_plan_device_ops = device_ops;
    model->cuda_decoder_graph_executor_last_plan_host_sampling_ops = host_sampling_ops;
    return SUCCESS;
}

static zval *king_inference_cuda_decoder_graph_executor_first_op(
    zval *graph,
    const char *name
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    size_t name_length = strlen(name);

    if (ops == NULL) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && Z_STRLEN_P(op_name) == name_length
            && memcmp(Z_STRVAL_P(op_name), name, name_length) == 0) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zval *king_inference_cuda_decoder_graph_executor_op_for_input(
    zval *graph,
    zend_string *input_id,
    const char *name
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    size_t name_length = name != NULL ? strlen(name) : 0;

    if (ops == NULL || input_id == NULL || name_length == 0) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *input;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        input = king_inference_array_find(entry, "input");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && Z_STRLEN_P(op_name) == name_length
            && memcmp(Z_STRVAL_P(op_name), name, name_length) == 0
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(input, input_id)) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_cuda_decoder_graph_executor_linear_shape(
    king_inference_model_object *model,
    zval *op,
    zend_ulong input_width,
    zend_string **weight_name,
    zend_ulong *rows
) {
    zval *descriptor;
    zval *dimensions;
    zend_ulong rank;
    zend_ulong type;
    zend_ulong cols;
    zend_ulong tensor_rows;
    zend_ulong row_offset = king_inference_tensor_option_ulong(op, "row_offset", 0);
    zend_ulong row_limit;

    *weight_name = NULL;
    *rows = 0;
    if (king_inference_graph_required_string(op, "weight", weight_name) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_linear_weight_invalid");
        return FAILURE;
    }

    descriptor = king_inference_tensor_index_descriptor(model, *weight_name);
    dimensions = descriptor != NULL
        ? zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1)
        : NULL;
    if (descriptor == NULL
        || dimensions == NULL
        || Z_TYPE_P(dimensions) != IS_ARRAY
        || !king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)
        || rank != 2
        || type != 8
        || king_inference_tensor_dimension_at(dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(dimensions, 1, &tensor_rows) != SUCCESS
        || cols != input_width
        || tensor_rows == 0
        || tensor_rows > UINT_MAX) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_linear_q8_0_shape_invalid");
        return FAILURE;
    }

    if (row_offset != 0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_linear_row_offset_unsupported");
        return FAILURE;
    }

    row_limit = king_inference_tensor_option_ulong(op, "row_limit", tensor_rows);
    if (row_limit == 0 || row_limit > tensor_rows) {
        row_limit = tensor_rows;
    }
    *rows = row_limit;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_slice_shape(
    king_inference_model_object *model,
    zval *op,
    zend_ulong input_length,
    zend_ulong *offset,
    zend_ulong *length
) {
    zend_ulong slice_offset = king_inference_tensor_option_ulong(op, "offset", 0);
    zend_ulong slice_length = king_inference_tensor_option_ulong(op, "count", 0);

    if (slice_length == 0) {
        slice_length = input_length > slice_offset ? input_length - slice_offset : 0;
    }
    if (input_length == 0
        || slice_offset > input_length
        || slice_length == 0
        || slice_length > input_length - slice_offset
        || slice_offset > UINT_MAX
        || slice_length > UINT_MAX
        || slice_length > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_slice_shape_invalid");
        return FAILURE;
    }

    *offset = slice_offset;
    *length = slice_length;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_release_device_ptr(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr *device_ptr
) {
    if (device_ptr != NULL && *device_ptr != 0) {
        if (king_inference_cuda_device_allocator_free(model, *device_ptr) != SUCCESS) {
            return FAILURE;
        }
        *device_ptr = 0;
    }
    return SUCCESS;
}

#include "cuda_decoder_graph_executor_rope_ops.inc"
#include "cuda_decoder_graph_executor_kv_ops.inc"
#include "cuda_decoder_graph_executor_query_ops.inc"
#include "cuda_decoder_graph_executor_projection_ops.inc"
#include "cuda_decoder_graph_executor_probe_ops.inc"

static zend_result king_inference_cuda_decoder_graph_executor_execute_initial_device_ops(
    king_inference_model_object *model,
    zval *graph,
    zval *result
) {
    zval *embedding_op = king_inference_cuda_decoder_graph_executor_first_op(graph, "embedding");
    zval *rms_norm_op = NULL;
    zval *token_value;
    zval embedding_probe;
    zval rms_norm_probe;
    zval linear_probe;
    zval slice_probe;
    zval rope_probe;
    zval kv_head_prepare_probe;
    zval attention_projection_probe;
    zend_string *embedding_id = NULL;
    zend_string *rms_norm_id = NULL;
    zend_string *rms_weight = NULL;
    zend_string *linear_id = NULL;
    zend_string *linear_weight = NULL;
    zend_string *attention_stack_id = NULL;
    zend_ulong width = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong linear_rows = 0;
    zend_ulong slice_length = 0;
    zend_ulong token_id;
    size_t bytes;
    king_inference_cuda_device_ptr embedding_output = 0;
    king_inference_cuda_device_ptr rms_norm_output = 0;
    king_inference_cuda_device_ptr linear_output = 0;
    king_inference_cuda_device_ptr attention_stack_output = 0;
    king_inference_cuda_device_ptr attention_projection_output = 0;
    zend_ulong attention_stack_width = 0;
    zend_ulong attention_projection_width = 0;
    zend_ulong executed_device_ops = 0;
    bool rope_executed = false;
    bool kv_head_prepared = false;
    bool attention_projection_probe_initialized = false;
    bool attention_projection_executed = false;
    double epsilon = 0.000001;

    array_init(&embedding_probe);
    add_assoc_string(&embedding_probe, "type", "gpu_decoder_embedding_device_execution");
    add_assoc_bool(&embedding_probe, "attempted", embedding_op != NULL);
    add_assoc_bool(&embedding_probe, "available", model->cuda_decoder_graph_executor_embedding_execution_available);
    add_assoc_bool(&embedding_probe, "executed", false);
    add_assoc_bool(&embedding_probe, "retained_device_output", false);

    array_init(&rms_norm_probe);
    add_assoc_string(&rms_norm_probe, "type", "gpu_decoder_rms_norm_device_execution");
    add_assoc_bool(&rms_norm_probe, "attempted", false);
    add_assoc_bool(&rms_norm_probe, "available", model->cuda_decoder_graph_executor_rms_norm_execution_available);
    add_assoc_bool(&rms_norm_probe, "executed", false);
    add_assoc_bool(&rms_norm_probe, "retained_device_output", false);

    array_init(&linear_probe);
    add_assoc_string(&linear_probe, "type", "gpu_decoder_linear_device_execution");
    add_assoc_bool(&linear_probe, "attempted", false);
    add_assoc_bool(&linear_probe, "available", model->cuda_decoder_graph_executor_linear_execution_available);
    add_assoc_bool(&linear_probe, "executed", false);
    add_assoc_bool(&linear_probe, "retained_device_output", false);

    array_init(&slice_probe);
    add_assoc_string(&slice_probe, "type", "gpu_decoder_slice_device_execution");
    add_assoc_bool(&slice_probe, "attempted", false);
    add_assoc_bool(&slice_probe, "available", model->cuda_decoder_graph_executor_slice_execution_available);
    add_assoc_bool(&slice_probe, "executed", false);
    add_assoc_bool(&slice_probe, "retained_device_output", false);

    array_init(&rope_probe);
    add_assoc_string(&rope_probe, "type", "gpu_decoder_rope_device_execution");
    add_assoc_bool(&rope_probe, "attempted", false);
    add_assoc_bool(&rope_probe, "available", model->cuda_decoder_graph_executor_rope_execution_available);
    add_assoc_bool(&rope_probe, "executed", false);
    add_assoc_bool(&rope_probe, "retained_device_output", false);

    array_init(&kv_head_prepare_probe);
    add_assoc_string(&kv_head_prepare_probe, "type", "gpu_decoder_kv_head_prepare_device_execution");
    add_assoc_bool(&kv_head_prepare_probe, "attempted", false);
    add_assoc_bool(
        &kv_head_prepare_probe,
        "available",
        model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
    );
    add_assoc_bool(&kv_head_prepare_probe, "executed", false);
    add_assoc_bool(&kv_head_prepare_probe, "retained_device_output", false);

    if (embedding_op == NULL) {
        add_assoc_zval(result, "embedding_device_execution", &embedding_probe);
        add_assoc_zval(result, "rms_norm_device_execution", &rms_norm_probe);
        add_assoc_zval(result, "linear_device_execution", &linear_probe);
        add_assoc_zval(result, "slice_device_execution", &slice_probe);
        add_assoc_zval(result, "rope_device_execution", &rope_probe);
        add_assoc_zval(result, "kv_head_prepare_device_execution", &kv_head_prepare_probe);
        add_assoc_bool(result, "embedding_device_execution_ready", false);
        add_assoc_bool(result, "rms_norm_device_execution_ready", false);
        add_assoc_bool(result, "linear_device_execution_ready", false);
        add_assoc_bool(result, "slice_device_execution_ready", false);
        add_assoc_bool(result, "rope_device_execution_ready", false);
        add_assoc_bool(result, "kv_head_prepare_device_execution_ready", false);
        add_assoc_bool(result, "kv_write_device_execution_ready", false);
        add_assoc_bool(result, "kv_attention_device_execution_ready", false);
        add_assoc_bool(result, "attention_stack_slot_device_execution_ready", false);
        add_assoc_bool(result, "attention_heads_device_execution_ready", false);
        add_assoc_bool(result, "attention_stack_device_execution_ready", false);
        add_assoc_bool(result, "attention_output_projection_device_execution_ready", false);
        add_assoc_long(result, "executed_device_ops", 0);
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_embedding_execution_available) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_embedding_execution_unavailable"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(embedding_op, "id", &embedding_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_embedding_id_invalid");
        return FAILURE;
    }

    token_value = king_inference_array_find(embedding_op, "token_id");
    if (token_value == NULL) {
        token_value = king_inference_array_find(embedding_op, "token");
    }
    if (token_value == NULL || Z_TYPE_P(token_value) != IS_LONG || Z_LVAL_P(token_value) < 0) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_embedding_token_invalid"
        );
        return FAILURE;
    }
    if (width == 0 || width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_embedding_width_invalid"
        );
        return FAILURE;
    }

    token_id = (zend_ulong) Z_LVAL_P(token_value);
    bytes = (size_t) width * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(model, bytes, &embedding_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_embedding_allocation_failed"
        );
        return FAILURE;
    }

    if (king_inference_cuda_embedding_row_load(model, token_id, embedding_output, width) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_embedding_row_result,
            model->cuda_embedding_row_error[0] != '\0'
                ? model->cuda_embedding_row_error
                : "cuda_decoder_graph_embedding_execution_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_embedding_execution_count++;
    model->cuda_decoder_graph_executor_last_embedding_width = width;
    model->cuda_decoder_graph_executor_last_embedding_token_id = token_id;
    model->cuda_decoder_graph_executor_last_embedding_token_available = true;
    executed_device_ops++;

    add_assoc_bool(&embedding_probe, "executed", true);
    add_assoc_long(&embedding_probe, "token_id", (zend_long) token_id);
    add_assoc_long(&embedding_probe, "width", (zend_long) width);
    add_assoc_long(&embedding_probe, "bytes", (zend_long) bytes);

    rms_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, embedding_id, "rms_norm");
    add_assoc_bool(&rms_norm_probe, "attempted", rms_norm_op != NULL);
    if (rms_norm_op != NULL) {
        zval *linear_op = NULL;

        if (!model->cuda_decoder_graph_executor_rms_norm_execution_available) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                -1,
                "cuda_decoder_graph_rms_norm_execution_unavailable"
            );
            return FAILURE;
        }
        if (king_inference_graph_required_string(rms_norm_op, "id", &rms_norm_id) != SUCCESS
            || king_inference_graph_required_string(rms_norm_op, "weight", &rms_weight) != SUCCESS
            || king_inference_graph_finite_double_option(rms_norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
            || epsilon <= 0.0) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_rms_norm_contract_invalid");
            return FAILURE;
        }
        if (king_inference_cuda_device_allocator_alloc(model, bytes, &rms_norm_output) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_device_allocator_result,
                model->cuda_device_allocator_error[0] != '\0'
                    ? model->cuda_device_allocator_error
                    : "cuda_decoder_graph_rms_norm_allocation_failed"
            );
            return FAILURE;
        }
        if (king_inference_cuda_rms_norm_f32(
                model,
                rms_weight,
                embedding_output,
                rms_norm_output,
                width,
                epsilon
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_rms_norm_result,
                model->cuda_rms_norm_error[0] != '\0'
                    ? model->cuda_rms_norm_error
                    : "cuda_decoder_graph_rms_norm_execution_failed"
            );
            return FAILURE;
        }
        model->cuda_decoder_graph_executor_rms_norm_execution_count++;
        model->cuda_decoder_graph_executor_last_rms_norm_width = width;
        executed_device_ops++;

        add_assoc_bool(&rms_norm_probe, "executed", true);
        add_assoc_long(&rms_norm_probe, "width", (zend_long) width);
        add_assoc_long(&rms_norm_probe, "bytes", (zend_long) bytes);
        add_assoc_double(&rms_norm_probe, "epsilon", epsilon);

        linear_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, rms_norm_id, "linear");
        add_assoc_bool(&linear_probe, "attempted", linear_op != NULL);
        if (linear_op != NULL) {
            if (!model->cuda_decoder_graph_executor_linear_execution_available) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    -1,
                    "cuda_decoder_graph_linear_execution_unavailable"
                );
                return FAILURE;
            }
            if (king_inference_graph_required_string(linear_op, "id", &linear_id) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_linear_id_invalid");
                return FAILURE;
            }
            if (king_inference_cuda_decoder_graph_executor_linear_shape(
                    model,
                    linear_op,
                    width,
                    &linear_weight,
                    &linear_rows
                ) != SUCCESS
                || linear_rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                return FAILURE;
            }
            if (king_inference_cuda_device_allocator_alloc(
                    model,
                    (size_t) linear_rows * sizeof(float),
                    &linear_output
                ) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    model->cuda_device_allocator_result,
                    model->cuda_device_allocator_error[0] != '\0'
                        ? model->cuda_device_allocator_error
                        : "cuda_decoder_graph_linear_allocation_failed"
                );
                return FAILURE;
            }
            if (king_inference_cuda_quantized_matvec_q8_0(
                    model,
                    linear_weight,
                    rms_norm_output,
                    width,
                    linear_output,
                    linear_rows
                ) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    model->cuda_quantized_matvec_result,
                    model->cuda_quantized_matvec_error[0] != '\0'
                        ? model->cuda_quantized_matvec_error
                        : "cuda_decoder_graph_linear_execution_failed"
                );
                return FAILURE;
            }
            model->cuda_decoder_graph_executor_linear_execution_count++;
            model->cuda_decoder_graph_executor_last_linear_input_width = width;
            model->cuda_decoder_graph_executor_last_linear_rows = linear_rows;
            executed_device_ops++;

            add_assoc_bool(&linear_probe, "executed", true);
            add_assoc_long(&linear_probe, "input_width", (zend_long) width);
            add_assoc_long(&linear_probe, "rows", (zend_long) linear_rows);
            add_assoc_long(&linear_probe, "bytes", (zend_long) ((size_t) linear_rows * sizeof(float)));

            if (king_inference_cuda_decoder_graph_executor_execute_query_attention_heads(
                    model,
                    graph,
                    rms_norm_id,
                    rms_norm_output,
                    width,
                    linear_id,
                    linear_output,
                    linear_rows,
                    &slice_probe,
                    &rope_probe,
                    &kv_head_prepare_probe,
                    &attention_stack_output,
                    &attention_stack_width,
                    &attention_stack_id,
                    &executed_device_ops,
                    &slice_length,
                    &rope_executed,
                    &kv_head_prepared
            ) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_stack_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                return FAILURE;
            }
            if (attention_stack_output != 0 && attention_stack_id != NULL) {
                array_init(&attention_projection_probe);
                attention_projection_probe_initialized = true;
                if (king_inference_cuda_decoder_graph_executor_execute_attention_output_projection(
                        model,
                        graph,
                        attention_stack_id,
                        attention_stack_output,
                        attention_stack_width,
                        &attention_projection_probe,
                        &attention_projection_output,
                        &attention_projection_width,
                        &executed_device_ops
                ) != SUCCESS) {
                    zval_ptr_dtor(&attention_projection_probe);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_projection_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_stack_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                    king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                    return FAILURE;
                }
                attention_projection_executed = true;
                if (king_inference_cuda_decoder_graph_executor_release_device_ptr(
                        model,
                        &attention_projection_output
                ) != SUCCESS) {
                    zval_ptr_dtor(&attention_projection_probe);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_stack_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                    king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                    king_inference_cuda_decoder_graph_executor_set_error(
                        model,
                        model->cuda_device_allocator_result,
                        model->cuda_device_allocator_error[0] != '\0'
                            ? model->cuda_device_allocator_error
                            : "cuda_decoder_graph_attention_output_projection_free_failed"
                    );
                    return FAILURE;
                }
                add_assoc_bool(&attention_projection_probe, "retained_device_output", false);
                add_assoc_bool(&attention_projection_probe, "projection_output_released", true);
            }
            if (attention_stack_output != 0
                && king_inference_cuda_decoder_graph_executor_release_device_ptr(
                    model,
                    &attention_stack_output
            ) != SUCCESS) {
                if (attention_projection_probe_initialized) {
                    zval_ptr_dtor(&attention_projection_probe);
                }
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    model->cuda_device_allocator_result,
                    model->cuda_device_allocator_error[0] != '\0'
                        ? model->cuda_device_allocator_error
                        : "cuda_decoder_graph_attention_stack_free_failed"
                );
                return FAILURE;
            }
            add_assoc_bool(&kv_head_prepare_probe, "attention_stack_released", true);
            add_assoc_long(&kv_head_prepare_probe, "attention_stack_width", (zend_long) attention_stack_width);
            if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output) != SUCCESS) {
                if (attention_projection_probe_initialized) {
                    zval_ptr_dtor(&attention_projection_probe);
                }
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    model->cuda_device_allocator_result,
                    model->cuda_device_allocator_error[0] != '\0'
                        ? model->cuda_device_allocator_error
                        : "cuda_decoder_graph_linear_free_failed"
                );
                return FAILURE;
            }
        }

        if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output) != SUCCESS) {
            if (attention_projection_probe_initialized) {
                zval_ptr_dtor(&attention_projection_probe);
            }
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_device_allocator_result,
                model->cuda_device_allocator_error[0] != '\0'
                    ? model->cuda_device_allocator_error
                    : "cuda_decoder_graph_rms_norm_free_failed"
            );
            return FAILURE;
        }
    }

    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output) != SUCCESS) {
        if (attention_projection_probe_initialized) {
            zval_ptr_dtor(&attention_projection_probe);
        }
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_embedding_free_failed"
        );
        return FAILURE;
    }
    add_assoc_zval(result, "embedding_device_execution", &embedding_probe);
    add_assoc_zval(result, "rms_norm_device_execution", &rms_norm_probe);
    add_assoc_zval(result, "linear_device_execution", &linear_probe);
    add_assoc_zval(result, "slice_device_execution", &slice_probe);
    add_assoc_zval(result, "rope_device_execution", &rope_probe);
    add_assoc_zval(result, "kv_head_prepare_device_execution", &kv_head_prepare_probe);
    if (attention_projection_probe_initialized) {
        add_assoc_zval(result, "attention_output_projection_device_execution", &attention_projection_probe);
    }
    add_assoc_bool(result, "embedding_device_execution_ready", true);
    add_assoc_bool(result, "rms_norm_device_execution_ready", rms_norm_op != NULL);
    add_assoc_bool(result, "linear_device_execution_ready", linear_rows > 0);
    add_assoc_bool(result, "slice_device_execution_ready", slice_length > 0);
    add_assoc_bool(result, "rope_device_execution_ready", rope_executed || kv_head_prepared);
    add_assoc_bool(result, "kv_head_prepare_device_execution_ready", kv_head_prepared);
    add_assoc_bool(result, "kv_write_device_execution_ready", kv_head_prepared);
    add_assoc_bool(result, "kv_attention_device_execution_ready", kv_head_prepared);
    add_assoc_bool(result, "attention_stack_slot_device_execution_ready", kv_head_prepared);
    add_assoc_bool(result, "attention_heads_device_execution_ready", kv_head_prepared);
    add_assoc_bool(result, "attention_stack_device_execution_ready", kv_head_prepared && attention_stack_width > 0);
    add_assoc_bool(result, "attention_output_projection_device_execution_ready", attention_projection_executed);
    add_assoc_bool(result, "first_device_op_executed", true);
    add_assoc_bool(result, "initial_device_ops_executed", executed_device_ops > 1);
    add_assoc_long(result, "executed_device_ops", (zend_long) executed_device_ops);
    return SUCCESS;
}
