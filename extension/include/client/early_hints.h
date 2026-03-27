#ifndef KING_CLIENT_EARLY_HINTS_H
#define KING_CLIENT_EARLY_HINTS_H

#include <php.h>

typedef struct _king_http1_request_context king_http1_request_context;

/**
 * @file extension/include/client/early_hints.h
 * @brief Client-side HTTP 103 Early Hints handling.
 */

/**
 * @brief Processes an HTTP 103-style header array for an in-flight request.
 *
 * @param request_context_resource The `King\HttpRequestContext` resource.
 * @param headers_array The received header list. `Link` values are parsed and
 *        stored as pending hints on the request context.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_client_early_hints_process);

/**
 * @brief Returns the parsed pending Early Hints for a request context.
 *
 * @param request_context_resource The `King\HttpRequestContext` resource.
 * @return The parsed hint list, or an empty array if none were received.
 */
PHP_FUNCTION(king_client_early_hints_get_pending);

zend_result king_client_early_hints_process_headers(
    king_http1_request_context *context,
    zval *headers,
    const char *function_name
);

#endif // KING_CLIENT_EARLY_HINTS_H
