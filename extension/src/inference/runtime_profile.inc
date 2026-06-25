/*
 * Runtime model profile selection for King inference.
 */

static bool king_inference_runtime_non_empty(const char *value)
{
    return value != NULL && value[0] != '\0';
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
    bool *selected_gpu)
{
    const char *profile = king_inference_runtime_non_empty(compute->inference_preferred_model_profile)
        ? compute->inference_preferred_model_profile
        : "auto";
    bool gpu_artifact_ready = king_inference_runtime_non_empty(compute->inference_gpu_model_artifact);
    bool process_gpu_enabled = king_high_perf_compute_ai_config.gpu_bindings_enable;
    bool config_gpu_enabled = compute->gpu_bindings_enable;
    bool gpu_available = process_gpu_enabled && config_gpu_enabled && gpu_artifact_ready;

    *selected_gpu = false;

    if (strcasecmp(profile, "gpu") == 0) {
        if (!process_gpu_enabled) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() gpu profile requires process-level king.gpu_bindings_enable=1.",
                function_name
            );
            return FAILURE;
        }
        if (!config_gpu_enabled) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() gpu profile requires gpu_bindings_enable in the effective King config.",
                function_name
            );
            return FAILURE;
        }
        if (!gpu_artifact_ready) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() gpu profile requires inference.gpu_model_artifact.",
                function_name
            );
            return FAILURE;
        }
        *selected_gpu = true;
        return SUCCESS;
    }

    if (strcasecmp(profile, "cpu") == 0) {
        *selected_gpu = false;
        return SUCCESS;
    }

    if (strcasecmp(profile, "auto") == 0) {
        *selected_gpu = gpu_available;
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
    bool gpu_artifact_ready = king_inference_runtime_non_empty(compute->inference_gpu_model_artifact);
    bool process_gpu_enabled = king_high_perf_compute_ai_config.gpu_bindings_enable;
    bool config_gpu_enabled = compute->gpu_bindings_enable;
    zval artifact;
    zval llm_cache;

    if (king_inference_runtime_select_profile(compute, function_name, &selected_gpu) != SUCCESS) {
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
    add_assoc_string(return_value, "backend", "king_native_cpu");
    add_assoc_string(return_value, "owned_by", "king-runtime");
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
        process_gpu_enabled && config_gpu_enabled && gpu_artifact_ready
    );

    array_init(&artifact);
    add_assoc_string(&artifact, "path", selected_artifact);
    add_assoc_zval(return_value, "artifact", &artifact);

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

        array_init(&gpu);
        add_assoc_bool(&gpu, "enabled", true);
        add_assoc_long(&gpu, "max_gpu_layers", compute->inference_gpu_max_gpu_layers);

        array_init(&thermal);
        if (king_inference_runtime_non_empty(compute->inference_gpu_thermal_sensor_path)) {
            add_assoc_string(
                &thermal,
                "sensor_path",
                compute->inference_gpu_thermal_sensor_path
            );
        }
        add_assoc_double(
            &thermal,
            "max_temperature_c",
            compute->inference_gpu_thermal_max_temperature_c
        );
        add_assoc_bool(
            &thermal,
            "allow_unmonitored_gpu",
            compute->inference_gpu_allow_unmonitored
        );
        add_assoc_zval(&gpu, "thermal", &thermal);
        add_assoc_zval(return_value, "gpu", &gpu);
    }

    return SUCCESS;
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
