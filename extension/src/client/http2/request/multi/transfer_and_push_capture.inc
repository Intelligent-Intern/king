/*
 * =========================================================================
 * FILENAME:   src/client/http2/request/multi.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Shared HTTP/2 multi-request and server-push state management for the
 * libcurl-backed client surface. This file owns transfer lifetime, batch
 * bookkeeping, pushed-stream capture, and the bounded helpers that let the
 * public multi-request leaf reuse the same response and error semantics as
 * the one-shot path.
 * =========================================================================
 */

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

