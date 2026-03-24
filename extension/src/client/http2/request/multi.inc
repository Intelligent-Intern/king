static void king_http2_multi_transfer_destroy(king_http2_multi_transfer_t *transfer)
{
    if (transfer == NULL) {
        return;
    }

    if (transfer->request_headers != NULL) {
        king_http2_libcurl.curl_slist_free_all_fn(transfer->request_headers);
    }
    if (transfer->body_string != NULL) {
        zend_string_release(transfer->body_string);
    }
    if (transfer->method != NULL) {
        zend_string_release(transfer->method);
    }
    if (transfer->url != NULL) {
        zend_string_release(transfer->url);
    }
    if (transfer->promise_headers_initialized) {
        zval_ptr_dtor(&transfer->promise_headers);
    }

    king_http2_easy_destroy(transfer->easy);
    king_http2_request_response_destroy(&transfer->response);
}

static zend_long king_http2_multi_now_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_REALTIME, &ts);

    return (zend_long) ts.tv_sec * 1000L + (zend_long) (ts.tv_nsec / 1000000L);
}

static void king_http2_multi_batch_destroy_pushes(king_http2_multi_batch_state_t *batch_state)
{
    king_http2_push_transfer_node_t *node;

    if (batch_state == NULL) {
        return;
    }

    node = batch_state->push_head;
    while (node != NULL) {
        king_http2_push_transfer_node_t *next = node->next;

        king_http2_multi_transfer_destroy(&node->transfer);
        efree(node);
        node = next;
    }

    batch_state->push_head = NULL;
    batch_state->push_tail = NULL;
    batch_state->push_count = 0;
}

static king_http2_multi_transfer_t *king_http2_multi_find_transfer(
    king_http2_multi_batch_state_t *batch_state,
    CURL *easy_handle)
{
    size_t i;
    king_http2_push_transfer_node_t *node;

    if (batch_state == NULL) {
        return NULL;
    }

    for (i = 0; i < batch_state->request_count; ++i) {
        if (batch_state->request_transfers[i].easy == easy_handle) {
            return &batch_state->request_transfers[i];
        }
    }

    for (node = batch_state->push_head; node != NULL; node = node->next) {
        if (node->transfer.easy == easy_handle) {
            return &node->transfer;
        }
    }

    return NULL;
}

static void king_http2_multi_set_push_error(
    king_http2_multi_batch_state_t *batch_state,
    zend_class_entry *ce,
    const char *format,
    ...)
{
    va_list args;

    if (batch_state == NULL || batch_state->push_callback_failed) {
        return;
    }

    batch_state->push_callback_failed = true;
    batch_state->push_error_ce = ce != NULL ? ce : king_ce_protocol_exception;

    va_start(args, format);
    vsnprintf(batch_state->push_error, sizeof(batch_state->push_error), format, args);
    va_end(args);
}

static zend_result king_http2_multi_capture_promise_header_line(
    zval *headers,
    const char *header_line)
{
    size_t header_line_len;
    const char *separator;
    const char *value_ptr;
    size_t header_name_len;
    size_t value_len;
    zend_string *header_name;

    if (headers == NULL || header_line == NULL) {
        return FAILURE;
    }

    header_line_len = strlen(header_line);
    if (header_line_len == 0) {
        return SUCCESS;
    }

    separator = header_line[0] == ':'
        ? strchr(header_line + 1, ':')
        : strchr(header_line, ':');
    if (separator == NULL) {
        return SUCCESS;
    }

    header_name_len = (size_t) (separator - header_line);
    if (header_name_len == 0) {
        return SUCCESS;
    }

    header_name = zend_string_init(header_line, header_name_len, 0);
    zend_str_tolower(ZSTR_VAL(header_name), ZSTR_LEN(header_name));

    value_ptr = separator + 1;
    while (*value_ptr == ' ' || *value_ptr == '\t') {
        value_ptr++;
    }

    value_len = header_line_len - (size_t) (value_ptr - header_line);
    add_assoc_stringl_ex(
        headers,
        ZSTR_VAL(header_name),
        ZSTR_LEN(header_name),
        value_ptr,
        value_len
    );
    zend_string_release(header_name);

    return SUCCESS;
}

