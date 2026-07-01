static zend_result king_telemetry_config_apply_inline_array(
    king_telemetry_config_t *config,
    zval *config_arr)
{
    zval *value;
    const char *validation_error;

    if (config == NULL || config_arr == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        return SUCCESS;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(config_arr), "enabled", sizeof("enabled") - 1);
    if (value != NULL) {
        config->enabled = zend_is_true(value) ? 1 : 0;
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "otel_exporter_endpoint",
        sizeof("otel_exporter_endpoint") - 1
    );
    if (value != NULL && Z_TYPE_P(value) == IS_STRING) {
        validation_error = king_open_telemetry_validate_exporter_endpoint_value(
            Z_STRVAL_P(value),
            Z_STRLEN_P(value)
        );
        if (validation_error != NULL) {
            zend_throw_exception_ex(
                spl_ce_InvalidArgumentException,
                0,
                "%s",
                validation_error
            );
            return FAILURE;
        }

        snprintf(
            config->otel_exporter_endpoint,
            sizeof(config->otel_exporter_endpoint),
            "%s",
            Z_STRVAL_P(value)
        );
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "otel_exporter_headers",
        sizeof("otel_exporter_headers") - 1
    );
    if (value != NULL && Z_TYPE_P(value) == IS_STRING) {
        validation_error = king_open_telemetry_validate_exporter_headers_value(
            Z_STRVAL_P(value),
            Z_STRLEN_P(value)
        );
        if (validation_error != NULL) {
            zend_throw_exception_ex(
                spl_ce_InvalidArgumentException,
                0,
                "%s",
                validation_error
            );
            return FAILURE;
        }
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "otel_exporter_protocol",
        sizeof("otel_exporter_protocol") - 1
    );
    if (value != NULL && Z_TYPE_P(value) == IS_STRING) {
        snprintf(
            config->otel_exporter_protocol,
            sizeof(config->otel_exporter_protocol),
            "%s",
            Z_STRVAL_P(value)
        );
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "service_name",
        sizeof("service_name") - 1
    );
    if (value != NULL && Z_TYPE_P(value) == IS_STRING) {
        snprintf(
            config->service_name,
            sizeof(config->service_name),
            "%s",
            Z_STRVAL_P(value)
        );
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "traces_sampler_type",
        sizeof("traces_sampler_type") - 1
    );
    if (value != NULL
        && Z_TYPE_P(value) == IS_STRING
        && (
            strcmp(Z_STRVAL_P(value), "parent_based_probability") == 0
            || strcmp(Z_STRVAL_P(value), "always_on") == 0
            || strcmp(Z_STRVAL_P(value), "always_off") == 0
        )) {
        snprintf(
            config->traces_sampler_type,
            sizeof(config->traces_sampler_type),
            "%s",
            Z_STRVAL_P(value)
        );
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "traces_sampler_ratio",
        sizeof("traces_sampler_ratio") - 1
    );
    if (value != NULL) {
        double sampler_ratio = zval_get_double(value);

        if (sampler_ratio >= 0.0 && sampler_ratio <= 1.0) {
            config->traces_sampler_ratio = sampler_ratio;
            config->traces_sampler_ratio_configured = 1;
        }
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "batch_processor_max_queue_size",
        sizeof("batch_processor_max_queue_size") - 1
    );
    if (value != NULL) {
        zend_long queue_limit = zval_get_long(value);

        if (queue_limit > 0) {
            config->max_queue_size = queue_limit > UINT32_MAX
                ? UINT32_MAX
                : (uint32_t) queue_limit;
        }
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "logs_exporter_batch_size",
        sizeof("logs_exporter_batch_size") - 1
    );
    if (value != NULL) {
        zend_long batch_size = zval_get_long(value);

        if (batch_size > 0) {
            config->max_export_batch_size = batch_size > UINT32_MAX
                ? UINT32_MAX
                : (uint32_t) batch_size;
        }
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "exporter_timeout_ms",
        sizeof("exporter_timeout_ms") - 1
    );
    if (value != NULL) {
        zend_long timeout_ms = zval_get_long(value);

        if (timeout_ms > 0) {
            config->batch_timeout_ms = timeout_ms > UINT32_MAX
                ? UINT32_MAX
                : (uint32_t) timeout_ms;
        }
    }

    return SUCCESS;
}
