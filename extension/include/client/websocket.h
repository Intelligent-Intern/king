#ifndef KING_CLIENT_WEBSOCKET_H
#define KING_CLIENT_WEBSOCKET_H

#include <php.h>
#include <main/php_streams.h>
#include <zend_object_handlers.h>
#include <stdbool.h>

typedef struct _king_ws_server_object king_ws_server_object;

typedef enum _king_ws_connection_state {
    KING_WS_STATE_CONNECTING = 0,
    KING_WS_STATE_OPEN = 1,
    KING_WS_STATE_CLOSING = 2,
    KING_WS_STATE_CLOSED = 3
} king_ws_connection_state_t;

typedef struct _king_ws_message {
    zend_string *payload;
    bool is_binary;
    struct _king_ws_message *next;
} king_ws_message;

typedef struct _king_ws_state {
    zend_string *url;
    zend_string *connection_id;
    zend_string *scheme;
    zend_string *host;
    zend_string *request_target;
    php_stream *transport_stream;
    zval config;
    zval headers;
    zend_long port;
    zend_long max_payload_size;
    zend_long max_queued_messages;
    zend_long max_queued_bytes;
    zend_long queued_message_count;
    zend_long queued_bytes;
    zend_long ping_interval_ms;
    zend_long handshake_timeout_ms;
    zend_long last_close_status_code;
    king_ws_connection_state_t state;
    king_ws_message *incoming_head;
    king_ws_message *incoming_tail;
    zend_string *last_close_reason;
    zend_string *last_ping_payload;
    bool secure;
    bool server_endpoint;
    bool server_local_only;
    bool handshake_complete;
    bool close_frame_sent;
    bool closed;
    king_ws_server_object *server_owner;
} king_ws_state;

typedef struct _king_ws_object {
    zval resource;
    zend_object std;
} king_ws_object;

static inline king_ws_object *
php_king_ws_obj_from_zend(zend_object *obj)
{
    return (king_ws_object *)
        ((char*)obj - XtOffsetOf(king_ws_object, std));
}

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
PHP_FUNCTION(king_client_websocket_connect_async);

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
PHP_FUNCTION(king_client_websocket_send_async);

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
PHP_FUNCTION(king_client_websocket_receive_async);

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

void king_ws_server_registry_detach(
    king_ws_server_object *server,
    king_ws_state *state
);
void king_ws_state_free(king_ws_state *state);

#endif // KING_CLIENT_WEBSOCKET_H