static zend_result king_http2_multi_capture_promise_headers(
    king_http2_multi_transfer_t *transfer,
    struct curl_pushheaders *headers)
{
    size_t header_index = 0;

    if (transfer == NULL || headers == NULL || king_http2_libcurl.curl_pushheader_bynum_fn == NULL) {
        return FAILURE;
    }

    array_init(&transfer->promise_headers);
    transfer->promise_headers_initialized = true;

    while (1) {
        char *header_line = king_http2_libcurl.curl_pushheader_bynum_fn(headers, header_index);

        if (header_line == NULL) {
            break;
        }

        if (king_http2_multi_capture_promise_header_line(
                &transfer->promise_headers,
                header_line
            ) != SUCCESS) {
            return FAILURE;
        }

        header_index++;
    }

    return SUCCESS;
}

static zend_result king_http2_multi_configure_pushed_handle(
    CURL *easy_handle,
    king_http2_response_buffer_t *response,
    king_http2_multi_batch_state_t *batch_state)
{
    if (king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_TIMEOUT_MS, 0L) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_CONNECTTIMEOUT_MS, 0L) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_WRITEDATA, response) != CURLE_OK
        || king_http2_libcurl.curl_easy_setopt_fn(easy_handle, CURLOPT_HEADERDATA, response) != CURLE_OK) {
        king_http2_multi_set_push_error(
            batch_state,
            king_ce_protocol_exception,
            "%s() failed to configure a pushed HTTP/2 stream handle.",
            batch_state != NULL ? batch_state->function_name : "HTTP/2 push"
        );
        return FAILURE;
    }

    return SUCCESS;
}

static int king_http2_multi_push_callback(
    CURL *parent_easy_handle,
    CURL *push_easy_handle,
    size_t num_headers,
    struct curl_pushheaders *headers,
    void *user_data)
{
    king_http2_multi_batch_state_t *batch_state = (king_http2_multi_batch_state_t *) user_data;
    king_http2_multi_transfer_t *parent_transfer;
    king_http2_push_transfer_node_t *node;

    (void) num_headers;

    if (batch_state == NULL || !batch_state->capture_push) {
        return CURL_PUSH_DENY;
    }

    parent_transfer = king_http2_multi_find_transfer(batch_state, parent_easy_handle);
    if (parent_transfer == NULL) {
        king_http2_multi_set_push_error(
            batch_state,
            king_ce_system_exception,
            "%s() failed to resolve the parent HTTP/2 stream for a pushed response.",
            batch_state->function_name
        );
        return CURL_PUSH_ERROROUT;
    }

    node = ecalloc(1, sizeof(*node));
    node->transfer.easy = push_easy_handle;
    node->transfer.secure_transport = parent_transfer->secure_transport;
    node->transfer.is_push = true;
    node->transfer.has_parent_request_index = true;
    node->transfer.parent_request_index = parent_transfer->is_push
        && parent_transfer->has_parent_request_index
        ? parent_transfer->parent_request_index
        : parent_transfer->request_index;

    if (king_http2_multi_capture_promise_headers(&node->transfer, headers) != SUCCESS
        || king_http2_multi_configure_pushed_handle(
            push_easy_handle,
            &node->transfer.response,
            batch_state
        ) != SUCCESS) {
        king_http2_multi_transfer_destroy(&node->transfer);
        efree(node);
        return CURL_PUSH_ERROROUT;
    }

    if (batch_state->push_tail != NULL) {
        batch_state->push_tail->next = node;
    } else {
        batch_state->push_head = node;
    }
    batch_state->push_tail = node;
    batch_state->push_count++;
    batch_state->open_transfers++;

    return CURL_PUSH_OK;
}

