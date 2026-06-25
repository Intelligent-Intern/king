/*
 * GPU runtime status reporting for King inference.
 */

static bool king_inference_gpu_status_non_empty(const char *value)
{
    return value != NULL && value[0] != '\0';
}

static bool king_inference_gpu_status_path_readable(const char *path)
{
    return king_inference_gpu_status_non_empty(path) && access(path, R_OK) == 0;
}

static bool king_inference_gpu_status_driver_visible(void)
{
    return access("/proc/driver/nvidia/version", R_OK) == 0
        || access("/dev/nvidiactl", R_OK) == 0
        || access("/dev/dri", R_OK | X_OK) == 0;
}

static bool king_inference_gpu_status_read_temperature_path(
    const char *path,
    double *temperature_c
) {
    FILE *file;
    char buffer[64];
    char *endptr;
    double value;

    if (!king_inference_gpu_status_non_empty(path)) {
        return false;
    }

    file = fopen(path, "r");
    if (file == NULL) {
        return false;
    }

    if (fgets(buffer, sizeof(buffer), file) == NULL) {
        fclose(file);
        return false;
    }
    fclose(file);

    errno = 0;
    value = strtod(buffer, &endptr);
    if (errno != 0 || endptr == buffer) {
        return false;
    }

    *temperature_c = value > 1000.0 ? value / 1000.0 : value;
    return true;
}

static bool king_inference_gpu_status_read_temperature_command(
    const char *command,
    double *temperature_c
) {
    FILE *pipe;
    char buffer[64];
    char *endptr;
    double value;
    int status;

    if (!king_inference_gpu_status_non_empty(command)) {
        return false;
    }

    pipe = popen(command, "r");
    if (pipe == NULL) {
        return false;
    }

    if (fgets(buffer, sizeof(buffer), pipe) == NULL) {
        pclose(pipe);
        return false;
    }

    status = pclose(pipe);
    if (status == -1) {
        return false;
    }

    errno = 0;
    value = strtod(buffer, &endptr);
    if (errno != 0 || endptr == buffer) {
        return false;
    }

    *temperature_c = value > 1000.0 ? value / 1000.0 : value;
    return true;
}

static bool king_inference_gpu_status_backend_supported(const char *backend)
{
    return !king_inference_gpu_status_non_empty(backend)
        || strcasecmp(backend, "auto") == 0
        || strcasecmp(backend, "cuda") == 0;
}

static const char *king_inference_gpu_status_reason(
    bool gpu_enabled,
    bool process_bindings_enabled,
    bool config_bindings_enabled,
    bool backend_supported,
    bool artifact_configured,
    bool artifact_readable,
    zend_long max_gpu_layers,
    bool thermal_monitored,
    bool allow_unmonitored,
    bool temperature_available,
    bool temperature_ok,
    bool driver_visible
) {
    if (!gpu_enabled) {
        return "gpu_disabled";
    }
    if (!process_bindings_enabled) {
        return "process_gpu_bindings_disabled";
    }
    if (!config_bindings_enabled) {
        return "config_gpu_bindings_disabled";
    }
    if (!backend_supported) {
        return "unsupported_gpu_backend";
    }
    if (!artifact_configured) {
        return "gpu_model_artifact_missing";
    }
    if (!artifact_readable) {
        return "gpu_model_artifact_not_readable";
    }
    if (max_gpu_layers <= 0) {
        return "gpu_layers_disabled";
    }
    if (!driver_visible && !temperature_available) {
        return "gpu_driver_not_visible";
    }
    if (!thermal_monitored && !allow_unmonitored) {
        return "gpu_thermal_monitor_missing";
    }
    if (thermal_monitored && !temperature_available) {
        return "gpu_temperature_unavailable";
    }
    if (temperature_available && !temperature_ok) {
        return "gpu_temperature_limit_reached";
    }

    return "gpu_decoder_kernel_not_implemented";
}

