static zend_result king_inference_read_temperature(zend_string *path, double *temperature_c)
{
    FILE *file = fopen(ZSTR_VAL(path), "r");
    char buffer[64];
    char *endptr;
    double value;

    if (file == NULL) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU thermal sensor '%s' could not be opened.",
            ZSTR_VAL(path)
        );
        return FAILURE;
    }

    if (fgets(buffer, sizeof(buffer), file) == NULL) {
        fclose(file);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU thermal sensor '%s' could not be read.",
            ZSTR_VAL(path)
        );
        return FAILURE;
    }
    fclose(file);

    errno = 0;
    value = strtod(buffer, &endptr);
    if (errno != 0 || endptr == buffer) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU thermal sensor '%s' did not return a numeric temperature.",
            ZSTR_VAL(path)
        );
        return FAILURE;
    }

    *temperature_c = value > 1000.0 ? value / 1000.0 : value;
    return SUCCESS;
}

static zend_result king_inference_read_temperature_command(zend_string *command, double *temperature_c)
{
    FILE *pipe = popen(ZSTR_VAL(command), "r");
    char buffer[64];
    char *endptr;
    double value;
    int status;

    if (pipe == NULL) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU thermal sensor command '%s' could not be opened.",
            ZSTR_VAL(command)
        );
        return FAILURE;
    }

    if (fgets(buffer, sizeof(buffer), pipe) == NULL) {
        pclose(pipe);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU thermal sensor command '%s' returned no temperature.",
            ZSTR_VAL(command)
        );
        return FAILURE;
    }

    status = pclose(pipe);
    if (status == -1) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU thermal sensor command '%s' could not be closed.",
            ZSTR_VAL(command)
        );
        return FAILURE;
    }

    errno = 0;
    value = strtod(buffer, &endptr);
    if (errno != 0 || endptr == buffer) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU thermal sensor command '%s' did not return a numeric temperature.",
            ZSTR_VAL(command)
        );
        return FAILURE;
    }

    *temperature_c = value > 1000.0 ? value / 1000.0 : value;
    return SUCCESS;
}

static zend_result king_inference_read_power_command(zend_string *command, double *power_watts)
{
    FILE *pipe = popen(ZSTR_VAL(command), "r");
    char buffer[64];
    char *endptr;
    double value;
    int status;

    if (pipe == NULL) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU power sensor command '%s' could not be opened.",
            ZSTR_VAL(command)
        );
        return FAILURE;
    }

    if (fgets(buffer, sizeof(buffer), pipe) == NULL) {
        pclose(pipe);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU power sensor command '%s' returned no power reading.",
            ZSTR_VAL(command)
        );
        return FAILURE;
    }

    status = pclose(pipe);
    if (status == -1) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU power sensor command '%s' could not be closed.",
            ZSTR_VAL(command)
        );
        return FAILURE;
    }

    errno = 0;
    value = strtod(buffer, &endptr);
    if (errno != 0 || endptr == buffer || !isfinite(value) || value < 0.0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "GPU power sensor command '%s' did not return a non-negative numeric watt reading.",
            ZSTR_VAL(command)
        );
        return FAILURE;
    }

    *power_watts = value;
    return SUCCESS;
}

