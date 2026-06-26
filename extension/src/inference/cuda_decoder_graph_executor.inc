/*
 * CUDA decoder graph executor contract for native King GPU inference.
 */

static void king_inference_cuda_decoder_graph_executor_reset_fields(king_inference_model_object *model)
{
    model->cuda_decoder_graph_executor_result = 0;
    model->cuda_decoder_graph_executor_error[0] = '\0';
    model->cuda_decoder_graph_executor_graph_count = 0;
    model->cuda_decoder_graph_executor_last_op_count = 0;
    model->cuda_decoder_graph_executor_last_supported_op_count = 0;
    model->cuda_decoder_graph_executor_result_count = 0;
    model->cuda_decoder_graph_executor_last_result_op_count = 0;
    model->cuda_decoder_graph_executor_plan_count = 0;
    model->cuda_decoder_graph_executor_last_plan_op_count = 0;
    model->cuda_decoder_graph_executor_last_plan_device_ops = 0;
    model->cuda_decoder_graph_executor_last_plan_host_sampling_ops = 0;
    model->cuda_decoder_graph_executor_embedding_execution_count = 0;
    model->cuda_decoder_graph_executor_last_embedding_width = 0;
    model->cuda_decoder_graph_executor_last_embedding_token_id = 0;
    model->cuda_decoder_graph_executor_rms_norm_execution_count = 0;
    model->cuda_decoder_graph_executor_last_rms_norm_width = 0;
    model->cuda_decoder_graph_executor_linear_execution_count = 0;
    model->cuda_decoder_graph_executor_last_linear_input_width = 0;
    model->cuda_decoder_graph_executor_last_linear_rows = 0;
    model->cuda_decoder_graph_executor_slice_execution_count = 0;
    model->cuda_decoder_graph_executor_last_slice_offset = 0;
    model->cuda_decoder_graph_executor_last_slice_length = 0;
    model->cuda_decoder_graph_executor_rope_execution_count = 0;
    model->cuda_decoder_graph_executor_last_rope_length = 0;
    model->cuda_decoder_graph_executor_last_rope_position = 0;
    model->cuda_decoder_graph_executor_kv_head_prepare_execution_count = 0;
    model->cuda_decoder_graph_executor_last_kv_head_key_length = 0;
    model->cuda_decoder_graph_executor_last_kv_head_value_length = 0;
    model->cuda_decoder_graph_executor_kv_write_execution_count = 0;
    model->cuda_decoder_graph_executor_last_kv_write_key_length = 0;
    model->cuda_decoder_graph_executor_last_kv_write_value_length = 0;
    model->cuda_decoder_graph_executor_last_kv_write_layer = 0;
    model->cuda_decoder_graph_executor_last_kv_write_head = 0;
    model->cuda_decoder_graph_executor_last_kv_write_position = 0;
    model->cuda_decoder_graph_executor_kv_attention_execution_count = 0;
    model->cuda_decoder_graph_executor_last_kv_attention_key_length = 0;
    model->cuda_decoder_graph_executor_last_kv_attention_value_length = 0;
    model->cuda_decoder_graph_executor_last_kv_attention_layer = 0;
    model->cuda_decoder_graph_executor_last_kv_attention_head = 0;
    model->cuda_decoder_graph_executor_last_kv_attention_slot_start = 0;
    model->cuda_decoder_graph_executor_last_kv_attention_slot_count = 0;
    model->cuda_decoder_graph_executor_attempted = false;
    model->cuda_decoder_graph_executor_available = false;
    model->cuda_decoder_graph_executor_token_decode_available = false;
    model->cuda_decoder_graph_executor_sampling_readback_available = false;
    model->cuda_decoder_graph_executor_result_contract_available = false;
    model->cuda_decoder_graph_executor_execution_plan_available = false;
    model->cuda_decoder_graph_executor_embedding_execution_available = false;
    model->cuda_decoder_graph_executor_last_embedding_token_available = false;
    model->cuda_decoder_graph_executor_rms_norm_execution_available = false;
    model->cuda_decoder_graph_executor_linear_execution_available = false;
    model->cuda_decoder_graph_executor_slice_execution_available = false;
    model->cuda_decoder_graph_executor_rope_execution_available = false;
    model->cuda_decoder_graph_executor_kv_head_prepare_execution_available = false;
    model->cuda_decoder_graph_executor_kv_write_execution_available = false;
    model->cuda_decoder_graph_executor_kv_attention_execution_available = false;
}

