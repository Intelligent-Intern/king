#include "php.h"
#include "php_king.h"
#include "include/client/websocket.h"
#include "include/config/config.h"
#include "include/config/app_http3_websockets_webtransport/base_layer.h"

#include "Zend/zend_smart_str.h"
#include "ext/standard/base64.h"
#include "ext/standard/sha1.h"
#include "zend_exceptions.h"
#include "ext/standard/url.h"

#include <stdint.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <strings.h>
#include <time.h>

#define KING_WS_PING_MAX_PAYLOAD_LEN 125
#define KING_WS_CLOSE_REASON_MAX_LEN 123
#define KING_WS_HTTP_LINE_MAX 4096
#define KING_WS_OPCODE_TEXT 0x1
#define KING_WS_OPCODE_BINARY 0x2
#define KING_WS_OPCODE_CLOSE 0x8
#define KING_WS_OPCODE_PING 0x9
#define KING_WS_OPCODE_PONG 0xA

static const char *king_websocket_accept_magic =
    "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_WebSocket_Connection___construct, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_send, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_sendBinary, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_ping, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, data, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_close, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, code, IS_LONG, 0, "1000")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, reason, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_getInfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static void king_websocket_set_error(
    const char *format,
    ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static bool king_websocket_string_has_crlf(
    const char *value,
    size_t value_len
)
{
    size_t i;

    for (i = 0; i < value_len; ++i) {
        if (value[i] == '\r' || value[i] == '\n') {
            return true;
        }
    }

    return false;
}

static king_ws_state *king_websocket_fetch_state(
    zval *websocket,
    uint32_t arg_num
)
{
    king_ws_state *state;

    if (
        Z_TYPE_P(websocket) == IS_OBJECT
        && king_ce_ws_connection != NULL
        && instanceof_function(Z_OBJCE_P(websocket), king_ce_ws_connection)
    ) {
        king_ws_object *intern = php_king_ws_obj_from_zend(Z_OBJ_P(websocket));

        if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
            zend_argument_type_error(
                arg_num,
                "must be a valid King\\WebSocket resource or King\\WebSocket\\Connection object"
            );
            return NULL;
        }

        websocket = &intern->resource;
    }

    if (Z_TYPE_P(websocket) != IS_RESOURCE) {
        zend_argument_type_error(
            arg_num,
            "must be of type resource or King\\WebSocket\\Connection, %s given",
            zend_zval_type_name(websocket)
        );
        return NULL;
    }

    state = (king_ws_state *) zend_fetch_resource(
        Z_RES_P(websocket),
        "King\\WebSocket",
        le_king_ws
    );
    if (state == NULL) {
        zend_argument_type_error(
            arg_num,
            "must be a valid King\\WebSocket resource or King\\WebSocket\\Connection object"
        );
    }

    return state;
}

static king_ws_state *king_websocket_object_fetch_state(
    zval *object,
    const char *function_name
)
{
    king_ws_object *intern = php_king_ws_obj_from_zend(Z_OBJ_P(object));
    king_ws_state *state;

    if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
        king_set_error("King\\WebSocket\\Connection object is not initialized.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() requires an initialized King\\WebSocket\\Connection object.",
            function_name
        );
        return NULL;
    }

    state = zend_fetch_resource(
        Z_RES(intern->resource),
        "King\\WebSocket",
        le_king_ws
    );
    if (state == NULL) {
        king_set_error("King\\WebSocket\\Connection object lost its native resource.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() lost its active King\\WebSocket resource.",
            function_name
        );
    }

    return state;
}

static void king_websocket_message_queue_clear(king_ws_state *state)
{
    king_ws_message *message = state->incoming_head;

    while (message != NULL) {
        king_ws_message *next = message->next;

        if (message->payload != NULL) {
            zend_string_release(message->payload);
        }

        efree(message);
        message = next;
    }

    state->incoming_head = NULL;
    state->incoming_tail = NULL;
    state->queued_message_count = 0;
    state->queued_bytes = 0;
}

static bool king_websocket_message_queue_can_accept(
    king_ws_state *state,
    size_t payload_len
)
{
    if (state->max_queued_messages > 0
        && state->queued_message_count >= state->max_queued_messages) {
        return false;
    }

    if (payload_len > (size_t) ZEND_LONG_MAX) {
        return false;
    }

    if (state->max_queued_bytes > 0) {
        size_t queued_bytes = state->queued_bytes > 0
            ? (size_t) state->queued_bytes
            : 0;

        if (queued_bytes > (size_t) ZEND_LONG_MAX - payload_len) {
            return false;
        }

        if (queued_bytes + payload_len > (size_t) state->max_queued_bytes) {
            return false;
        }
    }

    return true;
}

static zend_result king_websocket_message_queue_push(
    king_ws_state *state,
    const char *payload,
    size_t payload_len,
    bool is_binary
)
{
    king_ws_message *message;

    if (!king_websocket_message_queue_can_accept(state, payload_len)) {
        return FAILURE;
    }

    message = ecalloc(1, sizeof(*message));
    message->payload = zend_string_init(payload, payload_len, 0);
    message->is_binary = is_binary;

    if (state->incoming_tail != NULL) {
        state->incoming_tail->next = message;
    } else {
        state->incoming_head = message;
    }

    state->incoming_tail = message;
    state->queued_message_count++;
    state->queued_bytes += (zend_long) payload_len;
    return SUCCESS;
}

static king_ws_message *king_websocket_message_queue_shift(
    king_ws_state *state
)
{
    king_ws_message *message = state->incoming_head;

    if (message == NULL) {
        return NULL;
    }

    state->incoming_head = message->next;
    if (state->incoming_head == NULL) {
        state->incoming_tail = NULL;
    }

    if (state->queued_message_count > 0) {
        state->queued_message_count--;
    }
    if (state->queued_bytes > 0) {
        zend_long payload_len = message->payload != NULL
            ? (zend_long) ZSTR_LEN(message->payload)
            : 0;

        state->queued_bytes =
            state->queued_bytes >= payload_len
                ? state->queued_bytes - payload_len
                : 0;
    }

    message->next = NULL;
    return message;
}

static void king_websocket_trim_line(char *line, size_t *line_len)
{
    while (*line_len > 0) {
        char c = line[*line_len - 1];

        if (c != '\r' && c != '\n') {
            break;
        }

        (*line_len)--;
    }

    line[*line_len] = '\0';
}

static const char *king_websocket_skip_ascii_space(const char *value)
{
    while (*value == ' ' || *value == '\t') {
        value++;
    }

    return value;
}

static zend_string *king_websocket_build_authority(king_ws_state *state)
{
    bool default_port =
        (!state->secure && state->port == 80)
        || (state->secure && state->port == 443);
    const char *host = ZSTR_VAL(state->host);
    size_t host_len = ZSTR_LEN(state->host);
    bool needs_brackets =
        memchr(host, ':', host_len) != NULL
        && !(host_len >= 2 && host[0] == '[' && host[host_len - 1] == ']');

    if (default_port) {
        if (needs_brackets) {
            return strpprintf(0, "[%s]", host);
        }

        return zend_string_copy(state->host);
    }

    if (needs_brackets) {
        return strpprintf(0, "[%s]:%ld", host, state->port);
    }

    return strpprintf(0, "%s:%ld", host, state->port);
}

static zend_string *king_websocket_build_transport_target(king_ws_state *state)
{
    const char *transport = state->secure ? "ssl" : "tcp";
    const char *host = ZSTR_VAL(state->host);
    size_t host_len = ZSTR_LEN(state->host);
    bool needs_brackets =
        memchr(host, ':', host_len) != NULL
        && !(host_len >= 2 && host[0] == '[' && host[host_len - 1] == ']');

    if (needs_brackets) {
        return strpprintf(0, "%s://[%s]:%ld", transport, host, state->port);
    }

    return strpprintf(0, "%s://%s:%ld", transport, host, state->port);
}

