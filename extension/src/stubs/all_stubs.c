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
 * Autoscaling functions moved to src/autoscaling/
 */

/* =========================================================================
 * System Integration
 * ========================================================================= */

/*
 * System Integration functions moved to src/integration/
 */
