typedef enum _king_inference_backend_kind {
    KING_INFERENCE_BACKEND_LOCAL = 1,
    KING_INFERENCE_BACKEND_KING_NATIVE_CPU = 2,
    KING_INFERENCE_BACKEND_KING_NATIVE_GPU = 3
} king_inference_backend_kind;

static const char *king_inference_backend_kind_name(king_inference_backend_kind kind)
{
    switch (kind) {
        case KING_INFERENCE_BACKEND_LOCAL:
            return "local";
        case KING_INFERENCE_BACKEND_KING_NATIVE_CPU:
            return "king_native_cpu";
        case KING_INFERENCE_BACKEND_KING_NATIVE_GPU:
            return "king_native_gpu";
    }

    return "unknown";
}

static zend_result king_inference_backend_kind_from_string(
    zend_string *name,
    king_inference_backend_kind *kind
) {
    if (zend_string_equals_literal(name, "local")) {
        *kind = KING_INFERENCE_BACKEND_LOCAL;
        return SUCCESS;
    }

    if (zend_string_equals_literal(name, "king_native_cpu")) {
        *kind = KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
        return SUCCESS;
    }

    if (zend_string_equals_literal(name, "king_native_gpu")) {
        *kind = KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
        return SUCCESS;
    }

    return FAILURE;
}

static zval *king_inference_backend_config_value(zval *config)
{
    zval *backend = king_inference_array_find(config, "backend");

    if (backend == NULL) {
        return NULL;
    }

    if (Z_TYPE_P(backend) == IS_STRING) {
        return backend;
    }

    if (Z_TYPE_P(backend) == IS_ARRAY) {
        zval *name = king_inference_array_find(backend, "name");
        if (name != NULL) {
            return name;
        }
        return king_inference_array_find(backend, "type");
    }

    return backend;
}

static bool king_inference_backend_config_implies_local_runner(zval *config)
{
    zval *backend = king_inference_array_find(config, "backend");

    return backend != NULL
        && Z_TYPE_P(backend) == IS_ARRAY
        && (king_inference_array_find(backend, "runner") != NULL
            || king_inference_array_find(backend, "runner_path") != NULL);
}

static zend_result king_inference_backend_kind_from_config(
    zval *config,
    king_inference_backend_kind *kind,
    const char *function_name
) {
    zval *backend_name = king_inference_backend_config_value(config);

    *kind = KING_INFERENCE_BACKEND_KING_NATIVE_CPU;

    if (backend_name == NULL) {
        if (king_inference_backend_config_implies_local_runner(config)) {
            *kind = KING_INFERENCE_BACKEND_LOCAL;
        }
        return SUCCESS;
    }

    if (Z_TYPE_P(backend_name) != IS_STRING || Z_STRLEN_P(backend_name) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() backend name must be a non-empty string when configured.",
            function_name
        );
        return FAILURE;
    }

    if (king_inference_backend_kind_from_string(Z_STR_P(backend_name), kind) != SUCCESS) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() does not support inference backend '%s'.",
            function_name,
            Z_STRVAL_P(backend_name)
        );
        return FAILURE;
    }

    return SUCCESS;
}

static bool king_inference_backend_kind_is_available(king_inference_backend_kind kind)
{
    return kind == KING_INFERENCE_BACKEND_LOCAL
        || kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
        || kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
}

static bool king_inference_backend_kind_allows_model_registration(king_inference_backend_kind kind)
{
    return kind == KING_INFERENCE_BACKEND_LOCAL
        || kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
        || kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
}

