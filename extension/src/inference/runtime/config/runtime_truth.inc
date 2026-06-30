/*
 * Runtime truth payloads shared by model info, OpenAI model listings and errors.
 */

static void king_inference_model_runtime_timing_truth(
    king_inference_model_object *model,
    zval *return_value
) {
    array_init(return_value);
    add_assoc_bool(return_value, "available", model->runtime_last_timing_available);
    add_assoc_bool(return_value, "first_token_available", model->runtime_last_first_token_available);
    add_assoc_long(return_value, "started_monotonic_ms", model->runtime_last_started_ms);
    if (model->runtime_last_first_token_available) {
        add_assoc_long(return_value, "first_token_monotonic_ms", model->runtime_last_first_token_ms);
        add_assoc_long(return_value, "time_to_first_token_ms", model->runtime_last_time_to_first_token_ms);
    } else {
        add_assoc_null(return_value, "first_token_monotonic_ms");
        add_assoc_null(return_value, "time_to_first_token_ms");
    }
    if (model->runtime_last_timing_available) {
        add_assoc_long(return_value, "finished_monotonic_ms", model->runtime_last_finished_ms);
        add_assoc_long(return_value, "duration_ms", model->runtime_last_duration_ms);
        add_assoc_double(return_value, "tokens_per_second", model->runtime_last_tokens_per_second);
    } else {
        add_assoc_null(return_value, "finished_monotonic_ms");
        add_assoc_null(return_value, "duration_ms");
        add_assoc_null(return_value, "tokens_per_second");
    }
    add_assoc_long(return_value, "generated_tokens", model->runtime_last_generated_tokens);
}

static void king_inference_model_runtime_resident_truth(
    king_inference_model_object *model,
    zval *return_value
) {
    array_init(return_value);
    add_assoc_bool(return_value, "model_object", true);
    add_assoc_bool(return_value, "gguf_metadata", model->gguf.loaded);
    add_assoc_bool(return_value, "native_map", model->native_map_loaded);
    add_assoc_bool(return_value, "tensor_index", Z_TYPE(model->tensor_index) == IS_ARRAY
        && zend_hash_num_elements(Z_ARRVAL(model->tensor_index)) > 0);
    add_assoc_bool(return_value, "tokenizer", model->gguf.tokenizer_tokens_loaded
        && model->gguf.tokenizer_lookup_loaded);
    add_assoc_bool(return_value, "cuda_context", model->cuda_context_available);
    add_assoc_bool(return_value, "cuda_context_owned", model->cuda_context_owned);
    add_assoc_bool(return_value, "cuda_weights", model->cuda_weight_upload_complete
        && model->cuda_weight_uploaded_bytes > 0);
    add_assoc_bool(return_value, "cuda_weight_cache", model->cuda_weight_cache_ready);
    add_assoc_bool(return_value, "cuda_kv_cache", model->cuda_device_kv_cache_allocated);
    add_assoc_long(return_value, "cuda_device_bytes_allocated", (zend_long) model->cuda_device_bytes_allocated);
    add_assoc_long(return_value, "cuda_device_peak_bytes_allocated", (zend_long) model->cuda_device_peak_bytes_allocated);
}

