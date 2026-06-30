/*
 * OpenAI model-list runtime surface: capability, admission, refusal,
 * and experimental boundaries for client decisions.
 */

static void king_inference_openai_runtime_surface_refusal_reasons(
    zval *return_value,
    bool backend_config_valid,
    bool openai_generation_capable,
    bool openai_generation_ready,
    bool gpu_generation_capable,
    bool gpu_generation_ready,
    zval *gpu_runtime
) {
    zval *gpu_reasons;
    zval *gpu_reason;
    zval *entry;
    bool added_reason = false;

    array_init(return_value);

    if (!backend_config_valid) {
        add_next_index_string(return_value, "backend_config_invalid");
        added_reason = true;
    }

    if (gpu_runtime != NULL && Z_TYPE_P(gpu_runtime) == IS_ARRAY) {
        gpu_reasons = king_inference_array_find(gpu_runtime, "refusal_reasons");
        if (gpu_reasons != NULL && Z_TYPE_P(gpu_reasons) == IS_ARRAY) {
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(gpu_reasons), entry) {
                if (Z_TYPE_P(entry) == IS_STRING && Z_STRLEN_P(entry) > 0) {
                    add_next_index_str(return_value, zend_string_copy(Z_STR_P(entry)));
                    added_reason = true;
                }
            } ZEND_HASH_FOREACH_END();
        }
        gpu_reason = king_inference_array_find(gpu_runtime, "reason");
        if (gpu_reason != NULL && Z_TYPE_P(gpu_reason) == IS_STRING && Z_STRLEN_P(gpu_reason) > 0) {
            add_next_index_str(return_value, zend_string_copy(Z_STR_P(gpu_reason)));
            added_reason = true;
        }
    }

    if (gpu_generation_capable && !gpu_generation_ready) {
        add_next_index_string(return_value, "gpu_generation_not_admitted");
        added_reason = true;
    }

    if (openai_generation_capable && !openai_generation_ready && !added_reason) {
        add_next_index_string(return_value, "openai_generation_not_admitted");
    }
}

