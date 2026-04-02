#ifndef KING_SERVER_INDEX_H
#define KING_SERVER_INDEX_H

#include <php.h>

/**
 * @file extension/include/server/index.h
 * @brief Unified local server dispatcher entry point.
 */

/**
 * @brief Selects the primary local server leaf from the active config snapshot.
 *
 * The current dispatcher resolves config first and then chooses HTTP/3 when
 * `tcp.enable` is disabled, otherwise HTTP/2 when `http2.enable` is set, and
 * otherwise HTTP/1.
 */
PHP_FUNCTION(king_server_listen);

#endif // KING_SERVER_INDEX_H
