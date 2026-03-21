/* extension/include/server/websocket.h */

#ifndef KING_SERVER_WEBSOCKET_H
#define KING_SERVER_WEBSOCKET_H

#include <php.h>

/**
 * @file extension/include/server/websocket.h
 * @brief Server-side WebSocket helpers.
 */

/**
 * @brief Upgrades a request stream to WebSocket handling.
 *
 * @param session_resource The current session resource.
 * @param stream_id The stream that carried the request.
 * @return A WebSocket resource on success, or NULL on failure.
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
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_websocket_send);

#endif // KING_SERVER_WEBSOCKET_H
