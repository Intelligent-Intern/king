/*
 * =========================================================================
 * FILENAME:   src/stubs/all_stubs.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Compatibility stubs for extension entry points that are not implemented
 * in the current skeleton build. After signature validation, each function
 * returns FALSE or a neutral value and records a stable
 * "not available in the skeleton build" error.
 *
 * Keep this file logic-free. If a function needs real behavior, move it to
 * the subsystem implementation and delete the stub here.
 * =========================================================================
 */

#include "php_king.h"
#include <stdio.h>

static void king_stub_set_unavailable_error(const char *function_name)
{
    char message[KING_ERR_LEN];

    snprintf(
        message,
        sizeof(message),
        "%s() is not available in the skeleton build.",
        function_name
    );

    king_set_error(message);
}

#define KING_STUB_RETURN_FALSE(function_name) \
    do { \
        king_stub_set_unavailable_error(function_name); \
        RETURN_FALSE; \
    } while (0)

#define KING_STUB_RETURN_NULL(function_name) \
    do { \
        king_stub_set_unavailable_error(function_name); \
        RETURN_NULL(); \
    } while (0)

/* =========================================================================
 * Client APIs
 * ========================================================================= */

/* =========================================================================
 * Server APIs
 * ========================================================================= */

/*
 * Target modules:
 * - king_session_get_peer_cert_subject -> src/server/session.c
 * - king_session_close_server_initiated -> src/server/session.c
 */
/* =========================================================================
 * IIBIN Serialization
 * =========================================================================
 * These entry points are exposed as king_proto_* in the C layer.
 * ========================================================================= */


/* =========================================================================
 * Pipeline Orchestrator
 * ========================================================================= */

/*
 * Pipeline Orchestrator functions moved to src/pipeline_orchestrator/
 */

/* =========================================================================
 * Semantic DNS
 * ========================================================================= */

/* =========================================================================
 * Object Store and CDN
 * ========================================================================= */

/* =========================================================================
 * Telemetry
 * ========================================================================= */

/*
 * Target modules:
 * - king_telemetry_init -> src/telemetry/telemetry.c
 * - king_telemetry_start_span -> src/telemetry/telemetry.c
 * - king_telemetry_end_span -> src/telemetry/telemetry.c
 * - king_telemetry_record_metric -> src/telemetry/metrics.c
 * - king_telemetry_log -> src/telemetry/telemetry.c
 */
PHP_FUNCTION(king_telemetry_init)
{
    zval *config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_telemetry_init");
}

PHP_FUNCTION(king_telemetry_start_span)
{
    char *operation_name = NULL;
    char *parent_span_id = NULL;
    size_t operation_name_len = 0;
    size_t parent_span_id_len = 0;
    zval *attributes = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(operation_name, operation_name_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(attributes)
        Z_PARAM_STRING_OR_NULL(parent_span_id, parent_span_id_len)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_telemetry_start_span");
}

PHP_FUNCTION(king_telemetry_end_span)
{
    char *span_id = NULL;
    size_t span_id_len = 0;
    zval *final_attributes = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STRING(span_id, span_id_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(final_attributes)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_telemetry_end_span");
}

PHP_FUNCTION(king_telemetry_record_metric)
{
    char *metric_name = NULL;
    char *metric_type = NULL;
    size_t metric_name_len = 0;
    size_t metric_type_len = 0;
    double value;
    zval *labels = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 4)
        Z_PARAM_STRING(metric_name, metric_name_len)
        Z_PARAM_DOUBLE(value)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(labels)
        Z_PARAM_STRING(metric_type, metric_type_len)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_telemetry_record_metric");
}

PHP_FUNCTION(king_telemetry_log)
{
    char *level = NULL;
    char *message = NULL;
    size_t level_len = 0;
    size_t message_len = 0;
    zval *attributes = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(level, level_len)
        Z_PARAM_STRING(message, message_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(attributes)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_telemetry_log");
}

/* =========================================================================
 * Autoscaling
 * ========================================================================= */

/*
 * Target modules:
 * - king_autoscaling_init -> src/autoscaling/autoscaling.c
 * - king_autoscaling_start_monitoring -> src/autoscaling/autoscaling.c
 * - king_autoscaling_stop_monitoring -> src/autoscaling/autoscaling.c
 * - king_autoscaling_scale_up -> src/autoscaling/provisioning.c
 * - king_autoscaling_scale_down -> src/autoscaling/provisioning.c
 */
PHP_FUNCTION(king_autoscaling_init)
{
    zval *config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_autoscaling_init");
}

PHP_FUNCTION(king_autoscaling_start_monitoring)
{
    ZEND_PARSE_PARAMETERS_NONE();

    KING_STUB_RETURN_FALSE("king_autoscaling_start_monitoring");
}

PHP_FUNCTION(king_autoscaling_stop_monitoring)
{
    ZEND_PARSE_PARAMETERS_NONE();

    KING_STUB_RETURN_FALSE("king_autoscaling_stop_monitoring");
}

PHP_FUNCTION(king_autoscaling_scale_up)
{
    zend_long instances = 1;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(instances)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_autoscaling_scale_up");
}

PHP_FUNCTION(king_autoscaling_scale_down)
{
    zend_long instances = 1;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(instances)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_autoscaling_scale_down");
}

/* =========================================================================
 * System Integration
 * ========================================================================= */

/*
 * Target modules:
 * - king_system_init -> src/integration/system_integration.c
 * - king_system_process_request -> src/integration/system_integration.c
 * - king_system_restart_component -> src/integration/system_integration.c
 * - king_system_shutdown -> src/integration/system_integration.c
 */
PHP_FUNCTION(king_system_init)
{
    zval *config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_system_init");
}

PHP_FUNCTION(king_system_process_request)
{
    zval *request_data;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(request_data)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_system_process_request");
}

PHP_FUNCTION(king_system_restart_component)
{
    char *component_name = NULL;
    size_t component_name_len = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(component_name, component_name_len)
    ZEND_PARSE_PARAMETERS_END();

    KING_STUB_RETURN_FALSE("king_system_restart_component");
}

PHP_FUNCTION(king_system_shutdown)
{
    ZEND_PARSE_PARAMETERS_NONE();

    KING_STUB_RETURN_FALSE("king_system_shutdown");
}
