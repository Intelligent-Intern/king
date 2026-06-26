/*
 * CUDA prompt decoder loop contract for native King GPU inference.
 */

static void king_inference_cuda_decoder_prompt_loop_reset_fields(king_inference_model_object *model)
{
    model->cuda_decoder_prompt_loop_result = 0;
    model->cuda_decoder_prompt_loop_error[0] = '\0';
    model->cuda_decoder_prompt_loop_count = 0;
    model->cuda_decoder_prompt_loop_last_prompt_tokens = 0;
    model->cuda_decoder_prompt_loop_last_validated_graphs = 0;
    model->cuda_decoder_prompt_loop_last_result_envelopes = 0;
    model->cuda_decoder_prompt_loop_last_max_tokens = 0;
    model->cuda_decoder_prompt_loop_attempted = false;
    model->cuda_decoder_prompt_loop_available = false;
    model->cuda_decoder_prompt_loop_tokenizer_ready = false;
    model->cuda_decoder_prompt_loop_graph_validation_ready = false;
    model->cuda_decoder_prompt_loop_result_contract_ready = false;
}

static void king_inference_cuda_decoder_prompt_loop_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_decoder_prompt_loop_error";
    }

    model->cuda_decoder_prompt_loop_result = result;
    snprintf(
        model->cuda_decoder_prompt_loop_error,
        sizeof(model->cuda_decoder_prompt_loop_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_decoder_prompt_loop_tokenizer_ready(king_inference_model_object *model)
{
    return model->gguf.tokenizer_tokens_loaded
        && model->gguf.tokenizer_lookup_loaded
        && Z_TYPE(model->tokenizer_tokens) == IS_ARRAY
        && Z_TYPE(model->tokenizer_lookup) == IS_ARRAY
        && zend_hash_num_elements(Z_ARRVAL(model->tokenizer_tokens)) > 0
        && zend_hash_num_elements(Z_ARRVAL(model->tokenizer_lookup)) > 0;
}

static void king_inference_cuda_decoder_prompt_loop_release(king_inference_model_object *model)
{
    king_inference_cuda_decoder_prompt_loop_reset_fields(model);
}

static void king_inference_cuda_decoder_prompt_loop_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_decoder_prompt_loop_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_decoder_prompt_loop_attempted = true;
    model->cuda_decoder_prompt_loop_tokenizer_ready =
        king_inference_cuda_decoder_prompt_loop_tokenizer_ready(model);
    model->cuda_decoder_prompt_loop_graph_validation_ready =
        model->cuda_decoder_graph_executor_available;
    model->cuda_decoder_prompt_loop_result_contract_ready =
        model->cuda_decoder_graph_executor_result_contract_available;

    if (!model->cuda_decoder_prompt_loop_tokenizer_ready) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_loop_tokenizer_unavailable"
        );
        return;
    }
    if (!model->cuda_decoder_prompt_loop_graph_validation_ready) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_loop_graph_executor_unavailable"
        );
        return;
    }
    if (!model->cuda_decoder_prompt_loop_result_contract_ready) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_loop_result_contract_unavailable"
        );
        return;
    }

    model->cuda_decoder_prompt_loop_available = true;
    model->cuda_decoder_prompt_loop_result = 0;
    model->cuda_decoder_prompt_loop_error[0] = '\0';
}

