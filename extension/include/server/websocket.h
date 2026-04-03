/* extension/include/server/websocket.h */

#ifndef KING_SERVER_WEBSOCKET_H
#define KING_SERVER_WEBSOCKET_H

#include <php.h>

typedef struct _king_client_session king_client_session_t;

/**
 * @file extension/include/server/websocket.h
 * @brief Server-side WebSocket helpers.
 */

/**
 * @brief Upgrades one server stream to server-side WebSocket handling.
 *
 * @param session_resource The current session resource.
 * @param stream_id The stream that carried the request.
 * @return A WebSocket resource on success, or NULL on failure.
 *
 * Local listener leaves now surface an in-process bidirectional frame
 * resource. The one-shot on-wire HTTP/1 listener leaf upgrades real websocket
 * requests and attaches socket-backed frame I/O for the callback lifetime.
 */
PHP_FUNCTION(king_server_upgrade_to_websocket);

/**
 * @brief Core server-side upgrade helper shared by procedural and OO APIs.
 *
 * @param session Active server session snapshot.
 * @param stream_id Stream id to mark upgraded on the session.
 * @param request_headers Optional parsed request headers array for the
 * resulting websocket info surface.
 * @param return_value Resource zval to receive the `King\WebSocket` handle.
 * @param function_name Error-label prefix.
 * @return SUCCESS on success, FAILURE on validation or transport failure.
 */
zend_result king_server_websocket_upgrade_session(
    king_client_session_t *session,
    zend_long stream_id,
    zval *request_headers,
    zval *return_value,
    const char *function_name
);

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
