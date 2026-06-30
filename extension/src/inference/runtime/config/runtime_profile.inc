/*
 * Runtime model profile selection for King inference.
 */

static bool king_inference_runtime_non_empty(const char *value)
{
    return value != NULL && value[0] != '\0';
}

static bool king_inference_runtime_array_bool(zval *source, const char *key)
{
    zval *value;

    if (source == NULL || Z_TYPE_P(source) != IS_ARRAY) {
        return false;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(source), key, strlen(key));
    return value != NULL && zend_is_true(value);
}

static zend_long king_inference_runtime_array_long(zval *source, const char *key)
{
    zval *value;

    if (source == NULL || Z_TYPE_P(source) != IS_ARRAY) {
        return 0;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(source), key, strlen(key));
    return value != NULL && Z_TYPE_P(value) == IS_LONG ? Z_LVAL_P(value) : 0;
}

static const char *king_inference_runtime_array_string(zval *source, const char *key)
{
    zval *value;

    if (source == NULL || Z_TYPE_P(source) != IS_ARRAY) {
        return "";
    }

    value = zend_hash_str_find(Z_ARRVAL_P(source), key, strlen(key));
    return value != NULL && Z_TYPE_P(value) == IS_STRING ? Z_STRVAL_P(value) : "";
}

static zend_string *king_inference_runtime_gpu_refusal_reasons_string(zval *gpu_runtime)
{
    zval *reasons;
    zval *entry;
    smart_str buffer = {0};
    bool first = true;

    if (gpu_runtime == NULL || Z_TYPE_P(gpu_runtime) != IS_ARRAY) {
        return zend_string_init("gpu_runtime_status_missing", sizeof("gpu_runtime_status_missing") - 1, 0);
    }

    reasons = zend_hash_str_find(Z_ARRVAL_P(gpu_runtime), "refusal_reasons", sizeof("refusal_reasons") - 1);
    if (reasons == NULL || Z_TYPE_P(reasons) != IS_ARRAY) {
        const char *reason = king_inference_runtime_array_string(gpu_runtime, "reason");
        return zend_string_init(reason[0] != '\0' ? reason : "unknown", strlen(reason[0] != '\0' ? reason : "unknown"), 0);
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(reasons), entry) {
        if (entry == NULL || Z_TYPE_P(entry) != IS_STRING || Z_STRLEN_P(entry) == 0) {
            continue;
        }
        if (!first) {
            smart_str_appendc(&buffer, ',');
        }
        smart_str_append(&buffer, Z_STR_P(entry));
        first = false;
    } ZEND_HASH_FOREACH_END();

    if (first) {
        return zend_string_init("unknown", sizeof("unknown") - 1, 0);
    }

    smart_str_0(&buffer);
    return buffer.s;
}

