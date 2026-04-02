#ifndef KING_SERVER_HTTP1_H
#define KING_SERVER_HTTP1_H

#include <php.h>

/**
 * @file extension/include/server/http1.h
 * @brief HTTP/1 local server leaves.
 */

/**
 * @brief Runs the local HTTP/1 single-dispatch server leaf.
 */
PHP_FUNCTION(king_http1_server_listen);

/**
 * @brief Starts a one-shot on-wire HTTP/1.1 listener.
 *
 * Binds a real TCP socket, accepts exactly one request, invokes the handler,
 * writes one HTTP/1 response when no websocket upgrade takes ownership, and
 * then tears the listener back down. This is the narrow v1 leaf used for true
 * server-side websocket upgrade coverage without claiming a long-lived HTTP
 * server runtime.
 */
PHP_FUNCTION(king_http1_server_listen_once);

#endif // KING_SERVER_HTTP1_H
