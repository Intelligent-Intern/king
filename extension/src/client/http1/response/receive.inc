static zend_result king_http1_recv_response(
    int fd,
    smart_str *response_buffer,
    king_http1_response_meta_t *meta,
    bool expect_no_body,
    const king_http1_request_options_t *options,
    const char *function_name)
{
    bool headers_parsed = false;

    while (1) {
        char chunk[4096];
        ssize_t received;

        received = recv(fd, chunk, sizeof(chunk), 0);
        if (received > 0) {
            smart_str_appendl(response_buffer, chunk, (size_t) received);
            smart_str_0(response_buffer);

            if (ZSTR_LEN(response_buffer->s) > KING_HTTP1_MAX_RESPONSE_BYTES) {
                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() exceeded the HTTP/1 skeleton response size cap.",
                    function_name
                );
                return FAILURE;
            }

            if (!headers_parsed) {
                size_t header_end = king_http1_find_header_end(
                    ZSTR_VAL(response_buffer->s),
                    ZSTR_LEN(response_buffer->s)
                );

                if (header_end != 0) {
                    size_t response_end = 0;

                    if (king_http1_parse_response_headers(
                            ZSTR_VAL(response_buffer->s),
                            header_end,
                            meta,
                            function_name
                        ) != SUCCESS) {
                        return FAILURE;
                    }

                    headers_parsed = true;

                    if (meta->chunked_transfer_encoding) {
                        if (king_http1_find_chunked_message_end(
                                ZSTR_VAL(response_buffer->s),
                                ZSTR_LEN(response_buffer->s),
                                header_end,
                                &response_end,
                                function_name
                            ) != SUCCESS) {
                            return FAILURE;
                        }

                        if (response_end != 0) {
                            return SUCCESS;
                        }
                    } else if (
                        !meta->has_content_length
                        && (expect_no_body || king_http1_status_has_no_body(meta->status_code))
                    ) {
                        return SUCCESS;
                    }
                }
            }

            if (headers_parsed && meta->chunked_transfer_encoding) {
                size_t header_end = king_http1_find_header_end(
                    ZSTR_VAL(response_buffer->s),
                    ZSTR_LEN(response_buffer->s)
                );
                size_t response_end = 0;

                if (header_end != 0) {
                    if (king_http1_find_chunked_message_end(
                            ZSTR_VAL(response_buffer->s),
                            ZSTR_LEN(response_buffer->s),
                            header_end,
                            &response_end,
                            function_name
                        ) != SUCCESS) {
                        return FAILURE;
                    }

                    if (response_end != 0) {
                        return SUCCESS;
                    }
                }
            } else if (headers_parsed && meta->has_content_length) {
                size_t header_end = king_http1_find_header_end(
                    ZSTR_VAL(response_buffer->s),
                    ZSTR_LEN(response_buffer->s)
                );

                if (
                    header_end != 0
                    && ZSTR_LEN(response_buffer->s) >= header_end + meta->content_length
                ) {
                    return SUCCESS;
                }
            }

            continue;
        }

        if (received == 0) {
            break;
        }

        if (errno == EAGAIN || errno == EWOULDBLOCK) {
            if (king_http1_wait_fd(
                    fd,
                    POLLIN,
                    options->timeout_ms,
                    options->cancel_token,
                    options->cancel_function_name,
                    options->cancel_exception_ce,
                    function_name,
                    "receive"
                ) != SUCCESS) {
                return FAILURE;
            }
            continue;
        }

        king_http1_throw(
            king_ce_network_exception,
            "%s() failed while receiving the HTTP/1 response (errno %d).",
            function_name,
            errno
        );
        return FAILURE;
    }

    if (!headers_parsed) {
        king_http1_throw(
            king_ce_protocol_exception,
            "%s() did not receive a complete HTTP/1 response header block.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_http1_request_context_recv(
    king_http1_request_context *context,
    const char *function_name,
    bool *peer_closed,
    zval *cancel_token,
    const char *cancel_function_name,
    zend_class_entry *cancel_exception_ce)
{
    while (1) {
        char chunk[4096];
        ssize_t received;
        smart_str *target_buffer;

        *peer_closed = false;
        if (!context->headers_parsed) {
            target_buffer = &context->header_buffer;
        } else if (context->meta.chunked_transfer_encoding) {
            target_buffer = &context->chunk_buffer;
        } else {
            target_buffer = &context->body_buffer;
        }

        received = recv(context->fd, chunk, sizeof(chunk), 0);
        if (received > 0) {
            smart_str_appendl(target_buffer, chunk, (size_t) received);
            smart_str_0(target_buffer);

            if (
                (target_buffer->s != NULL && ZSTR_LEN(target_buffer->s) > KING_HTTP1_MAX_RESPONSE_BYTES)
                || (context->chunk_buffer.s != NULL && ZSTR_LEN(context->chunk_buffer.s) > KING_HTTP1_MAX_RESPONSE_BYTES)
                || (context->body_buffer.s != NULL && ZSTR_LEN(context->body_buffer.s) > KING_HTTP1_MAX_RESPONSE_BYTES)
            ) {
                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() exceeded the HTTP/1 skeleton response size cap.",
                    function_name
                );
                return FAILURE;
            }

            return SUCCESS;
        }

        if (received == 0) {
            *peer_closed = true;
            return SUCCESS;
        }

        if (errno == EAGAIN || errno == EWOULDBLOCK) {
            if (king_http1_wait_fd(
                    context->fd,
                    POLLIN,
                    context->timeout_ms,
                    cancel_token,
                    cancel_function_name,
                    cancel_exception_ce,
                    function_name,
                    "receive"
                ) != SUCCESS) {
                return FAILURE;
            }
            continue;
        }

        king_http1_throw(
            king_ce_network_exception,
            "%s() failed while receiving the HTTP/1 response (errno %d).",
            function_name,
            errno
        );
        return FAILURE;
    }
}

