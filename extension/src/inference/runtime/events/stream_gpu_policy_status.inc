/*
 * GPU policy status projection for inference streams.
 */

static const char *king_inference_stream_gpu_policy_abort_code(king_inference_stream_object *stream)
{
    if (stream->gpu_thermal_aborted) {
        return "king.policy.gpu_thermal_limit_reached";
    }
    if (stream->gpu_power_aborted) {
        return "king.policy.gpu_power_limit_reached";
    }
    return NULL;
}

static void king_inference_stream_gpu_policy_abort_state(king_inference_stream_object *stream)
{
    stream->cancelled = true;
    stream->timed_out = false;
    king_inference_stream_abort_state(stream);
}

static zval *king_inference_stream_gpu_policy_config(king_inference_stream_object *stream)
{
    king_inference_model_object *model;
    zval *gpu;

    if (stream == NULL || Z_ISUNDEF(stream->model) || Z_TYPE(stream->model) != IS_OBJECT) {
        return NULL;
    }

    model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    gpu = king_inference_array_find(&model->config, "gpu");
    return gpu != NULL && Z_TYPE_P(gpu) == IS_ARRAY ? gpu : NULL;
}

static void king_inference_stream_add_gpu_policy_config(
    king_inference_stream_object *stream,
    zval *return_value
) {
    king_inference_model_object *model = NULL;
    zval *gpu = king_inference_stream_gpu_policy_config(stream);
    zval *thermal = gpu != NULL ? king_inference_array_find(gpu, "thermal") : NULL;
    zval *power = gpu != NULL ? king_inference_array_find(gpu, "power") : NULL;
    zval *thermal_path = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_array_find(thermal, "sensor_path")
        : NULL;
    zval *thermal_command = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_array_find(thermal, "sensor_command")
        : NULL;
    zval *power_command = power != NULL && Z_TYPE_P(power) == IS_ARRAY
        ? king_inference_array_find(power, "sensor_command")
        : NULL;
    const char *thermal_path_string = thermal_path != NULL && Z_TYPE_P(thermal_path) == IS_STRING
        ? Z_STRVAL_P(thermal_path)
        : "";
    const char *thermal_command_string = thermal_command != NULL && Z_TYPE_P(thermal_command) == IS_STRING
        ? Z_STRVAL_P(thermal_command)
        : "";
    const char *power_command_string = power_command != NULL && Z_TYPE_P(power_command) == IS_STRING
        ? Z_STRVAL_P(power_command)
        : (
            king_high_perf_compute_ai_config.inference_gpu_power_sensor_command != NULL
                ? king_high_perf_compute_ai_config.inference_gpu_power_sensor_command
                : ""
        );
    const char *thermal_source = thermal_path_string[0] != '\0'
        ? "path"
        : (thermal_command_string[0] != '\0' ? "command" : "none");
    bool gpu_enabled = gpu != NULL && king_inference_bool_from_array(gpu, "enabled", false);
    double max_temperature_c = king_high_perf_compute_ai_config.inference_gpu_thermal_max_temperature_c;
    double max_watts = king_high_perf_compute_ai_config.inference_gpu_power_max_watts;
    zend_long thermal_interval = king_high_perf_compute_ai_config.inference_gpu_thermal_check_interval_sec;
    zend_long power_interval = king_high_perf_compute_ai_config.inference_gpu_power_check_interval_sec;

    if (stream != NULL && !Z_ISUNDEF(stream->model) && Z_TYPE(stream->model) == IS_OBJECT) {
        model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
        max_temperature_c = king_inference_gpu_thermal_max_temperature_c(&model->config);
        max_watts = king_inference_gpu_power_max_watts(&model->config);
        thermal_interval = king_inference_gpu_thermal_check_interval_seconds(&model->config);
        power_interval = king_inference_gpu_power_check_interval_seconds(&model->config);
    }

    add_assoc_bool(return_value, "gpu_policy_configured", gpu_enabled);
    add_assoc_string(return_value, "gpu_thermal_sensor_source", thermal_source);
    add_assoc_string(return_value, "gpu_thermal_sensor_path", thermal_path_string);
    add_assoc_string(return_value, "gpu_thermal_sensor_command", thermal_command_string);
    add_assoc_double(return_value, "gpu_thermal_max_temperature_c", max_temperature_c);
    add_assoc_long(return_value, "gpu_thermal_check_interval_seconds", thermal_interval);
    add_assoc_bool(return_value, "gpu_power_limit_enabled", max_watts > 0.0);
    add_assoc_string(return_value, "gpu_power_sensor_command", power_command_string);
    add_assoc_double(return_value, "gpu_power_max_watts", max_watts);
    add_assoc_long(return_value, "gpu_power_check_interval_seconds", power_interval);
}

