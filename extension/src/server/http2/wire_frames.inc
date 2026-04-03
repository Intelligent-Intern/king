static zend_result king_server_http2_hpack_encode_integer(
    smart_str *buffer,
    uint32_t value,
    uint8_t prefix_bits,
    uint8_t first_byte_mask
)
{
    uint32_t prefix_max = (1U << prefix_bits) - 1U;

    if (value < prefix_max) {
        smart_str_appendc(buffer, (char) (first_byte_mask | value));
        return SUCCESS;
    }

    smart_str_appendc(buffer, (char) (first_byte_mask | prefix_max));
    value -= prefix_max;
    while (value >= 128) {
        smart_str_appendc(buffer, (char) ((value % 128U) + 128U));
        value /= 128U;
    }
    smart_str_appendc(buffer, (char) value);
    return SUCCESS;
}

static zend_result king_server_http2_hpack_encode_string(
    smart_str *buffer,
    const char *value,
    size_t value_len
)
{
    king_server_http2_hpack_encode_integer(buffer, (uint32_t) value_len, 7, 0x00);
    if (value_len > 0) {
        smart_str_appendl(buffer, value, value_len);
    }
    return SUCCESS;
}

static zend_result king_server_http2_hpack_encode_literal_static_name(
    smart_str *buffer,
    uint32_t name_index,
    const char *value,
    size_t value_len
)
{
    king_server_http2_hpack_encode_integer(buffer, name_index, 4, 0x00);
    return king_server_http2_hpack_encode_string(buffer, value, value_len);
}

static zend_result king_server_http2_hpack_encode_literal_name(
    smart_str *buffer,
    const char *name,
    size_t name_len,
    const char *value,
    size_t value_len
)
{
    smart_str_appendc(buffer, '\0');
    king_server_http2_hpack_encode_string(buffer, name, name_len);
    return king_server_http2_hpack_encode_string(buffer, value, value_len);
}

static zend_result king_server_http2_hpack_append_header_value(
    smart_str *buffer,
    zend_string *name,
    zend_string *value
)
{
    zend_string *normalized_name;
    zend_result rc;

    normalized_name = zend_string_tolower(name);
    if (zend_string_equals_literal(normalized_name, "content-type")) {
        rc = king_server_http2_hpack_encode_literal_static_name(
            buffer,
            31,
            ZSTR_VAL(value),
            ZSTR_LEN(value)
        );
        zend_string_release(normalized_name);
        return rc;
    }

    rc = king_server_http2_hpack_encode_literal_name(
        buffer,
        ZSTR_VAL(normalized_name),
        ZSTR_LEN(normalized_name),
        ZSTR_VAL(value),
        ZSTR_LEN(value)
    );
    zend_string_release(normalized_name);
    return rc;
}

static zend_result king_server_http2_write_frame(
    int fd,
    uint8_t type,
    uint8_t flags,
    uint32_t stream_id,
    const unsigned char *payload,
    size_t payload_len,
    const char *function_name
)
{
    unsigned char header[9];

    if (payload_len > 0x00ffffffU) {
        king_server_local_set_errorf(
            "%s() attempted to write an HTTP/2 frame larger than the 24-bit wire length field.",
            function_name
        );
        return FAILURE;
    }

    header[0] = (unsigned char) ((payload_len >> 16) & 0xffU);
    header[1] = (unsigned char) ((payload_len >> 8) & 0xffU);
    header[2] = (unsigned char) (payload_len & 0xffU);
    header[3] = type;
    header[4] = flags;
    header[5] = (unsigned char) ((stream_id >> 24) & 0x7fU);
    header[6] = (unsigned char) ((stream_id >> 16) & 0xffU);
    header[7] = (unsigned char) ((stream_id >> 8) & 0xffU);
    header[8] = (unsigned char) (stream_id & 0xffU);

    if (king_server_http2_write_all_fd(fd, header, sizeof(header), function_name) != SUCCESS) {
        return FAILURE;
    }

    if (payload_len == 0) {
        return SUCCESS;
    }

    return king_server_http2_write_all_fd(fd, payload, payload_len, function_name);
}