static void king_inference_cuda_decoder_graph_executor_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_decoder_graph_executor_error";
    }

    model->cuda_decoder_graph_executor_result = result;
    snprintf(
        model->cuda_decoder_graph_executor_error,
        sizeof(model->cuda_decoder_graph_executor_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_decoder_graph_executor_dependencies_ready(
    king_inference_model_object *model
) {
    return model->cuda_embedding_row_available
        && model->cuda_quantized_matvec_q8_0_available
        && model->cuda_device_vector_ops_available
        && model->cuda_device_kv_cache_available
        && model->cuda_rms_norm_available
        && model->cuda_rope_available
        && model->cuda_attention_scores_available
        && model->cuda_attention_softmax_available
        && model->cuda_attention_values_available
        && model->cuda_ffn_swiglu_path_available
        && model->cuda_output_projection_available
        && model->cuda_logits_readback_available;
}

static bool king_inference_cuda_decoder_graph_executor_supported_op(zend_string *op_name)
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
        || zend_string_equals_literal(op_name, "stack")
        || zend_string_equals_literal(op_name, "argmax_token")
        || zend_string_equals_literal(op_name, "sample_token");
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_graph(
    king_inference_model_object *model,
    zval *graph
) {
    zval *ops;
    zval *entry;
    zend_ulong op_count = 0;
    zend_ulong supported_count = 0;

    if (!model->cuda_decoder_graph_executor_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_executor_unavailable"
        );
        return FAILURE;
    }
    if (graph == NULL || Z_TYPE_P(graph) != IS_ARRAY) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_invalid");
        return FAILURE;
    }

    ops = king_inference_array_find(graph, "ops");
    if (ops == NULL) {
        ops = king_inference_array_find(graph, "steps");
    }
    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(ops)) == 0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ops_missing");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zend_string *id = NULL;
        zend_string *op_name = NULL;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                -1,
                "cuda_decoder_graph_op_invalid"
            );
            return FAILURE;
        }
        if (king_inference_graph_required_string(entry, "id", &id) != SUCCESS
            || king_inference_graph_required_string(entry, "op", &op_name) != SUCCESS) {
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                -1,
                "cuda_decoder_graph_op_contract_invalid"
            );
            return FAILURE;
        }
        if (!king_inference_cuda_decoder_graph_executor_supported_op(op_name)) {
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                -1,
                "cuda_decoder_graph_op_unsupported"
            );
            return FAILURE;
        }
        op_count++;
        supported_count++;
    } ZEND_HASH_FOREACH_END();

    model->cuda_decoder_graph_executor_graph_count++;
    model->cuda_decoder_graph_executor_last_op_count = op_count;
    model->cuda_decoder_graph_executor_last_supported_op_count = supported_count;
    model->cuda_decoder_graph_executor_result = 0;
    model->cuda_decoder_graph_executor_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_decoder_graph_executor_copy_string_field(
    zval *target,
    zval *source,
    const char *key
) {
    zval *value = king_inference_array_find(source, key);

    if (value != NULL && Z_TYPE_P(value) == IS_STRING) {
        add_assoc_str(target, key, zend_string_copy(Z_STR_P(value)));
    }
}

static void king_inference_cuda_decoder_graph_executor_copy_long_field(
    zval *target,
    zval *source,
    const char *key
) {
    zval *value = king_inference_array_find(source, key);

    if (value != NULL && Z_TYPE_P(value) == IS_LONG) {
        add_assoc_long(target, key, Z_LVAL_P(value));
    }
}

static void king_inference_cuda_decoder_graph_executor_copy_bool_field(
    zval *target,
    zval *source,
    const char *key
) {
    zval *value = king_inference_array_find(source, key);

    if (value != NULL) {
        add_assoc_bool(target, key, zend_is_true(value));
    }
}

#include "cuda_decoder_graph_executor_device_ops.inc"