static void king_http2_multi_reset_push_state(king_http2_pool_entry_t *pool_entry)
{
    CURLMcode multi_code;

    if (pool_entry == NULL || pool_entry->multi == NULL) {
        return;
    }

    multi_code = king_http2_libcurl.curl_multi_setopt_fn(
        pool_entry->multi,
        CURLMOPT_PUSHFUNCTION,
        NULL
    );
    (void) multi_code;

    multi_code = king_http2_libcurl.curl_multi_setopt_fn(
        pool_entry->multi,
        CURLMOPT_PUSHDATA,
        NULL
    );
    (void) multi_code;
}

static zend_result king_http2_request_drive_multi_batch(
    king_http2_pool_entry_t *pool_entry,
    king_http2_multi_batch_state_t *batch_state)
{
    CURLMcode multi_code;
    CURLMcode remove_code;
    CURLMsg *message;
    int running_handles = 0;
    int ready_fds = 0;
    int msgs_in_queue = 0;
    zend_long wait_timeout_ms;
    zend_long current_wait_timeout_ms;
    zend_long deadline_start_ms = 0;
    size_t i;
    king_http2_push_transfer_node_t *node;

    if (batch_state != NULL && batch_state->capture_push) {
        if (king_http2_libcurl.curl_pushheader_bynum_fn == NULL) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() active libcurl HTTP/2 runtime does not expose server-push header capture support.",
                batch_state->function_name
            );
            return FAILURE;
        }

        multi_code = king_http2_libcurl.curl_multi_setopt_fn(
            pool_entry->multi,
            CURLMOPT_PUSHFUNCTION,
            king_http2_multi_push_callback
        );
        if (multi_code == CURLM_UNKNOWN_OPTION) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() active libcurl HTTP/2 runtime does not support server-push callbacks.",
                batch_state->function_name
            );
            return FAILURE;
        }
        if (multi_code != CURLM_OK) {
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed to configure HTTP/2 server-push callbacks on the active libcurl session pool: %s",
                batch_state->function_name,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            return FAILURE;
        }

        multi_code = king_http2_libcurl.curl_multi_setopt_fn(
            pool_entry->multi,
            CURLMOPT_PUSHDATA,
            batch_state
        );
        if (multi_code == CURLM_UNKNOWN_OPTION) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() active libcurl HTTP/2 runtime does not support HTTP/2 server-push userdata wiring.",
                batch_state->function_name
            );
            return FAILURE;
        }
        if (multi_code != CURLM_OK) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed to attach HTTP/2 server-push state to the active libcurl session pool: %s",
                batch_state->function_name,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            return FAILURE;
        }
    } else {
        king_http2_multi_reset_push_state(pool_entry);
    }

    for (i = 0; i < batch_state->request_count; ++i) {
        multi_code = king_http2_libcurl.curl_multi_add_handle_fn(
            pool_entry->multi,
            batch_state->request_transfers[i].easy
        );
        if (multi_code != CURLM_OK) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed to attach request #%zu to the libcurl HTTP/2 session pool: %s",
                batch_state->function_name,
                i + 1,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
    }

    do {
        multi_code = king_http2_libcurl.curl_multi_perform_fn(pool_entry->multi, &running_handles);
    } while (multi_code == CURLM_CALL_MULTI_PERFORM);

    if (multi_code != CURLM_OK) {
        king_http2_multi_reset_push_state(pool_entry);
        king_http2_throw(
            king_ce_system_exception,
            "%s() failed to drive the active libcurl HTTP/2 session pool: %s",
            batch_state->function_name,
            king_http2_libcurl.curl_multi_strerror_fn(multi_code)
        );
        king_http2_pool_remove(pool_entry);
        return FAILURE;
    }

    wait_timeout_ms = batch_state->options->timeout_ms > 0
        && batch_state->options->timeout_ms < KING_HTTP2_MULTI_WAIT_TIMEOUT_MS
        ? batch_state->options->timeout_ms
        : KING_HTTP2_MULTI_WAIT_TIMEOUT_MS;
    if (wait_timeout_ms > KING_TRANSPORT_INTERRUPT_SLICE_MS) {
        wait_timeout_ms = KING_TRANSPORT_INTERRUPT_SLICE_MS;
    }
    if (batch_state->capture_push && batch_state->options->timeout_ms > 0) {
        deadline_start_ms = king_http2_multi_now_ms();
    }

    do {
        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
        if (king_transport_maybe_throw_cancel(
                batch_state->options->cancel_token,
                batch_state->function_name,
                batch_state->options->cancel_function_name,
                batch_state->options->cancel_exception_ce,
                "HTTP/2"
            ) != SUCCESS) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }

        if (deadline_start_ms > 0) {
            zend_long elapsed_ms = king_http2_multi_now_ms() - deadline_start_ms;

            if (elapsed_ms >= batch_state->options->timeout_ms) {
                king_http2_multi_reset_push_state(pool_entry);
                king_http2_throw(
                    king_ce_timeout_exception,
                    "%s() exceeded the active HTTP/2 batch timeout while waiting for pushed streams to finish.",
                    batch_state->function_name
                );
                king_http2_pool_remove(pool_entry);
                return FAILURE;
            }

            current_wait_timeout_ms = batch_state->options->timeout_ms - elapsed_ms;
            if (current_wait_timeout_ms > KING_HTTP2_MULTI_WAIT_TIMEOUT_MS) {
                current_wait_timeout_ms = KING_HTTP2_MULTI_WAIT_TIMEOUT_MS;
            }
        } else {
            current_wait_timeout_ms = wait_timeout_ms;
        }
        if (current_wait_timeout_ms > KING_TRANSPORT_INTERRUPT_SLICE_MS) {
            current_wait_timeout_ms = KING_TRANSPORT_INTERRUPT_SLICE_MS;
        }

        do {
            multi_code = king_http2_libcurl.curl_multi_perform_fn(pool_entry->multi, &running_handles);
        } while (multi_code == CURLM_CALL_MULTI_PERFORM);

        if (multi_code != CURLM_OK) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed to continue the active libcurl HTTP/2 session pool: %s",
                batch_state->function_name,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }

        if (running_handles > 0) {
            ready_fds = 0;
            multi_code = king_http2_libcurl.curl_multi_wait_fn(
                pool_entry->multi,
                NULL,
                0,
                (int) current_wait_timeout_ms,
                &ready_fds
            );
            if (multi_code != CURLM_OK) {
                king_http2_multi_reset_push_state(pool_entry);
                king_http2_throw(
                    king_ce_system_exception,
                    "%s() failed while waiting on the active libcurl HTTP/2 session pool: %s",
                    batch_state->function_name,
                    king_http2_libcurl.curl_multi_strerror_fn(multi_code)
                );
                king_http2_pool_remove(pool_entry);
                return FAILURE;
            }
        }

        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
        if (king_transport_maybe_throw_cancel(
                batch_state->options->cancel_token,
                batch_state->function_name,
                batch_state->options->cancel_function_name,
                batch_state->options->cancel_exception_ce,
                "HTTP/2"
            ) != SUCCESS) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }

        while ((message = king_http2_libcurl.curl_multi_info_read_fn(pool_entry->multi, &msgs_in_queue)) != NULL) {
            if (message->msg == CURLMSG_DONE) {
                king_http2_multi_transfer_t *transfer = king_http2_multi_find_transfer(
                    batch_state,
                    message->easy_handle
                );

                if (transfer != NULL && !transfer->completed) {
                    transfer->curl_code = message->data.result;
                    transfer->completed = true;

                    remove_code = king_http2_libcurl.curl_multi_remove_handle_fn(
                        pool_entry->multi,
                        message->easy_handle
                    );
                    if (remove_code != CURLM_OK) {
                        king_http2_multi_reset_push_state(pool_entry);
                        king_http2_throw(
                            king_ce_system_exception,
                            "%s() failed to detach a completed HTTP/2 stream from the libcurl session pool: %s",
                            batch_state->function_name,
                            king_http2_libcurl.curl_multi_strerror_fn(remove_code)
                        );
                        king_http2_pool_remove(pool_entry);
                        return FAILURE;
                    }

                    if (batch_state->open_transfers > 0) {
                        batch_state->open_transfers--;
                    }
                }
            }
        }
    } while (batch_state->open_transfers > 0);

    for (i = 0; i < batch_state->request_count; ++i) {
        if (!batch_state->request_transfers[i].completed) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_system_exception,
                "%s() did not receive a completion signal for request #%zu from the libcurl HTTP/2 session pool.",
                batch_state->function_name,
                i + 1
            );
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
    }

    for (node = batch_state->push_head; node != NULL; node = node->next) {
        if (!node->transfer.completed) {
            king_http2_multi_reset_push_state(pool_entry);
            king_http2_throw(
                king_ce_system_exception,
                "%s() did not receive a completion signal for a pushed HTTP/2 stream from the libcurl session pool.",
                batch_state->function_name
            );
            king_http2_pool_remove(pool_entry);
            return FAILURE;
        }
    }

    king_http2_multi_reset_push_state(pool_entry);
    return SUCCESS;
}