static void king_inference_gpu_runtime_status_array(
    zval *return_value,
    bool gpu_enabled,
    bool process_bindings_enabled,
    bool config_bindings_enabled,
    const char *backend,
    const char *artifact_path,
    zend_long max_gpu_layers,
    const char *thermal_sensor_path,
    const char *thermal_sensor_command,
    double max_temperature_c,
    bool allow_unmonitored
) {
    zval thermal;
    bool backend_supported = king_inference_gpu_status_backend_supported(backend);
    bool artifact_configured = king_inference_gpu_status_non_empty(artifact_path);
    bool artifact_readable = king_inference_gpu_status_path_readable(artifact_path);
    bool thermal_path_configured = king_inference_gpu_status_non_empty(thermal_sensor_path);
    bool thermal_command_configured = king_inference_gpu_status_non_empty(thermal_sensor_command);
    bool thermal_monitored = thermal_path_configured || thermal_command_configured;
    bool temperature_available = false;
    bool temperature_ok = false;
    bool driver_visible = king_inference_gpu_status_driver_visible();
    bool config_ready;
    double temperature_c = 0.0;
    const char *reason;

    if (thermal_path_configured) {
        temperature_available = king_inference_gpu_status_read_temperature_path(
            thermal_sensor_path,
            &temperature_c
        );
    } else if (thermal_command_configured) {
        temperature_available = king_inference_gpu_status_read_temperature_command(
            thermal_sensor_command,
            &temperature_c
        );
    }

    if (temperature_available) {
        driver_visible = true;
        temperature_ok = temperature_c < max_temperature_c;
    }

    config_ready = gpu_enabled
        && process_bindings_enabled
        && config_bindings_enabled
        && backend_supported
        && artifact_configured
        && artifact_readable
        && max_gpu_layers > 0
        && driver_visible
        && ((thermal_monitored && temperature_available && temperature_ok)
            || (!thermal_monitored && allow_unmonitored));

    reason = king_inference_gpu_status_reason(
        gpu_enabled,
        process_bindings_enabled,
        config_bindings_enabled,
        backend_supported,
        artifact_configured,
        artifact_readable,
        max_gpu_layers,
        thermal_monitored,
        allow_unmonitored,
        temperature_available,
        temperature_ok,
        driver_visible
    );

    array_init(return_value);
    add_assoc_bool(return_value, "gpu_enabled", gpu_enabled);
    add_assoc_bool(return_value, "process_gpu_bindings_enable", process_bindings_enabled);
    add_assoc_bool(return_value, "config_gpu_bindings_enable", config_bindings_enabled);
    add_assoc_string(
        return_value,
        "backend",
        king_inference_gpu_status_non_empty(backend) ? backend : "auto"
    );
    add_assoc_bool(return_value, "backend_supported", backend_supported);
    add_assoc_string(
        return_value,
        "artifact_path",
        artifact_configured ? artifact_path : ""
    );
    add_assoc_bool(return_value, "artifact_configured", artifact_configured);
    add_assoc_bool(return_value, "artifact_readable", artifact_readable);
    add_assoc_long(return_value, "max_gpu_layers", max_gpu_layers);
    add_assoc_bool(return_value, "driver_visible", driver_visible);
    add_assoc_bool(return_value, "config_ready", config_ready);
    add_assoc_bool(return_value, "decoder_kernel_ready", false);
    add_assoc_bool(return_value, "generation_ready", false);
    add_assoc_string(return_value, "reason", reason);

    array_init(&thermal);
    add_assoc_string(
        &thermal,
        "sensor_path",
        thermal_path_configured ? thermal_sensor_path : ""
    );
    add_assoc_string(
        &thermal,
        "sensor_command",
        thermal_command_configured ? thermal_sensor_command : ""
    );
    add_assoc_bool(&thermal, "monitored", thermal_monitored);
    add_assoc_bool(&thermal, "allow_unmonitored_gpu", allow_unmonitored);
    add_assoc_double(&thermal, "max_temperature_c", max_temperature_c);
    add_assoc_bool(&thermal, "temperature_available", temperature_available);
    if (temperature_available) {
        add_assoc_double(&thermal, "temperature_c", temperature_c);
    } else {
        add_assoc_null(&thermal, "temperature_c");
    }
    add_assoc_bool(&thermal, "temperature_ok", temperature_available && temperature_ok);
    add_assoc_zval(return_value, "thermal", &thermal);
}

static void king_inference_gpu_runtime_status_from_compute(
    const kg_high_perf_compute_ai_config_t *compute,
    zval *return_value
) {
    bool process_gpu_bindings_enabled = king_high_perf_compute_ai_config.gpu_bindings_enable;
    bool config_gpu_bindings_enabled = compute->gpu_bindings_enable;
    bool gpu_enabled = process_gpu_bindings_enabled
        && config_gpu_bindings_enabled
        && compute->inference_gpu_max_gpu_layers > 0;

    king_inference_gpu_runtime_status_array(
        return_value,
        gpu_enabled,
        process_gpu_bindings_enabled,
        config_gpu_bindings_enabled,
        compute->gpu_default_backend,
        compute->inference_gpu_model_artifact,
        compute->inference_gpu_max_gpu_layers,
        compute->inference_gpu_thermal_sensor_path,
        compute->inference_gpu_thermal_sensor_command,
        compute->inference_gpu_thermal_max_temperature_c,
        compute->inference_gpu_allow_unmonitored
    );
}

static void king_inference_gpu_runtime_status_from_model(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *gpu = king_inference_array_find(&model->config, "gpu");
    zval *thermal = gpu != NULL && Z_TYPE_P(gpu) == IS_ARRAY
        ? king_inference_array_find(gpu, "thermal")
        : NULL;
    zval *sensor_path = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_array_find(thermal, "sensor_path")
        : NULL;
    zval *sensor_command = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_array_find(thermal, "sensor_command")
        : NULL;
    const char *thermal_sensor_path = sensor_path != NULL && Z_TYPE_P(sensor_path) == IS_STRING
        ? Z_STRVAL_P(sensor_path)
        : "";
    const char *thermal_sensor_command = sensor_command != NULL && Z_TYPE_P(sensor_command) == IS_STRING
        ? Z_STRVAL_P(sensor_command)
        : "";
    double max_temperature_c = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_double_from_array(thermal, "max_temperature_c", 80.0)
        : 80.0;
    bool allow_unmonitored = thermal != NULL
        && Z_TYPE_P(thermal) == IS_ARRAY
        && king_inference_bool_from_array(thermal, "allow_unmonitored_gpu", false);

    king_inference_gpu_runtime_status_array(
        return_value,
        king_inference_gpu_enabled(&model->config),
        king_high_perf_compute_ai_config.gpu_bindings_enable,
        true,
        king_high_perf_compute_ai_config.gpu_default_backend,
        model->artifact_path != NULL ? ZSTR_VAL(model->artifact_path) : "",
        king_inference_max_gpu_layers(&model->config),
        thermal_sensor_path,
        thermal_sensor_command,
        max_temperature_c,
        allow_unmonitored
    );
}
