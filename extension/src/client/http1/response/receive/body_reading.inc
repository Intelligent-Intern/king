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