static zend_string *king_websocket_generate_client_key(king_ws_state *state)
{
    unsigned char bytes[16];
    uint64_t seed =
        ((uint64_t) (uintptr_t) state >> 4)
        ^ ((uint64_t) state->port << 17)
        ^ (uint64_t) time(NULL)
        ^ (uint64_t) ZSTR_LEN(state->url);
    size_t i;

    for (i = 0; i < sizeof(bytes); ++i) {
        seed = (seed * 6364136223846793005ULL) + 1ULL;
        bytes[i] = (unsigned char) (seed >> 32);
    }

    return php_base64_encode(bytes, sizeof(bytes));
}

static zend_string *king_websocket_compute_expected_accept(zend_string *client_key)
{
    PHP_SHA1_CTX context;
    unsigned char digest[20];

    PHP_SHA1Init(&context);
    PHP_SHA1Update(
        &context,
        (const unsigned char *) ZSTR_VAL(client_key),
        ZSTR_LEN(client_key)
    );
    PHP_SHA1Update(
        &context,
        (const unsigned char *) king_websocket_accept_magic,
        strlen(king_websocket_accept_magic)
    );
    PHP_SHA1Final(digest, &context);

    return php_base64_encode(digest, sizeof(digest));
}

static zend_bool king_websocket_uses_local_server_runtime(king_ws_state *state)
{
    return state != NULL
        && state->server_local_only
        && state->transport_stream == NULL;
}

static zend_result king_websocket_reject_local_server_frame_io(
    const char *function_name
)
{
    king_websocket_set_error(
        "%s() cannot exchange frames on a local-only server-side WebSocket upgrade in v1.",
        function_name
    );
    return FAILURE;
}

static void king_websocket_apply_timeout(
    king_ws_state *state,
    zend_long timeout_ms
)
{
    struct timeval timeout;

    if (state->transport_stream == NULL) {
        return;
    }

    if (timeout_ms == 0) {
        timeout.tv_sec = 0;
        timeout.tv_usec = 0;
        php_stream_set_option(
            state->transport_stream,
            PHP_STREAM_OPTION_BLOCKING,
            0,
            NULL
        );
        php_stream_set_option(
            state->transport_stream,
            PHP_STREAM_OPTION_READ_TIMEOUT,
            0,
            &timeout
        );
        return;
    }

    php_stream_set_option(
        state->transport_stream,
        PHP_STREAM_OPTION_BLOCKING,
        1,
        NULL
    );

    if (timeout_ms > 0) {
        timeout.tv_sec = timeout_ms / 1000;
        timeout.tv_usec = (timeout_ms % 1000) * 1000;
        php_stream_set_option(
            state->transport_stream,
            PHP_STREAM_OPTION_READ_TIMEOUT,
            0,
            &timeout
        );
    }
}

static void king_websocket_mark_transport_closed(king_ws_state *state);
static void king_websocket_mark_transport_aborted(king_ws_state *state);

static zend_result king_websocket_write_all(
    king_ws_state *state,
    const unsigned char *buffer,
    size_t buffer_len,
    const char *function_name
)
{
    size_t written = 0;

    while (written < buffer_len) {
        ssize_t chunk = php_stream_write(
            state->transport_stream,
            (const char *) buffer + written,
            buffer_len - written
        );

        if (chunk <= 0) {
            king_websocket_set_error(
                "%s() failed to write the active WebSocket frame to the socket.",
                function_name
            );
            king_websocket_mark_transport_aborted(state);
            return FAILURE;
        }

        written += (size_t) chunk;
    }

    return SUCCESS;
}

