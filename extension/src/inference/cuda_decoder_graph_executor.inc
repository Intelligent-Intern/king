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
    model->cuda_decoder_graph_executor_attempted = false;
    model->cuda_decoder_graph_executor_available = false;
    model->cuda_decoder_graph_executor_token_decode_available = false;
    model->cuda_decoder_graph_executor_sampling_readback_available = false;
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
    add_assoc_long(&executor, "graphs", (zend_long) model->cuda_decoder_graph_executor_graph_count);
    add_assoc_long(&executor, "last_op_count", (zend_long) model->cuda_decoder_graph_executor_last_op_count);
    add_assoc_long(
        &executor,
        "last_supported_op_count",
        (zend_long) model->cuda_decoder_graph_executor_last_supported_op_count
    );
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