static zend_result king_http2_multi_collect_transfer_meta(
    king_http2_pool_entry_t *pool_entry,
    king_http2_multi_transfer_t *transfer,
    const char *function_name,
    const char *transfer_label)
{
    if (transfer->curl_code != CURLE_OK) {
        if (transfer->response.header_overflowed) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() %s exceeded the active runtime HTTP/2 response-header size limit.",
                function_name,
                transfer_label
            );
        } else if (transfer->response.body_overflowed) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() %s exceeded the active runtime HTTP/2 response-body size limit.",
                function_name,
                transfer_label
            );
        } else {
            king_http2_throw(
                king_http2_map_curl_exception(transfer->curl_code),
                "%s() %s libcurl HTTP/2 transfer failed: %s",
                function_name,
                transfer_label,
                king_http2_libcurl.curl_easy_strerror_fn(transfer->curl_code)
            );
        }

        king_http2_pool_remove(pool_entry);
        return FAILURE;
    }

    if (transfer->response.body_overflowed) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() %s exceeded the active runtime HTTP/2 response-body size limit.",
            function_name,
            transfer_label
        );
        return FAILURE;
    }

    if (transfer->response.header_overflowed) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() %s exceeded the active runtime HTTP/2 response-header size limit.",
            function_name,
            transfer_label
        );
        return FAILURE;
    }

    if (king_http2_libcurl.curl_easy_getinfo_fn(
            transfer->easy,
            CURLINFO_RESPONSE_CODE,
            &transfer->response_code
        ) != CURLE_OK) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() failed to read the HTTP/2 response code for %s from libcurl.",
            function_name,
            transfer_label
        );
        return FAILURE;
    }

    if (king_http2_libcurl.curl_easy_getinfo_fn(
            transfer->easy,
            CURLINFO_HTTP_VERSION,
            &transfer->http_version
        ) != CURLE_OK) {
        transfer->http_version = CURL_HTTP_VERSION_NONE;
    }

    if (king_http2_libcurl.curl_easy_getinfo_fn(
            transfer->easy,
            CURLINFO_EFFECTIVE_URL,
            &transfer->effective_url
        ) != CURLE_OK) {
        transfer->effective_url = NULL;
    }

    return SUCCESS;
}