static void king_http1_request_context_compact_chunk_buffer(
    king_http1_request_context *context)
{
    smart_str compacted = {0};
    size_t remaining;

    if (context == NULL || context->chunk_buffer.s == NULL || context->chunk_parse_offset == 0) {
        return;
    }

    if (context->chunk_parse_offset >= ZSTR_LEN(context->chunk_buffer.s)) {
        smart_str_free(&context->chunk_buffer);
        context->chunk_parse_offset = 0;
        return;
    }

    remaining = ZSTR_LEN(context->chunk_buffer.s) - context->chunk_parse_offset;
    smart_str_appendl(
        &compacted,
        ZSTR_VAL(context->chunk_buffer.s) + context->chunk_parse_offset,
        remaining
    );
    smart_str_0(&compacted);

    smart_str_free(&context->chunk_buffer);
    context->chunk_buffer = compacted;
    context->chunk_parse_offset = 0;
}

static zend_result king_http1_request_context_decode_chunked(
    king_http1_request_context *context,
    const char *function_name)
{
    const char *buffer;
    const char *cursor;
    const char *end;

    if (context == NULL || context->chunk_buffer.s == NULL) {
        return SUCCESS;
    }

    buffer = ZSTR_VAL(context->chunk_buffer.s);
    end = buffer + ZSTR_LEN(context->chunk_buffer.s);
    cursor = buffer + context->chunk_parse_offset;

    while (!context->response_complete && cursor < end) {
        const char *line_end = NULL;
        const char *scan;
        size_t chunk_size;

        if (context->chunk_final_chunk_seen) {
            size_t trailer_len;

            if (cursor == end) {
                context->response_complete = true;
                break;
            }

            trailer_len = king_http1_find_header_end(cursor, (size_t) (end - cursor));
            if (trailer_len == 0) {
                break;
            }

            context->chunk_parse_offset += trailer_len;
            context->response_complete = true;
            break;
        }

        for (scan = cursor; scan + 1 < end; ++scan) {
            if (scan[0] == '\r' && scan[1] == '\n') {
                line_end = scan;
                break;
            }
        }

        if (line_end == NULL) {
            break;
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
                break;
            }

            context->chunk_parse_offset = (size_t) (cursor - buffer);

            if (cursor[0] == '\r' && cursor[1] == '\n') {
                context->chunk_parse_offset += 2;
                context->response_complete = true;
                break;
            }

            context->chunk_final_chunk_seen = true;
            continue;
        }

        if ((size_t) (end - cursor) < chunk_size + 2) {
            break;
        }

        if (chunk_size > 0) {
            smart_str_appendl(&context->body_buffer, cursor, chunk_size);
            smart_str_0(&context->body_buffer);

            if (
                context->body_buffer.s != NULL
                && ZSTR_LEN(context->body_buffer.s) > KING_HTTP1_MAX_RESPONSE_BYTES
            ) {
                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() exceeded the HTTP/1 skeleton response size cap.",
                    function_name
                );
                return FAILURE;
            }
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
        context->chunk_parse_offset = (size_t) (cursor - buffer);
    }

    if (
        context->chunk_parse_offset > 4096
        || (context->response_complete && context->chunk_parse_offset > 0)
    ) {
        king_http1_request_context_compact_chunk_buffer(context);
    }

    if (context->response_complete) {
        king_http1_request_context_release_socket(context);
    }

    return SUCCESS;
}

