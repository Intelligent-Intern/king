zend_result king_http1_request_context_build_payload(
    king_http1_request_context *context,
    zval *payload,
    const char *function_name)
{
    zval headers_copy;

    if (context == NULL) {
        return FAILURE;
    }

    if (context->response_taken) {
        king_http1_throw(
            king_ce_runtime_exception,
            "%s() request context has already been consumed.",
            function_name
        );
        return FAILURE;
    }

    if (king_http1_request_context_ensure_headers(
            context,
            true,
            function_name,
            NULL,
            NULL,
            NULL
        ) != SUCCESS) {
        return FAILURE;
    }

    array_init(payload);
    add_assoc_long(payload, "status", context->meta.status_code);
    add_assoc_str(
        payload,
        "status_line",
        context->meta.status_line != NULL
            ? zend_string_copy(context->meta.status_line)
            : zend_string_init("", 0, 0)
    );
    ZVAL_COPY(&headers_copy, &context->meta.headers);
    add_assoc_zval(payload, "headers", &headers_copy);
    add_assoc_string(payload, "protocol", "http/1.1");
    add_assoc_string(payload, "transport_backend", "tcp_socket");
    add_assoc_stringl(
        payload,
        "effective_url",
        context->effective_url != NULL ? ZSTR_VAL(context->effective_url) : "",
        context->effective_url != NULL ? ZSTR_LEN(context->effective_url) : 0
    );

    context->response_taken = true;
    return SUCCESS;
}

zend_result king_http1_request_context_read(
    king_http1_request_context *context,
    zend_long read_offset,
    size_t length,
    zend_string **chunk_out,
    const char *function_name)
{
    size_t buffered_len;
    size_t offset;
    size_t chunk_len;

    *chunk_out = NULL;

    if (context == NULL) {
        return FAILURE;
    }

    if (length == 0) {
        *chunk_out = zend_string_init("", 0, 0);
        return SUCCESS;
    }

    if (king_http1_request_context_ensure_body(
            context,
            (size_t) read_offset + length,
            false,
            function_name,
            NULL,
            NULL,
            NULL
        ) != SUCCESS) {
        return FAILURE;
    }

    buffered_len = context->body_buffer.s != NULL
        ? ZSTR_LEN(context->body_buffer.s)
        : 0;
    offset = read_offset > 0 ? (size_t) read_offset : 0;

    if (offset >= buffered_len) {
        *chunk_out = zend_string_init("", 0, 0);
        return SUCCESS;
    }

    chunk_len = buffered_len - offset;
    if (chunk_len > length) {
        chunk_len = length;
    }

    *chunk_out = zend_string_init(
        ZSTR_VAL(context->body_buffer.s) + offset,
        chunk_len,
        0
    );
    return SUCCESS;
}

zend_result king_http1_request_context_get_body(
    king_http1_request_context *context,
    zend_string **body_out,
    const char *function_name)
{
    *body_out = NULL;

    if (context == NULL) {
        return FAILURE;
    }

    if (king_http1_request_context_ensure_body(
            context,
            0,
            true,
            function_name,
            NULL,
            NULL,
            NULL
        ) != SUCCESS) {
        return FAILURE;
    }

    if (context->body_buffer.s == NULL) {
        *body_out = zend_string_init("", 0, 0);
        return SUCCESS;
    }

    *body_out = zend_string_copy(context->body_buffer.s);
    return SUCCESS;
}

zend_result king_http1_request_context_append_early_hint(
    king_http1_request_context *context,
    zval *hint,
    const char *function_name)
{
    size_t hint_bytes;

    if (hint == NULL || Z_TYPE_P(hint) != IS_ARRAY) {
        return SUCCESS;
    }

    if (context == NULL) {
        zval_ptr_dtor(hint);
        ZVAL_UNDEF(hint);
        return SUCCESS;
    }

    hint_bytes = king_http1_early_hint_measure_bytes(hint);
    if (
        context->pending_early_hints_count >= KING_HTTP1_MAX_PENDING_EARLY_HINTS
        || hint_bytes > KING_HTTP1_MAX_PENDING_EARLY_HINT_BYTES
        || context->pending_early_hints_bytes > KING_HTTP1_MAX_PENDING_EARLY_HINT_BYTES - hint_bytes
    ) {
        zval_ptr_dtor(hint);
        ZVAL_UNDEF(hint);
        king_http1_throw(
            king_ce_protocol_exception,
            "%s() exceeded the HTTP/1 pending Early Hints budget.",
            function_name
        );
        return FAILURE;
    }

    if (Z_ISUNDEF(context->pending_early_hints)) {
        array_init(&context->pending_early_hints);
    }

    add_next_index_zval(&context->pending_early_hints, hint);
    context->pending_early_hints_count++;
    context->pending_early_hints_bytes += hint_bytes;
    return SUCCESS;
}

zend_result king_http1_request_context_get_pending_early_hints(
    king_http1_request_context *context,
    zval *return_value)
{
    zval *hint_value;

    array_init(return_value);

    if (
        context == NULL
        || Z_ISUNDEF(context->pending_early_hints)
        || Z_TYPE(context->pending_early_hints) != IS_ARRAY
    ) {
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(context->pending_early_hints), hint_value) {
        zval hint_copy;

        ZVAL_COPY(&hint_copy, hint_value);
        add_next_index_zval(return_value, &hint_copy);
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

bool king_http1_request_context_is_end_of_body(
    king_http1_request_context *context,
    zend_long read_offset)
{
    size_t buffered_len;
    size_t offset;

    if (context == NULL) {
        return true;
    }

    buffered_len = context->body_buffer.s != NULL
        ? ZSTR_LEN(context->body_buffer.s)
        : 0;
    offset = read_offset > 0 ? (size_t) read_offset : 0;

    return context->response_complete && offset >= buffered_len;
}