static const char *king_inference_runtime_gpu_profile_refusal_hint(zval *gpu_runtime)
{
    zval *cuda_driver;
    zval *thermal;
    zval *power;

    if (!king_inference_runtime_array_bool(gpu_runtime, "process_gpu_bindings_enable")) {
        return "process-level king.gpu_bindings_enable is disabled";
    }
    if (!king_inference_runtime_array_bool(gpu_runtime, "config_gpu_bindings_enable")) {
        return "effective King config has gpu_bindings_enable disabled";
    }
    if (!king_inference_runtime_array_bool(gpu_runtime, "backend_supported")) {
        return "configured GPU backend is not supported by this build";
    }
    if (!king_inference_runtime_array_bool(gpu_runtime, "artifact_configured")) {
        return "king.inference_gpu_model_artifact is missing";
    }
    if (!king_inference_runtime_array_bool(gpu_runtime, "artifact_readable")) {
        return "king.inference_gpu_model_artifact is not readable";
    }
    if (king_inference_runtime_array_long(gpu_runtime, "max_gpu_layers") <= 0) {
        return "king.inference_gpu_max_gpu_layers must be greater than 0";
    }

    cuda_driver = zend_hash_str_find(Z_ARRVAL_P(gpu_runtime), "cuda_driver", sizeof("cuda_driver") - 1);
    if (cuda_driver == NULL
        || Z_TYPE_P(cuda_driver) != IS_ARRAY
        || !king_inference_runtime_array_bool(cuda_driver, "loader_available")
        || !king_inference_runtime_array_bool(cuda_driver, "symbols_available")
        || !king_inference_runtime_array_bool(cuda_driver, "initialized")
        || king_inference_runtime_array_long(cuda_driver, "device_count") <= 0) {
        return "CUDA runtime or visible CUDA device is not available";
    }
    if (!king_inference_runtime_array_bool(gpu_runtime, "free_vram_available")
        || !king_inference_runtime_array_bool(gpu_runtime, "vram_admission_checked")) {
        return "CUDA free VRAM information is unavailable";
    }
    if (!king_inference_runtime_array_bool(gpu_runtime, "free_vram_floor_met")) {
        return "free VRAM is below king.inference_gpu_min_free_vram_mb";
    }
    if (!king_inference_runtime_array_bool(gpu_runtime, "runtime_vram_fits_free")
        || !king_inference_runtime_array_bool(gpu_runtime, "model_vram_admitted")) {
        return "model artifact and runtime memory do not fit into free VRAM after reserve";
    }

    thermal = zend_hash_str_find(Z_ARRVAL_P(gpu_runtime), "thermal", sizeof("thermal") - 1);
    if (thermal != NULL
        && Z_TYPE_P(thermal) == IS_ARRAY
        && !king_inference_runtime_array_bool(thermal, "temperature_ok")) {
        return "GPU thermal policy is not satisfied";
    }
    power = zend_hash_str_find(Z_ARRVAL_P(gpu_runtime), "power", sizeof("power") - 1);
    if (power != NULL
        && Z_TYPE_P(power) == IS_ARRAY
        && king_inference_runtime_array_bool(power, "enabled")
        && !king_inference_runtime_array_bool(power, "power_ok")) {
        return "GPU power policy is not satisfied";
    }

    return "GPU runtime admission is not ready";
}

static zend_result king_inference_runtime_refuse_gpu_profile(
    const char *function_name,
    zval *gpu_runtime)
{
    zend_string *reasons = king_inference_runtime_gpu_refusal_reasons_string(gpu_runtime);
    const char *hint = king_inference_runtime_gpu_profile_refusal_hint(gpu_runtime);
    const char *artifact_path = king_inference_runtime_array_string(gpu_runtime, "artifact_path");
    zval *cuda_driver = zend_hash_str_find(Z_ARRVAL_P(gpu_runtime), "cuda_driver", sizeof("cuda_driver") - 1);
    const char *cuda_error = cuda_driver != NULL && Z_TYPE_P(cuda_driver) == IS_ARRAY
        ? king_inference_runtime_array_string(cuda_driver, "error")
        : "";

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "%s() refused explicit gpu profile: %s. Reasons=%s. Artifact=%s. CUDA error=%s. King will not silently fall back to CPU for a gpu profile.",
        function_name,
        hint,
        ZSTR_VAL(reasons),
        artifact_path[0] != '\0' ? artifact_path : "(not configured)",
        cuda_error[0] != '\0' ? cuda_error : "none"
    );
    zend_string_release(reasons);
    return FAILURE;
}

static const kg_high_perf_compute_ai_config_t *king_inference_runtime_compute_config(
    zval *zconfig,
    const char *function_name)
{
    king_cfg_t *cfg;

    if (zconfig == NULL || Z_TYPE_P(zconfig) == IS_NULL) {
        return &king_high_perf_compute_ai_config;
    }

    cfg = king_fetch_config(zconfig);
    if (cfg == NULL) {
        king_set_error("King inference runtime model config requires King\\Config or null.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() expects null, a King\\Config object, or a native King\\Config resource.",
            function_name
        );
        return NULL;
    }

    return &cfg->compute_ai;
}

