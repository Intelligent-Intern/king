#ifndef KING_CLIENT_WEBSOCKET_H
#define KING_CLIENT_WEBSOCKET_H

#include <php.h>

/**
 * @file extension/include/client/websocket.h
 * @brief Client-side WebSocket helpers.
 */

/**
 * @brief Materializes a validated WebSocket client handle.
 *
 * The active runtime validates the target URL, snapshots the effective
 * WebSocket defaults from the global/runtime config plus optional
 * `connection_config`, and returns a local `King\WebSocket` resource. It does
 * not yet perform an on-wire handshake, but the same local runtime now backs
 * send/receive/ping/status/close over that validated state.
 *
 * @param url_str The WebSocket URL.
 * @param url_len The length of `url_str`.
 * @param headers_array Optional handshake headers.
 * @param options_array Optional connection options, including a per-request
 * `King\Config` override.
 * @return A `King\WebSocket` resource on success, FALSE on failure.
 */
PHP_FUNCTION(king_client_websocket_connect);

/**
 * @brief Sends a WebSocket message.
 *
 * @param websocket_resource The WebSocket resource.
 * @param data_str The payload to send.
 * @param data_len The length of `data_str`.
 * @param is_binary TRUE for a binary frame, FALSE for a text frame.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_client_websocket_send);

/**
 * @brief Receives a WebSocket message.
 *
 * @param websocket_resource The WebSocket resource.
 * @param timeout_ms Wait timeout in milliseconds. `0` returns immediately,
 * `-1` waits indefinitely.
 * @return The next queued local payload string, an empty string when the
 * local queue is empty and the connection remains open, or FALSE on close or
 * error.
 */
PHP_FUNCTION(king_client_websocket_receive);

/**
 * @brief Sends a WebSocket PING frame.
 *
 * @param websocket_resource The WebSocket resource.
 * @param payload_str Optional ping payload.
 * @param payload_len The length of `payload_str`.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_client_websocket_ping);

/**
 * @brief Returns the current WebSocket state.
 *
 * @param websocket_resource The WebSocket resource.
 * @return A numeric status value.
 */
PHP_FUNCTION(king_client_websocket_get_status);

/**
 * @brief Returns the last shared WebSocket error message.
 *
 * @return The last error string, or an empty string if none is set.
 */
PHP_FUNCTION(king_client_websocket_get_last_error);

/**
 * @brief Closes a WebSocket connection.
 *
 * @param websocket_resource The WebSocket resource.
 * @param status_code Optional close status code.
 * @param reason_str Optional close reason.
 * @param reason_len The length of `reason_str`.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_client_websocket_close);


#endif // KING_CLIENT_WEBSOCKET_H
