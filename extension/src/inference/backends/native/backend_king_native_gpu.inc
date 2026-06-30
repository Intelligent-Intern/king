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
        if (graph != NULL || graphs != NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() OpenAI-compatible King native GPU generation accepts native_prompt_text only; "
                "use the native stream contract for graph payloads.",
                function_name
            );
            return FAILURE;
        }
        if (prompt_text == NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() OpenAI-compatible King native GPU generation requires native_prompt_text.",
                function_name
            );
            return FAILURE;
        }
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

static bool king_inference_king_native_gpu_graph_token_id_from_input(
    zval *graph,
    zend_string *input_id,
    zend_ulong *token_id
) {
    zval *inputs = king_inference_array_find(graph, "inputs");
    zval *input;
    zval *token;
    double token_value;

    if (inputs == NULL || Z_TYPE_P(inputs) != IS_ARRAY) {
        return false;
    }

    input = zend_hash_find(Z_ARRVAL_P(inputs), input_id);
    if (input == NULL || Z_TYPE_P(input) != IS_ARRAY) {
        return false;
    }

    token = zend_hash_index_find(Z_ARRVAL_P(input), 0);
    if (token == NULL) {
        return false;
    }
    if (Z_TYPE_P(token) == IS_LONG) {
        if (Z_LVAL_P(token) < 0) {
            return false;
        }
        *token_id = (zend_ulong) Z_LVAL_P(token);
        return true;
    }
    if (Z_TYPE_P(token) != IS_DOUBLE || Z_DVAL_P(token) < 0.0) {
        return false;
    }

    token_value = Z_DVAL_P(token);
    if (token_value > (double) ZEND_ULONG_MAX) {
        return false;
    }

    *token_id = (zend_ulong) token_value;
    return true;
}

static bool king_inference_king_native_gpu_graph_scale_is_identity(zval *op)
{
    zval *factor = king_inference_array_find(op, "factor");
    zval *scale = king_inference_array_find(op, "scale");
    double value = 1.0;

    if (factor != NULL) {
        if (Z_TYPE_P(factor) == IS_LONG) {
            value = (double) Z_LVAL_P(factor);
        } else if (Z_TYPE_P(factor) == IS_DOUBLE) {
            value = Z_DVAL_P(factor);
        } else {
            return false;
        }
        if (value != 1.0) {
            return false;
        }
    }

    if (scale != NULL) {
        if (Z_TYPE_P(scale) == IS_LONG) {
            value = (double) Z_LVAL_P(scale);
        } else if (Z_TYPE_P(scale) == IS_DOUBLE) {
            value = Z_DVAL_P(scale);
        } else {
            return false;
        }
        if (value != 1.0) {
            return false;
        }
    }

    return true;
}

static bool king_inference_king_native_gpu_graph_direct_token_id(
    zval *graph,
    zend_ulong *token_id
) {
    zval *output = king_inference_array_find(graph, "output");
    zval *ops;
    zval *entry;

    if (output == NULL || Z_TYPE_P(output) != IS_STRING) {
        return false;
    }
    if (king_inference_king_native_gpu_graph_token_id_from_input(graph, Z_STR_P(output), token_id)) {
        return true;
    }

    ops = king_inference_array_find(graph, "ops");
    if (ops == NULL) {
        ops = king_inference_array_find(graph, "steps");
    }
    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY) {
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *id;
        zval *op;
        zval *input;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        id = king_inference_array_find(entry, "id");
        op = king_inference_array_find(entry, "op");
        if (id == NULL
            || Z_TYPE_P(id) != IS_STRING
            || !zend_string_equals(Z_STR_P(id), Z_STR_P(output))
            || op == NULL
            || Z_TYPE_P(op) != IS_STRING
            || !zend_string_equals_literal(Z_STR_P(op), "scale")
            || !king_inference_king_native_gpu_graph_scale_is_identity(entry)) {
            continue;
        }
        input = king_inference_array_find(entry, "input");
        if (input != NULL
            && Z_TYPE_P(input) == IS_STRING
            && king_inference_king_native_gpu_graph_token_id_from_input(graph, Z_STR_P(input), token_id)) {
            return true;
        }
    } ZEND_HASH_FOREACH_END();

    return false;
}

