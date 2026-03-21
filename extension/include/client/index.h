#ifndef KING_CLIENT_INDEX_H
#define KING_CLIENT_INDEX_H

#include <php.h>

/**
 * @file extension/include/client/index.h
 * @brief Protocol-agnostic client request entry point.
 *
 * `king_client_send_request()` selects the best available HTTP transport
 * for the supplied request options and forwards the request to it.
 */

/**
 * @brief Sends an HTTP request using the configured client transport.
 *
 * The function uses the provided configuration and options to choose an HTTP
 * backend and returns the response in a normalized array.
 *
 * @param url_str The target URL for the request.
 * @param url_len The length of the `url_str`.
 * @param method_str The HTTP method. Defaults to "GET" if omitted.
 * @param method_len The length of the `method_str`.
 * @param headers_array Optional associative array of request headers.
 * @param body_zval Optional request body.
 * @param options_array Optional request options, including protocol
 * preferences, timeouts, and an optional per-request `King\Config` override.
 * @return A PHP array on success. Returns FALSE on failure and throws a
 * protocol-specific `King\Exception` subclass.
 */
PHP_FUNCTION(king_client_send_request);

#endif // KING_CLIENT_INDEX_H
