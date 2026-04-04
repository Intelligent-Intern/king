/*
 * =========================================================================
 * FILENAME:   src/config/open_telemetry/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the OpenTelemetry config family. This
 * file validates the `King\\Config` subset that can target either a
 * temporary config snapshot or the live module-global state and applies
 * bounded telemetry enablement, exporter, batching, sampler, metrics, and
 * logs overrides.
 * =========================================================================
 */

#include "include/config/open_telemetry/config.h"
#include "include/config/open_telemetry/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"
#include "include/validation/config_param/validate_generic_string.h"
#include "include/validation/config_param/validate_double_range.h"
#include "include/validation/config_param/validate_comma_separated_numeric_string.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

static int kg_open_telemetry_apply_bool_field(zval *value, const char *name, bool *target)
{
    if (kg_validate_bool(value, name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

static const char *k_open_telemetry_exporter_protocol_allowed[] = {"grpc", "http/protobuf", NULL};
static const char *k_open_telemetry_sampler_type_allowed[] = {"parent_based_probability", "always_on", "always_off", NULL};

static zend_result kg_open_telemetry_apply_validated_endpoint_field(
    zval *value,
    char **target
)
{
    const char *validation_error;

    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided. A string is required."
        );
        return FAILURE;
    }

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

    if (*target != NULL) {
        pefree(*target, 1);
    }
    *target = pestrdup(Z_STRVAL_P(value), 1);

    return SUCCESS;
}

static zend_result kg_open_telemetry_apply_validated_headers_field(
    zval *value,
    char **target
)
{
    const char *validation_error;

    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided. A string is required."
        );
        return FAILURE;
    }

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

    if (*target != NULL) {
        pefree(*target, 1);
    }
    *target = pestrdup(Z_STRVAL_P(value), 1);

    return SUCCESS;
}

int kg_config_open_telemetry_apply_userland_config_to(
    kg_open_telemetry_config_t *target,
    zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (target == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Configuration must be provided as an array."
        );
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "enable")) {
            if (kg_open_telemetry_apply_bool_field(value, "enable", &target->enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "service_name")) {
            if (kg_validate_generic_string(value, &target->service_name) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "exporter_endpoint")) {
            if (kg_open_telemetry_apply_validated_endpoint_field(
                    value,
                    &target->exporter_endpoint
                ) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "exporter_protocol")) {
            if (kg_validate_string_from_allowlist(
                    value,
                    k_open_telemetry_exporter_protocol_allowed,
                    &target->exporter_protocol) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "exporter_timeout_ms")) {
            if (kg_validate_positive_long(value, &target->exporter_timeout_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "exporter_headers")) {
            if (kg_open_telemetry_apply_validated_headers_field(
                    value,
                    &target->exporter_headers
                ) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "batch_processor_max_queue_size")) {
            if (kg_validate_positive_long(value, &target->batch_processor_max_queue_size) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "batch_processor_schedule_delay_ms")) {
            if (kg_validate_positive_long(value, &target->batch_processor_schedule_delay_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "traces_sampler_type")) {
            if (kg_validate_string_from_allowlist(
                    value,
                    k_open_telemetry_sampler_type_allowed,
                    &target->traces_sampler_type) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "traces_sampler_ratio")) {
            if (kg_validate_double_range(value, 0.0, 1.0, &target->traces_sampler_ratio) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "traces_max_attributes_per_span")) {
            if (kg_validate_positive_long(value, &target->traces_max_attributes_per_span) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "metrics_enable")) {
            if (kg_open_telemetry_apply_bool_field(value, "metrics_enable", &target->metrics_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "metrics_export_interval_ms")) {
            if (kg_validate_positive_long(value, &target->metrics_export_interval_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "metrics_default_histogram_boundaries")) {
            if (kg_validate_comma_separated_numeric_string(value, &target->metrics_default_histogram_boundaries) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "logs_enable")) {
            if (kg_open_telemetry_apply_bool_field(value, "logs_enable", &target->logs_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "logs_exporter_batch_size")) {
            if (kg_validate_positive_long(value, &target->logs_exporter_batch_size) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_open_telemetry_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Configuration override from userland is disabled by system administrator."
        );
        return FAILURE;
    }

    return kg_config_open_telemetry_apply_userland_config_to(
        &king_open_telemetry_config,
        config_arr
    );
}
