#ifndef KING_CLIENT_WEBSOCKET_H
#define KING_CLIENT_WEBSOCKET_H

#include <php.h>

typedef struct _king_ws_state king_ws_state;

/**
 * @file extension/include/client/websocket.h
 * @brief Client-side WebSocket helpers.
 */

/**
 * @brief Materializes a validated WebSocket client handle.
 *
 * The active runtime validates the target URL, snapshots the effective
 * WebSocket defaults from the global/runtime config plus optional
 * `connection_config`, performs a real client handshake, and returns a
 * `King\WebSocket` resource.
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
 * @return The next queued payload string, an empty string when the queue is
 * empty and the connection remains open, or FALSE on close or error.
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

/**
 * @brief Shared frame-send helper for OO and server-owned websocket handles.
 *
 * Validates the active connection state, enforces the configured payload cap,
 * writes one text or binary frame, and throws the public exception class that
 * matches the current failure.
 *
 * @param state Active websocket runtime.
 * @param payload Message payload.
 * @param is_binary TRUE for a binary frame, FALSE for text.
 * @param function_name Error-label prefix.
 * @return SUCCESS on success, FAILURE after throwing.
 */
zend_result king_websocket_state_send(
    king_ws_state *state,
    zend_string *payload,
    bool is_binary,
    const char *function_name
);

/**
 * @brief Closes one live websocket state with a validated close code/reason.
 *
 * Stores the exported close metadata on the runtime, writes one close frame
 * when a transport is still active, drains the peer response briefly, and
 * force-marks the transport closed when the write side is already broken.
 *
 * @param state Active websocket runtime.
 * @param status_code Validated close status code.
 * @param reason Optional close reason.
 * @param function_name Error-label prefix.
 * @return SUCCESS on success, FAILURE with `king_get_error()` populated.
 */
zend_result king_websocket_state_close(
    king_ws_state *state,
    zend_long status_code,
    zend_string *reason,
    const char *function_name
);

/**
 * @brief Builds the public websocket info array for one live state.
 *
 * @param return_value Target PHP array.
 * @param state Active websocket runtime.
 */
void king_websocket_state_build_info_array(
    zval *return_value,
    king_ws_state *state
);

/**
 * @brief Low-level frame send for broadcast scatter.
 *
 * Writes a WebSocket frame (header + payload) without re-encoding.
 *
 * @param state Active websocket runtime.
 * @param opcode Frame opcode (e.g., KING_WS_OPCODE_BINARY).
 * @param payload Raw payload bytes.
 * @param payload_len Length of payload.
 * @param function_name Error-label prefix.
 * @return SUCCESS on success, FAILURE on error.
 */
zend_result king_websocket_send_frame(
    king_ws_state *state,
    unsigned char opcode,
    const unsigned char *payload,
    size_t payload_len,
    const char *function_name
);

/* WebSocket opcode constants */
#define KING_WS_OPCODE_TEXT 0x1
#define KING_WS_OPCODE_BINARY 0x2
#define KING_WS_OPCODE_CLOSE 0x8
#define KING_WS_OPCODE_PING 0x9
#define KING_WS_OPCODE_PONG 0xA


#endif // KING_CLIENT_WEBSOCKET_H