static zend_result king_inference_cuda_decoder_graph_executor_prepare_result(
    king_inference_model_object *model,
    zval *graph,
    zval *result
) {
    zval terminal;
    zval *graph_terminal;
    bool has_terminal = false;

    if (king_inference_cuda_decoder_graph_executor_validate_graph(model, graph) != SUCCESS) {
        return FAILURE;
    }

    array_init(result);
    add_assoc_string(result, "type", "gpu_decoder_graph_execution_result");
    add_assoc_string(result, "backend", "king_native_gpu");
    add_assoc_bool(result, "result_contract_ready", true);
    add_assoc_bool(result, "graph_validated", true);
    add_assoc_bool(result, "device_execution_result_ready", false);
    add_assoc_bool(result, "token_result_ready", false);
    add_assoc_bool(result, "decoded_token_ready", false);
    add_assoc_bool(result, "execution_plan_ready", false);
    add_assoc_bool(result, "embedding_device_execution_ready", false);
    add_assoc_bool(result, "first_device_op_executed", false);
    add_assoc_bool(result, "silent_cpu_fallback", false);
    add_assoc_long(result, "op_count", (zend_long) model->cuda_decoder_graph_executor_last_op_count);
    add_assoc_long(
        result,
        "supported_op_count",
        (zend_long) model->cuda_decoder_graph_executor_last_supported_op_count
    );
    king_inference_cuda_decoder_graph_executor_copy_string_field(result, graph, "graph_type");
    king_inference_cuda_decoder_graph_executor_copy_string_field(result, graph, "output");
    king_inference_cuda_decoder_graph_executor_copy_string_field(result, graph, "token_selection");
    king_inference_cuda_decoder_graph_executor_copy_string_field(result, graph, "token_selection_op");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "token_id");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "position");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "block_count");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "attention_head_count");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "attention_head_count_kv");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "attention_key_length");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "attention_value_length");

    graph_terminal = king_inference_array_find(graph, "terminal");
    array_init(&terminal);
    if (graph_terminal != NULL && Z_TYPE_P(graph_terminal) == IS_ARRAY) {
        has_terminal = true;
        king_inference_cuda_decoder_graph_executor_copy_string_field(&terminal, graph_terminal, "hidden_output");
        king_inference_cuda_decoder_graph_executor_copy_string_field(&terminal, graph_terminal, "final_norm");
        king_inference_cuda_decoder_graph_executor_copy_string_field(&terminal, graph_terminal, "logits");
        king_inference_cuda_decoder_graph_executor_copy_string_field(&terminal, graph_terminal, "token_selection");
        king_inference_cuda_decoder_graph_executor_copy_string_field(&terminal, graph_terminal, "token_selection_op");
        king_inference_cuda_decoder_graph_executor_copy_bool_field(&terminal, graph_terminal, "emits_token");
        king_inference_cuda_decoder_graph_executor_copy_bool_field(&terminal, graph_terminal, "emits_logits");
    }
    add_assoc_bool(result, "terminal_present", has_terminal);
    add_assoc_zval(result, "terminal", &terminal);
    if (king_inference_cuda_decoder_graph_executor_prepare_plan(model, graph, result) != SUCCESS) {
        zval_ptr_dtor(result);
        ZVAL_UNDEF(result);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_execute_initial_device_ops(model, graph, result) != SUCCESS) {
        zval_ptr_dtor(result);
        ZVAL_UNDEF(result);
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_result_count++;
    model->cuda_decoder_graph_executor_last_result_op_count =
        model->cuda_decoder_graph_executor_last_op_count;
    return SUCCESS;
}

static void king_inference_cuda_decoder_graph_executor_release(king_inference_model_object *model)
{
    king_inference_cuda_decoder_graph_executor_reset_fields(model);
}

static void king_inference_cuda_decoder_graph_executor_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_decoder_graph_executor_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_decoder_graph_executor_attempted = true;
    if (!king_inference_cuda_decoder_graph_executor_dependencies_ready(model)) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_executor_dependencies_unavailable"
        );
        return;
    }

    model->cuda_decoder_graph_executor_available = true;
    model->cuda_decoder_graph_executor_token_decode_available = true;
    model->cuda_decoder_graph_executor_sampling_readback_available = true;
    model->cuda_decoder_graph_executor_result_contract_available = true;
    model->cuda_decoder_graph_executor_execution_plan_available = true;
    model->cuda_decoder_graph_executor_embedding_execution_available =
        model->cuda_embedding_row_available && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_rms_norm_execution_available =
        model->cuda_rms_norm_available && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_linear_execution_available =
        model->cuda_quantized_matvec_q8_0_available && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_slice_execution_available =
        model->cuda_vector_slice_available && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_rope_execution_available =
        model->cuda_rope_f32_available && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_kv_head_prepare_execution_available =
        model->cuda_decoder_graph_executor_linear_execution_available
        && model->cuda_decoder_graph_executor_slice_execution_available
        && model->cuda_decoder_graph_executor_rope_execution_available;
    model->cuda_decoder_graph_executor_kv_write_execution_available =
        model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
        && model->cuda_device_kv_cache_available
        && model->cuda_device_kv_cache_dtod_available;
    model->cuda_decoder_graph_executor_kv_attention_execution_available =
        model->cuda_decoder_graph_executor_kv_write_execution_available
        && model->cuda_attention_scores_f32_available
        && model->cuda_attention_softmax_f32_available
        && model->cuda_attention_values_f32_available;
    model->cuda_decoder_graph_executor_result = 0;
    model->cuda_decoder_graph_executor_error[0] = '\0';
}

