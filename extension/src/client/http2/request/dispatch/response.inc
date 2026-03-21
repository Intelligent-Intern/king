static zend_result king_http2_request_abort_for_cancel(
    king_http2_pool_entry_t *pool_entry,
    CURL *easy_handle,
    const king_http2_request_options_t *options,
    const char *function_name)
{
    CURLMcode remove_code;

    if (king_transport_maybe_throw_cancel(
            options->cancel_token,
            function_name,
            options->cancel_function_name,
            options->cancel_exception_ce,
            "HTTP/2"
        ) != SUCCESS) {
        if (easy_handle != NULL) {
            remove_code = king_http2_libcurl.curl_multi_remove_handle_fn(pool_entry->multi, easy_handle);
            (void) remove_code;
        }
        king_http2_pool_remove(pool_entry);
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_http2_request_drive_multi_handle(
    king_http2_pool_entry_t *pool_entry,
    CURL *easy_handle,
    const king_http2_request_options_t *options,
    const char *function_name,
    CURLcode *curl_code_out)
{
    CURLMcode multi_code;
    CURLMcode remove_code;
    CURLMsg *message;
    int running_handles = 0;
    int ready_fds = 0;
    int msgs_in_queue = 0;
    zend_long wait_timeout_ms;
    bool transfer_completed = false;

    multi_code = king_http2_libcurl.curl_multi_add_handle_fn(pool_entry->multi, easy_handle);
    if (multi_code != CURLM_OK) {
        king_http2_throw(
            king_ce_system_exception,
            "%s() failed to attach the active HTTP/2 request to the libcurl session pool: %s",
            function_name,
            king_http2_libcurl.curl_multi_strerror_fn(multi_code)
        );
        king_http2_pool_remove(pool_entry);
        return FAILURE;
    }

    do {
        multi_code = king_http2_libcurl.curl_multi_perform_fn(pool_entry->multi, &running_handles);
    } while (multi_code == CURLM_CALL_MULTI_PERFORM);

    if (multi_code != CURLM_OK) {
        king_http2_throw(
            king_ce_system_exception,
            "%s() failed to drive the active libcurl HTTP/2 session pool: %s",
            function_name,
            king_http2_libcurl.curl_multi_strerror_fn(multi_code)
        );
        remove_code = king_http2_libcurl.curl_multi_remove_handle_fn(pool_entry->multi, easy_handle);
        (void) remove_code;
        king_http2_pool_remove(pool_entry);
        return FAILURE;
    }

    wait_timeout_ms = options->timeout_ms > 0
        && options->timeout_ms < KING_HTTP2_MULTI_WAIT_TIMEOUT_MS
        ? options->timeout_ms
        : KING_HTTP2_MULTI_WAIT_TIMEOUT_MS;
    if (wait_timeout_ms > KING_TRANSPORT_INTERRUPT_SLICE_MS) {
        wait_timeout_ms = KING_TRANSPORT_INTERRUPT_SLICE_MS;
    }

    while (running_handles > 0) {
        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            remove_code = king_http2_libcurl.curl_multi_remove_handle_fn(pool_entry->multi, easy_handle);
            (void) remove_code;
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
        if (king_http2_request_abort_for_cancel(
                pool_entry,
                easy_handle,
                options,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }

        ready_fds = 0;
        multi_code = king_http2_libcurl.curl_multi_wait_fn(
            pool_entry->multi,
            NULL,
            0,
            (int) wait_timeout_ms,
            &ready_fds
        );
        if (multi_code != CURLM_OK) {
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed while waiting on the active libcurl HTTP/2 session pool: %s",
                function_name,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            remove_code = king_http2_libcurl.curl_multi_remove_handle_fn(pool_entry->multi, easy_handle);
            (void) remove_code;
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }

        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            remove_code = king_http2_libcurl.curl_multi_remove_handle_fn(pool_entry->multi, easy_handle);
            (void) remove_code;
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
        if (king_http2_request_abort_for_cancel(
                pool_entry,
                easy_handle,
                options,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }

        do {
            multi_code = king_http2_libcurl.curl_multi_perform_fn(pool_entry->multi, &running_handles);
        } while (multi_code == CURLM_CALL_MULTI_PERFORM);

        if (multi_code != CURLM_OK) {
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed to continue the active libcurl HTTP/2 session pool: %s",
                function_name,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            remove_code = king_http2_libcurl.curl_multi_remove_handle_fn(pool_entry->multi, easy_handle);
            (void) remove_code;
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
    }

    *curl_code_out = CURLE_OK;
    while ((message = king_http2_libcurl.curl_multi_info_read_fn(pool_entry->multi, &msgs_in_queue)) != NULL) {
        if (message->msg == CURLMSG_DONE && message->easy_handle == easy_handle) {
            *curl_code_out = message->data.result;
            transfer_completed = true;
            break;
        }
    }

    remove_code = king_http2_libcurl.curl_multi_remove_handle_fn(pool_entry->multi, easy_handle);
    if (remove_code != CURLM_OK) {
        king_http2_throw(
            king_ce_system_exception,
            "%s() failed to detach the active HTTP/2 request from the libcurl session pool: %s",
            function_name,
            king_http2_libcurl.curl_multi_strerror_fn(remove_code)
        );
        king_http2_pool_remove(pool_entry);
        return FAILURE;
    }

    if (!transfer_completed) {
        king_http2_throw(
            king_ce_system_exception,
            "%s() did not receive a completion signal from the active libcurl HTTP/2 session pool.",
            function_name
        );
        king_http2_pool_remove(pool_entry);
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_http2_request_collect_transfer_meta(
    king_http2_pool_entry_t *pool_entry,
    CURL *easy_handle,
    king_http2_response_buffer_t *response,
    const king_http2_request_options_t *options,
    const char *function_name,
    long *response_code_out,
    long *http_version_out,
    char **effective_url_out
)
{
    CURLcode curl_code;

    if (king_http2_request_drive_multi_handle(
            pool_entry,
            easy_handle,
            options,
            function_name,
            &curl_code
        ) != SUCCESS) {
        return FAILURE;
    }

    if (curl_code != CURLE_OK) {
        if (response->header_overflowed) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() exceeded the active skeleton HTTP/2 response-header size limit.",
                function_name
            );
        } else if (response->body_overflowed) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() exceeded the active skeleton HTTP/2 response-body size limit.",
                function_name
            );
        } else {
            king_http2_throw(
                king_http2_map_curl_exception(curl_code),
                "%s() libcurl HTTP/2 transfer failed: %s",
                function_name,
                king_http2_libcurl.curl_easy_strerror_fn(curl_code)
            );
        }

        king_http2_pool_remove(pool_entry);
        return FAILURE;
    }

    if (response->body_overflowed) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() exceeded the active skeleton HTTP/2 response-body size limit.",
            function_name
        );
        return FAILURE;
    }

    if (response->header_overflowed) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() exceeded the active skeleton HTTP/2 response-header size limit.",
            function_name
        );
        return FAILURE;
    }

    if (king_http2_libcurl.curl_easy_getinfo_fn(
            easy_handle,
            CURLINFO_RESPONSE_CODE,
            response_code_out
        ) != CURLE_OK) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() failed to read the HTTP/2 response code from libcurl.",
            function_name
        );
        return FAILURE;
    }

    if (king_http2_libcurl.curl_easy_getinfo_fn(
            easy_handle,
            CURLINFO_HTTP_VERSION,
            http_version_out
        ) != CURLE_OK) {
        *http_version_out = CURL_HTTP_VERSION_NONE;
    }

    if (king_http2_libcurl.curl_easy_getinfo_fn(
            easy_handle,
            CURLINFO_EFFECTIVE_URL,
            effective_url_out
        ) != CURLE_OK) {
        *effective_url_out = NULL;
    }

    return SUCCESS;
}

