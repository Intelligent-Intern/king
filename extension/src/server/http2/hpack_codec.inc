static zend_result king_server_http2_hpack_static_name_value(
    uint32_t index,
    const char **name_out,
    const char **value_out
)
{
    switch (index) {
        case 1:
            *name_out = ":authority";
            *value_out = "";
            return SUCCESS;
        case 2:
            *name_out = ":method";
            *value_out = "GET";
            return SUCCESS;
        case 3:
            *name_out = ":method";
            *value_out = "POST";
            return SUCCESS;
        case 4:
            *name_out = ":path";
            *value_out = "/";
            return SUCCESS;
        case 5:
            *name_out = ":path";
            *value_out = "/index.html";
            return SUCCESS;
        case 6:
            *name_out = ":scheme";
            *value_out = "http";
            return SUCCESS;
        case 7:
            *name_out = ":scheme";
            *value_out = "https";
            return SUCCESS;
        case 8:
            *name_out = ":status";
            *value_out = "200";
            return SUCCESS;
        case 9:
            *name_out = ":status";
            *value_out = "204";
            return SUCCESS;
        case 10:
            *name_out = ":status";
            *value_out = "206";
            return SUCCESS;
        case 11:
            *name_out = ":status";
            *value_out = "304";
            return SUCCESS;
        case 12:
            *name_out = ":status";
            *value_out = "400";
            return SUCCESS;
        case 13:
            *name_out = ":status";
            *value_out = "404";
            return SUCCESS;
        case 14:
            *name_out = ":status";
            *value_out = "500";
            return SUCCESS;
        case 31:
            *name_out = "content-type";
            *value_out = "";
            return SUCCESS;
        default:
            return FAILURE;
    }
}

