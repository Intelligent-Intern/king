/* extension/include/server/websocket.h */

#ifndef KING_SERVER_WEBSOCKET_H
#define KING_SERVER_WEBSOCKET_H

#include <php.h>

/**
 * @file extension/include/server/websocket.h
 * @brief Server-side WebSocket helpers.
 */

/**
 * @brief Upgrades a request stream to local server-side WebSocket handling.
 *
 * @param session_resource The current session resource.
 * @param stream_id The stream that carried the request.
 * @return A local-only WebSocket resource on success, or NULL on failure.
 *
 * The v1 server-side upgrade path records upgrade metadata and supports local
 * close/status handling, but it does not expose real frame I/O until the
 * listener runtime grows a true on-wire upgrade path.
 */
PHP_FUNCTION(king_server_upgrade_to_websocket);

/**
 * @brief Sends a message on a WebSocket connection.
 *
 * @param websocket_connection_resource The WebSocket resource returned by
 * `king_server_upgrade_to_websocket`.
 * @param message_str The payload to send.
 * @param message_len The length of the message payload.
 * @param is_binary TRUE for a binary frame, FALSE for text.
 * @return TRUE on success, FALSE on failure. Server-side local upgrade
 * resources reject frame exchange in v1.
 */
PHP_FUNCTION(king_websocket_send);

#endif // KING_SERVER_WEBSOCKET_H