static void king_inference_model_runtime_truth_array(
    king_inference_model_object *model,
    zval *return_value
) {
    king_inference_backend_kind kind;
    zval resident;
    zval timing;
    bool backend_config_valid = true;
    bool gpu_enabled;
    bool gpu_runtime_created = false;
    zval gpu_runtime;
    zval *reason;
    const char *active_device = "unknown";
    const char *fallback_mode = "none";

    ZVAL_UNDEF(&gpu_runtime);
    if (king_inference_backend_kind_from_config(&model->config, &kind, "King runtime truth") != SUCCESS) {
        if (EG(exception)) {
            zend_clear_exception();
        }
        backend_config_valid = false;
        kind = KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    }
    gpu_enabled = backend_config_valid && king_inference_gpu_enabled(&model->config);
    if (gpu_enabled) {
        king_inference_gpu_runtime_status_from_model(model, &gpu_runtime);
        gpu_runtime_created = true;
    }

    if (!backend_config_valid) {
        active_device = "invalid";
        fallback_mode = "invalid_backend";
    } else if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU) {
        active_device = model->cuda_context_available ? "cuda" : "cuda_unavailable";
        fallback_mode = "refuse_cpu_fallback";
    } else if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU) {
        active_device = "cpu";
        fallback_mode = "cpu_profile";
    } else {
        active_device = "local_process";
        fallback_mode = "external_process";
    }

    array_init(return_value);
    add_assoc_long(return_value, "version", 1);
    add_assoc_bool(return_value, "backend_config_valid", backend_config_valid);
    add_assoc_string(
        return_value,
        "backend",
        backend_config_valid ? king_inference_backend_kind_name(kind) : "invalid"
    );
    add_assoc_string(return_value, "active_device", active_device);
    add_assoc_bool(return_value, "model_resident", true);
    add_assoc_bool(return_value, "gpu_required", gpu_enabled || kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU);
    add_assoc_bool(return_value, "silent_cpu_fallback", false);
    add_assoc_string(return_value, "fallback_mode", fallback_mode);
    if (gpu_runtime_created) {
        reason = king_inference_array_find(&gpu_runtime, "reason");
        if (reason != NULL && Z_TYPE_P(reason) == IS_STRING && Z_STRLEN_P(reason) > 0) {
            add_assoc_str(return_value, "gpu_admission_reason", zend_string_copy(Z_STR_P(reason)));
        } else {
            add_assoc_string(return_value, "gpu_admission_reason", "unknown");
        }
    } else {
        add_assoc_string(return_value, "gpu_admission_reason", "not_gpu_model");
    }

    king_inference_model_runtime_resident_truth(model, &resident);
    add_assoc_zval(return_value, "resident_state", &resident);
    king_inference_model_runtime_timing_truth(model, &timing);
    add_assoc_zval(return_value, "measured_token_timing", &timing);

    if (gpu_runtime_created) {
        zval_ptr_dtor(&gpu_runtime);
    }
}

static bool king_inference_model_memory_enabled(king_inference_model_object *model)
{
    return king_inference_bool_from_array(&model->config, "with_memory", false);
}

static void king_inference_model_memory_truth_array(
    king_inference_model_object *model,
    zval *return_value
) {
    bool with_memory = king_inference_model_memory_enabled(model);

    array_init(return_value);
    add_assoc_bool(return_value, "with_memory", with_memory);
    add_assoc_string(return_value, "mode", with_memory ? "stateful_graph_memory" : "stateless");
}