static zend_result king_server_http2_hpack_static_name(
    uint32_t index,
    const char **name_out
)
{
    const char *value = NULL;

    if (king_server_http2_hpack_static_name_value(index, name_out, &value) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_server_http2_hpack_decode_integer(
    const unsigned char *buffer,
    size_t buffer_len,
    uint8_t prefix_bits,
    uint32_t *value_out,
    size_t *consumed_out,
    const char *function_name
)
{
    uint32_t value;
    uint32_t mask = (1U << prefix_bits) - 1U;
    size_t consumed = 1;
    uint32_t multiplier = 0;

    if (buffer_len == 0) {
        king_server_local_set_errorf(
            "%s() received a truncated HPACK integer.",
            function_name
        );
        return FAILURE;
    }

    value = (uint32_t) (buffer[0] & mask);
    if (value < mask) {
        *value_out = value;
        *consumed_out = consumed;
        return SUCCESS;
    }

    while (consumed < buffer_len) {
        uint8_t byte = buffer[consumed++];

        value += (uint32_t) (byte & 0x7f) << multiplier;
        if ((byte & 0x80) == 0) {
            *value_out = value;
            *consumed_out = consumed;
            return SUCCESS;
        }

        multiplier += 7;
        if (multiplier > 28) {
            king_server_local_set_errorf(
                "%s() received an HPACK integer that is too large.",
                function_name
            );
            return FAILURE;
        }
    }

    king_server_local_set_errorf(
        "%s() received a truncated HPACK integer continuation.",
        function_name
    );
    return FAILURE;
}

static zend_result king_server_http2_hpack_decode_string(
    const unsigned char *buffer,
    size_t buffer_len,
    zend_string **value_out,
    size_t *consumed_out,
    const char *function_name
)
{
    uint32_t string_len = 0;
    size_t consumed = 0;

    if (buffer_len == 0) {
        king_server_local_set_errorf(
            "%s() received a truncated HPACK string.",
            function_name
        );
        return FAILURE;
    }

    if ((buffer[0] & 0x80) != 0) {
        king_server_local_set_errorf(
            "%s() does not accept Huffman-coded HPACK strings on the current HTTP/2 wire leaf.",
            function_name
        );
        return FAILURE;
    }

    if (
        king_server_http2_hpack_decode_integer(
            buffer,
            buffer_len,
            7,
            &string_len,
            &consumed,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    if (buffer_len - consumed < string_len) {
        king_server_local_set_errorf(
            "%s() received a truncated HPACK string payload.",
            function_name
        );
        return FAILURE;
    }

    *value_out = zend_string_init(
        (const char *) buffer + consumed,
        string_len,
        0
    );
    *consumed_out = consumed + string_len;
    return SUCCESS;
}

static zend_result king_server_http2_request_store_header(
    king_server_http2_request_state *state,
    zend_string *name,
    zend_string *value
)
{
    zval *existing;

    if (!state->headers_initialized) {
        array_init(&state->headers);
        state->headers_initialized = 1;
    }

    existing = zend_hash_find(Z_ARRVAL(state->headers), name);
    if (existing == NULL) {
        zval value_zv;

        ZVAL_STR(&value_zv, zend_string_copy(value));
        zend_hash_update(Z_ARRVAL(state->headers), name, &value_zv);
    } else if (Z_TYPE_P(existing) == IS_ARRAY) {
        add_next_index_str(existing, zend_string_copy(value));
    } else {
        zval values;
        zend_string *first_value = zval_get_string(existing);

        array_init(&values);
        add_next_index_str(&values, first_value);
        add_next_index_str(&values, zend_string_copy(value));
        zend_hash_update(Z_ARRVAL(state->headers), name, &values);
    }

    if (zend_string_equals_literal(name, ":method")) {
        if (state->method != NULL) {
            zend_string_release(state->method);
        }
        state->method = zend_string_copy(value);
    } else if (zend_string_equals_literal(name, ":path")) {
        if (state->path != NULL) {
            zend_string_release(state->path);
        }
        state->path = zend_string_copy(value);
    } else if (zend_string_equals_literal(name, ":scheme")) {
        if (state->scheme != NULL) {
            zend_string_release(state->scheme);
        }
        state->scheme = zend_string_copy(value);
    } else if (zend_string_equals_literal(name, ":authority")) {
        if (state->authority != NULL) {
            zend_string_release(state->authority);
        }
        state->authority = zend_string_copy(value);
    }

    return SUCCESS;
}

static zend_result king_server_http2_hpack_decode_headers(
    const unsigned char *buffer,
    size_t buffer_len,
    king_server_http2_request_state *state,
    const char *function_name
)
{
    size_t offset = 0;

    while (offset < buffer_len) {
        uint8_t first = buffer[offset];
        zend_string *name = NULL;
        zend_string *value = NULL;
        uint32_t name_index = 0;
        size_t consumed = 0;
        size_t string_consumed = 0;

        if ((first & 0x80) != 0) {
            const char *static_name = NULL;
            const char *static_value = NULL;
            uint32_t header_index = 0;

            if (
                king_server_http2_hpack_decode_integer(
                    buffer + offset,
                    buffer_len - offset,
                    7,
                    &header_index,
                    &consumed,
                    function_name
                ) != SUCCESS
            ) {
                return FAILURE;
            }

            if (
                header_index == 0
                || king_server_http2_hpack_static_name_value(
                    header_index,
                    &static_name,
                    &static_value
                ) != SUCCESS
            ) {
                king_server_local_set_errorf(
                    "%s() received an unsupported indexed HPACK header (%u).",
                    function_name,
                    header_index
                );
                return FAILURE;
            }

            name = zend_string_init(static_name, strlen(static_name), 0);
            value = zend_string_init(static_value, strlen(static_value), 0);
            offset += consumed;
        } else if ((first & 0xe0) == 0x20) {
            king_server_local_set_errorf(
                "%s() does not accept HPACK dynamic table size updates on the current HTTP/2 wire leaf.",
                function_name
            );
            return FAILURE;
        } else if ((first & 0xc0) == 0x40) {
            if (
                king_server_http2_hpack_decode_integer(
                    buffer + offset,
                    buffer_len - offset,
                    6,
                    &name_index,
                    &consumed,
                    function_name
                ) != SUCCESS
            ) {
                return FAILURE;
            }

            offset += consumed;
            if (name_index == 0) {
                if (
                    king_server_http2_hpack_decode_string(
                        buffer + offset,
                        buffer_len - offset,
                        &name,
                        &string_consumed,
                        function_name
                    ) != SUCCESS
                ) {
                    return FAILURE;
                }
                offset += string_consumed;
            } else {
                const char *static_name = NULL;

                if (
                    king_server_http2_hpack_static_name(name_index, &static_name) != SUCCESS
                ) {
                    king_server_local_set_errorf(
                        "%s() received an unsupported indexed HPACK header name (%u).",
                        function_name,
                        name_index
                    );
                    return FAILURE;
                }

                name = zend_string_init(static_name, strlen(static_name), 0);
            }

            if (
                king_server_http2_hpack_decode_string(
                    buffer + offset,
                    buffer_len - offset,
                    &value,
                    &string_consumed,
                    function_name
                ) != SUCCESS
            ) {
                zend_string_release(name);
                return FAILURE;
            }
            offset += string_consumed;
        } else {
            if (
                king_server_http2_hpack_decode_integer(
                    buffer + offset,
                    buffer_len - offset,
                    4,
                    &name_index,
                    &consumed,
                    function_name
                ) != SUCCESS
            ) {
                return FAILURE;
            }

            offset += consumed;
            if (name_index == 0) {
                if (
                    king_server_http2_hpack_decode_string(
                        buffer + offset,
                        buffer_len - offset,
                        &name,
                        &string_consumed,
                        function_name
                    ) != SUCCESS
                ) {
                    return FAILURE;
                }
                offset += string_consumed;
            } else {
                const char *static_name = NULL;

                if (
                    king_server_http2_hpack_static_name(name_index, &static_name) != SUCCESS
                ) {
                    king_server_local_set_errorf(
                        "%s() received an unsupported indexed HPACK header name (%u).",
                        function_name,
                        name_index
                    );
                    return FAILURE;
                }

                name = zend_string_init(static_name, strlen(static_name), 0);
            }

            if (
                king_server_http2_hpack_decode_string(
                    buffer + offset,
                    buffer_len - offset,
                    &value,
                    &string_consumed,
                    function_name
                ) != SUCCESS
            ) {
                zend_string_release(name);
                return FAILURE;
            }
            offset += string_consumed;
        }

        king_server_http2_request_store_header(state, name, value);
        zend_string_release(name);
        zend_string_release(value);
    }

    return SUCCESS;
}

