#ifndef KING_CLIENT_HTTP1_H
#define KING_CLIENT_HTTP1_H

#include <php.h>

/**
 * @file extension/include/client/http1.h
 * @brief HTTP/1.1 client entry point.
 */

zend_result king_http1_request_dispatch(
    zval *return_value,
    const char *url_str,
    size_t url_len,
    const char *method_str,
    size_t method_len,
    zval *headers_array,
    zval *body_zval,
    zval *options_array,
    const char *function_name
);

/**
 * @brief Sends an HTTP/1.1 request.
 *
 * @param url_str The target URL.
 * @param url_len The length of the `url_str`.
 * @param method_str The HTTP method. Defaults to `GET`.
 * @param method_len The length of the `method_str`.
 * @param headers_array Optional request headers.
 * @param body_zval Optional request body.
 * @param options_array Optional transport and timeout options.
 * @return A PHP array on success, FALSE on failure.
 */
PHP_FUNCTION(king_http1_request_send);

#endif // KING_CLIENT_HTTP1_H