static zend_result king_websocket_read_exact(
    king_ws_state *state,
    unsigned char *buffer,
    size_t buffer_len,
    zend_long timeout_ms,
    bool *timed_out,
    const char *function_name
)
{
    size_t total = 0;

    *timed_out = false;
    king_websocket_apply_timeout(state, timeout_ms);

    while (total < buffer_len) {
        ssize_t chunk = php_stream_read(
            state->transport_stream,
            (char *) buffer + total,
            buffer_len - total
        );

        if (chunk > 0) {
            total += (size_t) chunk;
            continue;
        }

        if (php_stream_eof(state->transport_stream)) {
            if (total == 0) {
                king_websocket_set_error(
                    "%s() lost the active WebSocket socket before the frame was fully read.",
                    function_name
                );
            } else {
                king_websocket_set_error(
                    "%s() received a partial WebSocket frame from the socket.",
                    function_name
                );
            }

            king_websocket_mark_transport_aborted(state);
            return FAILURE;
        }

        if (total == 0) {
            *timed_out = true;
            return SUCCESS;
        }

        king_websocket_set_error(
            "%s() received a partial WebSocket frame from the socket.",
            function_name
        );
        king_websocket_mark_transport_aborted(state);
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_websocket_send_frame(
    king_ws_state *state,
    unsigned char opcode,
    const unsigned char *payload,
    size_t payload_len,
    const char *function_name
)
{
    unsigned char header[14];
    unsigned char masking_key[4];
    zend_string *masked_payload = NULL;
    const unsigned char *wire_payload = payload;
    uint64_t mask_seed;
    size_t header_len = 0;
    bool mask_outgoing;
    size_t i;

    if (state->transport_stream == NULL) {
        if (king_websocket_uses_local_server_runtime(state)) {
            return king_websocket_reject_local_server_frame_io(function_name);
        }

        return king_websocket_message_queue_push(
            state,
            (const char *) payload,
            payload_len,
            opcode == KING_WS_OPCODE_BINARY
        );
    }

    if (payload_len > ((size_t) -1 >> 1)) {
        king_websocket_set_error(
            "%s() payload size %zu is too large for the active WebSocket frame writer.",
            function_name,
            payload_len
        );
        return FAILURE;
    }

    mask_outgoing = !state->server_endpoint;

    header[header_len++] = (unsigned char) (0x80 | (opcode & 0x0f));
    if (payload_len <= 125) {
        header[header_len++] = (unsigned char) ((mask_outgoing ? 0x80 : 0x00) | payload_len);
    } else if (payload_len <= 0xffff) {
        header[header_len++] = (unsigned char) ((mask_outgoing ? 0x80 : 0x00) | 126);
        header[header_len++] = (unsigned char) ((payload_len >> 8) & 0xff);
        header[header_len++] = (unsigned char) (payload_len & 0xff);
    } else {
        uint64_t extended_len = (uint64_t) payload_len;

        header[header_len++] = (unsigned char) ((mask_outgoing ? 0x80 : 0x00) | 127);
        for (i = 0; i < 8; ++i) {
            header[header_len++] = (unsigned char) (
                (extended_len >> ((7 - i) * 8)) & 0xff
            );
        }
    }

    if (mask_outgoing) {
        mask_seed =
            ((uint64_t) (uintptr_t) state >> 3)
            ^ ((uint64_t) payload_len << 9)
            ^ (uint64_t) time(NULL)
            ^ (uint64_t) opcode;
        for (i = 0; i < sizeof(masking_key); ++i) {
            mask_seed = (mask_seed * 1103515245ULL) + 12345ULL;
            masking_key[i] = (unsigned char) (mask_seed >> 24);
            header[header_len++] = masking_key[i];
        }
    }

    if (king_websocket_write_all(state, header, header_len, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (payload_len == 0) {
        return SUCCESS;
    }

    if (mask_outgoing) {
        masked_payload = zend_string_alloc(payload_len, 0);
        for (i = 0; i < payload_len; ++i) {
            ZSTR_VAL(masked_payload)[i] =
                (char) (payload[i] ^ masking_key[i % sizeof(masking_key)]);
        }
        ZSTR_VAL(masked_payload)[payload_len] = '\0';
        wire_payload = (const unsigned char *) ZSTR_VAL(masked_payload);
    }

    if (
        king_websocket_write_all(
            state,
            wire_payload,
            payload_len,
            function_name
        ) != SUCCESS
    ) {
        if (masked_payload != NULL) {
            zend_string_release(masked_payload);
        }
        return FAILURE;
    }

    if (masked_payload != NULL) {
        zend_string_release(masked_payload);
    }
    return SUCCESS;
}

static void king_websocket_store_close_reason(
    king_ws_state *state,
    const unsigned char *payload,
    size_t payload_len
)
{
    zend_long close_code = 1000;
    const char *reason = "";
    size_t reason_len = 0;

    if (payload_len >= 2) {
        close_code = ((zend_long) payload[0] << 8) | (zend_long) payload[1];
        reason = (const char *) payload + 2;
        reason_len = payload_len - 2;
    }

    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }
    state->last_close_reason = zend_string_init(reason, reason_len, 0);
    state->last_close_status_code = close_code;
}

static void king_websocket_mark_transport_closed(king_ws_state *state)
{
    if (state->transport_stream != NULL) {
        php_stream_close(state->transport_stream);
        state->transport_stream = NULL;
    }

    state->state = KING_WS_STATE_CLOSED;
    state->closed = true;
}

static void king_websocket_mark_transport_aborted(king_ws_state *state)
{
    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }

    state->last_close_reason = zend_string_init("", 0, 0);
    state->last_close_status_code = 1006;
    king_websocket_mark_transport_closed(state);
}

static bool king_websocket_opcode_is_control(unsigned char opcode)
{
    return opcode >= KING_WS_OPCODE_CLOSE;
}

static bool king_websocket_close_code_is_valid(zend_long close_code)
{
    if (close_code < 1000 || close_code >= 5000) {
        return false;
    }

    switch (close_code) {
        case 1004:
        case 1005:
        case 1006:
        case 1015:
            return false;
        default:
            break;
    }

    if (close_code >= 1016 && close_code <= 2999) {
        return false;
    }

    return true;
}

static zend_result king_websocket_reject_received_protocol_violation(
    king_ws_state *state,
    const char *function_name,
    const char *format,
    ...
)
{
    char message[KING_ERR_LEN];
    unsigned char close_payload[2] = {0x03, 0xea};
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
    king_websocket_store_close_reason(state, close_payload, sizeof(close_payload));

    if (!state->close_frame_sent && state->transport_stream != NULL) {
        if (
            king_websocket_send_frame(
                state,
                KING_WS_OPCODE_CLOSE,
                close_payload,
                sizeof(close_payload),
                function_name
            ) == SUCCESS
        ) {
            state->close_frame_sent = true;
        }

        king_set_error(message);
    }

    king_websocket_mark_transport_closed(state);
    return FAILURE;
}

static zend_result king_websocket_reject_received_backpressure_overflow(
    king_ws_state *state,
    const char *function_name
)
{
    unsigned char close_payload[2] = {0x03, 0xf0}; /* 1008 */

    king_websocket_set_error(
        "%s() pending WebSocket messages exceeded max_queued_messages %ld or max_queued_bytes %ld.",
        function_name,
        state->max_queued_messages,
        state->max_queued_bytes
    );
    king_websocket_store_close_reason(state, close_payload, sizeof(close_payload));

    if (!state->close_frame_sent && state->transport_stream != NULL) {
        if (
            king_websocket_send_frame(
                state,
                KING_WS_OPCODE_CLOSE,
                close_payload,
                sizeof(close_payload),
                function_name
            ) == SUCCESS
        ) {
            state->close_frame_sent = true;
        }
    }

    king_websocket_mark_transport_closed(state);
    return FAILURE;
}

static zend_result king_websocket_receive_one_frame(
    king_ws_state *state,
    zend_long timeout_ms,
    bool *message_ready,
    bool *timed_out,
    const char *function_name
)
{
    unsigned char header[2];
    unsigned char extended_len[8];
    unsigned char masking_key[4];
    unsigned char *payload_buffer = NULL;
    size_t payload_len = 0;
    unsigned char opcode;
    bool masked;
    zend_long frame_part_timeout_ms;

    *message_ready = false;
    *timed_out = false;

    if (
        king_websocket_read_exact(
            state,
            header,
            sizeof(header),
            timeout_ms,
            timed_out,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (*timed_out) {
        return SUCCESS;
    }

    if ((header[0] & 0x70) != 0) {
        return king_websocket_reject_received_protocol_violation(
            state,
            function_name,
            "%s() received a WebSocket frame with unsupported RSV bits set.",
            function_name
        );
    }

    if ((header[0] & 0x80) == 0) {
        return king_websocket_reject_received_protocol_violation(
            state,
            function_name,
            "%s() received a fragmented WebSocket frame that v1 does not support.",
            function_name
        );
    }

    frame_part_timeout_ms = timeout_ms > 0 ? timeout_ms : 100;

    opcode = (unsigned char) (header[0] & 0x0f);
    masked = (header[1] & 0x80) != 0;
    if (state->server_endpoint) {
        if (!masked) {
            return king_websocket_reject_received_protocol_violation(
                state,
                function_name,
                "%s() received an invalid unmasked client WebSocket frame.",
                function_name
            );
        }
    } else if (masked) {
        return king_websocket_reject_received_protocol_violation(
            state,
            function_name,
            "%s() received an invalid masked server WebSocket frame.",
            function_name
        );
    }

    payload_len = (size_t) (header[1] & 0x7f);
    if (payload_len == 126) {
        bool length_timed_out = false;

        if (
            king_websocket_read_exact(
                state,
                extended_len,
                2,
                frame_part_timeout_ms,
                &length_timed_out,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }

        if (length_timed_out) {
            king_websocket_set_error(
                "%s() received an incomplete extended WebSocket frame length.",
                function_name
            );
            return FAILURE;
        }

        payload_len = ((size_t) extended_len[0] << 8) | (size_t) extended_len[1];
    } else if (payload_len == 127) {
        uint64_t extended_payload_len = 0;
        bool length_timed_out = false;

        if (
            king_websocket_read_exact(
                state,
                extended_len,
                8,
                frame_part_timeout_ms,
                &length_timed_out,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }

        if (length_timed_out) {
            king_websocket_set_error(
                "%s() received an incomplete extended WebSocket frame length.",
                function_name
            );
            return FAILURE;
        }

        for (size_t i = 0; i < 8; ++i) {
            extended_payload_len = (extended_payload_len << 8) | extended_len[i];
        }

        if (extended_payload_len > (uint64_t) SIZE_MAX) {
            king_websocket_set_error(
                "%s() received a WebSocket frame that exceeds native size limits.",
                function_name
            );
            return FAILURE;
        }

        payload_len = (size_t) extended_payload_len;
    }

    /* Reject oversized control frames before any payload allocation or read. */
    if (king_websocket_opcode_is_control(opcode) && payload_len > 125) {
        return king_websocket_reject_received_protocol_violation(
            state,
            function_name,
            "%s() received a control WebSocket frame payload %zu that exceeds 125 bytes.",
            function_name,
            payload_len
        );
    }

    if (masked) {
        bool mask_timed_out = false;

        if (
            king_websocket_read_exact(
                state,
                masking_key,
                sizeof(masking_key),
                frame_part_timeout_ms,
                &mask_timed_out,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }

        if (mask_timed_out) {
            king_websocket_set_error(
                "%s() received an incomplete WebSocket masking key.",
                function_name
            );
            return FAILURE;
        }
    }

    if (
        (opcode == KING_WS_OPCODE_TEXT || opcode == KING_WS_OPCODE_BINARY)
        && payload_len > (size_t) state->max_payload_size
    ) {
        king_websocket_set_error(
            "%s() received a WebSocket frame payload %zu larger than max_payload_size %ld.",
            function_name,
            payload_len,
            state->max_payload_size
        );
        return FAILURE;
    }

    if (payload_len > 0) {
        bool payload_timed_out = false;

        payload_buffer = (unsigned char *) safe_emalloc(payload_len, 1, 0);
        if (
            king_websocket_read_exact(
                state,
                payload_buffer,
                payload_len,
                frame_part_timeout_ms,
                &payload_timed_out,
                function_name
            ) != SUCCESS
        ) {
            efree(payload_buffer);
            return FAILURE;
        }

        if (payload_timed_out) {
            efree(payload_buffer);
            king_websocket_set_error(
                "%s() received an incomplete WebSocket frame payload.",
                function_name
            );
            return FAILURE;
        }

        if (masked) {
            for (size_t i = 0; i < payload_len; ++i) {
                payload_buffer[i] ^= masking_key[i % sizeof(masking_key)];
            }
        }
    }

    switch (opcode) {
        case KING_WS_OPCODE_TEXT:
        case KING_WS_OPCODE_BINARY:
            if (
                king_websocket_message_queue_push(
                    state,
                    (const char *) payload_buffer,
                    payload_len,
                    opcode == KING_WS_OPCODE_BINARY
                ) != SUCCESS
            ) {
                if (payload_buffer != NULL) {
                    efree(payload_buffer);
                }
                return king_websocket_reject_received_backpressure_overflow(
                    state,
                    function_name
                );
            }
            *message_ready = true;
            break;

        case KING_WS_OPCODE_PING:
            if (
                king_websocket_send_frame(
                    state,
                    KING_WS_OPCODE_PONG,
                    payload_buffer,
                    payload_len,
                    function_name
                ) != SUCCESS
            ) {
                if (payload_buffer != NULL) {
                    efree(payload_buffer);
                }
                return FAILURE;
            }
            break;

        case KING_WS_OPCODE_PONG:
            break;

        case KING_WS_OPCODE_CLOSE:
            if (payload_len == 1) {
                if (payload_buffer != NULL) {
                    efree(payload_buffer);
                }
                return king_websocket_reject_received_protocol_violation(
                    state,
                    function_name,
                    "%s() received an invalid one-byte WebSocket close payload.",
                    function_name
                );
            }

            if (payload_len >= 2) {
                zend_long close_code =
                    ((zend_long) payload_buffer[0] << 8) | (zend_long) payload_buffer[1];

                if (!king_websocket_close_code_is_valid(close_code)) {
                    if (payload_buffer != NULL) {
                        efree(payload_buffer);
                    }
                    return king_websocket_reject_received_protocol_violation(
                        state,
                        function_name,
                        "%s() received an invalid WebSocket close status code %ld.",
                        function_name,
                        close_code
                    );
                }
            }

            if (payload_buffer != NULL) {
                king_websocket_store_close_reason(state, payload_buffer, payload_len);
            } else {
                king_websocket_store_close_reason(
                    state,
                    (const unsigned char *) "",
                    0
                );
            }

            if (!state->close_frame_sent) {
                if (
                    king_websocket_send_frame(
                        state,
                        KING_WS_OPCODE_CLOSE,
                        payload_buffer,
                        payload_len,
                        function_name
                    ) == SUCCESS
                ) {
                    state->close_frame_sent = true;
                }
            }

            king_websocket_mark_transport_closed(state);
            break;

        default:
            if (payload_buffer != NULL) {
                efree(payload_buffer);
            }
            return king_websocket_reject_received_protocol_violation(
                state,
                function_name,
                "%s() received an unsupported WebSocket opcode %u.",
                function_name,
                (unsigned int) opcode
            );
    }

    if (payload_buffer != NULL) {
        efree(payload_buffer);
    }

    return SUCCESS;
}

static zend_result king_websocket_pump_transport(
    king_ws_state *state,
    zend_long timeout_ms,
    const char *function_name
)
{
    bool timed_out = false;
    bool message_ready = false;
    zend_long current_timeout = timeout_ms;

    if (state->transport_stream == NULL) {
        return SUCCESS;
    }

    do {
        if (
            king_websocket_receive_one_frame(
                state,
                current_timeout,
                &message_ready,
                &timed_out,
                function_name
            ) != SUCCESS
        ) {
            if (!state->closed) {
                king_websocket_mark_transport_closed(state);
            }
            return FAILURE;
        }

        if (timed_out || state->closed) {
            return SUCCESS;
        }

        if (!message_ready && state->incoming_head == NULL) {
            return SUCCESS;
        }

        current_timeout = 0;
    } while (1);
}

static void king_websocket_drain_transport(
    king_ws_state *state,
    zend_long timeout_ms,
    const char *function_name
)
{
    bool timed_out = false;
    bool message_ready = false;
    zend_long current_timeout = timeout_ms;

    if (state->transport_stream == NULL) {
        return;
    }

    while (!state->closed) {
        if (
            king_websocket_receive_one_frame(
                state,
                current_timeout,
                &message_ready,
                &timed_out,
                function_name
            ) != SUCCESS
        ) {
            if (!state->closed) {
                king_websocket_mark_transport_closed(state);
            }
            return;
        }

        if (timed_out) {
            return;
        }

        current_timeout = 0;
    }
}

static zend_result king_websocket_validate_header_pair(
    zend_string *header_name,
    zend_string *header_value,
    const char *function_name
)
{
    if (header_name == NULL || ZSTR_LEN(header_name) == 0) {
        king_websocket_set_error(
            "%s() handshake header names must be non-empty strings.",
            function_name
        );
        return FAILURE;
    }

    if (
        king_websocket_string_has_crlf(
            ZSTR_VAL(header_name),
            ZSTR_LEN(header_name)
        )
        || king_websocket_string_has_crlf(
            ZSTR_VAL(header_value),
            ZSTR_LEN(header_value)
        )
    ) {
        king_websocket_set_error(
            "%s() handshake headers must not contain CRLF bytes.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_websocket_append_handshake_headers(
    smart_str *request,
    king_ws_state *state,
    const char *function_name
)
{
    zend_string *header_name;
    zval *header_value;

    if (Z_ISUNDEF(state->headers) || Z_TYPE(state->headers) != IS_ARRAY) {
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(state->headers), header_name, header_value)
    {
        if (header_name == NULL) {
            continue;
        }

        if (Z_TYPE_P(header_value) == IS_ARRAY) {
            zval *entry;

            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(header_value), entry)
            {
                zend_string *value = zval_get_string(entry);

                if (
                    king_websocket_validate_header_pair(
                        header_name,
                        value,
                        function_name
                    ) != SUCCESS
                ) {
                    zend_string_release(value);
                    return FAILURE;
                }

                smart_str_append(request, header_name);
                smart_str_appends(request, ": ");
                smart_str_append(request, value);
                smart_str_appends(request, "\r\n");
                zend_string_release(value);
            }
            ZEND_HASH_FOREACH_END();
            continue;
        }

        zend_string *value = zval_get_string(header_value);

        if (
            king_websocket_validate_header_pair(
                header_name,
                value,
                function_name
            ) != SUCCESS
        ) {
            zend_string_release(value);
            return FAILURE;
        }

        smart_str_append(request, header_name);
        smart_str_appends(request, ": ");
        smart_str_append(request, value);
        smart_str_appends(request, "\r\n");
        zend_string_release(value);
    }
    ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_websocket_complete_handshake(
    king_ws_state *state,
    const char *function_name
)
{
    struct timeval timeout;
    zend_string *transport_target = NULL;
    zend_string *authority = NULL;
    zend_string *client_key = NULL;
    zend_string *expected_accept = NULL;
    zend_string *transport_error = NULL;
    php_stream *stream = NULL;
    smart_str request = {0};
    char line[KING_WS_HTTP_LINE_MAX];
    size_t line_len = 0;
    int transport_error_code = 0;
    const char *accept_value = NULL;

    transport_target = king_websocket_build_transport_target(state);
    authority = king_websocket_build_authority(state);
    client_key = king_websocket_generate_client_key(state);
    expected_accept = king_websocket_compute_expected_accept(client_key);

    timeout.tv_sec = state->handshake_timeout_ms / 1000;
    timeout.tv_usec = (state->handshake_timeout_ms % 1000) * 1000;

    stream = php_stream_xport_create(
        ZSTR_VAL(transport_target),
        ZSTR_LEN(transport_target),
        0,
        STREAM_XPORT_CLIENT | STREAM_XPORT_CONNECT,
        NULL,
        &timeout,
        NULL,
        &transport_error,
        &transport_error_code
    );
    if (stream == NULL) {
        if (transport_error != NULL) {
            king_websocket_set_error(
                "%s() failed to connect the WebSocket socket: %s",
                function_name,
                ZSTR_VAL(transport_error)
            );
        } else {
            king_websocket_set_error(
                "%s() failed to connect the WebSocket socket (code %d).",
                function_name,
                transport_error_code
            );
        }
        if (transport_error != NULL) {
            zend_string_release(transport_error);
        }
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    state->transport_stream = stream;
    php_stream_set_option(stream, PHP_STREAM_OPTION_BLOCKING, 1, NULL);
    php_stream_set_option(stream, PHP_STREAM_OPTION_READ_TIMEOUT, 0, &timeout);

    smart_str_appends(&request, "GET ");
    smart_str_append(&request, state->request_target);
    smart_str_appends(&request, " HTTP/1.1\r\nHost: ");
    smart_str_append(&request, authority);
    smart_str_appends(&request, "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ");
    smart_str_append(&request, client_key);
    smart_str_appends(&request, "\r\nSec-WebSocket-Version: 13\r\n");

    if (
        king_websocket_append_handshake_headers(
            &request,
            state,
            function_name
        ) != SUCCESS
    ) {
        smart_str_free(&request);
        king_websocket_mark_transport_closed(state);
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    smart_str_appends(&request, "\r\n");
    smart_str_0(&request);

    if (
        king_websocket_write_all(
            state,
            (const unsigned char *) ZSTR_VAL(request.s),
            ZSTR_LEN(request.s),
            function_name
        ) != SUCCESS
    ) {
        smart_str_free(&request);
        king_websocket_mark_transport_closed(state);
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    if (
        php_stream_get_line(
            state->transport_stream,
            line,
            sizeof(line),
            &line_len
        ) == NULL
    ) {
        smart_str_free(&request);
        king_websocket_mark_transport_closed(state);
        king_websocket_set_error(
            "%s() did not receive a WebSocket handshake status line from the peer.",
            function_name
        );
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    king_websocket_trim_line(line, &line_len);
    if (strncmp(line, "HTTP/1.1 101", 12) != 0 && strncmp(line, "HTTP/1.0 101", 12) != 0) {
        smart_str_free(&request);
        king_websocket_mark_transport_closed(state);
        king_websocket_set_error(
            "%s() expected an HTTP 101 WebSocket upgrade response but received '%s'.",
            function_name,
            line
        );
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    while (
        php_stream_get_line(
            state->transport_stream,
            line,
            sizeof(line),
            &line_len
        ) != NULL
    ) {
        char *colon;

        king_websocket_trim_line(line, &line_len);
        if (line_len == 0) {
            break;
        }

        colon = strchr(line, ':');
        if (colon == NULL) {
            continue;
        }

        *colon = '\0';
        if (strcasecmp(line, "Sec-WebSocket-Accept") == 0) {
            accept_value = king_websocket_skip_ascii_space(colon + 1);
        }
    }

    smart_str_free(&request);

    if (accept_value == NULL || strcmp(accept_value, ZSTR_VAL(expected_accept)) != 0) {
        king_websocket_mark_transport_closed(state);
        king_websocket_set_error(
            "%s() received an invalid Sec-WebSocket-Accept handshake header.",
            function_name
        );
        zend_string_release(expected_accept);
        zend_string_release(client_key);
        zend_string_release(authority);
        zend_string_release(transport_target);
        return FAILURE;
    }

    state->state = KING_WS_STATE_OPEN;
    state->handshake_complete = true;
    state->closed = false;
    state->close_frame_sent = false;
    king_set_error("");

    zend_string_release(expected_accept);
    zend_string_release(client_key);
    zend_string_release(authority);
    zend_string_release(transport_target);
    return SUCCESS;
}

static zend_result king_websocket_require_open(
    king_ws_state *state,
    const char *function_name
)
{
    if (state->state == KING_WS_STATE_OPEN && !state->closed) {
        return SUCCESS;
    }

    king_websocket_set_error(
        "%s() cannot run on a closed WebSocket connection.",
        function_name
    );
    return FAILURE;
}

static zend_result king_websocket_validate_close_inputs(
    zend_long status_code,
    size_t reason_len,
    const char *function_name
)
{
    if (status_code < 1000 || status_code > 4999) {
        king_websocket_set_error(
            "%s() close status code must be between 1000 and 4999.",
            function_name
        );
        return FAILURE;
    }

    if (reason_len > KING_WS_CLOSE_REASON_MAX_LEN) {
        king_websocket_set_error(
            "%s() close reason cannot exceed %d bytes.",
            function_name,
            KING_WS_CLOSE_REASON_MAX_LEN
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_websocket_validate_positive_option(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name
)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        king_websocket_set_error(
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    if (Z_LVAL_P(value) <= 0) {
        king_websocket_set_error(
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_long king_websocket_resolve_default_port(php_url *parsed_url)
{
    if (parsed_url->port > 0) {
        return (zend_long) parsed_url->port;
    }

    if (
        parsed_url->scheme != NULL
        && strcasecmp(ZSTR_VAL(parsed_url->scheme), "wss") == 0
    ) {
        return 443;
    }

    return 80;
}

static zend_result king_websocket_parse_url(
    const char *url_str,
    size_t url_len,
    const char *function_name,
    php_url **parsed_url_out
)
{
    php_url *parsed_url;

    if (url_len == 0) {
        king_websocket_set_error(
            "%s() requires a non-empty WebSocket URL.",
            function_name
        );
        return FAILURE;
    }

    parsed_url = php_url_parse_ex(url_str, url_len);
    if (parsed_url == NULL || parsed_url->scheme == NULL || parsed_url->host == NULL) {
        if (parsed_url != NULL) {
            php_url_free(parsed_url);
        }

        king_websocket_set_error(
            "%s() requires a valid absolute ws:// or wss:// URL.",
            function_name
        );
        return FAILURE;
    }

    if (
        strcasecmp(ZSTR_VAL(parsed_url->scheme), "ws") != 0
        && strcasecmp(ZSTR_VAL(parsed_url->scheme), "wss") != 0
    ) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() currently supports only absolute ws:// and wss:// URLs.",
            function_name
        );
        return FAILURE;
    }

    if (parsed_url->user != NULL || parsed_url->pass != NULL) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() does not support embedding credentials in the connection URL.",
            function_name
        );
        return FAILURE;
    }

    if (parsed_url->fragment != NULL) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() does not support URL fragments in the connection URL.",
            function_name
        );
        return FAILURE;
    }

    if (
        king_websocket_string_has_crlf(
            ZSTR_VAL(parsed_url->host),
            ZSTR_LEN(parsed_url->host)
        )
    ) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() URL host contains invalid line breaks.",
            function_name
        );
        return FAILURE;
    }

    *parsed_url_out = parsed_url;
    return SUCCESS;
}

static zend_result king_websocket_build_request_target(
    php_url *parsed_url,
    const char *function_name,
    zend_string **request_target_out
)
{
    smart_str request_target = {0};
    const char *path;
    size_t path_len;

    path = parsed_url->path != NULL ? ZSTR_VAL(parsed_url->path) : "/";
    path_len = parsed_url->path != NULL ? ZSTR_LEN(parsed_url->path) : 1;

    if (path_len == 0) {
        path = "/";
        path_len = 1;
    }

    if (king_websocket_string_has_crlf(path, path_len)) {
        king_websocket_set_error(
            "%s() URL path contains invalid line breaks.",
            function_name
        );
        return FAILURE;
    }

    smart_str_appendl(&request_target, path, path_len);

    if (parsed_url->query != NULL && ZSTR_LEN(parsed_url->query) > 0) {
        if (
            king_websocket_string_has_crlf(
                ZSTR_VAL(parsed_url->query),
                ZSTR_LEN(parsed_url->query)
            )
        ) {
            smart_str_free(&request_target);
            king_websocket_set_error(
                "%s() URL query contains invalid line breaks.",
                function_name
            );
            return FAILURE;
        }

        smart_str_appendc(&request_target, '?');
        smart_str_append(&request_target, parsed_url->query);
    }

    smart_str_0(&request_target);
    *request_target_out = request_target.s;
    return SUCCESS;
}

static zend_result king_websocket_apply_options(
    king_ws_state *state,
    zval *options_array,
    const char *function_name
)
{
    king_cfg_t *config = NULL;
    zval *option_value;

    state->max_payload_size =
        king_app_protocols_config.websocket_default_max_payload_size > 0
            ? king_app_protocols_config.websocket_default_max_payload_size
            : 16777216;
    state->max_queued_messages =
        king_app_protocols_config.websocket_default_max_queued_messages > 0
            ? king_app_protocols_config.websocket_default_max_queued_messages
            : 64;
    state->max_queued_bytes =
        king_app_protocols_config.websocket_default_max_queued_bytes > 0
            ? king_app_protocols_config.websocket_default_max_queued_bytes
            : 67108864;
    state->ping_interval_ms =
        king_app_protocols_config.websocket_default_ping_interval_ms > 0
            ? king_app_protocols_config.websocket_default_ping_interval_ms
            : 25000;
    state->handshake_timeout_ms =
        king_app_protocols_config.websocket_handshake_timeout_ms > 0
            ? king_app_protocols_config.websocket_handshake_timeout_ms
            : 5000;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connection_config",
        sizeof("connection_config") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        config = (king_cfg_t *) king_fetch_config(option_value);
        if (config == NULL) {
            king_websocket_set_error(
                "%s() option 'connection_config' must be a King\\Config resource or object.",
                function_name
            );
            return FAILURE;
        }

        ZVAL_COPY(&state->config, option_value);
        state->max_payload_size = config->app_protocols.websocket_default_max_payload_size;
        state->max_queued_messages = config->app_protocols.websocket_default_max_queued_messages;
        state->max_queued_bytes = config->app_protocols.websocket_default_max_queued_bytes;
        state->ping_interval_ms = config->app_protocols.websocket_default_ping_interval_ms;
        state->handshake_timeout_ms = config->app_protocols.websocket_handshake_timeout_ms;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "max_payload_size",
        sizeof("max_payload_size") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "max_payload_size",
                &state->max_payload_size,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "max_queued_messages",
        sizeof("max_queued_messages") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "max_queued_messages",
                &state->max_queued_messages,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "max_queued_bytes",
        sizeof("max_queued_bytes") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "max_queued_bytes",
                &state->max_queued_bytes,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "ping_interval_ms",
        sizeof("ping_interval_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "ping_interval_ms",
                &state->ping_interval_ms,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "handshake_timeout_ms",
        sizeof("handshake_timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "handshake_timeout_ms",
                &state->handshake_timeout_ms,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    if (state->max_queued_bytes < state->max_payload_size) {
        king_websocket_set_error(
            "%s() option 'max_queued_bytes' must be >= max_payload_size.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static king_ws_state *king_websocket_state_create(
    const char *url_str,
    size_t url_len,
    zval *headers_array,
    zval *options_array,
    const char *function_name
)
{
    php_url *parsed_url = NULL;
    zend_string *request_target = NULL;
    king_ws_state *state = NULL;

    if (
        king_websocket_parse_url(
            url_str,
            url_len,
            function_name,
            &parsed_url
        ) != SUCCESS
    ) {
        return NULL;
    }

    if (
        king_websocket_build_request_target(
            parsed_url,
            function_name,
            &request_target
        ) != SUCCESS
    ) {
        php_url_free(parsed_url);
        return NULL;
    }

    state = ecalloc(1, sizeof(*state));
    state->url = zend_string_init(url_str, url_len, 0);
    state->scheme = zend_string_copy(parsed_url->scheme);
    state->host = zend_string_copy(parsed_url->host);
    state->request_target = request_target;
    state->port = king_websocket_resolve_default_port(parsed_url);
    state->state = KING_WS_STATE_CONNECTING;
    state->last_close_status_code = 1000;
    state->secure = strcasecmp(ZSTR_VAL(parsed_url->scheme), "wss") == 0;
    state->handshake_complete = false;
    state->close_frame_sent = false;
    state->closed = false;
    ZVAL_UNDEF(&state->config);
    ZVAL_UNDEF(&state->headers);

    if (
        king_websocket_apply_options(
            state,
            options_array,
            function_name
        ) != SUCCESS
    ) {
        king_ws_state_free(state);
        php_url_free(parsed_url);
        return NULL;
    }

    if (headers_array != NULL && Z_TYPE_P(headers_array) != IS_NULL) {
        ZVAL_COPY(&state->headers, headers_array);
    }

    php_url_free(parsed_url);
    if (king_websocket_complete_handshake(state, function_name) != SUCCESS) {
        king_ws_state_free(state);
        return NULL;
    }

    king_set_error("");
    return state;
}

void king_ws_state_free(king_ws_state *state)
{
    if (state == NULL) {
        return;
    }

    if (state->url != NULL) {
        zend_string_release(state->url);
    }
    if (state->scheme != NULL) {
        zend_string_release(state->scheme);
    }
    if (state->host != NULL) {
        zend_string_release(state->host);
    }
    if (state->request_target != NULL) {
        zend_string_release(state->request_target);
    }
    if (state->transport_stream != NULL) {
        php_stream_close(state->transport_stream);
        state->transport_stream = NULL;
    }
    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }
    if (state->last_ping_payload != NULL) {
        zend_string_release(state->last_ping_payload);
    }
    if (!Z_ISUNDEF(state->config)) {
        zval_ptr_dtor(&state->config);
    }
    if (!Z_ISUNDEF(state->headers)) {
        zval_ptr_dtor(&state->headers);
    }
    king_websocket_message_queue_clear(state);

    efree(state);
}

static void king_websocket_info_append_values(zval *target, zval *value)
{
    if (Z_TYPE_P(value) == IS_ARRAY) {
        zval *entry;

        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), entry)
        {
            add_next_index_str(target, zval_get_string(entry));
        }
        ZEND_HASH_FOREACH_END();
        return;
    }

    add_next_index_str(target, zval_get_string(value));
}

static void king_websocket_build_info_array(zval *return_value, king_ws_state *state)
{
    zval headers;

    array_init(return_value);
    add_assoc_str(return_value, "id", zend_string_copy(state->url));
    add_assoc_str(
        return_value,
        "remote_addr",
        strpprintf(0, "%s:%ld", ZSTR_VAL(state->host), state->port)
    );
    add_assoc_str(return_value, "protocol", zend_string_copy(state->scheme));
    add_assoc_long(return_value, "max_payload_size", state->max_payload_size);
    add_assoc_long(return_value, "max_queued_messages", state->max_queued_messages);
    add_assoc_long(return_value, "max_queued_bytes", state->max_queued_bytes);
    add_assoc_long(return_value, "queued_message_count", state->queued_message_count);
    add_assoc_long(return_value, "queued_bytes", state->queued_bytes);
    add_assoc_bool(return_value, "closed", state->closed);

    array_init(&headers);
    if (!Z_ISUNDEF(state->headers) && Z_TYPE(state->headers) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(state->headers), header_name, header_value)
        {
            zval normalized;

            if (header_name == NULL) {
                continue;
            }

            array_init(&normalized);
            king_websocket_info_append_values(&normalized, header_value);
            zend_hash_update(Z_ARRVAL(headers), header_name, &normalized);
        }
        ZEND_HASH_FOREACH_END();
    }
    add_assoc_zval(return_value, "headers", &headers);
}

static void king_websocket_throw_last_error(
    zend_class_entry *exception_ce,
    const char *fallback
)
{
    zend_throw_exception_ex(
        exception_ce,
        0,
        "%s",
        king_get_error()[0] != '\0' ? king_get_error() : fallback
    );
}

static zend_result king_websocket_object_send_internal(
    zval *object,
    zend_string *payload,
    bool is_binary,
    const char *function_name
)
{
    king_ws_state *state = king_websocket_object_fetch_state(object, function_name);

    if (state == NULL) {
        return FAILURE;
    }

    if (king_websocket_require_open(state, function_name) != SUCCESS) {
        king_websocket_throw_last_error(
            king_ce_ws_closed,
            "WebSocket connection is closed."
        );
        return FAILURE;
    }

    if (ZSTR_LEN(payload) > (size_t) state->max_payload_size) {
        king_websocket_set_error(
            "%s() payload size %zu exceeds max_payload_size %ld.",
            function_name,
            ZSTR_LEN(payload),
            state->max_payload_size
        );
        king_websocket_throw_last_error(
            king_ce_ws_protocol_error,
            "WebSocket frame exceeds the configured max payload size."
        );
        return FAILURE;
    }

    if (
        king_websocket_send_frame(
            state,
            is_binary ? KING_WS_OPCODE_BINARY : KING_WS_OPCODE_TEXT,
            (const unsigned char *) ZSTR_VAL(payload),
            ZSTR_LEN(payload),
            function_name
        ) != SUCCESS
    ) {
        king_websocket_throw_last_error(
            king_ce_system_exception,
            "Failed to send the active WebSocket frame."
        );
        return FAILURE;
    }

    king_set_error("");
    return SUCCESS;
}

PHP_METHOD(King_WebSocket_Connection, __construct)
{
    char *url_str = NULL;
    size_t url_len = 0;
    zval *headers_array = NULL;
    zval *options_array = NULL;
    king_ws_state *state;
    king_ws_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_state_create(
        url_str,
        url_len,
        headers_array,
        options_array,
        "WebSocket\\Connection::__construct"
    );
    if (state == NULL) {
        king_websocket_throw_last_error(
            king_ce_validation_exception,
            "King\\WebSocket\\Connection construction failed."
        );
        RETURN_THROWS();
    }

    intern = php_king_ws_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
    }
    ZVAL_RES(&intern->resource, zend_register_resource(state, le_king_ws));
    king_set_error("");
}

PHP_METHOD(King_WebSocket_Connection, send)
{
    zend_string *message;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(message)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_websocket_object_send_internal(
            ZEND_THIS,
            message,
            false,
            "WebSocket\\Connection::send"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Connection, sendBinary)
{
    zend_string *payload;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(payload)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_websocket_object_send_internal(
            ZEND_THIS,
            payload,
            true,
            "WebSocket\\Connection::sendBinary"
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_WebSocket_Connection, ping)
{
    zend_string *payload_str = NULL;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR_OR_NULL(payload_str)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_object_fetch_state(ZEND_THIS, "WebSocket\\Connection::ping");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (king_websocket_require_open(state, "WebSocket\\Connection::ping") != SUCCESS) {
        king_websocket_throw_last_error(
            king_ce_ws_closed,
            "WebSocket connection is closed."
        );
        RETURN_THROWS();
    }

    if (payload_str != NULL && ZSTR_LEN(payload_str) > KING_WS_PING_MAX_PAYLOAD_LEN) {
        king_websocket_set_error(
            "WebSocket\\Connection::ping() ping payload cannot exceed %d bytes.",
            KING_WS_PING_MAX_PAYLOAD_LEN
        );
        king_websocket_throw_last_error(
            king_ce_ws_protocol_error,
            "WebSocket ping payload exceeds the protocol limit."
        );
        RETURN_THROWS();
    }

    if (king_websocket_uses_local_server_runtime(state)) {
        king_websocket_reject_local_server_frame_io(
            "WebSocket\\Connection::ping"
        );
        king_websocket_throw_last_error(
            king_ce_ws_connection_error,
            "Failed to send the active WebSocket ping frame."
        );
        RETURN_THROWS();
    }

    if (state->last_ping_payload != NULL) {
        zend_string_release(state->last_ping_payload);
    }
    state->last_ping_payload = payload_str != NULL
        ? zend_string_copy(payload_str)
        : zend_string_init("", 0, 0);

    if (
        state->transport_stream != NULL
        && king_websocket_send_frame(
            state,
            KING_WS_OPCODE_PING,
            payload_str != NULL
                ? (const unsigned char *) ZSTR_VAL(payload_str)
                : (const unsigned char *) "",
            payload_str != NULL ? ZSTR_LEN(payload_str) : 0,
            "WebSocket\\Connection::ping"
        ) != SUCCESS
    ) {
        king_websocket_throw_last_error(
            king_ce_ws_connection_error,
            "Failed to send the active WebSocket ping frame."
        );
        RETURN_THROWS();
    }

    king_set_error("");
}

PHP_METHOD(King_WebSocket_Connection, close)
{
    zend_long status_code = 1000;
    zend_string *reason = NULL;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(0, 2)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(status_code)
        Z_PARAM_STR_OR_NULL(reason)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_object_fetch_state(ZEND_THIS, "WebSocket\\Connection::close");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->state == KING_WS_STATE_CLOSED || state->closed) {
        king_set_error("");
        return;
    }

    if (
        king_websocket_validate_close_inputs(
            status_code,
            reason != NULL ? ZSTR_LEN(reason) : 0,
            "WebSocket\\Connection::close"
        ) != SUCCESS
    ) {
        king_websocket_throw_last_error(
            king_ce_validation_exception,
            "WebSocket close input validation failed."
        );
        RETURN_THROWS();
    }

    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }
    state->last_close_reason = reason != NULL
        ? zend_string_copy(reason)
        : zend_string_init("", 0, 0);
    state->last_close_status_code = status_code;

    if (state->transport_stream != NULL) {
        unsigned char close_payload[2 + KING_WS_CLOSE_REASON_MAX_LEN];
        size_t close_payload_len = 2 + (reason != NULL ? ZSTR_LEN(reason) : 0);

        close_payload[0] = (unsigned char) ((status_code >> 8) & 0xff);
        close_payload[1] = (unsigned char) (status_code & 0xff);
        if (reason != NULL && ZSTR_LEN(reason) > 0) {
            memcpy(close_payload + 2, ZSTR_VAL(reason), ZSTR_LEN(reason));
        }

        if (
            king_websocket_send_frame(
                state,
                KING_WS_OPCODE_CLOSE,
                close_payload,
                close_payload_len,
                "WebSocket\\Connection::close"
        ) != SUCCESS
        ) {
            king_websocket_throw_last_error(
                king_ce_ws_connection_error,
                "Failed to send the active WebSocket close frame."
            );
            RETURN_THROWS();
        }

        state->close_frame_sent = true;
        king_websocket_drain_transport(state, 100, "WebSocket\\Connection::close");
        if (!state->closed) {
            king_websocket_mark_transport_closed(state);
        }
    } else {
        state->state = KING_WS_STATE_CLOSED;
        state->closed = true;
    }

    king_set_error("");
}

PHP_METHOD(King_WebSocket_Connection, getInfo)
{
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_NONE();

    state = king_websocket_object_fetch_state(ZEND_THIS, "WebSocket\\Connection::getInfo");
    if (state == NULL) {
        RETURN_THROWS();
    }

    king_websocket_build_info_array(return_value, state);
    king_set_error("");
}

PHP_FUNCTION(king_client_websocket_connect)
{
    char *url_str = NULL;
    size_t url_len = 0;
    zval *headers_array = NULL;
    zval *options_array = NULL;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_state_create(
        url_str,
        url_len,
        headers_array,
        options_array,
        "king_client_websocket_connect"
    );
    if (state == NULL) {
        RETURN_FALSE;
    }

    RETURN_RES(zend_register_resource(state, le_king_ws));
}

PHP_FUNCTION(king_client_websocket_send)
{
    zval *websocket;
    char *data = NULL;
    size_t data_len = 0;
    zend_bool is_binary = false;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_STRING(data, data_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(is_binary)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (
        king_websocket_require_open(
            state,
            "king_client_websocket_send"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (data_len > (size_t) state->max_payload_size) {
        king_websocket_set_error(
            "king_client_websocket_send() payload size %zu exceeds max_payload_size %ld.",
            data_len,
            state->max_payload_size
        );
        RETURN_FALSE;
    }

    if (
        king_websocket_send_frame(
            state,
            is_binary ? KING_WS_OPCODE_BINARY : KING_WS_OPCODE_TEXT,
            (const unsigned char *) data,
            data_len,
            "king_client_websocket_send"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_websocket_send)
{
    zval *websocket;
    char *message = NULL;
    size_t message_len = 0;
    zend_bool is_binary = false;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_STRING(message, message_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(is_binary)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (
        king_websocket_require_open(
            state,
            "king_websocket_send"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (message_len > (size_t) state->max_payload_size) {
        king_websocket_set_error(
            "king_websocket_send() payload size %zu exceeds max_payload_size %ld.",
            message_len,
            state->max_payload_size
        );
        RETURN_FALSE;
    }

    if (
        king_websocket_send_frame(
            state,
            is_binary ? KING_WS_OPCODE_BINARY : KING_WS_OPCODE_TEXT,
            (const unsigned char *) message,
            message_len,
            "king_websocket_send"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_client_websocket_receive)
{
    zval *websocket;
    zend_long timeout_ms = -1;
    king_ws_state *state;
    king_ws_message *message;
    zend_string *payload;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (timeout_ms < -1) {
        king_websocket_set_error(
            "king_client_websocket_receive() timeout_ms must be -1 or >= 0."
        );
        RETURN_FALSE;
    }

    message = king_websocket_message_queue_shift(state);
    if (message != NULL) {
        payload = message->payload;
        efree(message);
        king_set_error("");
        RETURN_STR(payload);
    }

    if (king_websocket_uses_local_server_runtime(state)) {
        king_websocket_reject_local_server_frame_io(
            "king_client_websocket_receive"
        );
        RETURN_FALSE;
    }

    if (state->transport_stream != NULL && !state->closed) {
        if (
            king_websocket_pump_transport(
                state,
                timeout_ms,
                "king_client_websocket_receive"
            ) != SUCCESS
        ) {
            RETURN_FALSE;
        }

        message = king_websocket_message_queue_shift(state);
        if (message != NULL) {
            payload = message->payload;
            efree(message);
            king_set_error("");
            RETURN_STR(payload);
        }
    }

    if (
        (state->state == KING_WS_STATE_OPEN || state->state == KING_WS_STATE_CONNECTING)
        && !state->closed
    ) {
        king_set_error("");
        RETURN_EMPTY_STRING();
    }

    king_websocket_set_error(
        "king_client_websocket_receive() cannot run on a closed WebSocket connection."
    );
    RETURN_FALSE;
}

PHP_FUNCTION(king_client_websocket_ping)
{
    zval *websocket;
    char *payload = "";
    size_t payload_len = 0;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(payload, payload_len)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (
        king_websocket_require_open(
            state,
            "king_client_websocket_ping"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (payload_len > KING_WS_PING_MAX_PAYLOAD_LEN) {
        king_websocket_set_error(
            "king_client_websocket_ping() ping payload cannot exceed %d bytes.",
            KING_WS_PING_MAX_PAYLOAD_LEN
        );
        RETURN_FALSE;
    }

    if (king_websocket_uses_local_server_runtime(state)) {
        king_websocket_reject_local_server_frame_io(
            "king_client_websocket_ping"
        );
        RETURN_FALSE;
    }

    if (state->last_ping_payload != NULL) {
        zend_string_release(state->last_ping_payload);
    }
    state->last_ping_payload = zend_string_init(payload, payload_len, 0);

    if (
        state->transport_stream != NULL
        && king_websocket_send_frame(
            state,
            KING_WS_OPCODE_PING,
            (const unsigned char *) payload,
            payload_len,
            "king_client_websocket_ping"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_client_websocket_get_status)
{
    zval *websocket;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(websocket)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    king_set_error("");
    RETURN_LONG((zend_long) state->state);
}

PHP_FUNCTION(king_client_websocket_close)
{
    zval *websocket;
    zend_long status_code = 1000;
    char *reason = "";
    size_t reason_len = 0;
    king_ws_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_ZVAL(websocket)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(status_code)
        Z_PARAM_STRING(reason, reason_len)
    ZEND_PARSE_PARAMETERS_END();

    state = king_websocket_fetch_state(websocket, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->state == KING_WS_STATE_CLOSED || state->closed) {
        king_set_error("");
        RETURN_TRUE;
    }

    if (
        king_websocket_validate_close_inputs(
            status_code,
            reason_len,
            "king_client_websocket_close"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }
    state->last_close_reason = zend_string_init(reason, reason_len, 0);
    state->last_close_status_code = status_code;

    if (state->transport_stream != NULL) {
        unsigned char close_payload[2 + KING_WS_CLOSE_REASON_MAX_LEN];

        close_payload[0] = (unsigned char) ((status_code >> 8) & 0xff);
        close_payload[1] = (unsigned char) (status_code & 0xff);
        if (reason_len > 0) {
            memcpy(close_payload + 2, reason, reason_len);
        }

        if (
            king_websocket_send_frame(
                state,
                KING_WS_OPCODE_CLOSE,
                close_payload,
                reason_len + 2,
                "king_client_websocket_close"
            ) != SUCCESS
        ) {
            RETURN_FALSE;
        }

        state->close_frame_sent = true;
        king_websocket_drain_transport(state, 100, "king_client_websocket_close");
        if (!state->closed) {
            king_websocket_mark_transport_closed(state);
        }
    } else {
        state->state = KING_WS_STATE_CLOSED;
        state->closed = true;
    }

    king_set_error("");
    RETURN_TRUE;
}

const zend_function_entry king_ws_connection_class_methods[] = {
    PHP_ME(King_WebSocket_Connection, __construct, arginfo_class_King_WebSocket_Connection___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_WebSocket_Connection, send, arginfo_class_King_WebSocket_Connection_send, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, sendBinary, arginfo_class_King_WebSocket_Connection_sendBinary, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, ping, arginfo_class_King_WebSocket_Connection_ping, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, close, arginfo_class_King_WebSocket_Connection_close, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Connection, getInfo, arginfo_class_King_WebSocket_Connection_getInfo, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
