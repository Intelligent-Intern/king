/* extension/include/server/http3.h */

#ifndef KING_SERVER_HTTP3_H
#define KING_SERVER_HTTP3_H

#include <php.h>

/**
 * @file extension/include/server/http3.h
 * @brief HTTP/3 server entry point.
 */

/**
 * @brief Starts an HTTP/3 server listener.
 *
 * @param host_str The host address or IP to bind.
 * @param host_len The length of the `host_str`.
 * @param port The UDP port to bind.
 * @param config_resource The `King\Config` resource for the listener.
 * @param request_handler_callable The PHP callback that handles requests.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_http3_server_listen);
PHP_FUNCTION(king_http3_server_listen_once);

#endif // KING_SERVER_HTTP3_H
