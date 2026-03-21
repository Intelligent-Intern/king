static size_t king_http1_find_header_end(const char *buffer, size_t buffer_len)
{
    size_t i;

    if (buffer_len < 4) {
        return 0;
    }

    for (i = 0; i + 3 < buffer_len; ++i) {
        if (
            buffer[i] == '\r'
            && buffer[i + 1] == '\n'
            && buffer[i + 2] == '\r'
            && buffer[i + 3] == '\n'
        ) {
            return i + 4;
        }
    }

    return 0;
}

static void king_http1_lowercase_ascii(char *value, size_t value_len)
{
    size_t i;

    for (i = 0; i < value_len; ++i) {
        value[i] = (char) tolower((unsigned char) value[i]);
    }
}

static bool king_http1_contains_ci(const char *haystack, const char *needle)
{
    size_t haystack_len;
    size_t needle_len;
    size_t i;

    haystack_len = strlen(haystack);
    needle_len = strlen(needle);

    if (needle_len == 0 || haystack_len < needle_len) {
        return false;
    }

    for (i = 0; i + needle_len <= haystack_len; ++i) {
        if (strncasecmp(haystack + i, needle, needle_len) == 0) {
            return true;
        }
    }

    return false;
}

static zend_result king_http1_parse_chunk_size(
    const char *line,
    size_t line_len,
    size_t *chunk_size,
    const char *function_name)
{
    size_t value = 0;
    bool saw_digit = false;
    size_t i = 0;

    while (i < line_len && (line[i] == ' ' || line[i] == '\t')) {
        i++;
    }

    for (; i < line_len; ++i) {
        unsigned char c = (unsigned char) line[i];
        unsigned char lower;
        size_t digit;

        if (c == ';') {
            break;
        }

        if (c == ' ' || c == '\t') {
            while (i < line_len && (line[i] == ' ' || line[i] == '\t')) {
                i++;
            }
            if (i < line_len && line[i] != ';') {
                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() received an invalid HTTP/1 chunk size line.",
                    function_name
                );
                return FAILURE;
            }
            break;
        }

        lower = (unsigned char) tolower(c);
        if (lower >= '0' && lower <= '9') {
            digit = (size_t) (lower - '0');
        } else if (lower >= 'a' && lower <= 'f') {
            digit = (size_t) (10 + (lower - 'a'));
        } else {
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() received an invalid HTTP/1 chunk size line.",
                function_name
            );
            return FAILURE;
        }

        saw_digit = true;
        if (value > ((((size_t) -1) - digit) / 16)) {
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() received an oversized HTTP/1 chunk size.",
                function_name
            );
            return FAILURE;
        }

        value = (value * 16) + digit;
    }

    if (!saw_digit) {
        king_http1_throw(
            king_ce_protocol_exception,
            "%s() received an empty HTTP/1 chunk size line.",
            function_name
        );
        return FAILURE;
    }

    *chunk_size = value;
    return SUCCESS;
}

static zend_result king_http1_find_chunked_message_end(
    const char *buffer,
    size_t buffer_len,
    size_t body_offset,
    size_t *message_end,
    const char *function_name)
{
    const char *cursor = buffer + body_offset;
    const char *end = buffer + buffer_len;

    *message_end = 0;

    while (cursor < end) {
        const char *line_end = NULL;
        const char *scan;
        size_t chunk_size;

        for (scan = cursor; scan + 1 < end; ++scan) {
            if (scan[0] == '\r' && scan[1] == '\n') {
                line_end = scan;
                break;
            }
        }

        if (line_end == NULL) {
            return SUCCESS;
        }

        if (king_http1_parse_chunk_size(
                cursor,
                (size_t) (line_end - cursor),
                &chunk_size,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }

        cursor = line_end + 2;

        if (chunk_size == 0) {
            size_t trailer_end;

            if ((size_t) (end - cursor) < 2) {
                return SUCCESS;
            }

            if (cursor[0] == '\r' && cursor[1] == '\n') {
                *message_end = (size_t) ((cursor - buffer) + 2);
                return SUCCESS;
            }

            trailer_end = king_http1_find_header_end(cursor, (size_t) (end - cursor));
            if (trailer_end == 0) {
                return SUCCESS;
            }

            *message_end = (size_t) ((cursor - buffer) + trailer_end);
            return SUCCESS;
        }

        if ((size_t) (end - cursor) < chunk_size + 2) {
            return SUCCESS;
        }

        if (cursor[chunk_size] != '\r' || cursor[chunk_size + 1] != '\n') {
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() received a malformed HTTP/1 chunk payload terminator.",
                function_name
            );
            return FAILURE;
        }

        cursor += chunk_size + 2;
    }

    return SUCCESS;
}

static zend_result king_http1_decode_chunked_body(
    const char *buffer,
    size_t buffer_len,
    size_t body_offset,
    smart_str *decoded_body,
    const char *function_name)
{
    const char *cursor = buffer + body_offset;
    const char *end = buffer + buffer_len;

    while (cursor < end) {
        const char *line_end = NULL;
        const char *scan;
        size_t chunk_size;

        for (scan = cursor; scan + 1 < end; ++scan) {
            if (scan[0] == '\r' && scan[1] == '\n') {
                line_end = scan;
                break;
            }
        }

        if (line_end == NULL) {
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() received an incomplete HTTP/1 chunk size line.",
                function_name
            );
            return FAILURE;
        }

        if (king_http1_parse_chunk_size(
                cursor,
                (size_t) (line_end - cursor),
                &chunk_size,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }

        cursor = line_end + 2;

        if (chunk_size == 0) {
            if ((size_t) (end - cursor) < 2) {
                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() received an incomplete HTTP/1 trailer section.",
                    function_name
                );
                return FAILURE;
            }

            if (!(cursor[0] == '\r' && cursor[1] == '\n')) {
                size_t trailer_end = king_http1_find_header_end(cursor, (size_t) (end - cursor));

                if (trailer_end == 0) {
                    king_http1_throw(
                        king_ce_protocol_exception,
                        "%s() received an incomplete HTTP/1 trailer section.",
                        function_name
                    );
                    return FAILURE;
                }
            }

            smart_str_0(decoded_body);
            return SUCCESS;
        }

        if ((size_t) (end - cursor) < chunk_size + 2) {
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() received an incomplete HTTP/1 chunk payload.",
                function_name
            );
            return FAILURE;
        }

        if (chunk_size > 0) {
            smart_str_appendl(decoded_body, cursor, chunk_size);
        }

        if (cursor[chunk_size] != '\r' || cursor[chunk_size + 1] != '\n') {
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() received a malformed HTTP/1 chunk payload terminator.",
                function_name
            );
            return FAILURE;
        }

        cursor += chunk_size + 2;
    }

    king_http1_throw(
        king_ce_protocol_exception,
        "%s() received an incomplete HTTP/1 chunked body.",
        function_name
    );
    return FAILURE;
}
