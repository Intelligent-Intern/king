/* extension/include/server/http3.h */

#ifndef KING_SERVER_HTTP3_H
#define KING_SERVER_HTTP3_H

#include <php.h>

/**
 * @file extension/include/server/http3.h
 * @brief HTTP/3 local server leaves.
 */

/**
 * @brief Runs the local HTTP/3-style single-dispatch server leaf.
 */
PHP_FUNCTION(king_http3_server_listen);

/**
 * @brief Runs the on-wire one-shot QUIC/HTTP/3 leaf.
 *
 * Accepts one real client flow, waits for the full request body, writes one
 * response, sends `GOAWAY`, drains the remaining protocol traffic, and then
 * releases the UDP port so a fresh listener can restart cleanly.
 */
PHP_FUNCTION(king_http3_server_listen_once);

#endif // KING_SERVER_HTTP3_H
