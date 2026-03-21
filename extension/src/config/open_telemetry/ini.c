#include "include/config/open_telemetry/ini.h"
#include "include/config/open_telemetry/base_layer.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>
#include <zend_ini.h>
#include <strings.h>

/* INI strings live in persistent module storage, so replace them manually. */
static void otel_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(ZSTR_VAL(value), 1);
}

static ZEND_INI_MH(OnUpdateOtelPositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (val <= 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for an OpenTelemetry directive. A positive integer is required."
        );
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.otel_exporter_timeout_ms")) {
        king_open_telemetry_config.exporter_timeout_ms = val;
    } else if (zend_string_equals_literal(entry->name, "king.otel_batch_processor_max_queue_size")) {
        king_open_telemetry_config.batch_processor_max_queue_size = val;
    } else if (zend_string_equals_literal(entry->name, "king.otel_batch_processor_schedule_delay_ms")) {
        king_open_telemetry_config.batch_processor_schedule_delay_ms = val;
    } else if (zend_string_equals_literal(entry->name, "king.otel_traces_max_attributes_per_span")) {
        king_open_telemetry_config.traces_max_attributes_per_span = val;
    } else if (zend_string_equals_literal(entry->name, "king.otel_metrics_export_interval_ms")) {
        king_open_telemetry_config.metrics_export_interval_ms = val;
    } else if (zend_string_equals_literal(entry->name, "king.otel_logs_exporter_batch_size")) {
        king_open_telemetry_config.logs_exporter_batch_size = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateOtelSamplerRatio)
{
    double val = zend_strtod(ZSTR_VAL(new_value), NULL);
    if (val < 0.0 || val > 1.0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for sampler ratio. A float between 0.0 and 1.0 is required."
        );
        return FAILURE;
    }
    king_open_telemetry_config.traces_sampler_ratio = val;
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateOtelAllowlist)
{
    const char *protocol_allowed[] = {"grpc", "http/protobuf", NULL};
    const char *sampler_allowed[] = {"parent_based_probability", "always_on", "always_off", NULL};
    const char **current_list = NULL;

    if (zend_string_equals_literal(entry->name, "king.otel_exporter_protocol")) {
        current_list = protocol_allowed;
    } else if (zend_string_equals_literal(entry->name, "king.otel_traces_sampler_type")) {
        current_list = sampler_allowed;
    }

    bool is_allowed = false;
    for (int i = 0; current_list[i] != NULL; i++) {
        if (strcasecmp(ZSTR_VAL(new_value), current_list[i]) == 0) {
            is_allowed = true;
            break;
        }
    }

    if (!is_allowed) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided. The value is not one of the allowed options."
        );
        return FAILURE;
    }
    if (zend_string_equals_literal(entry->name, "king.otel_exporter_protocol")) {
        otel_replace_string(&king_open_telemetry_config.exporter_protocol, new_value);
    } else if (zend_string_equals_literal(entry->name, "king.otel_traces_sampler_type")) {
        otel_replace_string(&king_open_telemetry_config.traces_sampler_type, new_value);
    }
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateOtelHistogramBoundaries)
{
    const char *s = ZSTR_VAL(new_value);
    const char *p = s;
    bool saw_digit = false;

    /* The parser is intentionally strict: only comma-separated numeric tokens are allowed. */
    while (*p) {
        if (*p == ',') {
            if (!saw_digit) {
                zend_throw_exception_ex(
                    spl_ce_InvalidArgumentException,
                    0,
                    "Invalid value for histogram boundaries. Expected a comma-separated list of numeric values."
                );
                return FAILURE;
            }
            saw_digit = false;
        } else if ((*p >= '0' && *p <= '9') || *p == '.' || *p == '-' || *p == '+') {
            saw_digit = true;
        } else {
            zend_throw_exception_ex(
                spl_ce_InvalidArgumentException,
                0,
                "Invalid value for histogram boundaries. Expected a comma-separated list of numeric values."
            );
            return FAILURE;
        }
        p++;
    }

    if (!saw_digit && ZSTR_LEN(new_value) > 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for histogram boundaries. Expected a comma-separated list of numeric values."
        );
        return FAILURE;
    }

    otel_replace_string(&king_open_telemetry_config.metrics_default_histogram_boundaries, new_value);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.otel_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, enable, kg_open_telemetry_config_t, king_open_telemetry_config)
    STD_PHP_INI_ENTRY("king.otel_service_name", "king_application", PHP_INI_SYSTEM, OnUpdateString, service_name, kg_open_telemetry_config_t, king_open_telemetry_config)
    STD_PHP_INI_ENTRY("king.otel_exporter_endpoint", "http://localhost:4317", PHP_INI_SYSTEM, OnUpdateString, exporter_endpoint, kg_open_telemetry_config_t, king_open_telemetry_config)
    ZEND_INI_ENTRY("king.otel_exporter_protocol", "grpc", PHP_INI_SYSTEM, OnUpdateOtelAllowlist)
    ZEND_INI_ENTRY("king.otel_exporter_timeout_ms", "10000", PHP_INI_SYSTEM, OnUpdateOtelPositiveLong)
    STD_PHP_INI_ENTRY("king.otel_exporter_headers", "", PHP_INI_SYSTEM, OnUpdateString, exporter_headers, kg_open_telemetry_config_t, king_open_telemetry_config)
    ZEND_INI_ENTRY("king.otel_batch_processor_max_queue_size", "2048", PHP_INI_SYSTEM, OnUpdateOtelPositiveLong)
    ZEND_INI_ENTRY("king.otel_batch_processor_schedule_delay_ms", "5000", PHP_INI_SYSTEM, OnUpdateOtelPositiveLong)
    ZEND_INI_ENTRY("king.otel_traces_sampler_type", "parent_based_probability", PHP_INI_SYSTEM, OnUpdateOtelAllowlist)
    ZEND_INI_ENTRY("king.otel_traces_sampler_ratio", "1.0", PHP_INI_SYSTEM, OnUpdateOtelSamplerRatio)
    ZEND_INI_ENTRY("king.otel_traces_max_attributes_per_span", "128", PHP_INI_SYSTEM, OnUpdateOtelPositiveLong)
    STD_PHP_INI_ENTRY("king.otel_metrics_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, metrics_enable, kg_open_telemetry_config_t, king_open_telemetry_config)
    ZEND_INI_ENTRY("king.otel_metrics_export_interval_ms", "60000", PHP_INI_SYSTEM, OnUpdateOtelPositiveLong)
    ZEND_INI_ENTRY("king.otel_metrics_default_histogram_boundaries", "0,5,10,25,50,75,100,250,500,1000", PHP_INI_SYSTEM, OnUpdateOtelHistogramBoundaries)
    STD_PHP_INI_ENTRY("king.otel_logs_enable", "0", PHP_INI_SYSTEM, OnUpdateBool, logs_enable, kg_open_telemetry_config_t, king_open_telemetry_config)
    ZEND_INI_ENTRY("king.otel_logs_exporter_batch_size", "512", PHP_INI_SYSTEM, OnUpdateOtelPositiveLong)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_open_telemetry_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_open_telemetry_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
