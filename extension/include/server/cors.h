/*
 * =========================================================================
 * FILENAME:   include/server/cors.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Internal local-listener CORS helpers for the active runtime. The
 * current slice materializes config-backed CORS request metadata and applies
 * deterministic wildcard response defaults on the local server runtime.
 * =========================================================================
 */

#ifndef KING_SERVER_CORS_H
#define KING_SERVER_CORS_H

#include <php.h>
#include "include/client/session.h"

void king_server_cors_add_request_metadata(
    zval *request,
    king_client_session_t *session
);

void king_server_cors_apply_response(
    zval *response,
    king_client_session_t *session
);

#endif /* KING_SERVER_CORS_H */
