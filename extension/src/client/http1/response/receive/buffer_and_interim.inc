/*
 * =========================================================================
 * FILENAME:   src/client/http1/response/receive.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * HTTP/1 receive and streaming-context helpers. This slice drives socket
 * reads, consumes interim 1xx headers, feeds pending early hints into the
 * live request context, finalizes buffered vs. streaming bodies, and exposes
 * the `King\\HttpRequestContext` read/getBody/isEndOfBody surface.
 * =========================================================================
 */

static void king_http1_response_buffer_discard_prefix(
    smart_str *buffer,
    size_t prefix_len)
{
    smart_str compacted = {0};
    size_t total_len;

    if (buffer == NULL || buffer->s == NULL || prefix_len == 0) {
        return;
    }

    total_len = ZSTR_LEN(buffer->s);
    if (prefix_len >= total_len) {
        smart_str_free(buffer);
        return;
    }

    smart_str_appendl(
        &compacted,
        ZSTR_VAL(buffer->s) + prefix_len,
        total_len - prefix_len
    );
    smart_str_0(&compacted);

    smart_str_free(buffer);
    *buffer = compacted;
}

static zend_result king_http1_response_account_bytes(
    size_t *total_bytes,
    size_t delta,
    const char *function_name)
{
    if (total_bytes == NULL || delta == 0) {
        return SUCCESS;
    }

    if (
        delta > KING_HTTP1_MAX_RESPONSE_BYTES
        || *total_bytes > KING_HTTP1_MAX_RESPONSE_BYTES - delta
    ) {
        king_http1_throw(
            king_ce_protocol_exception,
            "%s() exceeded the HTTP/1 runtime response size cap.",
            function_name
        );
        return FAILURE;
    }

    *total_bytes += delta;
    return SUCCESS;
}

static size_t king_http1_early_hint_measure_bytes(zval *hint)
{
    size_t total = 0;
    zend_string *key;
    zval *value;

    if (hint == NULL || Z_TYPE_P(hint) != IS_ARRAY) {
        return 0;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(hint), key, value) {
        if (key != NULL) {
            total += ZSTR_LEN(key);
        }

        switch (Z_TYPE_P(value)) {
            case IS_STRING:
                total += Z_STRLEN_P(value);
                break;
            case IS_TRUE:
            case IS_FALSE:
                total += 1;
                break;
            case IS_LONG:
            case IS_DOUBLE:
                total += 32;
                break;
            default:
                total += 16;
                break;
        }
    } ZEND_HASH_FOREACH_END();

    return total;
}

static zend_result king_http1_response_buffer_consume_interim_headers(
    smart_str *buffer,
    king_http1_request_context *context,
    const char *function_name)
{
    while (buffer != NULL && buffer->s != NULL) {
        king_http1_response_meta_t interim_meta;
        size_t header_end = king_http1_find_header_end(
            ZSTR_VAL(buffer->s),
            ZSTR_LEN(buffer->s)
        );

        if (header_end == 0) {
            return SUCCESS;
        }

        memset(&interim_meta, 0, sizeof(interim_meta));
        if (king_http1_parse_response_headers(
                ZSTR_VAL(buffer->s),
                header_end,
                &interim_meta,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }

        if (
            interim_meta.status_code < 100
            || interim_meta.status_code >= 200
            || interim_meta.status_code == 101
        ) {
            king_http1_response_meta_destroy(&interim_meta);
            return SUCCESS;
        }

        if (context != NULL && interim_meta.status_code == 103) {
            if (king_client_early_hints_process_headers(
                    context,
                    &interim_meta.headers,
                    function_name
                ) != SUCCESS) {
                king_http1_response_meta_destroy(&interim_meta);
                return FAILURE;
            }
        }

        king_http1_response_meta_destroy(&interim_meta);
        king_http1_response_buffer_discard_prefix(buffer, header_end);
    }

    return SUCCESS;
}

