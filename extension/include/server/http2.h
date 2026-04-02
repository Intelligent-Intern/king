/* extension/include/server/http2.h */

#ifndef KING_SERVER_HTTP2_H
#define KING_SERVER_HTTP2_H

#include <php.h>

/**
 * @file extension/include/server/http2.h
 * @brief HTTP/2 local server leaves.
 */

/**
 * @brief Runs the local HTTP/2-style single-dispatch server leaf.
 */
PHP_FUNCTION(king_http2_server_listen);

/**
 * @brief Runs the on-wire one-shot h2c leaf.
 *
 * Accepts one real HTTP/2 connection, reads one request, waits for the full
 * DATA body, sends one response plus `GOAWAY`, drains the remaining protocol
 * tail, and then closes the connection.
 */
PHP_FUNCTION(king_http2_server_listen_once);

#endif // KING_SERVER_HTTP2_H