static void king_inference_backend_capabilities_array(king_inference_backend_kind kind, zval *return_value)
{
    bool native_backend = kind != KING_INFERENCE_BACKEND_LOCAL;
    bool gpu_backend = kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
    bool native_cpu_backend = kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    bool native_stream_backend = kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
        || kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
    bool local_backend = kind == KING_INFERENCE_BACKEND_LOCAL;

    array_init(return_value);
    add_assoc_bool(return_value, "implemented", king_inference_backend_kind_is_available(kind));
    add_assoc_bool(return_value, "local", true);
    add_assoc_bool(return_value, "streaming", true);
    add_assoc_bool(return_value, "openai_chat_completions_stream", local_backend);
    add_assoc_bool(return_value, "cancellable", true);
    add_assoc_bool(return_value, "model_registration", king_inference_backend_kind_allows_model_registration(kind));
    add_assoc_bool(return_value, "model_metadata", true);
    add_assoc_bool(return_value, "gguf_metadata", true);
    add_assoc_bool(return_value, "gguf_architecture_classification", true);
    add_assoc_bool(return_value, "process_runner", local_backend);
    add_assoc_bool(return_value, "native_runtime", native_backend);
    add_assoc_bool(return_value, "native_model_loader", true);
    add_assoc_bool(return_value, "native_tensor_directory", true);
    add_assoc_bool(return_value, "native_tensor_views", true);
    add_assoc_bool(return_value, "native_model_mapping", native_backend);
    add_assoc_bool(return_value, "native_tokenization", true);
    add_assoc_bool(return_value, "native_token_decode", true);
    add_assoc_bool(return_value, "paged_kv_cache", true);
    add_assoc_bool(return_value, "native_stream_contract", native_stream_backend);
    add_assoc_bool(return_value, "native_graph_streaming", native_cpu_backend);
    add_assoc_bool(return_value, "native_token_selection", native_cpu_backend);
    add_assoc_bool(return_value, "token_generation", !gpu_backend);
    add_assoc_bool(return_value, "openai_generation", local_backend || native_cpu_backend);
    add_assoc_bool(return_value, "embeddings", native_cpu_backend);
    add_assoc_bool(return_value, "gpu", gpu_backend);
    add_assoc_bool(return_value, "gpu_backend", gpu_backend);
    add_assoc_bool(return_value, "gpu_runtime_status", gpu_backend);
    add_assoc_bool(return_value, "gpu_cuda_driver_probe", gpu_backend);
    add_assoc_bool(return_value, "gpu_cuda_context", gpu_backend);
    add_assoc_bool(return_value, "gpu_cuda_context_owned", gpu_backend);
    add_assoc_bool(return_value, "gpu_device_memory_allocator", gpu_backend);
    add_assoc_bool(return_value, "gpu_host_to_device_weight_upload", gpu_backend);
    add_assoc_bool(return_value, "gpu_uploaded_weight_cache", gpu_backend);
    add_assoc_bool(return_value, "gpu_quantized_matvec_kernel", gpu_backend);
    add_assoc_bool(return_value, "gpu_rms_norm_kernel", gpu_backend);
    add_assoc_bool(return_value, "gpu_rope_kernel", gpu_backend);
    add_assoc_bool(return_value, "gpu_attention_scores_kernel", gpu_backend);
    add_assoc_bool(return_value, "gpu_attention_softmax_kernel", gpu_backend);
    add_assoc_bool(return_value, "gpu_attention_value_aggregation_kernel", gpu_backend);
    add_assoc_bool(return_value, "gpu_ffn_swiglu_path", gpu_backend);
    add_assoc_bool(return_value, "gpu_output_projection_path", gpu_backend);
    add_assoc_bool(return_value, "gpu_minimized_logits_readback", gpu_backend);
    add_assoc_bool(return_value, "gpu_embedding_row_loader", gpu_backend);
    add_assoc_bool(return_value, "gpu_device_vector_ops", gpu_backend);
    add_assoc_bool(return_value, "gpu_device_kv_cache", gpu_backend);
    add_assoc_bool(return_value, "gpu_decoder_graph_executor", gpu_backend);
    add_assoc_bool(return_value, "gpu_decoder_graph_result_envelope", gpu_backend);
    add_assoc_bool(return_value, "gpu_decoder_graph_execution_plan", gpu_backend);
    add_assoc_bool(return_value, "gpu_decoder_graph_embedding_execution", gpu_backend);
    add_assoc_bool(return_value, "gpu_decoder_graph_rms_norm_execution", gpu_backend);
    add_assoc_bool(return_value, "gpu_decoder_graph_linear_execution", gpu_backend);
    add_assoc_bool(return_value, "gpu_prompt_decoder_loop", gpu_backend);
    add_assoc_bool(return_value, "gpu_plain_text_chat_generation", false);
    add_assoc_bool(return_value, "gpu_vram_admission", gpu_backend);
    add_assoc_bool(return_value, "gpu_kv_cache_vram_estimate", gpu_backend);
    add_assoc_bool(return_value, "gpu_thermal_policy", gpu_backend);
    add_assoc_bool(return_value, "gpu_thermal_preflight", gpu_backend);
    add_assoc_bool(return_value, "gpu_thermal_stream_abort", gpu_backend);
    add_assoc_bool(return_value, "gpu_decoder_stream_contract", gpu_backend);
    add_assoc_bool(return_value, "gpu_decoder_kernel", false);
    add_assoc_bool(return_value, "gpu_generation", false);
    add_assoc_bool(return_value, "silent_cpu_fallback", false);
    add_assoc_string(return_value, "model_format", "gguf");
}