static void king_inference_cuda_decoder_graph_executor_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval executor;
    zval supported_ops;

    array_init(&executor);
    add_assoc_bool(&executor, "attempted", model->cuda_decoder_graph_executor_attempted);
    add_assoc_bool(&executor, "available", model->cuda_decoder_graph_executor_available);
    add_assoc_bool(&executor, "token_decode_available", model->cuda_decoder_graph_executor_token_decode_available);
    add_assoc_bool(
        &executor,
        "sampling_readback_available",
        model->cuda_decoder_graph_executor_sampling_readback_available
    );
    add_assoc_bool(
        &executor,
        "result_contract_available",
        model->cuda_decoder_graph_executor_result_contract_available
    );
    add_assoc_bool(
        &executor,
        "execution_plan_available",
        model->cuda_decoder_graph_executor_execution_plan_available
    );
    add_assoc_bool(
        &executor,
        "embedding_execution_available",
        model->cuda_decoder_graph_executor_embedding_execution_available
    );
    add_assoc_bool(
        &executor,
        "rms_norm_execution_available",
        model->cuda_decoder_graph_executor_rms_norm_execution_available
    );
    add_assoc_bool(
        &executor,
        "linear_execution_available",
        model->cuda_decoder_graph_executor_linear_execution_available
    );
    add_assoc_bool(
        &executor,
        "slice_execution_available",
        model->cuda_decoder_graph_executor_slice_execution_available
    );
    add_assoc_bool(
        &executor,
        "rope_execution_available",
        model->cuda_decoder_graph_executor_rope_execution_available
    );
    add_assoc_bool(
        &executor,
        "kv_head_prepare_execution_available",
        model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
    );
    add_assoc_bool(
        &executor,
        "kv_write_execution_available",
        model->cuda_decoder_graph_executor_kv_write_execution_available
    );
    add_assoc_bool(
        &executor,
        "kv_attention_execution_available",
        model->cuda_decoder_graph_executor_kv_attention_execution_available
    );
    add_assoc_long(&executor, "graphs", (zend_long) model->cuda_decoder_graph_executor_graph_count);
    add_assoc_long(&executor, "last_op_count", (zend_long) model->cuda_decoder_graph_executor_last_op_count);
    add_assoc_long(
        &executor,
        "last_supported_op_count",
        (zend_long) model->cuda_decoder_graph_executor_last_supported_op_count
    );
    add_assoc_long(&executor, "result_envelopes", (zend_long) model->cuda_decoder_graph_executor_result_count);
    add_assoc_long(&executor, "execution_plans", (zend_long) model->cuda_decoder_graph_executor_plan_count);
    add_assoc_long(
        &executor,
        "last_result_op_count",
        (zend_long) model->cuda_decoder_graph_executor_last_result_op_count
    );
    add_assoc_long(
        &executor,
        "last_plan_op_count",
        (zend_long) model->cuda_decoder_graph_executor_last_plan_op_count
    );
    add_assoc_long(
        &executor,
        "last_plan_device_ops",
        (zend_long) model->cuda_decoder_graph_executor_last_plan_device_ops
    );
    add_assoc_long(
        &executor,
        "last_plan_host_sampling_ops",
        (zend_long) model->cuda_decoder_graph_executor_last_plan_host_sampling_ops
    );
    add_assoc_long(
        &executor,
        "embedding_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_embedding_execution_count
    );
    add_assoc_long(
        &executor,
        "last_embedding_width",
        (zend_long) model->cuda_decoder_graph_executor_last_embedding_width
    );
    add_assoc_long(
        &executor,
        "rms_norm_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_rms_norm_execution_count
    );
    add_assoc_long(
        &executor,
        "last_rms_norm_width",
        (zend_long) model->cuda_decoder_graph_executor_last_rms_norm_width
    );
    add_assoc_long(
        &executor,
        "linear_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_linear_execution_count
    );
    add_assoc_long(
        &executor,
        "last_linear_input_width",
        (zend_long) model->cuda_decoder_graph_executor_last_linear_input_width
    );
    add_assoc_long(
        &executor,
        "last_linear_rows",
        (zend_long) model->cuda_decoder_graph_executor_last_linear_rows
    );
    add_assoc_long(
        &executor,
        "slice_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_slice_execution_count
    );
    add_assoc_long(
        &executor,
        "last_slice_offset",
        (zend_long) model->cuda_decoder_graph_executor_last_slice_offset
    );
    add_assoc_long(
        &executor,
        "last_slice_length",
        (zend_long) model->cuda_decoder_graph_executor_last_slice_length
    );
    add_assoc_long(
        &executor,
        "rope_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_rope_execution_count
    );
    add_assoc_long(
        &executor,
        "last_rope_length",
        (zend_long) model->cuda_decoder_graph_executor_last_rope_length
    );
    add_assoc_long(
        &executor,
        "last_rope_position",
        (zend_long) model->cuda_decoder_graph_executor_last_rope_position
    );
    add_assoc_long(
        &executor,
        "kv_head_prepare_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_kv_head_prepare_execution_count
    );
    add_assoc_long(
        &executor,
        "last_kv_head_key_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_head_key_length
    );
    add_assoc_long(
        &executor,
        "last_kv_head_value_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_head_value_length
    );
    add_assoc_long(
        &executor,
        "kv_write_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_kv_write_execution_count
    );
    add_assoc_long(
        &executor,
        "last_kv_write_key_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_write_key_length
    );
    add_assoc_long(
        &executor,
        "last_kv_write_value_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_write_value_length
    );
    add_assoc_long(
        &executor,
        "last_kv_write_layer",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_write_layer
    );
    add_assoc_long(
        &executor,
        "last_kv_write_head",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_write_head
    );
    add_assoc_long(
        &executor,
        "last_kv_write_position",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_write_position
    );
    add_assoc_long(
        &executor,
        "kv_attention_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_kv_attention_execution_count
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_key_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_key_length
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_value_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_value_length
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_layer",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_layer
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_head",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_head
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_slot_start",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_slot_start
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_slot_count",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_slot_count
    );
    if (model->cuda_decoder_graph_executor_last_embedding_token_available
        && model->cuda_decoder_graph_executor_last_embedding_token_id <= (zend_ulong) ZEND_LONG_MAX) {
        add_assoc_long(
            &executor,
            "last_embedding_token_id",
            (zend_long) model->cuda_decoder_graph_executor_last_embedding_token_id
        );
    } else {
        add_assoc_null(&executor, "last_embedding_token_id");
    }
    add_assoc_bool(&executor, "device_execution_result_ready", false);
    add_assoc_long(&executor, "result", model->cuda_decoder_graph_executor_result);
    add_assoc_string(&executor, "error", model->cuda_decoder_graph_executor_error);

    array_init(&supported_ops);
    add_next_index_string(&supported_ops, "embedding");
    add_next_index_string(&supported_ops, "rms_norm");
    add_next_index_string(&supported_ops, "linear");
    add_next_index_string(&supported_ops, "add");
    add_next_index_string(&supported_ops, "scale");
    add_next_index_string(&supported_ops, "mul");
    add_next_index_string(&supported_ops, "silu");
    add_next_index_string(&supported_ops, "slice");
    add_next_index_string(&supported_ops, "rope");
    add_next_index_string(&supported_ops, "kv_write");
    add_next_index_string(&supported_ops, "kv_attention");
    add_next_index_string(&supported_ops, "stack");
    add_next_index_string(&supported_ops, "argmax_token");
    add_next_index_string(&supported_ops, "sample_token");
    add_assoc_zval(&executor, "supported_ops", &supported_ops);

    add_assoc_zval(return_value, "decoder_graph_executor", &executor);
    add_assoc_bool(return_value, "decoder_graph_executor_ready", model->cuda_decoder_graph_executor_available);
    add_assoc_bool(
        return_value,
        "decoder_graph_result_contract_ready",
        model->cuda_decoder_graph_executor_result_contract_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_execution_plan_ready",
        model->cuda_decoder_graph_executor_execution_plan_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_embedding_execution_ready",
        model->cuda_decoder_graph_executor_embedding_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_rms_norm_execution_ready",
        model->cuda_decoder_graph_executor_rms_norm_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_linear_execution_ready",
        model->cuda_decoder_graph_executor_linear_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_slice_execution_ready",
        model->cuda_decoder_graph_executor_slice_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_rope_execution_ready",
        model->cuda_decoder_graph_executor_rope_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_kv_head_prepare_execution_ready",
        model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_kv_write_execution_ready",
        model->cuda_decoder_graph_executor_kv_write_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_kv_attention_execution_ready",
        model->cuda_decoder_graph_executor_kv_attention_execution_available
    );
}

static void king_inference_cuda_decoder_graph_executor_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_executor;

    king_inference_cuda_decoder_graph_executor_add_status(model, return_value);
    if (!model->cuda_decoder_graph_executor_attempted
        || model->cuda_decoder_graph_executor_available
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_executor = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_executor) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_decoder_graph_executor_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_decoder_graph_executor_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_decoder_graph_executor_unavailable");
    }
}