static zend_result king_http1_request_context_finalize_headers(
    king_http1_request_context *context,
    bool require_self_delimited,
    const char *function_name)
{
    if (
        context->expect_no_body
        || king_http1_status_has_no_body(context->meta.status_code)
    ) {
        context->response_complete = true;
        king_http1_request_context_release_socket(context);
        return SUCCESS;
    }

    if (context->meta.chunked_transfer_encoding) {
        return king_http1_request_context_decode_chunked(context, function_name);
    }

    if (!context->meta.has_content_length) {
        if (!require_self_delimited) {
            return SUCCESS;
        }

        if (context->fd >= 0) {
            close(context->fd);
            context->fd = -1;
        }
        king_http1_throw(
            king_ce_protocol_exception,
            "%s() response_stream mode currently requires a self-delimited or empty HTTP/1 response.",
            function_name
        );
        return FAILURE;
    }

    if (
        context->body_buffer.s != NULL
        && ZSTR_LEN(context->body_buffer.s) >= context->meta.content_length
    ) {
        if (ZSTR_LEN(context->body_buffer.s) > context->meta.content_length) {
            if (context->fd >= 0) {
                close(context->fd);
                context->fd = -1;
            }
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() received more HTTP/1 body bytes than announced by Content-Length.",
                function_name
            );
            return FAILURE;
        }
        context->response_complete = true;
        king_http1_request_context_release_socket(context);
    }

    return SUCCESS;
}