static zend_result king_http2_multi_collect_meta(
    king_http2_pool_entry_t *pool_entry,
    king_http2_multi_batch_state_t *batch_state)
{
    size_t i;
    size_t push_index = 0;
    char label[96];
    king_http2_push_transfer_node_t *node;

    for (i = 0; i < batch_state->request_count; ++i) {
        snprintf(label, sizeof(label), "request #%zu", i + 1);
        if (king_http2_multi_collect_transfer_meta(
                pool_entry,
                &batch_state->request_transfers[i],
                batch_state->function_name,
                label
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    for (node = batch_state->push_head; node != NULL; node = node->next) {
        push_index++;
        snprintf(label, sizeof(label), "captured push #%zu", push_index);
        if (king_http2_multi_collect_transfer_meta(
                pool_entry,
                &node->transfer,
                batch_state->function_name,
                label
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_http2_multi_attach_pushes(
    zval *response_value,
    king_http2_multi_batch_state_t *batch_state,
    size_t request_index)
{
    zval pushes;
    king_http2_push_transfer_node_t *node;
    size_t push_count = 0;

    array_init(&pushes);

    for (node = batch_state->push_head; node != NULL; node = node->next) {
        zval push_value;

        if (!node->transfer.has_parent_request_index
            || node->transfer.parent_request_index != request_index) {
            continue;
        }

        ZVAL_UNDEF(&push_value);
        if (king_http2_request_materialize_transfer_response(
                &push_value,
                &node->transfer
            ) != SUCCESS) {
            if (Z_TYPE(push_value) != IS_UNDEF) {
                zval_ptr_dtor(&push_value);
            }
            zval_ptr_dtor(&pushes);
            return FAILURE;
        }

        add_next_index_zval(&pushes, &push_value);
        push_count++;
    }

    add_assoc_long(response_value, "push_count", (zend_long) push_count);
    add_assoc_zval(response_value, "pushes", &pushes);

    return SUCCESS;
}

static zend_result king_http2_request_execute_multi_batch(
    zval *return_value,
    king_http2_pool_entry_t *pool_entry,
    king_http2_multi_transfer_t *transfers,
    size_t request_count,
    const king_http2_request_options_t *options,
    const char *function_name,
    bool unwrap_single_response)
{
    king_http2_multi_batch_state_t batch_state;
    size_t i;
    bool return_value_initialized = false;

    memset(&batch_state, 0, sizeof(batch_state));
    batch_state.request_transfers = transfers;
    batch_state.request_count = request_count;
    batch_state.open_transfers = request_count;
    batch_state.options = options;
    batch_state.function_name = function_name;
    batch_state.capture_push = options->capture_push;

    if (king_http2_pool_prepare_multi(pool_entry, options, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_http2_request_drive_multi_batch(pool_entry, &batch_state) != SUCCESS) {
        goto fail;
    }

    if (batch_state.push_callback_failed) {
        king_http2_throw(
            batch_state.push_error_ce != NULL ? batch_state.push_error_ce : king_ce_protocol_exception,
            "%s",
            batch_state.push_error[0] != '\0'
                ? batch_state.push_error
                : "HTTP/2 server-push capture failed."
        );
        king_http2_pool_remove(pool_entry);
        goto fail;
    }

    if (king_http2_multi_collect_meta(pool_entry, &batch_state) != SUCCESS) {
        goto fail;
    }

    if (unwrap_single_response) {
        if (request_count != 1) {
            king_http2_throw(
                king_ce_system_exception,
                "%s() internal HTTP/2 batch unwrap expected exactly one request.",
                function_name
            );
            goto fail;
        }

        if (king_http2_request_materialize_transfer_response(
                return_value,
                &transfers[0]
            ) != SUCCESS) {
            goto fail;
        }
        return_value_initialized = true;

        if (options->capture_push
            && king_http2_multi_attach_pushes(return_value, &batch_state, 0) != SUCCESS) {
            goto fail;
        }
    } else {
        array_init(return_value);
        return_value_initialized = true;

        for (i = 0; i < request_count; ++i) {
            zval response_value;

            ZVAL_UNDEF(&response_value);
            if (king_http2_request_materialize_transfer_response(
                    &response_value,
                    &transfers[i]
                ) != SUCCESS) {
                if (Z_TYPE(response_value) != IS_UNDEF) {
                    zval_ptr_dtor(&response_value);
                }
                goto fail;
            }

            if (options->capture_push
                && king_http2_multi_attach_pushes(
                    &response_value,
                    &batch_state,
                    i
                ) != SUCCESS) {
                zval_ptr_dtor(&response_value);
                goto fail;
            }

            add_next_index_zval(return_value, &response_value);
        }
    }

    king_http2_multi_batch_destroy_pushes(&batch_state);
    king_set_error("");
    return SUCCESS;

fail:
    king_http2_multi_batch_destroy_pushes(&batch_state);

    if (return_value_initialized && Z_TYPE_P(return_value) == IS_ARRAY) {
        zval_ptr_dtor(return_value);
        ZVAL_UNDEF(return_value);
    }

    return FAILURE;
}

PHP_FUNCTION(king_http2_request_send_multi)
{
    zval *requests_array;
    zval *options_array = NULL;
    king_http2_request_options_t options;
    king_http2_pool_entry_t *pool_entry = NULL;
    king_http2_multi_transfer_t *transfers = NULL;
    char *pool_key = NULL;
    size_t request_count;
    size_t i = 0;
    zval *request_entry;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(requests_array)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    request_count = zend_hash_num_elements(Z_ARRVAL_P(requests_array));
    if (request_count == 0) {
        king_http2_set_error("king_http2_request_send_multi() requires at least one request definition.");
        RETURN_FALSE;
    }

    if (king_http2_parse_options(options_array, &options, "king_http2_request_send_multi") != SUCCESS) {
        RETURN_FALSE;
    }

    if (king_http2_ensure_libcurl_ready() != SUCCESS) {
        king_http2_throw(
            king_ce_protocol_exception,
            "king_http2_request_send_multi() %s",
            king_http2_libcurl.load_error
        );
        RETURN_THROWS();
    }

    transfers = ecalloc(request_count, sizeof(*transfers));

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(requests_array), request_entry) {
        php_url *parsed_url = NULL;
        zval *url_value;
        zval *method_value;
        zval *headers_value;
        zval *body_value;
        zend_long port;
        char *current_pool_key = NULL;

        if (Z_TYPE_P(request_entry) != IS_ARRAY) {
            king_http2_set_error(
                "king_http2_request_send_multi() request #%zu must be provided as an array.",
                i + 1
            );
            goto fail;
        }

        url_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "url",
            sizeof("url") - 1
        );
        if (url_value == NULL || Z_TYPE_P(url_value) == IS_NULL) {
            king_http2_set_error(
                "king_http2_request_send_multi() request #%zu requires a non-empty 'url'.",
                i + 1
            );
            goto fail;
        }

        transfers[i].url = zval_get_string(url_value);
        if (king_http2_request_parse_url(
                ZSTR_VAL(transfers[i].url),
                ZSTR_LEN(transfers[i].url),
                "king_http2_request_send_multi",
                &parsed_url
            ) != SUCCESS) {
            goto fail;
        }

        transfers[i].secure_transport = king_http2_url_is_https(parsed_url);
        transfers[i].request_index = i;
        port = king_http2_resolve_default_port(parsed_url);

        if (king_http2_build_origin_key(parsed_url, port, &options, &current_pool_key) != SUCCESS) {
            king_http2_set_error("king_http2_request_send_multi() failed to build an HTTP/2 origin key.");
            php_url_free(parsed_url);
            goto fail;
        }

        if (pool_key == NULL) {
            pool_key = current_pool_key;
        } else if (strcmp(pool_key, current_pool_key) != 0) {
            efree(current_pool_key);
            php_url_free(parsed_url);
            king_http2_set_error(
                "king_http2_request_send_multi() currently requires all requests to target the same HTTP/2 origin and TLS profile."
            );
            goto fail;
        } else {
            efree(current_pool_key);
        }

        method_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "method",
            sizeof("method") - 1
        );
        if (method_value == NULL || Z_TYPE_P(method_value) == IS_NULL) {
            transfers[i].method = zend_string_init("GET", sizeof("GET") - 1, 0);
        } else {
            transfers[i].method = zval_get_string(method_value);
        }

        if (king_http2_validate_method(
                ZSTR_VAL(transfers[i].method),
                ZSTR_LEN(transfers[i].method),
                "king_http2_request_send_multi"
            ) != SUCCESS) {
            php_url_free(parsed_url);
            goto fail;
        }

        headers_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "headers",
            sizeof("headers") - 1
        );
        if (headers_value != NULL
            && Z_TYPE_P(headers_value) != IS_NULL
            && Z_TYPE_P(headers_value) != IS_ARRAY) {
            king_http2_set_error(
                "king_http2_request_send_multi() request #%zu option 'headers' must be provided as an array or null.",
                i + 1
            );
            php_url_free(parsed_url);
            goto fail;
        }

        body_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "body",
            sizeof("body") - 1
        );
        if (body_value != NULL && Z_TYPE_P(body_value) != IS_NULL) {
            transfers[i].body_string = zval_get_string(body_value);
            transfers[i].body_was_supplied = true;
        }

        if (king_http2_prepare_headers(
                &transfers[i].request_headers,
                headers_value != NULL && Z_TYPE_P(headers_value) == IS_ARRAY ? headers_value : NULL,
                transfers[i].body_string,
                transfers[i].body_was_supplied,
                "king_http2_request_send_multi"
            ) != SUCCESS) {
            php_url_free(parsed_url);
            goto fail;
        }

        transfers[i].easy = king_http2_easy_create();
        if (transfers[i].easy == NULL) {
            king_http2_throw(
                king_ce_protocol_exception,
                "king_http2_request_send_multi() failed to allocate an HTTP/2 transfer handle for request #%zu.",
                i + 1
            );
            php_url_free(parsed_url);
            goto fail;
        }

        if (king_http2_request_configure_handle(
                transfers[i].easy,
                ZSTR_VAL(transfers[i].url),
                ZSTR_VAL(transfers[i].method),
                transfers[i].secure_transport,
                &options,
                transfers[i].request_headers,
                transfers[i].body_was_supplied,
                transfers[i].body_string,
                &transfers[i].response,
                "king_http2_request_send_multi"
            ) != SUCCESS) {
            php_url_free(parsed_url);
            goto fail;
        }

        php_url_free(parsed_url);
        i++;
    } ZEND_HASH_FOREACH_END();

    pool_entry = king_http2_pool_get_or_create(pool_key);
    if (pool_entry == NULL) {
        king_http2_throw(
            king_ce_protocol_exception,
            "king_http2_request_send_multi() failed to allocate a reusable libcurl session pool for origin '%s'.",
            pool_key
        );
        goto fail;
    }

    if (king_http2_request_execute_multi_batch(
            return_value,
            pool_entry,
            transfers,
            request_count,
            &options,
            "king_http2_request_send_multi",
            false
        ) != SUCCESS) {
        goto fail;
    }

    for (i = 0; i < request_count; ++i) {
        king_http2_multi_transfer_destroy(&transfers[i]);
    }
    efree(transfers);
    if (pool_key != NULL) {
        efree(pool_key);
    }
    return;

fail:
    if (transfers != NULL) {
        for (i = 0; i < request_count; ++i) {
            king_http2_multi_transfer_destroy(&transfers[i]);
        }
        efree(transfers);
    }
    if (pool_key != NULL) {
        efree(pool_key);
    }

    if (EG(exception) != NULL) {
        RETURN_THROWS();
    }

    RETURN_FALSE;
}