static void king_inference_model_readiness_truth_array(
    king_inference_model_object *model,
    zval *return_value
) {
    king_inference_backend_kind kind;
    bool backend_config_valid = true;
    bool gpu_enabled;
    bool gpu_config_ready = false;
    bool gpu_generation_ready = false;
    bool local_backend;
    bool native_cpu_backend;
    bool native_gpu_backend;
    zval gpu_runtime;

    ZVAL_UNDEF(&gpu_runtime);
    if (king_inference_backend_kind_from_config(&model->config, &kind, "King readiness truth") != SUCCESS) {
        if (EG(exception)) {
            zend_clear_exception();
        }
        backend_config_valid = false;
        kind = KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    }

    gpu_enabled = backend_config_valid && king_inference_gpu_enabled(&model->config);
    if (gpu_enabled) {
        zval *config_ready;
        zval *generation_ready;

        king_inference_gpu_runtime_status_from_model(model, &gpu_runtime);
        config_ready = king_inference_array_find(&gpu_runtime, "config_ready");
        generation_ready = king_inference_array_find(&gpu_runtime, "generation_ready");
        gpu_config_ready = config_ready != NULL && zend_is_true(config_ready);
        gpu_generation_ready = generation_ready != NULL && zend_is_true(generation_ready);
    }

    local_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_LOCAL;
    native_cpu_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    native_gpu_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;

    array_init(return_value);
    add_assoc_bool(return_value, "backend_config_valid", backend_config_valid);
    add_assoc_bool(return_value, "model_resident", true);
    add_assoc_bool(return_value, "metadata_ready", model->gguf.loaded);
    add_assoc_bool(return_value, "native_map_ready", model->native_map_loaded);
    add_assoc_bool(return_value, "tokenizer_ready", model->gguf.tokenizer_tokens_loaded && model->gguf.tokenizer_lookup_loaded);
    add_assoc_bool(return_value, "gpu_required", gpu_enabled || native_gpu_backend);
    add_assoc_bool(return_value, "gpu_config_ready", gpu_config_ready);
    add_assoc_bool(return_value, "gpu_generation_ready", native_gpu_backend && gpu_generation_ready);
    add_assoc_bool(
        return_value,
        "openai_generation_ready",
        backend_config_valid && ((local_backend && !gpu_enabled) || native_cpu_backend || (native_gpu_backend && gpu_generation_ready))
    );
    add_assoc_bool(return_value, "native_graph_streaming_ready", native_cpu_backend);
    add_assoc_bool(return_value, "native_stream_contract_ready", native_cpu_backend || native_gpu_backend);
    add_assoc_bool(return_value, "embeddings_ready", native_cpu_backend);

    if (gpu_enabled) {
        zval_ptr_dtor(&gpu_runtime);
    }
}

static void king_inference_model_add_runtime_surface_fields(
    king_inference_model_object *model,
    zval *target,
    zval *runtime_truth
) {
    zval memory;
    zval readiness;
    zval *backend = king_inference_array_find(runtime_truth, "backend");
    zval *active_device = king_inference_array_find(runtime_truth, "active_device");
    zval *fallback_mode = king_inference_array_find(runtime_truth, "fallback_mode");
    zval *silent_cpu_fallback = king_inference_array_find(runtime_truth, "silent_cpu_fallback");
    zval *gpu_admission_reason = king_inference_array_find(runtime_truth, "gpu_admission_reason");
    bool with_memory = king_inference_model_memory_enabled(model);

    if (backend != NULL && Z_TYPE_P(backend) == IS_STRING) {
        add_assoc_str(target, "configured_backend", zend_string_copy(Z_STR_P(backend)));
        add_assoc_str(target, "active_backend", zend_string_copy(Z_STR_P(backend)));
    } else {
        add_assoc_string(target, "configured_backend", "invalid");
        add_assoc_string(target, "active_backend", "invalid");
    }
    if (active_device != NULL && Z_TYPE_P(active_device) == IS_STRING) {
        add_assoc_str(target, "active_device", zend_string_copy(Z_STR_P(active_device)));
    }
    if (fallback_mode != NULL && Z_TYPE_P(fallback_mode) == IS_STRING) {
        add_assoc_str(target, "fallback_mode", zend_string_copy(Z_STR_P(fallback_mode)));
    } else {
        add_assoc_string(target, "fallback_mode", "unknown");
    }
    add_assoc_bool(
        target,
        "silent_cpu_fallback",
        silent_cpu_fallback != NULL && zend_is_true(silent_cpu_fallback)
    );
    if (gpu_admission_reason != NULL && Z_TYPE_P(gpu_admission_reason) == IS_STRING) {
        add_assoc_str(target, "gpu_admission_reason", zend_string_copy(Z_STR_P(gpu_admission_reason)));
    } else {
        add_assoc_string(target, "gpu_admission_reason", "unknown");
    }
    add_assoc_bool(target, "with_memory", with_memory);
    add_assoc_string(target, "memory_mode", with_memory ? "stateful_graph_memory" : "stateless");

    king_inference_model_memory_truth_array(model, &memory);
    add_assoc_zval(target, "memory", &memory);
    king_inference_model_readiness_truth_array(model, &readiness);
    add_assoc_zval(target, "readiness", &readiness);
}