static zend_result king_http2_request_materialize_response(
    zval *return_value,
    king_http2_response_buffer_t *response,
    long response_code,
    long http_version,
    bool secure_transport,
    const char *effective_url,
    const char *url_str,
    const char *stream_kind
)
{
    if (!response->headers_initialized) {
        array_init(&response->headers);
        response->headers_initialized = true;
    }

    smart_str_0(&response->body);

    if (response->status_line == NULL) {
        char status_line_buffer[64];

        snprintf(status_line_buffer, sizeof(status_line_buffer), "HTTP/2 %ld", response_code);
        response->status_line = zend_string_init(status_line_buffer, strlen(status_line_buffer), 0);
    }

    array_init(return_value);
    add_assoc_long(return_value, "status", response_code);
    add_assoc_str(return_value, "status_line", response->status_line);
    response->status_line = NULL;
    add_assoc_zval(return_value, "headers", &response->headers);
    response->headers_initialized = false;
    add_assoc_stringl(
        return_value,
        "body",
        response->body.s != NULL ? ZSTR_VAL(response->body.s) : "",
        response->body.s != NULL ? ZSTR_LEN(response->body.s) : 0
    );
    add_assoc_string(return_value, "protocol", king_http2_protocol_label(http_version));
    add_assoc_string(
        return_value,
        "transport_backend",
        secure_transport ? "libcurl_h2_tls" : "libcurl_h2c"
    );
    add_assoc_string(return_value, "effective_url", effective_url != NULL ? effective_url : url_str);
    add_assoc_bool(return_value, "response_complete", 1);
    add_assoc_long(return_value, "body_bytes", (zend_long) response->body_bytes);
    add_assoc_long(return_value, "header_bytes", (zend_long) response->header_bytes);
    add_assoc_string(return_value, "stream_kind", (char *) stream_kind);

    return SUCCESS;
}

static zend_result king_http2_request_materialize_transfer_response(
    zval *return_value,
    king_http2_multi_transfer_t *transfer)
{
    if (king_http2_request_materialize_response(
            return_value,
            &transfer->response,
            transfer->response_code,
            transfer->http_version,
            transfer->secure_transport,
            transfer->effective_url,
            transfer->url != NULL ? ZSTR_VAL(transfer->url) : "",
            transfer->is_push ? "push" : "request"
        ) != SUCCESS) {
        return FAILURE;
    }

    if (!transfer->is_push) {
        return SUCCESS;
    }

    add_assoc_long(
        return_value,
        "pushed_from_request_index",
        (zend_long) transfer->parent_request_index
    );

    if (transfer->promise_headers_initialized) {
        add_assoc_zval(return_value, "promise_headers", &transfer->promise_headers);
        transfer->promise_headers_initialized = false;
    } else {
        zval promise_headers;

        array_init(&promise_headers);
        add_assoc_zval(return_value, "promise_headers", &promise_headers);
    }

    return SUCCESS;
}

static void king_http2_request_response_destroy(king_http2_response_buffer_t *response)
{
    if (response->headers_initialized) {
        zval_ptr_dtor(&response->headers);
    }
    if (response->status_line != NULL) {
        zend_string_release(response->status_line);
    }
    smart_str_free(&response->body);
}