static void king_inference_openai_runtime_surface_array(
    zval *return_value,
    king_inference_model_object *model,
    king_inference_backend_kind kind,
    bool backend_config_valid,
    bool gpu_enabled,
    bool gpu_generation_ready,
    bool openai_generation_ready,
    bool native_graph_streaming_ready,
    bool native_stream_contract_ready,
    bool embeddings_ready,
    zval *gpu_runtime
) {
    bool local_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_LOCAL;
    bool native_cpu_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    bool native_gpu_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
    bool openai_generation_capable = backend_config_valid
        && ((local_backend && !gpu_enabled) || native_cpu_backend || native_gpu_backend);
    bool native_stream_contract_capable = backend_config_valid
        && (native_cpu_backend || native_gpu_backend);
    bool native_graph_streaming_capable = backend_config_valid && native_cpu_backend;
    bool embeddings_capable = backend_config_valid && native_cpu_backend;
    bool gpu_generation_capable = backend_config_valid && native_gpu_backend;
    bool batch_prefill_experimental_enabled = native_gpu_backend
        && king_inference_cuda_decoder_prompt_loop_batch_prefill_experimental_enabled(model);
    bool batch_prefill_admitted = native_gpu_backend
        && king_inference_cuda_decoder_prompt_loop_batch_prefill_admitted(model);
    bool batch_prefill_ready = native_gpu_backend
        && king_inference_cuda_decoder_prompt_loop_batch_prefill_ready(model);
    zval implemented;
    zval admitted;
    zval refused;
    zval experimental;
    zval refusal_reasons;
    zval refusal_error_codes;
    zval refusal_error_categories;

    array_init(return_value);
    add_assoc_long(return_value, "schema_version", 1);
    add_assoc_string(return_value, "scope", "runtime_admission_surface");
    add_assoc_string(
        return_value,
        "backend",
        backend_config_valid ? king_inference_backend_kind_name(kind) : "invalid"
    );
    add_assoc_bool(return_value, "backend_config_valid", backend_config_valid);
    add_assoc_bool(return_value, "gpu_required", gpu_enabled || native_gpu_backend);
    add_assoc_bool(return_value, "silent_cpu_fallback", false);

    array_init(&implemented);
    add_assoc_bool(&implemented, "openai_generation", openai_generation_capable);
    add_assoc_bool(&implemented, "openai_chat_completions", openai_generation_capable);
    add_assoc_bool(&implemented, "openai_chat_completions_stream", openai_generation_capable);
    add_assoc_bool(&implemented, "openai_responses", openai_generation_capable);
    add_assoc_bool(&implemented, "openai_completions", openai_generation_capable);
    add_assoc_bool(&implemented, "native_stream_contract", native_stream_contract_capable);
    add_assoc_bool(&implemented, "native_graph_streaming", native_graph_streaming_capable);
    add_assoc_bool(&implemented, "embeddings", embeddings_capable);
    add_assoc_bool(&implemented, "gpu_generation", gpu_generation_capable);
    add_assoc_bool(&implemented, "batch_prefill", native_gpu_backend);
    add_assoc_zval(return_value, "implemented_capabilities", &implemented);

    array_init(&admitted);
    add_assoc_bool(&admitted, "openai_generation", openai_generation_ready);
    add_assoc_bool(&admitted, "openai_chat_completions", openai_generation_ready);
    add_assoc_bool(&admitted, "openai_chat_completions_stream", openai_generation_ready);
    add_assoc_bool(&admitted, "openai_responses", openai_generation_ready);
    add_assoc_bool(&admitted, "openai_completions", openai_generation_ready);
    add_assoc_bool(&admitted, "native_stream_contract", native_stream_contract_ready);
    add_assoc_bool(&admitted, "native_graph_streaming", native_graph_streaming_ready);
    add_assoc_bool(&admitted, "embeddings", embeddings_ready);
    add_assoc_bool(&admitted, "gpu_generation", native_gpu_backend && gpu_generation_ready);
    add_assoc_bool(&admitted, "batch_prefill", batch_prefill_admitted);
    add_assoc_zval(return_value, "admitted_runtime", &admitted);

    array_init(&refused);
    add_assoc_bool(&refused, "openai_generation", openai_generation_capable && !openai_generation_ready);
    add_assoc_bool(&refused, "openai_chat_completions", openai_generation_capable && !openai_generation_ready);
    add_assoc_bool(&refused, "openai_chat_completions_stream", openai_generation_capable && !openai_generation_ready);
    add_assoc_bool(&refused, "native_stream_contract", native_stream_contract_capable && !native_stream_contract_ready);
    add_assoc_bool(&refused, "native_graph_streaming", native_graph_streaming_capable && !native_graph_streaming_ready);
    add_assoc_bool(&refused, "embeddings", embeddings_capable && !embeddings_ready);
    add_assoc_bool(&refused, "gpu_generation", gpu_generation_capable && !gpu_generation_ready);
    add_assoc_bool(
        &refused,
        "batch_prefill",
        native_gpu_backend && !batch_prefill_admitted
    );
    add_assoc_zval(return_value, "refused_runtime", &refused);

    array_init(&experimental);
    add_assoc_bool(&experimental, "gpu_generation", native_gpu_backend);
    add_assoc_bool(&experimental, "native_graph_streaming", native_cpu_backend);
    add_assoc_bool(&experimental, "synthetic_token_vector_graph", native_stream_contract_capable);
    add_assoc_bool(&experimental, "batch_prefill_compiled", native_gpu_backend);
    add_assoc_bool(&experimental, "batch_prefill_ready", batch_prefill_ready);
    add_assoc_bool(&experimental, "batch_prefill_config_enabled", batch_prefill_experimental_enabled);
    add_assoc_bool(&experimental, "batch_prefill_admitted", batch_prefill_admitted);
    add_assoc_bool(&experimental, "batch_prefill_request_control_allowed", false);
    add_assoc_string(
        &experimental,
        "batch_prefill_status",
        native_gpu_backend
            ? king_inference_cuda_decoder_prompt_loop_batch_prefill_status(model)
            : "non_gpu_backend"
    );
    add_assoc_zval(return_value, "experimental_features", &experimental);

    king_inference_openai_runtime_surface_refusal_reasons(
        &refusal_reasons,
        backend_config_valid,
        openai_generation_capable,
        openai_generation_ready,
        gpu_generation_capable,
        gpu_generation_ready,
        gpu_runtime
    );
    king_inference_error_taxonomy_codes_from_reason_list(&refusal_reasons, &refusal_error_codes);
    king_inference_error_taxonomy_categories_from_code_list(
        &refusal_error_codes,
        &refusal_error_categories
    );
    add_assoc_zval(return_value, "refusal_reasons", &refusal_reasons);
    add_assoc_zval(return_value, "refusal_error_codes", &refusal_error_codes);
    add_assoc_zval(return_value, "refusal_error_categories", &refusal_error_categories);
}
