/*
 * GPU runtime VRAM admission after model residency.
 */

static void king_inference_gpu_runtime_add_kv_cache_estimate(
    zval *return_value,
    king_inference_model_object *model
) {
    zval *plan;
    zval *max_sequence_bytes;
    zval *max_context_tokens;
    zval *layers;
    zval *page_tokens;
    zval *element_bytes;
    zval *artifact_bytes_value;
    zval *free_vram_bytes_value;
    zval *free_vram_after_reserve_bytes_value;
    zval *free_vram_floor_met_value;
    zval *previous_model_vram_admitted;
    zval *system_ram_offload_allowed_value;
    zval *system_ram_offload_max_bytes_value;
    zval *system_ram_offload_min_free_bytes_value;
    bool estimate_available = false;
    bool compared_to_free = false;
    bool fits_free = false;
    bool fits_without_reserve = false;
    bool free_vram_floor_met = false;
    bool overflow = false;
    bool previous_model_vram_admitted_bool = false;
    bool system_ram_offload_allowed = false;
    bool system_ram_offload_limit_configured = false;
    bool system_ram_offload_limit_met = false;
    bool system_ram_available = false;
    bool system_ram_floor_met = false;
    bool system_ram_offload_budget_admitted = false;
    bool system_ram_offload_required = false;
    bool system_ram_offload_supported = false;
    bool system_ram_offload_admitted = false;
    bool weights_resident = false;
    bool kv_cache_resident = false;
    const char *runtime_reason = NULL;
    const char *runtime_required_source = "artifact_plus_kv_cache";
    zend_long artifact_bytes = 0;
    zend_long estimated_bytes = 0;
    zend_long free_vram_bytes = 0;
    zend_long effective_free_vram_bytes = 0;
    zend_long runtime_required_bytes = 0;
    zend_long system_ram_available_bytes = 0;
    zend_long system_ram_offload_required_bytes = 0;
    zend_long system_ram_offload_max_bytes = 0;
    zend_long system_ram_offload_min_free_bytes = 0;

    if (!Z_ISUNDEF(model->paged_kv_cache_plan) && Z_TYPE(model->paged_kv_cache_plan) == IS_ARRAY) {
        plan = &model->paged_kv_cache_plan;
        max_sequence_bytes = zend_hash_str_find(
            Z_ARRVAL_P(plan),
            "max_sequence_bytes",
            sizeof("max_sequence_bytes") - 1
        );
        max_context_tokens = zend_hash_str_find(
            Z_ARRVAL_P(plan),
            "max_context_tokens",
            sizeof("max_context_tokens") - 1
        );
        layers = zend_hash_str_find(Z_ARRVAL_P(plan), "layers", sizeof("layers") - 1);
        page_tokens = zend_hash_str_find(Z_ARRVAL_P(plan), "page_tokens", sizeof("page_tokens") - 1);
        element_bytes = zend_hash_str_find(Z_ARRVAL_P(plan), "element_bytes", sizeof("element_bytes") - 1);
    } else {
        plan = NULL;
        max_sequence_bytes = NULL;
        max_context_tokens = NULL;
        layers = NULL;
        page_tokens = NULL;
        element_bytes = NULL;
    }

    if (max_sequence_bytes != NULL && Z_TYPE_P(max_sequence_bytes) == IS_LONG && Z_LVAL_P(max_sequence_bytes) > 0) {
        estimate_available = true;
        estimated_bytes = Z_LVAL_P(max_sequence_bytes);
    }

    artifact_bytes_value = zend_hash_str_find(
        Z_ARRVAL_P(return_value),
        "artifact_bytes",
        sizeof("artifact_bytes") - 1
    );
    free_vram_bytes_value = zend_hash_str_find(
        Z_ARRVAL_P(return_value),
        "free_vram_bytes",
        sizeof("free_vram_bytes") - 1
    );
    free_vram_after_reserve_bytes_value = zend_hash_str_find(
        Z_ARRVAL_P(return_value),
        "free_vram_after_reserve_bytes",
        sizeof("free_vram_after_reserve_bytes") - 1
    );
    free_vram_floor_met_value = zend_hash_str_find(
        Z_ARRVAL_P(return_value),
        "free_vram_floor_met",
        sizeof("free_vram_floor_met") - 1
    );
    previous_model_vram_admitted = zend_hash_str_find(
        Z_ARRVAL_P(return_value),
        "model_vram_admitted",
        sizeof("model_vram_admitted") - 1
    );
    system_ram_offload_allowed_value = zend_hash_str_find(
        Z_ARRVAL_P(return_value),
        "system_ram_offload_allowed",
        sizeof("system_ram_offload_allowed") - 1
    );
    system_ram_offload_max_bytes_value = zend_hash_str_find(
        Z_ARRVAL_P(return_value),
        "system_ram_offload_max_bytes",
        sizeof("system_ram_offload_max_bytes") - 1
    );
    system_ram_offload_min_free_bytes_value = zend_hash_str_find(
        Z_ARRVAL_P(return_value),
        "system_ram_offload_min_free_bytes",
        sizeof("system_ram_offload_min_free_bytes") - 1
    );
    if (artifact_bytes_value != NULL && Z_TYPE_P(artifact_bytes_value) == IS_LONG && Z_LVAL_P(artifact_bytes_value) > 0) {
        artifact_bytes = Z_LVAL_P(artifact_bytes_value);
    }
    if (free_vram_bytes_value != NULL && Z_TYPE_P(free_vram_bytes_value) == IS_LONG && Z_LVAL_P(free_vram_bytes_value) > 0) {
        free_vram_bytes = Z_LVAL_P(free_vram_bytes_value);
    }
    if (free_vram_after_reserve_bytes_value != NULL
        && Z_TYPE_P(free_vram_after_reserve_bytes_value) == IS_LONG
        && Z_LVAL_P(free_vram_after_reserve_bytes_value) > 0) {
        effective_free_vram_bytes = Z_LVAL_P(free_vram_after_reserve_bytes_value);
    } else {
        effective_free_vram_bytes = free_vram_bytes;
    }
    previous_model_vram_admitted_bool = previous_model_vram_admitted != NULL
        && zend_is_true(previous_model_vram_admitted);
    system_ram_offload_allowed = system_ram_offload_allowed_value != NULL
        && zend_is_true(system_ram_offload_allowed_value);
    if (system_ram_offload_max_bytes_value != NULL
        && Z_TYPE_P(system_ram_offload_max_bytes_value) == IS_LONG
        && Z_LVAL_P(system_ram_offload_max_bytes_value) > 0) {
        system_ram_offload_max_bytes = Z_LVAL_P(system_ram_offload_max_bytes_value);
    }
    if (system_ram_offload_min_free_bytes_value != NULL
        && Z_TYPE_P(system_ram_offload_min_free_bytes_value) == IS_LONG
        && Z_LVAL_P(system_ram_offload_min_free_bytes_value) > 0) {
        system_ram_offload_min_free_bytes = Z_LVAL_P(system_ram_offload_min_free_bytes_value);
    }
    free_vram_floor_met = free_vram_floor_met_value != NULL && zend_is_true(free_vram_floor_met_value);
    weights_resident = model->cuda_weight_upload_result == 0
        && model->cuda_weight_uploaded_bytes > 0
        && model->cuda_device_bytes_allocated > 0;
    kv_cache_resident = model->cuda_device_kv_cache_allocated
        && model->cuda_device_kv_cache_bytes > 0;
    if (weights_resident) {
        if (kv_cache_resident) {
            runtime_required_bytes = 0;
            runtime_required_source = "weights_and_kv_cache_already_loaded";
        } else {
            runtime_required_bytes = estimate_available ? estimated_bytes : 0;
            runtime_required_source = estimate_available ? "kv_cache_after_weight_upload" : "weights_already_loaded";
        }
    } else if (estimate_available && estimated_bytes > ZEND_LONG_MAX - artifact_bytes) {
        overflow = true;
        runtime_required_bytes = ZEND_LONG_MAX;
    } else {
        runtime_required_bytes = artifact_bytes + (estimate_available ? estimated_bytes : 0);
    }
    compared_to_free = (runtime_required_bytes > 0 || weights_resident) && effective_free_vram_bytes > 0;
    fits_free = free_vram_floor_met
        && compared_to_free
        && !overflow
        && (weights_resident && !estimate_available
            ? effective_free_vram_bytes > 0
            : runtime_required_bytes <= effective_free_vram_bytes);
    fits_without_reserve = free_vram_bytes > 0 && !overflow && runtime_required_bytes <= free_vram_bytes;
    system_ram_offload_required = compared_to_free && !fits_free;
    system_ram_available = king_inference_gpu_system_ram_available_bytes(&system_ram_available_bytes);
    if (system_ram_offload_required && runtime_required_bytes > effective_free_vram_bytes) {
        system_ram_offload_required_bytes = runtime_required_bytes - effective_free_vram_bytes;
    }
    system_ram_offload_limit_configured = system_ram_offload_max_bytes > 0;
    system_ram_offload_limit_met = system_ram_offload_required_bytes > 0
        && system_ram_offload_limit_configured
        && system_ram_offload_required_bytes <= system_ram_offload_max_bytes;
    system_ram_floor_met = !system_ram_offload_required
        || (system_ram_available
            && system_ram_available_bytes > system_ram_offload_required_bytes
            && system_ram_available_bytes - system_ram_offload_required_bytes >= system_ram_offload_min_free_bytes);
    system_ram_offload_budget_admitted = system_ram_offload_required
        && system_ram_offload_allowed
        && system_ram_offload_limit_met
        && system_ram_floor_met;

    add_assoc_bool(return_value, "kv_cache_estimate_available", estimate_available);
    add_assoc_long(return_value, "kv_cache_estimated_bytes", estimate_available ? estimated_bytes : 0);
    add_assoc_long(
        return_value,
        "kv_cache_context_tokens",
        max_context_tokens != NULL && Z_TYPE_P(max_context_tokens) == IS_LONG ? Z_LVAL_P(max_context_tokens) : 0
    );
    add_assoc_long(
        return_value,
        "kv_cache_layers",
        layers != NULL && Z_TYPE_P(layers) == IS_LONG ? Z_LVAL_P(layers) : 0
    );
    add_assoc_long(
        return_value,
        "kv_cache_page_tokens",
        page_tokens != NULL && Z_TYPE_P(page_tokens) == IS_LONG ? Z_LVAL_P(page_tokens) : 0
    );
    add_assoc_long(
        return_value,
        "kv_cache_element_bytes",
        element_bytes != NULL && Z_TYPE_P(element_bytes) == IS_LONG ? Z_LVAL_P(element_bytes) : 0
    );
    add_assoc_string(
        return_value,
        "kv_cache_estimate_source",
        estimate_available ? "paged_kv_cache_plan" : "paged_kv_cache_plan_incomplete"
    );
    add_assoc_long(return_value, "model_vram_required_bytes", runtime_required_bytes);
    add_assoc_long(return_value, "runtime_vram_required_bytes", runtime_required_bytes);
    add_assoc_string(
        return_value,
        "runtime_vram_required_source",
        estimate_available || weights_resident ? runtime_required_source : "artifact_only"
    );
    add_assoc_bool(return_value, "runtime_vram_compared_to_free", compared_to_free);
    add_assoc_bool(return_value, "runtime_vram_fits_without_reserve", fits_without_reserve);
    add_assoc_bool(return_value, "runtime_vram_fits_after_reserve", fits_free);
    add_assoc_bool(return_value, "runtime_vram_fits_free", fits_free);
    add_assoc_bool(return_value, "system_ram_offload_allowed", system_ram_offload_allowed);
    add_assoc_bool(return_value, "system_ram_offload_required", system_ram_offload_required);
    add_assoc_long(return_value, "system_ram_offload_required_bytes", system_ram_offload_required_bytes);
    add_assoc_bool(return_value, "system_ram_offload_limit_configured", system_ram_offload_limit_configured);
    add_assoc_bool(return_value, "system_ram_offload_limit_met", system_ram_offload_limit_met);
    add_assoc_bool(return_value, "system_ram_available", system_ram_available);
    add_assoc_long(return_value, "system_ram_available_bytes", system_ram_available ? system_ram_available_bytes : 0);
    add_assoc_bool(return_value, "system_ram_offload_floor_met", system_ram_floor_met);
    add_assoc_bool(return_value, "system_ram_offload_budget_admitted", system_ram_offload_budget_admitted);
    add_assoc_bool(return_value, "system_ram_offload_supported", system_ram_offload_supported);
    add_assoc_bool(return_value, "system_ram_offload_admitted", system_ram_offload_admitted);
    add_assoc_string(
        return_value,
        "system_ram_offload_status",
        king_inference_gpu_system_ram_offload_status(
            system_ram_offload_required,
            system_ram_offload_allowed,
            system_ram_offload_limit_configured,
            system_ram_offload_limit_met,
            system_ram_available,
            system_ram_floor_met,
            system_ram_offload_supported,
            system_ram_offload_admitted
        )
    );
    add_assoc_string(
        return_value,
        "system_ram_offload_error",
        king_inference_gpu_system_ram_offload_error(
            system_ram_offload_required,
            system_ram_offload_allowed,
            system_ram_offload_limit_configured,
            system_ram_offload_limit_met,
            system_ram_available,
            system_ram_floor_met,
            system_ram_offload_supported,
            system_ram_offload_admitted
        )
    );
    if (estimate_available || weights_resident) {
        bool runtime_config_ready = king_inference_gpu_runtime_config_ready_after_vram(return_value, fits_free);

        add_assoc_bool(return_value, "model_vram_admitted", fits_free);
        add_assoc_bool(return_value, "config_ready", runtime_config_ready);
        if (runtime_config_ready) {
            zval empty_refusals;

            add_assoc_string(return_value, "reason", "ready");
            array_init(&empty_refusals);
            add_assoc_zval(return_value, "refusal_reasons", &empty_refusals);
        }
    }
    if (system_ram_offload_required && !system_ram_offload_allowed) {
        add_assoc_string(return_value, "reason", "gpu_system_ram_offload_disabled");
        king_inference_gpu_status_append_return_reason(return_value, "gpu_system_ram_offload_disabled");
    } else if (system_ram_offload_required && !system_ram_offload_limit_configured) {
        add_assoc_string(return_value, "reason", "gpu_system_ram_offload_limit_missing");
        king_inference_gpu_status_append_return_reason(return_value, "gpu_system_ram_offload_limit_missing");
    } else if (system_ram_offload_required && !system_ram_offload_limit_met) {
        add_assoc_string(return_value, "reason", "gpu_system_ram_offload_exceeds_limit");
        king_inference_gpu_status_append_return_reason(return_value, "gpu_system_ram_offload_exceeds_limit");
    } else if (system_ram_offload_required && !system_ram_available) {
        add_assoc_string(return_value, "reason", "gpu_system_ram_unavailable");
        king_inference_gpu_status_append_return_reason(return_value, "gpu_system_ram_unavailable");
    } else if (system_ram_offload_required && !system_ram_floor_met) {
        add_assoc_string(return_value, "reason", "gpu_system_ram_offload_floor_not_met");
        king_inference_gpu_status_append_return_reason(return_value, "gpu_system_ram_offload_floor_not_met");
    } else if (system_ram_offload_required && !system_ram_offload_supported) {
        add_assoc_string(return_value, "reason", "gpu_system_ram_offload_not_supported");
        king_inference_gpu_status_append_return_reason(return_value, "gpu_system_ram_offload_not_supported");
    } else if (system_ram_offload_required && !system_ram_offload_admitted) {
        add_assoc_string(return_value, "reason", "gpu_system_ram_offload_not_admitted");
        king_inference_gpu_status_append_return_reason(return_value, "gpu_system_ram_offload_not_admitted");
    }
    if (previous_model_vram_admitted_bool && compared_to_free && !fits_free) {
        runtime_reason = overflow
            ? "gpu_required_vram_overflow"
            : (fits_without_reserve
                ? "gpu_required_vram_exceeds_free_vram_after_reserve"
                : "gpu_required_vram_exceeds_free_vram");

        add_assoc_string(return_value, "reason", runtime_reason);
        king_inference_gpu_status_append_return_reason(return_value, runtime_reason);
    }
}
