static zend_result king_inference_king_native_gpu_validate_stream_request(
    king_inference_stream_object *stream,
    const char *function_name,
    zend_long *max_tokens
) {
    zval *graph = king_inference_array_find(&stream->request, "graph");
    zval *graphs = king_inference_array_find(&stream->request, "graphs");
    zval *prompt_text = king_inference_array_find(&stream->request, "native_prompt_text");
    zval *request_graph_options = king_inference_array_find(&stream->request, "graph_options");
    zval *option_graph_options = king_inference_array_find(&stream->options, "graph_options");
    zval *graph_options = king_inference_king_native_graph_options(stream);
    zval *max_tokens_value = king_inference_array_find(&stream->request, "max_tokens");
    zend_long max_native_stream_tokens;

    *max_tokens = 1;

    if (stream->openai_compatible) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() backend 'king_native_gpu' exposes the native event stream contract; "
            "OpenAI-compatible GPU text generation is not ready in this build.",
            function_name
        );
        return FAILURE;
    }
    if (graph != NULL && !king_inference_openai_array_is_object(graph)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native GPU stream graph must be an object array.");
        return FAILURE;
    }
    if (graphs != NULL && !king_inference_openai_array_is_list(graphs)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native GPU stream graphs must be a list array.");
        return FAILURE;
    }
    if (prompt_text != NULL && (Z_TYPE_P(prompt_text) != IS_STRING || Z_STRLEN_P(prompt_text) == 0)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native GPU stream native_prompt_text must be a non-empty string.");
        return FAILURE;
    }
    if (prompt_text != NULL && (graph != NULL || graphs != NULL)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King native GPU stream must use either native_prompt_text or graph/graphs, not both."
        );
        return FAILURE;
    }
    if ((request_graph_options != NULL && !king_inference_openai_array_is_object(request_graph_options))
        || (option_graph_options != NULL && !king_inference_openai_array_is_object(option_graph_options))) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native GPU stream graph_options must be an object array.");
        return FAILURE;
    }
    if (graphs == NULL && graph == NULL && prompt_text == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King native GPU streaming requires native_prompt_text, graph, or graphs."
        );
        return FAILURE;
    }
    if (max_tokens_value != NULL) {
        if (Z_TYPE_P(max_tokens_value) != IS_LONG) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King native GPU stream max_tokens must be an integer.");
            return FAILURE;
        }
        *max_tokens = Z_LVAL_P(max_tokens_value);
    } else if (graphs != NULL) {
        *max_tokens = (zend_long) zend_hash_num_elements(Z_ARRVAL_P(graphs));
    }
    if (*max_tokens <= 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King native GPU stream max_tokens must be positive.");
        return FAILURE;
    }
    if (king_inference_king_native_stream_token_limit(stream, graph_options, &max_native_stream_tokens) != SUCCESS) {
        return FAILURE;
    }
    if (*max_tokens > max_native_stream_tokens) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King native GPU stream max_tokens exceeds configured max_native_stream_tokens."
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_king_native_gpu_emit_graph_results(
    king_inference_model_object *model,
    king_inference_stream_object *stream
) {
    zval *graph = king_inference_array_find(&stream->request, "graph");
    zval *graphs = king_inference_array_find(&stream->request, "graphs");
    zval *entry;
    zval graph_result;
    zend_long validated = 0;

    if (graph != NULL) {
        ZVAL_UNDEF(&graph_result);
        if (king_inference_cuda_decoder_graph_executor_prepare_result(model, graph, &graph_result) != SUCCESS) {
            return FAILURE;
        }
        add_next_index_zval(&stream->native_events, &graph_result);
        validated++;
    }
    if (graphs != NULL) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(graphs), entry) {
            ZVAL_UNDEF(&graph_result);
            if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    -1,
                    "cuda_decoder_graph_request_invalid"
                );
                return FAILURE;
            }
            if (king_inference_cuda_decoder_graph_executor_prepare_result(model, entry, &graph_result) != SUCCESS) {
                return FAILURE;
            }
            add_next_index_zval(&stream->native_events, &graph_result);
            validated++;
        } ZEND_HASH_FOREACH_END();
    }
    if (validated == 0) {
        return SUCCESS;
    }

    return SUCCESS;
}