static zend_result king_inference_check_gpu_policy_sample(
    zval *config,
    const char *function_name,
    bool *temperature_checked,
    double *temperature_c_out,
    double *max_temperature_c_out,
    bool *temperature_ceiling_reached
)
{
    zval *gpu = king_inference_array_find(config, "gpu");
    zval *thermal;
    zval *sensor_path;
    zval *sensor_command;
    double temperature_c;
    double max_temperature_c;

    if (temperature_checked != NULL) {
        *temperature_checked = false;
    }
    if (temperature_c_out != NULL) {
        *temperature_c_out = 0.0;
    }
    if (max_temperature_c_out != NULL) {
        *max_temperature_c_out = 0.0;
    }
    if (temperature_ceiling_reached != NULL) {
        *temperature_ceiling_reached = false;
    }

    if (gpu == NULL || Z_TYPE_P(gpu) != IS_ARRAY || !king_inference_bool_from_array(gpu, "enabled", false)) {
        return SUCCESS;
    }

    if (!king_high_perf_compute_ai_config.gpu_bindings_enable) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() refused GPU inference because king.gpu_bindings_enable is disabled.",
            function_name
        );
        return FAILURE;
    }

    thermal = king_inference_array_find(gpu, "thermal");
    sensor_path = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_array_find(thermal, "sensor_path")
        : NULL;
    sensor_command = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_array_find(thermal, "sensor_command")
        : NULL;

    if ((sensor_path == NULL || Z_TYPE_P(sensor_path) != IS_STRING || Z_STRLEN_P(sensor_path) == 0)
        && (sensor_command == NULL || Z_TYPE_P(sensor_command) != IS_STRING || Z_STRLEN_P(sensor_command) == 0)) {
        if (thermal != NULL
            && Z_TYPE_P(thermal) == IS_ARRAY
            && king_inference_bool_from_array(thermal, "allow_unmonitored_gpu", false)) {
            return SUCCESS;
        }

        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() refused GPU inference without a thermal sensor path. Set gpu.thermal.sensor_path or explicitly allow unmonitored GPU use.",
            function_name
        );
        return FAILURE;
    }

    if (sensor_path != NULL && Z_TYPE_P(sensor_path) == IS_STRING && Z_STRLEN_P(sensor_path) > 0) {
        if (king_inference_read_temperature(Z_STR_P(sensor_path), &temperature_c) != SUCCESS) {
            return FAILURE;
        }
    } else if (king_inference_read_temperature_command(Z_STR_P(sensor_command), &temperature_c) != SUCCESS) {
        return FAILURE;
    }
    if (temperature_checked != NULL) {
        *temperature_checked = true;
    }
    if (temperature_c_out != NULL) {
        *temperature_c_out = temperature_c;
    }

    max_temperature_c = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_double_from_array(thermal, "max_temperature_c", 80.0)
        : 80.0;
    if (max_temperature_c_out != NULL) {
        *max_temperature_c_out = max_temperature_c;
    }

    if (temperature_c >= max_temperature_c) {
        if (temperature_ceiling_reached != NULL) {
            *temperature_ceiling_reached = true;
        }
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() refused GPU inference because sensor '%s' reports %.1f C at or above the configured %.1f C limit.",
            function_name,
            sensor_path != NULL && Z_TYPE_P(sensor_path) == IS_STRING ? Z_STRVAL_P(sensor_path) : Z_STRVAL_P(sensor_command),
            temperature_c,
            max_temperature_c
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_check_gpu_policy(zval *config, const char *function_name)
{
    return king_inference_check_gpu_policy_sample(config, function_name, NULL, NULL, NULL, NULL);
}

static double king_inference_gpu_power_max_watts(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");
    zval *power;

    if (gpu == NULL || Z_TYPE_P(gpu) != IS_ARRAY) {
        return king_high_perf_compute_ai_config.inference_gpu_power_max_watts;
    }

    power = king_inference_array_find(gpu, "power");
    if (power == NULL || Z_TYPE_P(power) != IS_ARRAY) {
        return king_high_perf_compute_ai_config.inference_gpu_power_max_watts;
    }

    return king_inference_double_from_array(
        power,
        "max_watts",
        king_high_perf_compute_ai_config.inference_gpu_power_max_watts
    );
}

static zend_long king_inference_gpu_power_check_interval_seconds(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");
    zval *power;

    if (gpu == NULL || Z_TYPE_P(gpu) != IS_ARRAY) {
        return king_high_perf_compute_ai_config.inference_gpu_power_check_interval_sec;
    }

    power = king_inference_array_find(gpu, "power");
    if (power == NULL || Z_TYPE_P(power) != IS_ARRAY) {
        return king_high_perf_compute_ai_config.inference_gpu_power_check_interval_sec;
    }

    return king_inference_long_from_array(
        power,
        "check_interval_seconds",
        king_high_perf_compute_ai_config.inference_gpu_power_check_interval_sec
    );
}

static zend_result king_inference_check_gpu_power_policy_sample(
    zval *config,
    const char *function_name,
    bool *power_checked,
    double *power_watts_out,
    double *max_watts_out,
    bool *power_ceiling_reached
) {
    zval *gpu = king_inference_array_find(config, "gpu");
    zval *power;
    zval *sensor_command = NULL;
    zend_string *command = NULL;
    double max_watts = king_inference_gpu_power_max_watts(config);
    double power_watts = 0.0;

    if (power_checked != NULL) {
        *power_checked = false;
    }
    if (power_watts_out != NULL) {
        *power_watts_out = 0.0;
    }
    if (max_watts_out != NULL) {
        *max_watts_out = max_watts;
    }
    if (power_ceiling_reached != NULL) {
        *power_ceiling_reached = false;
    }

    if (gpu == NULL || Z_TYPE_P(gpu) != IS_ARRAY || !king_inference_bool_from_array(gpu, "enabled", false)) {
        return SUCCESS;
    }
    if (max_watts <= 0.0) {
        return SUCCESS;
    }

    power = king_inference_array_find(gpu, "power");
    if (power != NULL && Z_TYPE_P(power) == IS_ARRAY) {
        sensor_command = king_inference_array_find(power, "sensor_command");
    }
    if (sensor_command != NULL && Z_TYPE_P(sensor_command) == IS_STRING && Z_STRLEN_P(sensor_command) > 0) {
        command = Z_STR_P(sensor_command);
    } else if (king_high_perf_compute_ai_config.inference_gpu_power_sensor_command != NULL
               && king_high_perf_compute_ai_config.inference_gpu_power_sensor_command[0] != '\0') {
        command = zend_string_init(
            king_high_perf_compute_ai_config.inference_gpu_power_sensor_command,
            strlen(king_high_perf_compute_ai_config.inference_gpu_power_sensor_command),
            0
        );
    }

    if (command == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() refused GPU inference because gpu.power.max_watts is enabled without gpu.power.sensor_command.",
            function_name
        );
        return FAILURE;
    }

    if (king_inference_read_power_command(command, &power_watts) != SUCCESS) {
        if (command != NULL && (sensor_command == NULL || command != Z_STR_P(sensor_command))) {
            zend_string_release(command);
        }
        return FAILURE;
    }
    if (command != NULL && (sensor_command == NULL || command != Z_STR_P(sensor_command))) {
        zend_string_release(command);
    }

    if (power_checked != NULL) {
        *power_checked = true;
    }
    if (power_watts_out != NULL) {
        *power_watts_out = power_watts;
    }

    if (power_watts >= max_watts) {
        if (power_ceiling_reached != NULL) {
            *power_ceiling_reached = true;
        }
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() refused GPU inference because power draw %.1f W is at or above the configured %.1f W limit.",
            function_name,
            power_watts,
            max_watts
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_long king_inference_gpu_thermal_check_interval_seconds(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");
    zval *thermal;

    if (gpu == NULL || Z_TYPE_P(gpu) != IS_ARRAY) {
        return king_high_perf_compute_ai_config.inference_gpu_thermal_check_interval_sec;
    }

    thermal = king_inference_array_find(gpu, "thermal");
    if (thermal == NULL || Z_TYPE_P(thermal) != IS_ARRAY) {
        return king_high_perf_compute_ai_config.inference_gpu_thermal_check_interval_sec;
    }

    return king_inference_long_from_array(
        thermal,
        "check_interval_seconds",
        king_high_perf_compute_ai_config.inference_gpu_thermal_check_interval_sec
    );
}

static zend_result king_inference_check_gpu_policy_before_run(
    king_inference_stream_object *stream,
    const char *function_name
) {
    king_inference_model_object *model;
    zval *gpu;
    bool temperature_checked = false;
    double temperature_c = 0.0;

    if (Z_ISUNDEF(stream->model) || Z_TYPE(stream->model) != IS_OBJECT) {
        return SUCCESS;
    }

    model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    gpu = king_inference_array_find(&model->config, "gpu");
    if (gpu == NULL || Z_TYPE_P(gpu) != IS_ARRAY || !king_inference_bool_from_array(gpu, "enabled", false)) {
        return SUCCESS;
    }

    if (king_inference_check_gpu_policy_sample(
            &model->config,
            function_name,
            &temperature_checked,
            &temperature_c,
            NULL,
            NULL
        ) != SUCCESS) {
        return FAILURE;
    }

    stream->gpu_thermal_preflight_checked = true;
    stream->gpu_thermal_preflight_at = (zend_long) time(NULL);
    stream->gpu_thermal_last_check_at = stream->gpu_thermal_preflight_at;
    stream->gpu_thermal_preflight_temperature_available = temperature_checked;
    stream->gpu_thermal_preflight_temperature_c = temperature_checked ? temperature_c : 0.0;

    {
        bool power_checked = false;
        double power_watts = 0.0;

        if (king_inference_check_gpu_power_policy_sample(
                &model->config,
                function_name,
                &power_checked,
                &power_watts,
                NULL,
                NULL
            ) != SUCCESS) {
            return FAILURE;
        }
        if (power_checked) {
            stream->gpu_power_preflight_checked = true;
            stream->gpu_power_preflight_at = stream->gpu_thermal_preflight_at;
            stream->gpu_power_last_check_at = stream->gpu_power_preflight_at;
            stream->gpu_power_preflight_available = true;
            stream->gpu_power_preflight_watts = power_watts;
        }
    }
    return SUCCESS;
}

static zend_result king_inference_check_gpu_policy_during_run(
    king_inference_stream_object *stream,
    const char *function_name
) {
    king_inference_model_object *model;
    zval *gpu;
    bool temperature_checked = false;
    bool ceiling_reached = false;
    bool power_checked = false;
    bool power_ceiling_reached = false;
    double temperature_c = 0.0;
    double max_temperature_c = 0.0;
    double power_watts = 0.0;
    double max_watts = 0.0;
    zend_long now;
    zend_long check_interval_sec;
    zend_long power_check_interval_sec;
    bool thermal_due;
    bool power_due;

    if (Z_ISUNDEF(stream->model) || Z_TYPE(stream->model) != IS_OBJECT) {
        return SUCCESS;
    }

    model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    gpu = king_inference_array_find(&model->config, "gpu");
    if (gpu == NULL || Z_TYPE_P(gpu) != IS_ARRAY || !king_inference_bool_from_array(gpu, "enabled", false)) {
        return SUCCESS;
    }

    now = (zend_long) time(NULL);
    check_interval_sec = king_inference_gpu_thermal_check_interval_seconds(&model->config);
    stream->gpu_policy_during_run_checks++;
    stream->gpu_thermal_check_interval_seconds = check_interval_sec;
    thermal_due = check_interval_sec > 0
        && (stream->gpu_thermal_last_check_at <= 0
            || now < stream->gpu_thermal_last_check_at
            || now - stream->gpu_thermal_last_check_at >= check_interval_sec);
    power_check_interval_sec = king_inference_gpu_power_check_interval_seconds(&model->config);
    stream->gpu_power_check_interval_seconds = power_check_interval_sec;
    power_due = king_inference_gpu_power_max_watts(&model->config) > 0.0
        && power_check_interval_sec > 0
        && (stream->gpu_power_last_check_at <= 0
            || now < stream->gpu_power_last_check_at
            || now - stream->gpu_power_last_check_at >= power_check_interval_sec);
    if (!thermal_due && !power_due) {
        stream->gpu_policy_interval_skips++;
        return SUCCESS;
    }

    if (thermal_due) {
        if (king_inference_check_gpu_policy_sample(
                &model->config,
                function_name,
                &temperature_checked,
                &temperature_c,
                &max_temperature_c,
                &ceiling_reached
            ) != SUCCESS) {
            if (ceiling_reached) {
                stream->gpu_thermal_aborted = true;
                stream->gpu_thermal_abort_at = now;
                stream->gpu_thermal_abort_temperature_c = temperature_checked ? temperature_c : 0.0;
                stream->gpu_thermal_abort_ceiling_c = max_temperature_c;
            }
            return FAILURE;
        }
        stream->gpu_thermal_runtime_checks++;
        stream->gpu_thermal_last_check_at = now;
    }

    if (power_due) {
        if (king_inference_check_gpu_power_policy_sample(
                &model->config,
                function_name,
                &power_checked,
                &power_watts,
                &max_watts,
                &power_ceiling_reached
            ) != SUCCESS) {
            if (power_ceiling_reached) {
                stream->gpu_power_aborted = true;
                stream->gpu_power_abort_at = now;
                stream->gpu_power_abort_watts = power_checked ? power_watts : 0.0;
                stream->gpu_power_abort_ceiling_watts = max_watts;
            }
            return FAILURE;
        }
        if (power_checked) {
            stream->gpu_power_runtime_checks++;
            stream->gpu_power_last_check_at = now;
        }
    }
    return SUCCESS;
}
