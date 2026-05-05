typedef struct _king_websocket_raw_frame {
    unsigned char opcode;
    unsigned char *payload;
    size_t payload_len;
    bool fin;
} king_websocket_raw_frame;

#define KING_WS_FRAME_PART_TIMEOUT_FLOOR_MS 250

static zend_long king_websocket_frame_part_timeout_ms(zend_long timeout_ms)
{
    if (timeout_ms < 0) {
        return timeout_ms;
    }

    return timeout_ms < KING_WS_FRAME_PART_TIMEOUT_FLOOR_MS
        ? KING_WS_FRAME_PART_TIMEOUT_FLOOR_MS
        : timeout_ms;
}

static void king_websocket_raw_frame_release(king_websocket_raw_frame *frame)
{
    if (frame->payload != NULL) {
        efree(frame->payload);
    }

    frame->opcode = 0;
    frame->payload = NULL;
    frame->payload_len = 0;
    frame->fin = false;
}

static bool king_websocket_opcode_is_supported(unsigned char opcode)
{
    switch (opcode) {
        case KING_WS_OPCODE_CONTINUATION:
        case KING_WS_OPCODE_TEXT:
        case KING_WS_OPCODE_BINARY:
        case KING_WS_OPCODE_CLOSE:
        case KING_WS_OPCODE_PING:
        case KING_WS_OPCODE_PONG:
            return true;

        default:
            return false;
    }
}

