static bool king_inference_gpu_enabled(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");

    return gpu != NULL
        && Z_TYPE_P(gpu) == IS_ARRAY
        && king_inference_bool_from_array(gpu, "enabled", false);
}

static zend_long king_inference_max_gpu_layers(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");

    if (gpu == NULL || Z_TYPE_P(gpu) != IS_ARRAY) {
        return 0;
    }

    return king_inference_long_from_array(gpu, "max_gpu_layers", 0);
}

static zend_result king_inference_validate_gpu_bool_field(
    zval *config,
    const char *field_name,
    const char *qualified_name,
    const char *function_name
) {
    zval *value = king_inference_array_find(config, field_name);

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a boolean when provided.",
            function_name,
            qualified_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_validate_gpu_non_negative_long_field(
    zval *config,
    const char *field_name,
    const char *qualified_name,
    const char *function_name
) {
    zval *value = king_inference_array_find(config, field_name);

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a non-negative integer when provided.",
            function_name,
            qualified_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_validate_gpu_positive_number_field(
    zval *config,
    const char *field_name,
    const char *qualified_name,
    const char *function_name
) {
    zval *value = king_inference_array_find(config, field_name);
    double number;

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) == IS_LONG) {
        number = (double) Z_LVAL_P(value);
    } else if (Z_TYPE_P(value) == IS_DOUBLE) {
        number = Z_DVAL_P(value);
    } else {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a positive finite number when provided.",
            function_name,
            qualified_name
        );
        return FAILURE;
    }

    if (!isfinite(number) || number <= 0.0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a positive finite number when provided.",
            function_name,
            qualified_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_validate_gpu_string_field(
    zval *config,
    const char *field_name,
    const char *qualified_name,
    const char *function_name
) {
    zval *value = king_inference_array_find(config, field_name);

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a non-empty string when provided.",
            function_name,
            qualified_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_validate_gpu_config(zval *config, const char *function_name)
{
    zval *gpu = king_inference_array_find(config, "gpu");
    zval *thermal;

    if (gpu == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(gpu) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() gpu must be an array when provided.",
            function_name
        );
        return FAILURE;
    }

    if (king_inference_validate_gpu_bool_field(gpu, "enabled", "gpu.enabled", function_name) != SUCCESS
        || king_inference_validate_gpu_non_negative_long_field(
            gpu,
            "max_gpu_layers",
            "gpu.max_gpu_layers",
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    thermal = king_inference_array_find(gpu, "thermal");
    if (thermal == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(thermal) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() gpu.thermal must be an array when provided.",
            function_name
        );
        return FAILURE;
    }

    if (king_inference_validate_gpu_string_field(
            thermal,
            "sensor_path",
            "gpu.thermal.sensor_path",
            function_name
        ) != SUCCESS
        || king_inference_validate_gpu_string_field(
            thermal,
            "sensor_command",
            "gpu.thermal.sensor_command",
            function_name
        ) != SUCCESS
        || king_inference_validate_gpu_positive_number_field(
            thermal,
            "max_temperature_c",
            "gpu.thermal.max_temperature_c",
            function_name
        ) != SUCCESS
        || king_inference_validate_gpu_bool_field(
            thermal,
            "allow_unmonitored_gpu",
            "gpu.thermal.allow_unmonitored_gpu",
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}
