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
        return strpprintf(0, "[%s]:" ZEND_LONG_FMT, host, state->port);
    }

    return strpprintf(0, "%s:" ZEND_LONG_FMT, host, state->port);
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
        return strpprintf(0, "%s://[%s]:" ZEND_LONG_FMT, transport, host, state->port);
    }

    return strpprintf(0, "%s://%s:" ZEND_LONG_FMT, transport, host, state->port);
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
        "%s() pending WebSocket messages exceeded max_queued_messages " ZEND_LONG_FMT " or max_queued_bytes " ZEND_LONG_FMT ".",
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
