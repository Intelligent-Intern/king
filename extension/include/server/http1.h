#ifndef KING_SERVER_HTTP1_H
#define KING_SERVER_HTTP1_H

#include <php.h>

/**
 * @file extension/include/server/http1.h
 * @brief HTTP/1.1 server entry point.
 */

/**
 * @brief Starts an HTTP/1.1 server listener.
 *
 * @param host_str The host address or IP to bind.
 * @param host_len The length of the `host_str`.
 * @param port The port to bind.
 * @param config_resource The `King\Config` resource for the listener.
 * @param request_handler_callable The PHP callback that handles requests.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_http1_server_listen);

#endif // KING_SERVER_HTTP1_H