static zend_result king_inference_runtime_select_profile(
    const kg_high_perf_compute_ai_config_t *compute,
    const char *function_name,
    bool *selected_gpu,
    bool *gpu_profile_available,
    zval *selected_gpu_runtime)
{
    const char *profile = king_inference_runtime_non_empty(compute->inference_preferred_model_profile)
        ? compute->inference_preferred_model_profile
        : "auto";
    zval gpu_runtime;

    *selected_gpu = false;
    *gpu_profile_available = false;
    ZVAL_UNDEF(selected_gpu_runtime);

    ZVAL_UNDEF(&gpu_runtime);

    if (strcasecmp(profile, "gpu") == 0) {
        king_inference_gpu_runtime_status_from_compute(compute, &gpu_runtime);
        if (!king_inference_runtime_array_bool(&gpu_runtime, "config_ready")) {
            king_inference_runtime_refuse_gpu_profile(function_name, &gpu_runtime);
            zval_ptr_dtor(&gpu_runtime);
            return FAILURE;
        }
        *gpu_profile_available = true;
        *selected_gpu = true;
        ZVAL_COPY(selected_gpu_runtime, &gpu_runtime);
        zval_ptr_dtor(&gpu_runtime);
        return SUCCESS;
    }

    if (strcasecmp(profile, "cpu") == 0) {
        *selected_gpu = false;
        return SUCCESS;
    }

    if (strcasecmp(profile, "auto") == 0) {
        king_inference_gpu_runtime_status_from_compute(compute, &gpu_runtime);
        *gpu_profile_available = king_inference_runtime_array_bool(&gpu_runtime, "config_ready");
        *selected_gpu = *gpu_profile_available;
        if (*selected_gpu) {
            ZVAL_COPY(selected_gpu_runtime, &gpu_runtime);
        }
        zval_ptr_dtor(&gpu_runtime);
        return SUCCESS;
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "%s() inference profile must be one of auto, gpu, or cpu.",
        function_name
    );
    return FAILURE;
}