static zend_result king_http1_request_context_ensure_headers(
    king_http1_request_context *context,
    bool require_self_delimited,
    const char *function_name,
    zval *cancel_token,
    const char *cancel_function_name,
    zend_class_entry *cancel_exception_ce)
{
    if (context->headers_parsed) {
        return king_http1_request_context_finalize_headers(
            context,
            require_self_delimited,
            function_name
        );
    }

    while (!context->headers_parsed) {
        size_t header_end = 0;
        bool peer_closed = false;

        if (context->header_buffer.s != NULL) {
            header_end = king_http1_find_header_end(
                ZSTR_VAL(context->header_buffer.s),
                ZSTR_LEN(context->header_buffer.s)
            );
        }

        if (header_end != 0) {
            size_t body_len;
            const char *body_ptr;

            if (king_http1_parse_response_headers(
                    ZSTR_VAL(context->header_buffer.s),
                    header_end,
                    &context->meta,
                    function_name
                ) != SUCCESS) {
                return FAILURE;
            }

            context->headers_parsed = true;
            body_ptr = ZSTR_VAL(context->header_buffer.s) + header_end;
            body_len = ZSTR_LEN(context->header_buffer.s) - header_end;
            if (body_len > 0) {
                if (context->meta.chunked_transfer_encoding) {
                    smart_str_appendl(&context->chunk_buffer, body_ptr, body_len);
                    smart_str_0(&context->chunk_buffer);
                } else {
                    smart_str_appendl(&context->body_buffer, body_ptr, body_len);
                    smart_str_0(&context->body_buffer);
                }
            }
            smart_str_free(&context->header_buffer);

            return king_http1_request_context_finalize_headers(
                context,
                require_self_delimited,
                function_name
            );
        }

        if (king_http1_request_context_recv(
                context,
                function_name,
                &peer_closed,
                cancel_token,
                cancel_function_name,
                cancel_exception_ce
            ) != SUCCESS) {
            return FAILURE;
        }

        if (peer_closed) {
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() did not receive a complete HTTP/1 response header block.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_http1_request_context_ensure_body(
    king_http1_request_context *context,
    size_t target_len,
    bool drain_full,
    const char *function_name,
    zval *cancel_token,
    const char *cancel_function_name,
    zend_class_entry *cancel_exception_ce)
{
    if (king_http1_request_context_ensure_headers(
            context,
            true,
            function_name,
            cancel_token,
            cancel_function_name,
            cancel_exception_ce
        ) != SUCCESS) {
        return FAILURE;
    }

    if (context->response_complete) {
        return SUCCESS;
    }

    if (context->meta.chunked_transfer_encoding) {
        while (!context->response_complete) {
            size_t buffered_len = context->body_buffer.s != NULL
                ? ZSTR_LEN(context->body_buffer.s)
                : 0;
            bool peer_closed = false;

            if (!drain_full && buffered_len >= target_len) {
                return SUCCESS;
            }

            if (king_http1_request_context_decode_chunked(context, function_name) != SUCCESS) {
                return FAILURE;
            }

            buffered_len = context->body_buffer.s != NULL
                ? ZSTR_LEN(context->body_buffer.s)
                : 0;
            if (context->response_complete || (!drain_full && buffered_len >= target_len)) {
                return SUCCESS;
            }

            if (king_http1_request_context_recv(
                    context,
                    function_name,
                    &peer_closed,
                    cancel_token,
                    cancel_function_name,
                    cancel_exception_ce
                ) != SUCCESS) {
                return FAILURE;
            }

            if (peer_closed) {
                if (king_http1_request_context_decode_chunked(context, function_name) != SUCCESS) {
                    return FAILURE;
                }

                if (context->response_complete) {
                    return SUCCESS;
                }

                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() peer closed the HTTP/1 chunked body stream before the terminating chunk was received.",
                    function_name
                );
                return FAILURE;
            }
        }

        return SUCCESS;
    }

    if (!context->meta.has_content_length) {
        return SUCCESS;
    }

    while (!context->response_complete) {
        size_t buffered_len = context->body_buffer.s != NULL
            ? ZSTR_LEN(context->body_buffer.s)
            : 0;
        bool peer_closed = false;

        if (buffered_len >= context->meta.content_length) {
            if (buffered_len > context->meta.content_length) {
                if (context->fd >= 0) {
                    close(context->fd);
                    context->fd = -1;
                }
                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() received more HTTP/1 body bytes than announced by Content-Length.",
                    function_name
                );
                return FAILURE;
            }
            context->response_complete = true;
            king_http1_request_context_release_socket(context);
            return SUCCESS;
        }

        if (!drain_full && buffered_len >= target_len) {
            return SUCCESS;
        }

        if (king_http1_request_context_recv(
                context,
                function_name,
                &peer_closed,
                cancel_token,
                cancel_function_name,
                cancel_exception_ce
            ) != SUCCESS) {
            return FAILURE;
        }

        if (peer_closed) {
            if (
                context->body_buffer.s != NULL
                && ZSTR_LEN(context->body_buffer.s) == context->meta.content_length
            ) {
                context->response_complete = true;
                king_http1_request_context_release_socket(context);
                return SUCCESS;
            }

            king_http1_throw(
                king_ce_protocol_exception,
                "%s() peer closed the HTTP/1 body stream before Content-Length bytes were received.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

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

void king_http1_request_context_append_early_hint(
    king_http1_request_context *context,
    zval *hint)
{
    if (hint == NULL || Z_TYPE_P(hint) != IS_ARRAY) {
        return;
    }

    if (context == NULL) {
        zval_ptr_dtor(hint);
        ZVAL_UNDEF(hint);
        return;
    }

    if (Z_ISUNDEF(context->pending_early_hints)) {
        array_init(&context->pending_early_hints);
    }

    add_next_index_zval(&context->pending_early_hints, hint);
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
