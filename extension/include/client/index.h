#ifndef KING_CLIENT_INDEX_H
#define KING_CLIENT_INDEX_H

#include <php.h>
#include "cancel.h"
#include "class_entries.h"
#include "early_hints.h"
#include "http1.h"
#include "http2.h"
#include "http3.h"
#include "legacy.h"
#include "objects.h"
#include "registration.h"
#include "resource_ids.h"
#include "session.h"
#include "tls.h"
#include "websocket.h"

/**
 * @file extension/include/client/index.h
 * @brief Public umbrella header and protocol-agnostic client request entry point.
 *
 * `king_client_send_request()` is the general client-facing dispatcher. In the
 * current runtime it defaults to the live HTTP/1 path and can force the active
 * HTTP/2 or HTTP/3 leaves via `options['preferred_protocol']`.
 */

/**
 * @brief Sends an HTTP request using the configured client transport.
 *
 * The function uses the provided options to select the active request leaf and
 * returns the normalized response shape used across the client runtime.
 *
 * @param url_str The target URL for the request.
 * @param url_len The length of the `url_str`.
 * @param method_str The HTTP method. Defaults to "GET" if omitted.
 * @param method_len The length of the `method_str`.
 * @param headers_array Optional associative array of request headers.
 * @param body_zval Optional request body.
 * @param options_array Optional request options, including
 * `preferred_protocol`, timeouts, and an optional per-request `King\Config`
 * override. `response_stream` remains an HTTP/1-only mode in the current
 * runtime.
 * @return A PHP array on success. Returns FALSE on failure and throws a
 * protocol-specific `King\Exception` subclass.
 */
PHP_FUNCTION(king_client_send_request);
PHP_FUNCTION(king_client_send_request_async);

#endif // KING_CLIENT_INDEX_H
