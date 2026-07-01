/*
 * GPU runtime admission readiness helpers.
 */

static bool king_inference_gpu_runtime_thermal_ready(zval *source)
{
    zval *thermal = zend_hash_str_find(Z_ARRVAL_P(source), "thermal", sizeof("thermal") - 1);
    zval *monitored;
    zval *allow_unmonitored;
    zval *temperature_available;
    zval *temperature_ok;

    if (thermal == NULL || Z_TYPE_P(thermal) != IS_ARRAY) {
        return false;
    }

    monitored = zend_hash_str_find(Z_ARRVAL_P(thermal), "monitored", sizeof("monitored") - 1);
    allow_unmonitored = zend_hash_str_find(
        Z_ARRVAL_P(thermal),
        "allow_unmonitored_gpu",
        sizeof("allow_unmonitored_gpu") - 1
    );
    temperature_available = zend_hash_str_find(
        Z_ARRVAL_P(thermal),
        "temperature_available",
        sizeof("temperature_available") - 1
    );
    temperature_ok = zend_hash_str_find(
        Z_ARRVAL_P(thermal),
        "temperature_ok",
        sizeof("temperature_ok") - 1
    );

    return (monitored != NULL && zend_is_true(monitored)
            && temperature_available != NULL && zend_is_true(temperature_available)
            && temperature_ok != NULL && zend_is_true(temperature_ok))
        || ((monitored == NULL || !zend_is_true(monitored))
            && allow_unmonitored != NULL && zend_is_true(allow_unmonitored));
}

static bool king_inference_gpu_runtime_power_ready(zval *source)
{
    zval *power = zend_hash_str_find(Z_ARRVAL_P(source), "power", sizeof("power") - 1);
    zval *enabled;
    zval *power_ok;

    if (power == NULL || Z_TYPE_P(power) != IS_ARRAY) {
        return true;
    }

    enabled = zend_hash_str_find(Z_ARRVAL_P(power), "enabled", sizeof("enabled") - 1);
    if (enabled == NULL || !zend_is_true(enabled)) {
        return true;
    }

    power_ok = zend_hash_str_find(Z_ARRVAL_P(power), "power_ok", sizeof("power_ok") - 1);
    return power_ok != NULL && zend_is_true(power_ok);
}

static bool king_inference_gpu_runtime_config_ready_after_vram(
    zval *return_value,
    bool fits_free
) {
    return king_inference_gpu_runtime_bool_field(return_value, "gpu_enabled")
        && king_inference_gpu_runtime_bool_field(return_value, "process_gpu_bindings_enable")
        && king_inference_gpu_runtime_bool_field(return_value, "config_gpu_bindings_enable")
        && king_inference_gpu_runtime_bool_field(return_value, "backend_supported")
        && king_inference_gpu_runtime_bool_field(return_value, "artifact_configured")
        && king_inference_gpu_runtime_bool_field(return_value, "artifact_readable")
        && king_inference_gpu_runtime_long_field(return_value, "max_gpu_layers") > 0
        && king_inference_gpu_runtime_bool_field(return_value, "vram_admission_checked")
        && king_inference_gpu_runtime_bool_field(return_value, "free_vram_floor_met")
        && fits_free
        && king_inference_gpu_runtime_bool_field(return_value, "driver_visible")
        && king_inference_gpu_runtime_thermal_ready(return_value)
        && king_inference_gpu_runtime_power_ready(return_value);
}