static zend_result king_server_http2_read_frame(
    int fd,
    uint8_t *type_out,
    uint8_t *flags_out,
    uint32_t *stream_id_out,
    zend_string **payload_out,
    const char *function_name
)
{
    unsigned char header[9];
    size_t payload_len;
    zend_string *payload;

    if (king_server_http2_read_exact_fd(fd, header, sizeof(header), function_name) != SUCCESS) {
        return FAILURE;
    }

    payload_len = ((size_t) header[0] << 16)
        | ((size_t) header[1] << 8)
        | (size_t) header[2];
    if (payload_len > KING_SERVER_HTTP2_MAX_FRAME_PAYLOAD_BYTES) {
        king_server_local_set_errorf(
            "%s() received an HTTP/2 frame larger than the current one-shot wire leaf allows.",
            function_name
        );
        return FAILURE;
    }

    payload = zend_string_alloc(payload_len, 0);
    if (payload_len > 0) {
        if (
            king_server_http2_read_exact_fd(
                fd,
                (unsigned char *) ZSTR_VAL(payload),
                payload_len,
                function_name
            ) != SUCCESS
        ) {
            zend_string_release(payload);
            return FAILURE;
        }
    }
    ZSTR_VAL(payload)[payload_len] = '\0';

    *type_out = header[3];
    *flags_out = header[4];
    *stream_id_out = (
        ((uint32_t) header[5] & 0x7fU) << 24
        | ((uint32_t) header[6] << 16)
        | ((uint32_t) header[7] << 8)
        | (uint32_t) header[8]
    );
    *payload_out = payload;
    return SUCCESS;
}

static zend_result king_server_http2_expect_client_preface(
    int fd,
    const char *function_name
)
{
    unsigned char preface[KING_SERVER_HTTP2_CLIENT_PREFACE_LEN];

    if (
        king_server_http2_read_exact_fd(
            fd,
            preface,
            sizeof(preface),
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (
        memcmp(
            preface,
            KING_SERVER_HTTP2_CLIENT_PREFACE,
            KING_SERVER_HTTP2_CLIENT_PREFACE_LEN
        ) != 0
    ) {
        king_server_local_set_errorf(
            "%s() received an invalid HTTP/2 client preface.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_server_http2_send_settings(
    int fd,
    const char *function_name
)
{
    return king_server_http2_write_frame(
        fd,
        KING_SERVER_HTTP2_FRAME_SETTINGS,
        0,
        0,
        NULL,
        0,
        function_name
    );
}

static zend_result king_server_http2_send_settings_ack(
    int fd,
    const char *function_name
)
{
    return king_server_http2_write_frame(
        fd,
        KING_SERVER_HTTP2_FRAME_SETTINGS,
        KING_SERVER_HTTP2_FLAG_ACK,
        0,
        NULL,
        0,
        function_name
    );
}

static zend_result king_server_http2_send_goaway(
    int fd,
    uint32_t last_stream_id,
    const char *function_name
)
{
    unsigned char payload[8];

    payload[0] = (unsigned char) ((last_stream_id >> 24) & 0x7fU);
    payload[1] = (unsigned char) ((last_stream_id >> 16) & 0xffU);
    payload[2] = (unsigned char) ((last_stream_id >> 8) & 0xffU);
    payload[3] = (unsigned char) (last_stream_id & 0xffU);
    payload[4] = 0;
    payload[5] = 0;
    payload[6] = 0;
    payload[7] = 0;

    return king_server_http2_write_frame(
        fd,
        KING_SERVER_HTTP2_FRAME_GOAWAY,
        0,
        0,
        payload,
        sizeof(payload),
        function_name
    );
}

static void king_server_http2_drain_after_goaway(int fd)
{
    struct pollfd pfd;
    int attempt;

    (void) shutdown(fd, SHUT_WR);

    memset(&pfd, 0, sizeof(pfd));
    pfd.fd = fd;
    pfd.events = POLLIN | POLLHUP;

    for (attempt = 0; attempt < 4; attempt++) {
        int poll_rc = poll(&pfd, 1, 100);

        if (poll_rc <= 0) {
            break;
        }

        if ((pfd.revents & (POLLIN | POLLHUP)) == 0) {
            break;
        }

        if ((pfd.revents & POLLIN) != 0) {
            unsigned char header[9];
            ssize_t received = recv(fd, header, sizeof(header), MSG_WAITALL);
            size_t payload_len;

            if (received <= 0 || received < (ssize_t) sizeof(header)) {
                break;
            }

            payload_len = ((size_t) header[0] << 16)
                | ((size_t) header[1] << 8)
                | (size_t) header[2];
            while (payload_len > 0) {
                unsigned char discard[256];
                size_t chunk_len = payload_len < sizeof(discard)
                    ? payload_len
                    : sizeof(discard);

                received = recv(fd, discard, chunk_len, MSG_WAITALL);
                if (received <= 0) {
                    return;
                }

                payload_len -= (size_t) received;
            }
        }

        if ((pfd.revents & POLLHUP) != 0) {
            break;
        }
    }
}

