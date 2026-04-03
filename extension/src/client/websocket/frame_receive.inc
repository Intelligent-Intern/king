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