static zend_result king_inference_runtime_model_config_array(
    const kg_high_perf_compute_ai_config_t *compute,
    zval *return_value,
    const char *function_name)
{
    const char *profile = king_inference_runtime_non_empty(compute->inference_preferred_model_profile)
        ? compute->inference_preferred_model_profile
        : "auto";
    const char *cpu_name = king_inference_runtime_non_empty(compute->inference_cpu_model_name)
        ? compute->inference_cpu_model_name
        : "gemma3:1b";
    const char *gpu_name = king_inference_runtime_non_empty(compute->inference_gpu_model_name)
        ? compute->inference_gpu_model_name
        : "gemma4:12b";
    const char *selected_name;
    const char *selected_artifact;
    bool selected_gpu;
    bool gpu_profile_available;
    bool process_gpu_enabled = king_high_perf_compute_ai_config.gpu_bindings_enable;
    bool config_gpu_enabled = compute->gpu_bindings_enable;
    zval selected_gpu_runtime;
    zval artifact;
    zval llm_cache;
    zval kv_cache;

    ZVAL_UNDEF(&selected_gpu_runtime);
    if (king_inference_runtime_select_profile(
            compute,
            function_name,
            &selected_gpu,
            &gpu_profile_available,
            &selected_gpu_runtime
        ) != SUCCESS) {
        return FAILURE;
    }

    selected_name = selected_gpu ? gpu_name : cpu_name;
    selected_artifact = selected_gpu
        ? compute->inference_gpu_model_artifact
        : compute->inference_cpu_model_artifact;

    if (!king_inference_runtime_non_empty(selected_artifact)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() requires %s.",
            function_name,
            selected_gpu ? "inference.gpu_model_artifact" : "inference.cpu_model_artifact"
        );
        return FAILURE;
    }

    array_init(return_value);
    add_assoc_string(return_value, "name", selected_name);
    add_assoc_string(return_value, "backend", selected_gpu ? "king_native_gpu" : "king_native_cpu");
    add_assoc_string(return_value, "owned_by", "king-runtime");
    add_assoc_long(return_value, "context_tokens", compute->inference_context_tokens);
    add_assoc_bool(return_value, "with_memory", compute->inference_with_memory);
    add_assoc_string(return_value, "runtime_profile", selected_gpu ? "gpu" : "cpu");
    add_assoc_string(return_value, "runtime_requested_profile", profile);
    add_assoc_string(return_value, "runtime_cpu_model_name", cpu_name);
    add_assoc_string(return_value, "runtime_gpu_model_name", gpu_name);
    add_assoc_bool(return_value, "runtime_gpu_bindings_enable", process_gpu_enabled);
    add_assoc_bool(return_value, "runtime_config_gpu_bindings_enable", config_gpu_enabled);
    add_assoc_bool(
        return_value,
        "runtime_gpu_profile_available",
        gpu_profile_available
    );

    array_init(&artifact);
    add_assoc_string(&artifact, "path", selected_artifact);
    add_assoc_zval(return_value, "artifact", &artifact);

    array_init(&kv_cache);
    add_assoc_long(&kv_cache, "max_context_tokens", compute->inference_context_tokens);
    add_assoc_long(&kv_cache, "page_tokens", compute->inference_kv_page_tokens);
    add_assoc_long(&kv_cache, "element_bytes", compute->inference_kv_element_bytes);
    add_assoc_zval(return_value, "kv_cache", &kv_cache);

    array_init(&llm_cache);
    add_assoc_bool(&llm_cache, "enabled", compute->inference_llm_cache_enable);
    add_assoc_string(
        &llm_cache,
        "path",
        king_inference_runtime_non_empty(compute->inference_llm_cache_path)
            ? compute->inference_llm_cache_path
            : "/tmp/king-llm-cache"
    );
    add_assoc_long(&llm_cache, "min_free_mb", compute->inference_llm_cache_min_free_mb);
    add_assoc_bool(&llm_cache, "fail_closed", compute->inference_llm_cache_fail_closed);
    add_assoc_string(
        &llm_cache,
        "disk_alert_webhook",
        compute->inference_llm_cache_disk_alert_webhook != NULL
            ? compute->inference_llm_cache_disk_alert_webhook
            : ""
    );
    add_assoc_string(
        &llm_cache,
        "disk_alert_mcp_service",
        compute->inference_llm_cache_disk_alert_mcp_service != NULL
            ? compute->inference_llm_cache_disk_alert_mcp_service
            : ""
    );
    add_assoc_string(
        &llm_cache,
        "disk_alert_mcp_method",
        compute->inference_llm_cache_disk_alert_mcp_method != NULL
            ? compute->inference_llm_cache_disk_alert_mcp_method
            : ""
    );
    add_assoc_zval(return_value, "llm_cache", &llm_cache);

    if (selected_gpu) {
        zval gpu;
        zval thermal;
        zval power;

        array_init(&gpu);
        add_assoc_bool(&gpu, "enabled", true);
        add_assoc_long(&gpu, "max_gpu_layers", compute->inference_gpu_max_gpu_layers);
        add_assoc_long(&gpu, "vram_reserve_mb", compute->inference_gpu_vram_reserve_mb);
        add_assoc_long(&gpu, "min_free_vram_mb", compute->inference_gpu_min_free_vram_mb);
        add_assoc_bool(
            &gpu,
            "batch_prefill_experimental_enable",
            compute->inference_gpu_batch_prefill_experimental_enable
        );

        array_init(&thermal);
        if (king_inference_runtime_non_empty(compute->inference_gpu_thermal_sensor_path)) {
            add_assoc_string(
                &thermal,
                "sensor_path",
                compute->inference_gpu_thermal_sensor_path
            );
        }
        if (king_inference_runtime_non_empty(compute->inference_gpu_thermal_sensor_command)) {
            add_assoc_string(
                &thermal,
                "sensor_command",
                compute->inference_gpu_thermal_sensor_command
            );
        }
        add_assoc_double(
            &thermal,
            "max_temperature_c",
            compute->inference_gpu_thermal_max_temperature_c
        );
        add_assoc_long(
            &thermal,
            "check_interval_seconds",
            compute->inference_gpu_thermal_check_interval_sec
        );
        add_assoc_bool(
            &thermal,
            "allow_unmonitored_gpu",
            compute->inference_gpu_allow_unmonitored
        );
        add_assoc_zval(&gpu, "thermal", &thermal);
        array_init(&power);
        if (king_inference_runtime_non_empty(compute->inference_gpu_power_sensor_command)) {
            add_assoc_string(
                &power,
                "sensor_command",
                compute->inference_gpu_power_sensor_command
            );
        }
        add_assoc_double(&power, "max_watts", compute->inference_gpu_power_max_watts);
        add_assoc_long(
            &power,
            "check_interval_seconds",
            compute->inference_gpu_power_check_interval_sec
        );
        add_assoc_zval(&gpu, "power", &power);
        add_assoc_zval(return_value, "gpu", &gpu);

        if (Z_ISUNDEF(selected_gpu_runtime)) {
            zval gpu_runtime;

            king_inference_gpu_runtime_status_from_compute(compute, &gpu_runtime);
            add_assoc_zval(return_value, "gpu_runtime", &gpu_runtime);
        } else {
            add_assoc_zval(return_value, "gpu_runtime", &selected_gpu_runtime);
        }
    } else if (!Z_ISUNDEF(selected_gpu_runtime)) {
        zval_ptr_dtor(&selected_gpu_runtime);
    }

    return SUCCESS;
}