static void king_inference_stream_add_gpu_policy_runtime_metrics(
    king_inference_stream_object *stream,
    zval *return_value
) {
    const char *abort_code = king_inference_stream_gpu_policy_abort_code(stream);

    add_assoc_bool(return_value, "gpu_guardrail_aborted", abort_code != NULL);
    if (abort_code != NULL) {
        add_assoc_string(return_value, "gpu_policy_abort_code", abort_code);
    } else {
        add_assoc_null(return_value, "gpu_policy_abort_code");
    }
    add_assoc_long(return_value, "gpu_policy_during_run_checks", stream->gpu_policy_during_run_checks);
    add_assoc_long(return_value, "gpu_policy_interval_skips", stream->gpu_policy_interval_skips);
    add_assoc_long(return_value, "gpu_thermal_runtime_checks", stream->gpu_thermal_runtime_checks);
    add_assoc_long(return_value, "gpu_power_runtime_checks", stream->gpu_power_runtime_checks);
}

static void king_inference_stream_add_gpu_policy_abort_metrics(
    king_inference_stream_object *stream,
    zval *return_value
) {
    add_assoc_bool(return_value, "gpu_thermal_aborted", stream->gpu_thermal_aborted);
    add_assoc_long(return_value, "gpu_thermal_abort_at", stream->gpu_thermal_abort_at);
    if (stream->gpu_thermal_aborted) {
        add_assoc_double(return_value, "gpu_thermal_abort_temperature_c", stream->gpu_thermal_abort_temperature_c);
        add_assoc_double(return_value, "gpu_thermal_abort_ceiling_c", stream->gpu_thermal_abort_ceiling_c);
    } else {
        add_assoc_null(return_value, "gpu_thermal_abort_temperature_c");
        add_assoc_null(return_value, "gpu_thermal_abort_ceiling_c");
    }

    add_assoc_bool(return_value, "gpu_power_aborted", stream->gpu_power_aborted);
    add_assoc_long(return_value, "gpu_power_abort_at", stream->gpu_power_abort_at);
    if (stream->gpu_power_aborted) {
        add_assoc_double(return_value, "gpu_power_abort_watts", stream->gpu_power_abort_watts);
        add_assoc_double(return_value, "gpu_power_abort_ceiling_watts", stream->gpu_power_abort_ceiling_watts);
    } else {
        add_assoc_null(return_value, "gpu_power_abort_watts");
        add_assoc_null(return_value, "gpu_power_abort_ceiling_watts");
    }
}

static void king_inference_stream_add_gpu_policy_preflight_metrics(
    king_inference_stream_object *stream,
    zval *return_value
) {
    add_assoc_bool(return_value, "gpu_thermal_preflight_checked", stream->gpu_thermal_preflight_checked);
    add_assoc_long(return_value, "gpu_thermal_preflight_at", stream->gpu_thermal_preflight_at);
    add_assoc_bool(
        return_value,
        "gpu_thermal_preflight_temperature_available",
        stream->gpu_thermal_preflight_temperature_available
    );
    if (stream->gpu_thermal_preflight_temperature_available) {
        add_assoc_double(
            return_value,
            "gpu_thermal_preflight_temperature_c",
            stream->gpu_thermal_preflight_temperature_c
        );
    } else {
        add_assoc_null(return_value, "gpu_thermal_preflight_temperature_c");
    }

    add_assoc_bool(return_value, "gpu_power_preflight_checked", stream->gpu_power_preflight_checked);
    add_assoc_long(return_value, "gpu_power_preflight_at", stream->gpu_power_preflight_at);
    add_assoc_bool(return_value, "gpu_power_preflight_available", stream->gpu_power_preflight_available);
    if (stream->gpu_power_preflight_available) {
        add_assoc_double(return_value, "gpu_power_preflight_watts", stream->gpu_power_preflight_watts);
    } else {
        add_assoc_null(return_value, "gpu_power_preflight_watts");
    }
}

static void king_inference_stream_add_gpu_policy_start_status(
    king_inference_stream_object *stream,
    zval *return_value
) {
    king_inference_stream_add_gpu_policy_config(stream, return_value);
    king_inference_stream_add_gpu_policy_preflight_metrics(stream, return_value);
}

static void king_inference_stream_add_gpu_policy_metrics(
    king_inference_stream_object *stream,
    zval *return_value
) {
    king_inference_stream_add_gpu_policy_config(stream, return_value);
    king_inference_stream_add_gpu_policy_runtime_metrics(stream, return_value);
    king_inference_stream_add_gpu_policy_abort_metrics(stream, return_value);
    king_inference_stream_add_gpu_policy_preflight_metrics(stream, return_value);
}
