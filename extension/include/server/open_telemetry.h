/*
 * =========================================================================
 * FILENAME:   include/server/open_telemetry.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the local server-side telemetry control leaf and the shared
 * listener helpers that attach telemetry metadata plus incoming trace-context
 * extraction results to request/response snapshots in the active runtime.
 * =========================================================================
 */

#ifndef KING_SERVER_OPEN_TELEMETRY_H
#define KING_SERVER_OPEN_TELEMETRY_H

#include <php.h>
#include "include/client/session.h"

/* Validates telemetry config and records the resulting snapshot on a session. */
PHP_FUNCTION(king_server_init_telemetry);

void king_server_open_telemetry_add_request_metadata(
    zval *request,
    king_client_session_t *session
);

void king_server_open_telemetry_record_response(
    king_client_session_t *session,
    const char *protocol,
    zval *response
);

#endif /* KING_SERVER_OPEN_TELEMETRY_H */
