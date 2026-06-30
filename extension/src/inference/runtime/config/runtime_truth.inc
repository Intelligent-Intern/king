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
    zval tokenizer_status;

    array_init(return_value);
    add_assoc_bool(return_value, "model_object", true);
    add_assoc_bool(return_value, "gguf_metadata", model->gguf.loaded);
    add_assoc_bool(return_value, "native_map", model->native_map_loaded);
    add_assoc_bool(return_value, "tensor_index", Z_TYPE(model->tensor_index) == IS_ARRAY
        && zend_hash_num_elements(Z_ARRVAL(model->tensor_index)) > 0);
    add_assoc_bool(return_value, "tokenizer", model->gguf.tokenizer_tokens_loaded
        && model->gguf.tokenizer_lookup_loaded);
    king_inference_tokenizer_status_array(model, &tokenizer_status);
    add_assoc_zval(return_value, "tokenizer_status", &tokenizer_status);
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
    zval *generation_ready;
    const char *active_device = "unknown";
    const char *fallback_mode = "none";
    const char *fallback_error = "none";
    bool cpu_fallback_allowed = false;

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
        fallback_error = "king.validation.invalid_backend_config";
    } else if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU) {
        active_device = model->cuda_context_available ? "cuda" : "cuda_unavailable";
        fallback_mode = "refuse_cpu_fallback";
        if (!model->cuda_context_available) {
            fallback_error = "king.policy.gpu_unavailable";
        } else if (gpu_runtime_created) {
            generation_ready = king_inference_array_find(&gpu_runtime, "generation_ready");
            if (generation_ready == NULL || !zend_is_true(generation_ready)) {
                fallback_error = "king.policy.gpu_generation_not_ready";
            }
        }
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
    add_assoc_bool(return_value, "cpu_fallback_allowed", cpu_fallback_allowed);
    add_assoc_bool(return_value, "silent_cpu_fallback", false);
    add_assoc_string(return_value, "fallback_mode", fallback_mode);
    add_assoc_string(return_value, "fallback_error", fallback_error);
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

static void king_inference_model_decoder_truth_array(
    king_inference_model_object *model,
    zval *return_value
) {
    king_inference_backend_kind kind;
    bool backend_config_valid = true;
    bool native_cpu_backend;
    bool native_gpu_backend;
    bool local_backend;
    bool gpu_enabled;
    bool gpu_prompt_ready = false;
    bool gpu_synthetic_graph_ready = false;
    bool silent_cpu_fallback = false;
    const char *backend_name;
    const char *active_device;
    const char *prompt_graph_path;
    const char *synthetic_graph_path;
    const char *sampler_path;
    const char *kv_cache_path;
    const char *fallback_error = "none";
    const char *fallback_policy = "no_cpu_fallback";

    if (king_inference_backend_kind_from_config(&model->config, &kind, "King decoder truth") != SUCCESS) {
        if (EG(exception)) {
            zend_clear_exception();
        }
        backend_config_valid = false;
        kind = KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    }

    native_cpu_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    native_gpu_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
    local_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_LOCAL;
    gpu_enabled = backend_config_valid && king_inference_gpu_enabled(&model->config);
    backend_name = backend_config_valid ? king_inference_backend_kind_name(kind) : "invalid";

    if (!backend_config_valid) {
        active_device = "invalid";
        prompt_graph_path = "unavailable";
        synthetic_graph_path = "unavailable";
        sampler_path = "unavailable";
        kv_cache_path = "unavailable";
        fallback_error = "king.validation.invalid_backend_config";
        fallback_policy = "invalid_backend_refused";
    } else if (native_gpu_backend) {
        gpu_prompt_ready = model->cuda_decoder_prompt_loop_available;
        gpu_synthetic_graph_ready = model->cuda_decoder_graph_executor_available
            && model->cuda_decoder_graph_executor_result_contract_available;
        active_device = model->cuda_context_available ? "cuda" : "cuda_unavailable";
        prompt_graph_path = gpu_prompt_ready
            ? "king_native_gpu_prompt_to_logits_decode"
            : "unavailable";
        synthetic_graph_path = gpu_synthetic_graph_ready
            ? "king_native_gpu_synthetic_token_vector_graph"
            : "unavailable";
        sampler_path = gpu_prompt_ready
            ? "king_native_gpu_bounded_top_k_sampler"
            : "unavailable";
        kv_cache_path = model->cuda_device_kv_cache_allocated
            ? "king_native_gpu_device_kv_cache"
            : "unallocated";
        fallback_policy = "refuse_cpu_fallback";
        if (!model->cuda_context_available) {
            fallback_error = "king.policy.gpu_unavailable";
        } else if (!gpu_prompt_ready) {
            fallback_error = "king.policy.gpu_generation_not_ready";
        }
    } else if (native_cpu_backend) {
        active_device = "cpu";
        prompt_graph_path = "king_native_cpu_prompt_to_logits_decode";
        synthetic_graph_path = "king_native_cpu_synthetic_token_vector_graph";
        sampler_path = "king_native_cpu_sampler";
        kv_cache_path = (!Z_ISUNDEF(model->paged_kv_cache_plan) && Z_TYPE(model->paged_kv_cache_plan) == IS_ARRAY)
            ? "king_native_cpu_paged_kv_cache_plan"
            : "king_native_cpu_runtime_state";
        fallback_policy = "cpu_profile";
    } else if (local_backend) {
        active_device = gpu_enabled ? "local_process_gpu_requested" : "local_process";
        prompt_graph_path = "external_local_runner";
        synthetic_graph_path = "not_supported";
        sampler_path = "external_local_runner";
        kv_cache_path = "external_local_runner";
        fallback_policy = gpu_enabled ? "external_runner_gpu_policy" : "external_process";
    } else {
        active_device = "unknown";
        prompt_graph_path = "unavailable";
        synthetic_graph_path = "unavailable";
        sampler_path = "unavailable";
        kv_cache_path = "unavailable";
        fallback_error = "king.runtime.unknown_backend";
        fallback_policy = "unknown";
    }

    array_init(return_value);
    add_assoc_long(return_value, "schema_version", 1);
    add_assoc_string(return_value, "backend", backend_name);
    add_assoc_bool(return_value, "backend_config_valid", backend_config_valid);
    add_assoc_string(return_value, "active_device", active_device);
    add_assoc_string(return_value, "prompt_graph_path", prompt_graph_path);
    add_assoc_string(return_value, "synthetic_graph_path", synthetic_graph_path);
    add_assoc_string(return_value, "sampler_path", sampler_path);
    add_assoc_string(return_value, "kv_cache_path", kv_cache_path);
    add_assoc_bool(return_value, "prompt_to_logits_inference", native_cpu_backend || gpu_prompt_ready);
    add_assoc_bool(return_value, "synthetic_token_vector_graph", native_cpu_backend || gpu_synthetic_graph_ready);
    add_assoc_bool(return_value, "sampler_available", native_cpu_backend || gpu_prompt_ready || local_backend);
    add_assoc_bool(return_value, "kv_cache_available", native_cpu_backend || model->cuda_device_kv_cache_allocated || local_backend);
    add_assoc_bool(return_value, "silent_cpu_fallback", silent_cpu_fallback);
    add_assoc_bool(return_value, "cpu_prompt_fallback_allowed", false);
    add_assoc_string(return_value, "fallback_policy", fallback_policy);
    add_assoc_string(return_value, "fallback_error", fallback_error);
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

static bool king_inference_native_readiness_bool(zval *source, const char *key)
{
    zval *value;

    if (source == NULL || Z_TYPE_P(source) != IS_ARRAY) {
        return false;
    }

    value = king_inference_array_find(source, key);
    return value != NULL && zend_is_true(value);
}

static void king_inference_model_native_engine_readiness_array(
    king_inference_model_object *model,
    zval *return_value
) {
    king_inference_backend_kind kind;
    bool backend_config_valid = true;
    bool gpu_enabled;
    bool local_backend;
    bool native_cpu_backend;
    bool native_gpu_backend;
    bool gpu_config_ready = false;
    bool gpu_generation_ready = false;
    bool native_stream_contract_admitted = false;
    bool native_graph_payload_admitted = false;
    bool plain_text_generation_admitted = false;
    const char *backend_name;
    const char *state = "not_native_backend";
    const char *proof_path = "non_native_backend";
    zval blockers;
    zval refusal_reasons;
    zval refusal_error_codes;
    zval refusal_error_categories;
    zval gpu_runtime;

    ZVAL_UNDEF(&gpu_runtime);
    if (king_inference_backend_kind_from_config(&model->config, &kind, "King native engine readiness") != SUCCESS) {
        if (EG(exception)) {
            zend_clear_exception();
        }
        backend_config_valid = false;
        kind = KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    }

    gpu_enabled = backend_config_valid && king_inference_gpu_enabled(&model->config);
    local_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_LOCAL;
    native_cpu_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    native_gpu_backend = backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
    backend_name = backend_config_valid ? king_inference_backend_kind_name(kind) : "invalid";

    array_init(&blockers);
    array_init(&refusal_reasons);

    if (!backend_config_valid) {
        state = "invalid_backend_config";
        proof_path = "backend_config_validation";
        add_next_index_string(&refusal_reasons, "backend_config_invalid");
    } else if (local_backend) {
        state = "not_native_backend";
        proof_path = "local_process_backend";
        add_next_index_string(&refusal_reasons, "not_native_backend");
    } else if (native_cpu_backend) {
        state = "admitted";
        proof_path = "native_cpu_model_resident_tokenizer_contract";
        native_stream_contract_admitted = true;
        native_graph_payload_admitted = true;
        plain_text_generation_admitted = true;
    } else if (native_gpu_backend) {
        zval *config_ready;
        zval *generation_ready;
        zval *runtime_refusals;
        zval *entry;

        proof_path = "gpu_runtime_status_decoder_blockers_and_policy";
        king_inference_gpu_runtime_status_from_model(model, &gpu_runtime);
        config_ready = king_inference_array_find(&gpu_runtime, "config_ready");
        generation_ready = king_inference_array_find(&gpu_runtime, "generation_ready");
        gpu_config_ready = config_ready != NULL && zend_is_true(config_ready);
        gpu_generation_ready = generation_ready != NULL && zend_is_true(generation_ready);
        native_stream_contract_admitted = true;
        native_graph_payload_admitted = model->cuda_decoder_graph_executor_available
            && model->cuda_decoder_graph_executor_result_contract_available;
        plain_text_generation_admitted = gpu_generation_ready;
        state = plain_text_generation_admitted ? "admitted" : "refused";

        king_inference_gpu_decoder_blockers(model, &blockers);
        runtime_refusals = king_inference_array_find(&gpu_runtime, "refusal_reasons");
        if (runtime_refusals != NULL && Z_TYPE_P(runtime_refusals) == IS_ARRAY) {
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(runtime_refusals), entry) {
                if (Z_TYPE_P(entry) == IS_STRING && Z_STRLEN_P(entry) > 0) {
                    add_next_index_str(&refusal_reasons, zend_string_copy(Z_STR_P(entry)));
                }
            } ZEND_HASH_FOREACH_END();
        }
        if (!plain_text_generation_admitted) {
            add_next_index_string(&refusal_reasons, "native_plain_text_generation_not_admitted");
        }
    }

    king_inference_error_taxonomy_codes_from_reason_list(&refusal_reasons, &refusal_error_codes);
    king_inference_error_taxonomy_categories_from_code_list(
        &refusal_error_codes,
        &refusal_error_categories
    );

    array_init(return_value);
    add_assoc_long(return_value, "schema_version", 1);
    add_assoc_string(return_value, "scope", "native_engine_readiness");
    add_assoc_bool(return_value, "router_independent", true);
    add_assoc_string(return_value, "backend", backend_name);
    add_assoc_bool(return_value, "backend_config_valid", backend_config_valid);
    add_assoc_bool(return_value, "native_backend", native_cpu_backend || native_gpu_backend);
    add_assoc_bool(return_value, "gpu_required", gpu_enabled || native_gpu_backend);
    add_assoc_bool(return_value, "gpu_config_ready", native_gpu_backend && gpu_config_ready);
    add_assoc_bool(return_value, "native_stream_contract_admitted", native_stream_contract_admitted);
    add_assoc_bool(return_value, "native_graph_payload_admitted", native_graph_payload_admitted);
    add_assoc_bool(return_value, "plain_text_generation_admitted", plain_text_generation_admitted);
    add_assoc_bool(return_value, "openai_prompt_formatting_applied", false);
    add_assoc_string(return_value, "prompt_formatting_owner", "openai_router");
    add_assoc_string(return_value, "tool_fields_owner", "openai_router");
    add_assoc_string(return_value, "error_response_owner", "openai_router");
    add_assoc_string(return_value, "state", state);
    add_assoc_string(return_value, "proof_path", proof_path);
    add_assoc_zval(return_value, "decoder_blockers", &blockers);
    add_assoc_zval(return_value, "refusal_reasons", &refusal_reasons);
    add_assoc_zval(return_value, "refusal_error_codes", &refusal_error_codes);
    add_assoc_zval(return_value, "refusal_error_categories", &refusal_error_categories);
    if (native_gpu_backend && !Z_ISUNDEF(gpu_runtime)) {
        add_assoc_zval(return_value, "gpu_runtime", &gpu_runtime);
    }
}

static void king_inference_model_add_runtime_surface_fields(
    king_inference_model_object *model,
    zval *target,
    zval *runtime_truth
) {
    zval memory;
    zval readiness;
    zval decoder_truth;
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
    king_inference_model_decoder_truth_array(model, &decoder_truth);
    add_assoc_zval(target, "decoder_truth", &decoder_truth);
}
