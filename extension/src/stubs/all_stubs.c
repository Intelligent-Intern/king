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
 * Telemetry functions moved to src/telemetry/
 */

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