static zend_result king_inference_cuda_decoder_prompt_loop_validate_prompt(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *prompt_text,
    zend_long max_tokens
) {
    zval encoded;
    zval tokenized_prompt;
    zval decode_options;
    zval event;
    zend_ulong token_count = 0;
    zend_ulong position;
    size_t validated_graphs = 0;
    size_t result_envelopes = 0;

    ZVAL_UNDEF(&encoded);
    ZVAL_UNDEF(&tokenized_prompt);
    ZVAL_UNDEF(&decode_options);

    if (!model->cuda_decoder_prompt_loop_available) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_loop_unavailable"
        );
        return FAILURE;
    }
    if (prompt_text == NULL || Z_TYPE_P(prompt_text) != IS_STRING || Z_STRLEN_P(prompt_text) == 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_prompt_invalid");
        return FAILURE;
    }
    if (max_tokens <= 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_max_tokens_invalid");
        return FAILURE;
    }

    if (king_inference_tokenizer_encode(model, Z_STR_P(prompt_text), &encoded) != SUCCESS
        || king_inference_king_native_prompt_tokens(model, &encoded, &tokenized_prompt, &token_count) != SUCCESS) {
        if (!Z_ISUNDEF(encoded)) {
            zval_ptr_dtor(&encoded);
        }
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_tokenize_failed");
        return FAILURE;
    }
    if (token_count == 0) {
        zval_ptr_dtor(&tokenized_prompt);
        zval_ptr_dtor(&encoded);
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_tokenize_empty");
        return FAILURE;
    }

    king_inference_king_native_prompt_decode_options(stream, &decode_options);
    for (position = 0; position < token_count; position++) {
        zval graph;
        zval graph_result;

        ZVAL_UNDEF(&graph);
        ZVAL_UNDEF(&graph_result);
        add_assoc_bool(&decode_options, "emit_token", position + 1 == token_count);
        if (king_inference_token_decode_graph_array(model, &tokenized_prompt, position, &decode_options, &graph) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_prepare_result(model, &graph, &graph_result) != SUCCESS) {
            if (!Z_ISUNDEF(graph_result)) {
                zval_ptr_dtor(&graph_result);
            }
            if (!Z_ISUNDEF(graph)) {
                zval_ptr_dtor(&graph);
            }
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                -1,
                "cuda_decoder_prompt_loop_graph_validation_failed"
            );
            return FAILURE;
        }
        validated_graphs++;
        result_envelopes++;
        zval_ptr_dtor(&graph_result);
        zval_ptr_dtor(&graph);
    }

    model->cuda_decoder_prompt_loop_count++;
    model->cuda_decoder_prompt_loop_last_prompt_tokens = token_count;
    model->cuda_decoder_prompt_loop_last_validated_graphs = validated_graphs;
    model->cuda_decoder_prompt_loop_last_result_envelopes = result_envelopes;
    model->cuda_decoder_prompt_loop_last_max_tokens = max_tokens;
    model->cuda_decoder_prompt_loop_result = 0;
    model->cuda_decoder_prompt_loop_error[0] = '\0';

    array_init(&event);
    add_assoc_string(&event, "type", "gpu_prompt_decoder_loop_contract");
    add_assoc_string(&event, "backend", "king_native_gpu");
    add_assoc_bool(&event, "prompt_loop_ready", true);
    add_assoc_bool(&event, "prompt_graphs_validated", true);
    add_assoc_bool(&event, "graph_result_contract_ready", true);
    add_assoc_bool(&event, "graph_execution_plan_ready", true);
    add_assoc_bool(
        &event,
        "embedding_device_execution_ready",
        model->cuda_decoder_graph_executor_embedding_execution_available
    );
    add_assoc_bool(
        &event,
        "rms_norm_device_execution_ready",
        model->cuda_decoder_graph_executor_rms_norm_execution_available
    );
    add_assoc_bool(
        &event,
        "linear_device_execution_ready",
        model->cuda_decoder_graph_executor_linear_execution_available
    );
    add_assoc_bool(&event, "device_execution_result_ready", false);
    add_assoc_bool(&event, "generation_ready", false);
    add_assoc_long(&event, "prompt_tokens", (zend_long) token_count);
    add_assoc_long(&event, "validated_graphs", (zend_long) validated_graphs);
    add_assoc_long(&event, "result_envelopes", (zend_long) result_envelopes);
    add_assoc_long(&event, "accepted_max_tokens", max_tokens);
    add_next_index_zval(&stream->native_events, &event);

    zval_ptr_dtor(&decode_options);
    zval_ptr_dtor(&tokenized_prompt);
    zval_ptr_dtor(&encoded);
    return SUCCESS;
}

static void king_inference_cuda_decoder_prompt_loop_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval loop;

    array_init(&loop);
    add_assoc_bool(&loop, "attempted", model->cuda_decoder_prompt_loop_attempted);
    add_assoc_bool(&loop, "available", model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(&loop, "tokenizer_ready", model->cuda_decoder_prompt_loop_tokenizer_ready);
    add_assoc_bool(
        &loop,
        "graph_validation_ready",
        model->cuda_decoder_prompt_loop_graph_validation_ready
    );
    add_assoc_bool(
        &loop,
        "result_contract_ready",
        model->cuda_decoder_prompt_loop_result_contract_ready
    );
    add_assoc_bool(
        &loop,
        "execution_plan_ready",
        model->cuda_decoder_graph_executor_execution_plan_available
    );
    add_assoc_bool(
        &loop,
        "embedding_device_execution_ready",
        model->cuda_decoder_graph_executor_embedding_execution_available
    );
    add_assoc_bool(
        &loop,
        "rms_norm_device_execution_ready",
        model->cuda_decoder_graph_executor_rms_norm_execution_available
    );
    add_assoc_bool(
        &loop,
        "linear_device_execution_ready",
        model->cuda_decoder_graph_executor_linear_execution_available
    );
    add_assoc_bool(&loop, "device_execution_result_ready", false);
    add_assoc_long(&loop, "runs", (zend_long) model->cuda_decoder_prompt_loop_count);
    add_assoc_long(&loop, "last_prompt_tokens", (zend_long) model->cuda_decoder_prompt_loop_last_prompt_tokens);
    add_assoc_long(
        &loop,
        "last_validated_graphs",
        (zend_long) model->cuda_decoder_prompt_loop_last_validated_graphs
    );
    add_assoc_long(
        &loop,
        "last_result_envelopes",
        (zend_long) model->cuda_decoder_prompt_loop_last_result_envelopes
    );
    add_assoc_long(&loop, "last_max_tokens", model->cuda_decoder_prompt_loop_last_max_tokens);
    add_assoc_long(&loop, "result", model->cuda_decoder_prompt_loop_result);
    add_assoc_string(&loop, "error", model->cuda_decoder_prompt_loop_error);
    add_assoc_zval(return_value, "decoder_prompt_loop", &loop);
    add_assoc_bool(return_value, "decoder_prompt_loop_ready", model->cuda_decoder_prompt_loop_available);
}

static void king_inference_cuda_decoder_prompt_loop_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_loop;

    king_inference_cuda_decoder_prompt_loop_add_status(model, return_value);
    if (!model->cuda_decoder_prompt_loop_attempted
        || model->cuda_decoder_prompt_loop_available
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_loop = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_loop) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_decoder_prompt_loop_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_decoder_prompt_loop_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_decoder_prompt_loop_unavailable");
    }
}
