static zend_result king_http1_recv_response(
    int fd,
    smart_str *response_buffer,
    king_http1_response_meta_t *meta,
    bool expect_no_body,
    const king_http1_request_options_t *options,
    const char *function_name)
{
    bool headers_parsed = false;
    size_t total_received_bytes = 0;

    while (1) {
        char chunk[4096];
        ssize_t received;

        received = recv(fd, chunk, sizeof(chunk), 0);
        if (received > 0) {
            if (king_http1_response_account_bytes(
                    &total_received_bytes,
                    (size_t) received,
                    function_name
                ) != SUCCESS) {
                return FAILURE;
            }

            smart_str_appendl(response_buffer, chunk, (size_t) received);
            smart_str_0(response_buffer);

            if (ZSTR_LEN(response_buffer->s) > KING_HTTP1_MAX_RESPONSE_BYTES) {
                king_http1_throw(
                    king_ce_protocol_exception,
                    "%s() exceeded the HTTP/1 runtime response size cap.",
                    function_name
                );
                return FAILURE;
            }

            if (king_http1_response_buffer_consume_interim_headers(
                    response_buffer,
                    NULL,
                    function_name
                ) != SUCCESS) {
                return FAILURE;
            }

            if (response_buffer->s == NULL) {
                continue;
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

                    if (
                        expect_no_body
                        || king_http1_status_has_no_body(meta->status_code)
                    ) {
                        return SUCCESS;
                    }

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
                    }
                }
            }

            if (
                headers_parsed
                && (expect_no_body || king_http1_status_has_no_body(meta->status_code))
            ) {
                return SUCCESS;
            } else if (headers_parsed && meta->chunked_transfer_encoding) {
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
                size_t expected_len = header_end + meta->content_length;

                if (
                    header_end != 0
                    && ZSTR_LEN(response_buffer->s) >= expected_len
                ) {
                    if (ZSTR_LEN(response_buffer->s) > expected_len) {
                        king_http1_throw(
                            king_ce_protocol_exception,
                            "%s() received more HTTP/1 body bytes than announced by Content-Length.",
                            function_name
                        );
                        return FAILURE;
                    }
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

    if (
        expect_no_body
        || king_http1_status_has_no_body(meta->status_code)
    ) {
        return SUCCESS;
    }

    if (response_buffer->s != NULL) {
        size_t header_end = king_http1_find_header_end(
            ZSTR_VAL(response_buffer->s),
            ZSTR_LEN(response_buffer->s)
        );

        if (meta->chunked_transfer_encoding) {
            king_http1_throw(
                king_ce_protocol_exception,
                "%s() peer closed the HTTP/1 chunked body stream before the terminating chunk was received.",
                function_name
            );
            return FAILURE;
        }

        if (
            meta->has_content_length
            && header_end != 0
            && ZSTR_LEN(response_buffer->s) < header_end + meta->content_length
        ) {
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