static zend_result king_inference_king_native_gpu_prepare_direct_token_event(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph,
    zval *graph_result,
    zend_string **token_text_out
) {
    zend_ulong token_id;
    zend_string *token_text;

    *token_text_out = NULL;

    if (!king_inference_king_native_gpu_graph_direct_token_id(graph, &token_id)) {
        return SUCCESS;
    }

    token_text = king_inference_tokenizer_decode_token(model, token_id);
    if (token_text == NULL) {
        return FAILURE;
    }

    add_assoc_bool(graph_result, "token_result_ready", true);
    add_assoc_bool(graph_result, "decoded_token_ready", true);
    add_assoc_bool(graph_result, "direct_token_graph", true);
    add_assoc_bool(graph_result, "synthetic_token_vector_graph", true);
    add_assoc_bool(graph_result, "prompt_to_logits_inference", false);
    add_assoc_string(graph_result, "result_source", "synthetic_token_vector_graph");
    add_assoc_string(graph_result, "token_selection_contract", "direct_token_id");
    if (token_id <= (zend_ulong) ZEND_LONG_MAX) {
        add_assoc_long(graph_result, "selected_token_id", (zend_long) token_id);
    } else {
        add_assoc_double(graph_result, "selected_token_id", (double) token_id);
    }
    add_assoc_double(graph_result, "selected_token_probability", 1.0);
    add_assoc_double(graph_result, "selected_token_logit", 0.0);
    add_assoc_double(graph_result, "selected_token_rank", 0.0);
    add_assoc_bool(graph_result, "selected_token_probability_synthetic", true);
    add_assoc_bool(graph_result, "selected_token_logit_synthetic", true);
    add_assoc_bool(graph_result, "selected_token_rank_synthetic", true);

    stream->native_decoder_token_count++;
    stream->native_decoder_last_token_id = token_id;
    stream->native_decoder_last_token_available = true;
    stream->native_decoder_last_probability = 1.0;
    stream->native_decoder_last_logit = 0.0;
    stream->native_decoder_last_rank = 0.0;
    stream->native_decoder_last_score_available = true;

    *token_text_out = token_text;
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
        zend_string *token_text = NULL;

        ZVAL_UNDEF(&graph_result);
        if (king_inference_cuda_decoder_graph_executor_prepare_result(model, graph, &graph_result) != SUCCESS) {
            return FAILURE;
        }
        if (king_inference_king_native_gpu_prepare_direct_token_event(model, stream, graph, &graph_result, &token_text) != SUCCESS) {
            zval_ptr_dtor(&graph_result);
            return FAILURE;
        }
        add_next_index_zval(&stream->native_events, &graph_result);
        if (token_text != NULL) {
            add_next_index_str(&stream->native_events, token_text);
        }
        validated++;
    }
    if (graphs != NULL) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(graphs), entry) {
            zend_string *token_text = NULL;

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
            if (king_inference_king_native_gpu_prepare_direct_token_event(model, stream, entry, &graph_result, &token_text) != SUCCESS) {
                zval_ptr_dtor(&graph_result);
                return FAILURE;
            }
            add_next_index_zval(&stream->native_events, &graph_result);
            if (token_text != NULL) {
                add_next_index_str(&stream->native_events, token_text);
            }
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
    add_assoc_bool(
        &event,
        "decoder_graph_kv_attention_execution_ready",
        model->cuda_decoder_graph_executor_kv_attention_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_attention_stack_slot_execution_ready",
        model->cuda_decoder_graph_executor_attention_stack_slot_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_attention_heads_execution_ready",
        model->cuda_decoder_graph_executor_attention_heads_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_attention_stack_execution_ready",
        model->cuda_decoder_graph_executor_attention_stack_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_attention_output_projection_execution_ready",
        model->cuda_decoder_graph_executor_attention_output_projection_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_attention_residual_execution_ready",
        model->cuda_decoder_graph_executor_attention_residual_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_ffn_norm_execution_ready",
        model->cuda_decoder_graph_executor_ffn_norm_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_ffn_gate_up_projection_execution_ready",
        model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_ffn_swiglu_execution_ready",
        model->cuda_decoder_graph_executor_ffn_swiglu_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_ffn_down_projection_execution_ready",
        model->cuda_decoder_graph_executor_ffn_down_projection_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_ffn_output_residual_execution_ready",
        model->cuda_decoder_graph_executor_ffn_output_residual_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_final_norm_execution_ready",
        model->cuda_decoder_graph_executor_final_norm_execution_available
    );
    add_assoc_bool(
        &event,
        "decoder_graph_logits_projection_execution_ready",
        model->cuda_decoder_graph_executor_logits_projection_execution_available
    );
    add_assoc_bool(&event, "decoder_prompt_loop_ready", model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(&event, "decoder_kernel_ready", model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(&event, "generation_ready", model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(&event, "plain_text_chat_ready", model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(&event, "token_generation", model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(&event, "openai_generation", model->cuda_decoder_prompt_loop_available);
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
    if (king_inference_gpu_runtime_require_config_ready(model, function_name) != SUCCESS) {
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

    if (king_inference_king_native_stream_diagnostics_enabled(stream)) {
        king_inference_king_native_gpu_add_contract_event(model, stream, max_tokens);
    }
    {
        zval *prompt_text = king_inference_array_find(&stream->request, "native_prompt_text");
        if (prompt_text != NULL
            && king_inference_cuda_decoder_prompt_stream_start(
                model,
                stream,
                max_tokens
            ) != SUCCESS) {
            king_set_error(
                model->cuda_decoder_prompt_loop_error[0] != '\0'
                    ? model->cuda_decoder_prompt_loop_error
                    : "King native GPU prompt generation failed."
            );
            return FAILURE;
        }
        if (prompt_text == NULL
            && king_inference_king_native_gpu_emit_graph_results(model, stream) != SUCCESS) {
            king_set_error(
                model->cuda_decoder_graph_executor_error[0] != '\0'
                    ? model->cuda_decoder_graph_executor_error
                    : "King native GPU graph execution failed."
            );
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
    add_assoc_bool(
        return_value,
        "decoder_graph_kv_attention_execution_ready",
        intern->cuda_decoder_graph_executor_kv_attention_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_attention_stack_slot_execution_ready",
        intern->cuda_decoder_graph_executor_attention_stack_slot_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_attention_heads_execution_ready",
        intern->cuda_decoder_graph_executor_attention_heads_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_attention_stack_execution_ready",
        intern->cuda_decoder_graph_executor_attention_stack_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_attention_output_projection_execution_ready",
        intern->cuda_decoder_graph_executor_attention_output_projection_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_attention_residual_execution_ready",
        intern->cuda_decoder_graph_executor_attention_residual_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_ffn_norm_execution_ready",
        intern->cuda_decoder_graph_executor_ffn_norm_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_ffn_gate_up_projection_execution_ready",
        intern->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_ffn_swiglu_execution_ready",
        intern->cuda_decoder_graph_executor_ffn_swiglu_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_ffn_down_projection_execution_ready",
        intern->cuda_decoder_graph_executor_ffn_down_projection_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_ffn_output_residual_execution_ready",
        intern->cuda_decoder_graph_executor_ffn_output_residual_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_final_norm_execution_ready",
        intern->cuda_decoder_graph_executor_final_norm_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_logits_projection_execution_ready",
        intern->cuda_decoder_graph_executor_logits_projection_execution_available
    );
    add_assoc_bool(return_value, "decoder_prompt_loop_ready", intern->cuda_decoder_prompt_loop_available);
    add_assoc_bool(return_value, "decoder_prompt_batch_prefill_compiled", true);
    add_assoc_bool(return_value, "decoder_prompt_batch_prefill_available", intern->cuda_decoder_prompt_loop_batch_prefill_available);
    add_assoc_bool(return_value, "decoder_prompt_batch_prefill_ready", king_inference_cuda_decoder_prompt_loop_batch_prefill_ready(intern));
    add_assoc_bool(return_value, "decoder_prompt_batch_prefill_experimental_enabled", king_inference_cuda_decoder_prompt_loop_batch_prefill_experimental_enabled(intern));
    add_assoc_bool(return_value, "decoder_prompt_batch_prefill_admitted", king_inference_cuda_decoder_prompt_loop_batch_prefill_admitted(intern));
    add_assoc_string(return_value, "decoder_prompt_batch_prefill_status", king_inference_cuda_decoder_prompt_loop_batch_prefill_status(intern));
    add_assoc_string(
        return_value,
        "decoder_prompt_batch_prefill_admission_reason",
        king_inference_cuda_decoder_prompt_loop_batch_prefill_admission_reason(intern)
    );
    add_assoc_bool(return_value, "decoder_prompt_batch_prefill_last_used", intern->cuda_decoder_prompt_loop_last_used_batch_prefill);
    add_assoc_long(
        return_value,
        "decoder_prompt_batch_prefill_last_tokens",
        (zend_long) intern->cuda_decoder_prompt_loop_last_prefill_tokens
    );
    add_assoc_long(
        return_value,
        "decoder_prompt_batch_prefill_last_template_reuses",
        (zend_long) intern->cuda_decoder_prompt_loop_last_prefill_template_reuses
    );
    add_assoc_bool(return_value, "decoder_kernel_ready", intern->cuda_decoder_prompt_loop_available);
    add_assoc_bool(return_value, "generation_ready", intern->cuda_decoder_prompt_loop_available);
    add_assoc_bool(return_value, "plain_text_chat_ready", intern->cuda_decoder_prompt_loop_available);
    add_assoc_bool(return_value, "token_generation", intern->cuda_decoder_prompt_loop_available);
    add_assoc_bool(return_value, "openai_generation", intern->cuda_decoder_prompt_loop_available);
    king_inference_gpu_decoder_blockers(intern, &decoder_blockers);
    add_assoc_zval(return_value, "decoder_blockers", &decoder_blockers);
    add_assoc_bool(return_value, "silent_cpu_fallback", false);
}