static zend_result king_websocket_read_raw_frame(
    king_ws_state *state,
    zend_long timeout_ms,
    bool *timed_out,
    king_websocket_raw_frame *frame,
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

    *timed_out = false;
    frame->opcode = 0;
    frame->payload = NULL;
    frame->payload_len = 0;
    frame->fin = false;

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

    frame->fin = (header[0] & 0x80) != 0;

    if ((header[0] & 0x70) != 0) {
        return king_websocket_reject_received_protocol_violation(
            state,
            function_name,
            "%s() received a WebSocket frame with unsupported RSV bits set.",
            function_name
        );
    }

    frame_part_timeout_ms = king_websocket_frame_part_timeout_ms(timeout_ms);

    opcode = (unsigned char) (header[0] & 0x0f);
    frame->opcode = opcode;
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

    if (!king_websocket_opcode_is_supported(opcode)) {
        return king_websocket_reject_received_protocol_violation(
            state,
            function_name,
            "%s() received an unsupported WebSocket opcode %u.",
            function_name,
            (unsigned int) opcode
        );
    }

    if (king_websocket_opcode_is_control(opcode) && !frame->fin) {
        return king_websocket_reject_received_protocol_violation(
            state,
            function_name,
            "%s() received a fragmented control WebSocket frame.",
            function_name
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
        (
            opcode == KING_WS_OPCODE_TEXT
            || opcode == KING_WS_OPCODE_BINARY
            || opcode == KING_WS_OPCODE_CONTINUATION
        )
        && payload_len > (size_t) state->max_payload_size
    ) {
        king_websocket_set_error(
            "%s() received a WebSocket frame payload %zu larger than max_payload_size " ZEND_LONG_FMT ".",
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

    frame->payload = payload_buffer;
    frame->payload_len = payload_len;
    return SUCCESS;
}

static zend_result king_websocket_queue_received_message(
    king_ws_state *state,
    const unsigned char *payload,
    size_t payload_len,
    bool is_binary,
    bool *message_ready,
    const char *function_name
)
{
    if (
        king_websocket_message_queue_push(
            state,
            payload != NULL ? (const char *) payload : "",
            payload_len,
            is_binary
        ) != SUCCESS
    ) {
        return king_websocket_reject_received_backpressure_overflow(
            state,
            function_name
        );
    }

    *message_ready = true;
    return SUCCESS;
}

static zend_result king_websocket_handle_control_frame(
    king_ws_state *state,
    const king_websocket_raw_frame *frame,
    const char *function_name
)
{
    switch (frame->opcode) {
        case KING_WS_OPCODE_PING:
            if (
                king_websocket_send_frame(
                    state,
                    KING_WS_OPCODE_PONG,
                    frame->payload,
                    frame->payload_len,
                    function_name
                ) != SUCCESS
            ) {
                return FAILURE;
            }
            break;

        case KING_WS_OPCODE_PONG:
            break;

        case KING_WS_OPCODE_CLOSE:
            if (frame->payload_len == 1) {
                return king_websocket_reject_received_protocol_violation(
                    state,
                    function_name,
                    "%s() received an invalid one-byte WebSocket close payload.",
                    function_name
                );
            }

            if (frame->payload_len >= 2) {
                zend_long close_code =
                    ((zend_long) frame->payload[0] << 8) | (zend_long) frame->payload[1];

                if (!king_websocket_close_code_is_valid(close_code)) {
                    return king_websocket_reject_received_protocol_violation(
                        state,
                        function_name,
                        "%s() received an invalid WebSocket close status code " ZEND_LONG_FMT ".",
                        function_name,
                        close_code
                    );
                }
            }

            if (frame->payload != NULL) {
                king_websocket_store_close_reason(
                    state,
                    frame->payload,
                    frame->payload_len
                );
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
                        frame->payload,
                        frame->payload_len,
                        function_name
                    ) == SUCCESS
                ) {
                    state->close_frame_sent = true;
                }
            }

            king_websocket_mark_transport_closed(state);
            break;

        default:
            return king_websocket_reject_received_protocol_violation(
                state,
                function_name,
                "%s() received an unsupported WebSocket opcode %u.",
                function_name,
                (unsigned int) frame->opcode
            );
    }

    return SUCCESS;
}

static zend_result king_websocket_append_fragment(
    smart_str *message,
    size_t *message_len,
    const king_websocket_raw_frame *frame,
    king_ws_state *state,
    const char *function_name
)
{
    size_t max_payload_size = state->max_payload_size > 0
        ? (size_t) state->max_payload_size
        : 0;

    if (
        *message_len > max_payload_size
        || frame->payload_len > max_payload_size - *message_len
    ) {
        king_websocket_set_error(
            "%s() received a fragmented WebSocket message payload %zu larger than max_payload_size " ZEND_LONG_FMT ".",
            function_name,
            *message_len + frame->payload_len,
            state->max_payload_size
        );
        return FAILURE;
    }

    if (frame->payload_len > 0) {
        smart_str_appendl(
            message,
            (const char *) frame->payload,
            frame->payload_len
        );
    }

    *message_len += frame->payload_len;
    return SUCCESS;
}

static zend_result king_websocket_receive_fragmented_message(
    king_ws_state *state,
    king_websocket_raw_frame *first_frame,
    zend_long frame_part_timeout_ms,
    bool *message_ready,
    const char *function_name
)
{
    smart_str message = {0};
    size_t message_len = 0;
    bool is_binary = first_frame->opcode == KING_WS_OPCODE_BINARY;

    if (
        king_websocket_append_fragment(
            &message,
            &message_len,
            first_frame,
            state,
            function_name
        ) != SUCCESS
    ) {
        king_websocket_raw_frame_release(first_frame);
        smart_str_free(&message);
        return FAILURE;
    }
    king_websocket_raw_frame_release(first_frame);

    while (1) {
        king_websocket_raw_frame next_frame;
        bool timed_out = false;

        if (
            king_websocket_read_raw_frame(
                state,
                frame_part_timeout_ms,
                &timed_out,
                &next_frame,
                function_name
            ) != SUCCESS
        ) {
            smart_str_free(&message);
            return FAILURE;
        }

        if (timed_out) {
            smart_str_free(&message);
            king_websocket_set_error(
                "%s() received an incomplete fragmented WebSocket message.",
                function_name
            );
            return FAILURE;
        }

        if (king_websocket_opcode_is_control(next_frame.opcode)) {
            zend_result result = king_websocket_handle_control_frame(
                state,
                &next_frame,
                function_name
            );
            king_websocket_raw_frame_release(&next_frame);

            if (result != SUCCESS) {
                smart_str_free(&message);
                return FAILURE;
            }

            if (state->closed) {
                smart_str_free(&message);
                return SUCCESS;
            }

            continue;
        }

        if (next_frame.opcode != KING_WS_OPCODE_CONTINUATION) {
            king_websocket_raw_frame_release(&next_frame);
            smart_str_free(&message);
            return king_websocket_reject_received_protocol_violation(
                state,
                function_name,
                "%s() received a non-continuation WebSocket frame while a fragmented message was active.",
                function_name
            );
        }

        if (
            king_websocket_append_fragment(
                &message,
                &message_len,
                &next_frame,
                state,
                function_name
            ) != SUCCESS
        ) {
            king_websocket_raw_frame_release(&next_frame);
            smart_str_free(&message);
            return FAILURE;
        }

        if (next_frame.fin) {
            king_websocket_raw_frame_release(&next_frame);
            break;
        }

        king_websocket_raw_frame_release(&next_frame);
    }

    if (message.s != NULL) {
        smart_str_0(&message);
    }

    if (
        king_websocket_queue_received_message(
            state,
            message.s != NULL ? (const unsigned char *) ZSTR_VAL(message.s) : NULL,
            message_len,
            is_binary,
            message_ready,
            function_name
        ) != SUCCESS
    ) {
        smart_str_free(&message);
        return FAILURE;
    }

    smart_str_free(&message);
    return SUCCESS;
}

static zend_result king_websocket_receive_one_frame(
    king_ws_state *state,
    zend_long timeout_ms,
    bool *message_ready,
    bool *timed_out,
    const char *function_name
)
{
    king_websocket_raw_frame frame;
    zend_long frame_part_timeout_ms = king_websocket_frame_part_timeout_ms(timeout_ms);
    zend_result result;

    *message_ready = false;
    *timed_out = false;

    if (
        king_websocket_read_raw_frame(
            state,
            timeout_ms,
            timed_out,
            &frame,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (*timed_out) {
        return SUCCESS;
    }

    switch (frame.opcode) {
        case KING_WS_OPCODE_TEXT:
        case KING_WS_OPCODE_BINARY:
            if (!frame.fin) {
                return king_websocket_receive_fragmented_message(
                    state,
                    &frame,
                    frame_part_timeout_ms,
                    message_ready,
                    function_name
                );
            }

            result = king_websocket_queue_received_message(
                state,
                frame.payload,
                frame.payload_len,
                frame.opcode == KING_WS_OPCODE_BINARY,
                message_ready,
                function_name
            );
            king_websocket_raw_frame_release(&frame);
            return result;

        case KING_WS_OPCODE_CONTINUATION:
            king_websocket_raw_frame_release(&frame);
            return king_websocket_reject_received_protocol_violation(
                state,
                function_name,
                "%s() received a WebSocket continuation frame without an active fragmented message.",
                function_name
            );

        case KING_WS_OPCODE_PING:
        case KING_WS_OPCODE_PONG:
        case KING_WS_OPCODE_CLOSE:
        default:
            result = king_websocket_handle_control_frame(
                state,
                &frame,
                function_name
            );
            king_websocket_raw_frame_release(&frame);
            return result;
    }
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