static void king_inference_king_native_gpu_add_contract_event(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zend_long max_tokens
) {
    zval decoder_blockers;
    zval event;
    zval gpu_runtime;
    zval *reason;

    array_init(&event);
    add_assoc_string(&event, "type", "gpu_decoder_stream_contract");
    add_assoc_string(&event, "backend", "king_native_gpu");
    add_assoc_string(&event, "stream_contract", "king_native_events");
    add_assoc_bool(&event, "native_stream", true);
    add_assoc_bool(&event, "decoder_stream_contract_ready", true);
    add_assoc_bool(&event, "embedding_row_loader_ready", model->cuda_embedding_row_available);
    add_assoc_bool(&event, "device_vector_ops_ready", model->cuda_device_vector_ops_available);
    add_assoc_bool(&event, "device_kv_cache_ready", model->cuda_device_kv_cache_available);
    add_assoc_bool(&event, "decoder_graph_executor_ready", model->cuda_decoder_graph_executor_available);
    add_assoc_bool(
        &event,
        "decoder_graph_result_contract_ready",
        model->cuda_decoder_graph_executor_result_contract_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_execution_plan_ready",
        model->cuda_decoder_graph_executor_execution_plan_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_embedding_execution_ready",
        model->cuda_decoder_graph_executor_embedding_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_rms_norm_execution_ready",
        model->cuda_decoder_graph_executor_rms_norm_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_linear_execution_ready",
        model->cuda_decoder_graph_executor_linear_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_slice_execution_ready",
        model->cuda_decoder_graph_executor_slice_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_rope_execution_ready",
        model->cuda_decoder_graph_executor_rope_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_kv_head_prepare_execution_ready",
        model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_kv_write_execution_ready",
        model->cuda_decoder_graph_executor_kv_write_execution_available
    );
    add_assoc_bool(&event, "decoder_prompt_loop_ready", model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(&event, "decoder_kernel_ready", false);
    add_assoc_bool(&event, "generation_ready", false);
    add_assoc_bool(&event, "plain_text_chat_ready", false);
    add_assoc_bool(&event, "token_generation", false);
    add_assoc_bool(&event, "openai_generation", false);
    add_assoc_bool(&event, "silent_cpu_fallback", false);
    add_assoc_long(&event, "accepted_max_tokens", max_tokens);
    king_inference_gpu_decoder_blockers(model, &decoder_blockers);
    add_assoc_zval(&event, "decoder_blockers", &decoder_blockers);

    king_inference_gpu_runtime_status_from_model(model, &gpu_runtime);
    reason = king_inference_array_find(&gpu_runtime, "reason");
    if (reason != NULL && Z_TYPE_P(reason) == IS_STRING) {
        add_assoc_str(&event, "reason", zend_string_copy(Z_STR_P(reason)));
    }
    add_assoc_zval(&event, "gpu_runtime", &gpu_runtime);
    add_next_index_zval(&stream->native_events, &event);
}

static zend_result king_inference_king_native_gpu_stream_start(
    king_inference_stream_object *stream,
    const char *function_name
) {
    king_inference_model_object *model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    zend_long max_tokens;

    if (king_inference_check_gpu_policy_before_run(stream, function_name) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_king_native_gpu_validate_stream_request(stream, function_name, &max_tokens) != SUCCESS) {
        return FAILURE;
    }

    if (!Z_ISUNDEF(stream->native_events)) {
        zval_ptr_dtor(&stream->native_events);
    }
    array_init(&stream->native_events);
    stream->native_event_index = 0;

    king_inference_king_native_gpu_add_contract_event(model, stream, max_tokens);
    {
        zval *prompt_text = king_inference_array_find(&stream->request, "native_prompt_text");
        if (prompt_text != NULL
            && king_inference_cuda_decoder_prompt_loop_validate_prompt(
                model,
                stream,
                prompt_text,
                max_tokens
            ) != SUCCESS) {
            return FAILURE;
        }
        if (prompt_text == NULL
            && king_inference_king_native_gpu_emit_graph_results(model, stream) != SUCCESS) {
            return FAILURE;
        }
    }
    return SUCCESS;
}

static void king_inference_king_native_gpu_model_info(
    king_inference_model_object *intern,
    zval *return_value
) {
    zval decoder_blockers;

    (void) intern;

    add_assoc_string(return_value, "engine", "king_native_gpu");
    add_assoc_string(return_value, "loader", "king_gguf_structure");
    add_assoc_string(return_value, "stream_contract", "king_native_events");
    add_assoc_bool(return_value, "external_runtime", false);
    add_assoc_bool(return_value, "decoder_stream_contract_ready", true);
    add_assoc_bool(return_value, "embedding_row_loader_ready", intern->cuda_embedding_row_available);
    add_assoc_bool(return_value, "device_vector_ops_ready", intern->cuda_device_vector_ops_available);
    add_assoc_bool(return_value, "device_kv_cache_ready", intern->cuda_device_kv_cache_available);
    add_assoc_bool(return_value, "decoder_graph_executor_ready", intern->cuda_decoder_graph_executor_available);
    add_assoc_bool(
        return_value,
        "decoder_graph_result_contract_ready",
        intern->cuda_decoder_graph_executor_result_contract_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_execution_plan_ready",
        intern->cuda_decoder_graph_executor_execution_plan_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_embedding_execution_ready",
        intern->cuda_decoder_graph_executor_embedding_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_rms_norm_execution_ready",
        intern->cuda_decoder_graph_executor_rms_norm_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_linear_execution_ready",
        intern->cuda_decoder_graph_executor_linear_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_slice_execution_ready",
        intern->cuda_decoder_graph_executor_slice_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_rope_execution_ready",
        intern->cuda_decoder_graph_executor_rope_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_kv_head_prepare_execution_ready",
        intern->cuda_decoder_graph_executor_kv_head_prepare_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_kv_write_execution_ready",
        intern->cuda_decoder_graph_executor_kv_write_execution_available
    );
    add_assoc_bool(return_value, "decoder_prompt_loop_ready", intern->cuda_decoder_prompt_loop_available);
    add_assoc_bool(return_value, "decoder_kernel_ready", false);
    add_assoc_bool(return_value, "generation_ready", false);
    add_assoc_bool(return_value, "plain_text_chat_ready", false);
    king_inference_gpu_decoder_blockers(intern, &decoder_blockers);
    add_assoc_zval(return_value, "decoder_blockers", &decoder_blockers);
    add_assoc_bool(return_value, "silent_cpu_fallback", false);
}