PHP_FUNCTION(king_inference_gpu_runtime_status)
{
    zval *zconfig = NULL;
    const kg_high_perf_compute_ai_config_t *compute;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL_OR_NULL(zconfig)
    ZEND_PARSE_PARAMETERS_END();

    compute = king_inference_runtime_compute_config(
        zconfig,
        "king_inference_gpu_runtime_status"
    );
    if (compute == NULL) {
        RETURN_THROWS();
    }

    king_inference_gpu_runtime_status_from_compute(compute, return_value);
}

PHP_FUNCTION(king_inference_runtime_model_config)
{
    zval *zconfig = NULL;
    const kg_high_perf_compute_ai_config_t *compute;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL_OR_NULL(zconfig)
    ZEND_PARSE_PARAMETERS_END();

    compute = king_inference_runtime_compute_config(
        zconfig,
        "king_inference_runtime_model_config"
    );
    if (compute == NULL) {
        RETURN_THROWS();
    }

    if (king_inference_runtime_model_config_array(
            compute,
            return_value,
            "king_inference_runtime_model_config"
        ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_runtime_model_load)
{
    zval *zconfig = NULL;
    const kg_high_perf_compute_ai_config_t *compute;
    zval model_config;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL_OR_NULL(zconfig)
    ZEND_PARSE_PARAMETERS_END();

    compute = king_inference_runtime_compute_config(
        zconfig,
        "king_inference_runtime_model_load"
    );
    if (compute == NULL) {
        RETURN_THROWS();
    }

    ZVAL_UNDEF(&model_config);
    if (king_inference_runtime_model_config_array(
            compute,
            &model_config,
            "king_inference_runtime_model_load"
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    object_init_ex(return_value, king_ce_inference_model);
    if (king_inference_model_init(
            return_value,
            &model_config,
            "king_inference_runtime_model_load"
        ) != SUCCESS) {
        zval_ptr_dtor(&model_config);
        zval_ptr_dtor(return_value);
        RETURN_THROWS();
    }

    zval_ptr_dtor(&model_config);
}
