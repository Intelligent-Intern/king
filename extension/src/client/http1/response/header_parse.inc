/*
 * =========================================================================
 * FILENAME:   src/client/http1/response/header_parse.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * HTTP/1 response-header parsing helpers. This slice validates the status
 * line, normalizes header names to lowercase, preserves repeated header
 * fields in arrival order, derives keep-alive and chunked/content-length
 * policy, and materializes the shared response-meta snapshot used by both
 * buffered and streaming reads.
 * =========================================================================
 */

static zend_result king_http1_parse_response_headers(
    const char *buffer,
    size_t header_len,
    king_http1_response_meta_t *meta,
    const char *function_name)
{
    char *header_copy;
    char *line;
    char *cursor;
    char *line_end;

    memset(meta, 0, sizeof(*meta));

    if (header_len == 0 || header_len > KING_HTTP1_MAX_HEADER_BYTES) {
        king_http1_throw(
            king_ce_protocol_exception,
            "%s() received an invalid or oversized HTTP/1 response header block.",
            function_name
        );
        return FAILURE;
    }

    header_copy = estrndup(buffer, header_len - 4);

    line = header_copy;
    line_end = strstr(line, "\r\n");
    if (line_end != NULL) {
        *line_end = '\0';
    }

    if (*line == '\0') {
        efree(header_copy);
        king_http1_throw(
            king_ce_protocol_exception,
            "%s() received a malformed HTTP/1 status line.",
            function_name
        );
        return FAILURE;
    }

    meta->status_line = zend_string_init(line, strlen(line), 0);
    meta->persistent_by_default = strncasecmp(line, "HTTP/1.1", sizeof("HTTP/1.1") - 1) == 0;

    {
        char *status_code_start = strchr(line, ' ');
        char *status_code_end;
        long status_code;

        if (status_code_start == NULL) {
            efree(header_copy);
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() received a malformed HTTP/1 status line.",
                function_name
            );
            return FAILURE;
        }

        status_code = strtol(status_code_start + 1, &status_code_end, 10);
        if (status_code_end == status_code_start + 1 || status_code < 100 || status_code > 599) {
            efree(header_copy);
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() received an invalid HTTP/1 status code.",
                function_name
            );
            return FAILURE;
        }

        meta->status_code = (zend_long) status_code;
    }

    array_init(&meta->headers);
    meta->headers_initialized = true;

    cursor = line_end != NULL ? line_end + 2 : line + strlen(line);
    while (*cursor != '\0') {
        char *colon;
        char *value;
        size_t key_len;
        size_t value_len;
        char *lowercase_key;

        line_end = strstr(cursor, "\r\n");
        if (line_end != NULL) {
            *line_end = '\0';
        }

        if (*cursor == '\0') {
            break;
        }

        colon = strchr(cursor, ':');
        if (colon == NULL) {
            efree(header_copy);
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() received a malformed HTTP/1 header line.",
                function_name
            );
            return FAILURE;
        }

        *colon = '\0';
        value = colon + 1;
        while (*value == ' ' || *value == '\t') {
            value++;
        }

        key_len = strlen(cursor);
        value_len = strlen(value);
        while (value_len > 0 && (value[value_len - 1] == ' ' || value[value_len - 1] == '\t')) {
            value[--value_len] = '\0';
        }

        lowercase_key = estrndup(cursor, key_len);
        king_http1_lowercase_ascii(lowercase_key, key_len);
        king_http1_add_normalized_header_value(
            &meta->headers,
            lowercase_key,
            key_len,
            value,
            value_len
        );

        if (strcmp(lowercase_key, "content-length") == 0) {
            char *length_end;
            zend_long parsed_length;

            parsed_length = strtol(value, &length_end, 10);
            if (length_end == value || parsed_length < 0) {
                efree(lowercase_key);
                efree(header_copy);
                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() received an invalid Content-Length header.",
                    function_name
                );
                return FAILURE;
            }

            meta->has_content_length = true;
            meta->content_length = (size_t) parsed_length;
        } else if (
            strcmp(lowercase_key, "transfer-encoding") == 0
            && king_http1_contains_ci(value, "chunked")
        ) {
            meta->chunked_transfer_encoding = true;
        } else if (strcmp(lowercase_key, "connection") == 0) {
            if (king_http1_header_value_has_token(value, "close")) {
                meta->connection_close = true;
            }
            if (king_http1_header_value_has_token(value, "keep-alive")) {
                meta->connection_keep_alive = true;
            }
        }

        efree(lowercase_key);
        if (line_end == NULL) {
            break;
        }

        cursor = line_end + 2;
    }

    if (meta->chunked_transfer_encoding) {
        meta->has_content_length = false;
        meta->content_length = 0;
    }

    efree(header_copy);
    return SUCCESS;
}
