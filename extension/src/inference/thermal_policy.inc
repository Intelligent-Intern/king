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

static zend_result king_inference_check_gpu_policy_sample(
    zval *config,
    const char *function_name,
    bool *temperature_checked,
    double *temperature_c_out
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

    if (temperature_c >= max_temperature_c) {
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
    return king_inference_check_gpu_policy_sample(config, function_name, NULL, NULL);
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
            &temperature_c
        ) != SUCCESS) {
        return FAILURE;
    }

    stream->gpu_thermal_preflight_checked = true;
    stream->gpu_thermal_preflight_at = (zend_long) time(NULL);
    stream->gpu_thermal_preflight_temperature_available = temperature_checked;
    stream->gpu_thermal_preflight_temperature_c = temperature_checked ? temperature_c : 0.0;
    return SUCCESS;
}
