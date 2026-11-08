/* Request-scoped, cooperative libcurl multi loop. Each easy handle belongs to
 * one Awaitable. Waiting on any handle advances every registered transfer. */
#include "awaitable/awaitable.h"
#include "client/objects.h"

typedef struct _king_http_async_transfer {
    king_http2_multi_transfer_t transfer;
    king_awaitable_object *awaitable; /* weak: object finalizer removes handle */
    bool attached;
    bool response_object;
    int protocol;
    struct _king_http_async_transfer *next;
} king_http_async_transfer;

ZEND_TLS CURLM *king_http_async_multi;
ZEND_TLS king_http_async_transfer *king_http_async_head;
ZEND_TLS size_t king_http_async_count;

static uint64_t king_http_async_now(void)
{
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (uint64_t) ts.tv_sec * 1000 + ts.tv_nsec / 1000000;
}

static void king_http_async_release(king_awaitable_object *intern)
{
    king_http_async_transfer *state = intern->native_data, **cursor;
    if (state == NULL) return;
    if (state->attached && king_http_async_multi != NULL) {
        king_http2_libcurl.curl_multi_remove_handle_fn(king_http_async_multi, state->transfer.easy);
        state->attached = false;
    }
    for (cursor = &king_http_async_head; *cursor != NULL; cursor = &(*cursor)->next) {
        if (*cursor == state) { *cursor = state->next; --king_http_async_count; break; }
    }
    intern->native_data = NULL;
    king_http2_multi_transfer_destroy(&state->transfer);
    efree(state);
}

void king_http_async_request_shutdown(void)
{
    while (king_http_async_head != NULL) {
        king_awaitable_object *intern = king_http_async_head->awaitable;
        king_http_async_release(intern);
        intern->status = KING_AWAITABLE_CANCELLED;
    }
    if (king_http_async_multi != NULL) {
        king_http2_libcurl.curl_multi_cleanup_fn(king_http_async_multi);
        king_http_async_multi = NULL;
    }
}

static void king_http_async_complete(king_http_async_transfer *state, CURLcode code)
{
    king_awaitable_object *intern = state->awaitable;
    king_http2_multi_transfer_t *transfer = &state->transfer;
    zval payload, result;
    ZVAL_UNDEF(&payload); ZVAL_UNDEF(&result);
    if (code != CURLE_OK) {
        const char *message = transfer->response.body_overflowed ? "Async HTTP response-body size limit exceeded."
            : transfer->response.header_overflowed ? "Async HTTP response-header size limit exceeded."
            : king_http2_curl_error_detail(code, &transfer->response);
        king_awaitable_reject(intern, king_http2_map_curl_exception_with_detail(code, message), message);
    } else {
        king_http2_libcurl.curl_easy_getinfo_fn(transfer->easy, CURLINFO_RESPONSE_CODE, &transfer->response_code);
        king_http2_libcurl.curl_easy_getinfo_fn(transfer->easy, CURLINFO_HTTP_VERSION, &transfer->http_version);
        king_http2_libcurl.curl_easy_getinfo_fn(transfer->easy, CURLINFO_EFFECTIVE_URL, &transfer->effective_url);
        if (state->protocol == 2 && transfer->http_version != CURL_HTTP_VERSION_2_0) {
            king_awaitable_reject(intern, king_ce_protocol_exception, "Async HTTP/2 request did not negotiate HTTP/2.");
        } else if (king_http2_request_materialize_transfer_response(&payload, transfer) == SUCCESS) {
            add_assoc_string(&payload, "transport_backend", state->protocol == 1 ? "libcurl_http1_async" : "libcurl_http2_async");
            if (state->response_object) {
                if (king_response_object_init_from_array(&result, &payload) == SUCCESS) king_awaitable_resolve(intern, &result);
                else king_awaitable_reject(intern, king_ce_runtime_exception, "Cannot materialize async HTTP response.");
            } else king_awaitable_resolve(intern, &payload);
        } else king_awaitable_reject(intern, king_ce_protocol_exception, "Cannot materialize async HTTP response.");
    }
    if (!Z_ISUNDEF(payload)) zval_ptr_dtor(&payload);
    if (!Z_ISUNDEF(result)) zval_ptr_dtor(&result);
    king_http_async_release(intern);
}

static zend_result king_http_async_tick(zend_long wait_ms)
{
    king_http_async_transfer *state, *next;
    CURLMcode status;
    CURLMsg *message;
    int running = 0, queued = 0, ready = 0;
    if (king_http_async_multi == NULL || king_http_async_head == NULL) return SUCCESS;
    for (state = king_http_async_head; state != NULL; state = next) {
        next = state->next;
        king_awaitable_check_cancel(state->awaitable);
    }
    if (king_http_async_head == NULL) return SUCCESS;
    if (wait_ms > 0) {
        uint64_t before = king_http_async_now();
        if (king_http2_libcurl.curl_multi_poll_fn != NULL) {
            status = king_http2_libcurl.curl_multi_poll_fn(king_http_async_multi, NULL, 0, (int) wait_ms, &ready);
        } else {
            status = king_http2_libcurl.curl_multi_wait_fn(king_http_async_multi, NULL, 0, (int) wait_ms, &ready);
            /* Older libcurl returns immediately when it has no socket yet. */
            if (status == CURLM_OK && ready == 0 && king_http_async_now() == before) {
                struct timespec pause = {0, 1000000};
                nanosleep(&pause, NULL);
            }
        }
        if (status != CURLM_OK) goto failed;
    }
    do {
        status = king_http2_libcurl.curl_multi_perform_fn(king_http_async_multi, &running);
    } while (status == CURLM_CALL_MULTI_PERFORM);
    if (status != CURLM_OK) goto failed;
    while ((message = king_http2_libcurl.curl_multi_info_read_fn(king_http_async_multi, &queued)) != NULL) {
        if (message->msg != CURLMSG_DONE) continue;
        for (state = king_http_async_head; state != NULL; state = state->next) {
            if (state->transfer.easy == message->easy_handle) break;
        }
        if (state != NULL) king_http_async_complete(state, message->data.result);
    }
    return SUCCESS;
failed:
    while (king_http_async_head != NULL) {
        king_awaitable_object *intern = king_http_async_head->awaitable;
        king_awaitable_reject(intern, king_ce_system_exception, king_http2_libcurl.curl_multi_strerror_fn(status));
        king_http_async_release(intern);
    }
    return SUCCESS; /* each operation receives its own rejection */
}

static zend_result king_http_async_poll(king_awaitable_object *intern, zend_long timeout_ms)
{
    uint64_t start = king_http_async_now(), elapsed;
    if (king_http_async_tick(0) != SUCCESS) return FAILURE;
    while (intern->status == KING_AWAITABLE_PENDING && timeout_ms != 0) {
        zend_long slice = KING_TRANSPORT_INTERRUPT_SLICE_MS;
        elapsed = king_http_async_now() - start;
        if (timeout_ms > 0) {
            if (elapsed >= (uint64_t) timeout_ms) break;
            if ((uint64_t) slice > (uint64_t) timeout_ms - elapsed) slice = timeout_ms - elapsed;
        }
        king_process_pending_interrupts();
        if (EG(exception) != NULL) return FAILURE;
        if (king_http_async_tick(slice) != SUCCESS) return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_http_async_pending(king_awaitable_object *intern, zend_long timeout_ms, zval *result)
{
    ZVAL_UNDEF(result);
    return SUCCESS;
}

zend_result king_http_async_start(
    zval *out, const char *url, size_t url_len, const char *method, size_t method_len,
    zval *headers, zval *body, zval *options_array, const char *operation,
    bool response_object, int protocol, zval *owner)
{
    king_http2_request_options_t options;
    king_http_async_transfer *state = NULL;
    king_awaitable_object *intern;
    php_url *parsed = NULL;
    zval payload, copy;
    CURLMcode code;
    ZVAL_UNDEF(out); ZVAL_UNDEF(&payload);
    if (headers != NULL && Z_TYPE_P(headers) == IS_NULL) headers = NULL;
    if (king_http2_validate_method(method, method_len, operation) != SUCCESS
        || king_http2_request_parse_url(url, url_len, operation, &parsed) != SUCCESS) return FAILURE;
    if (king_http2_parse_options_for_protocol(options_array, &options, operation, protocol == 2) != SUCCESS) goto fail;
    if (options_array != NULL && Z_TYPE_P(options_array) == IS_ARRAY) {
        zval *stream = zend_hash_str_find(Z_ARRVAL_P(options_array), "response_stream", sizeof("response_stream") - 1);
        zval *redirects = zend_hash_str_find(Z_ARRVAL_P(options_array), "follow_redirects", sizeof("follow_redirects") - 1);
        if ((stream != NULL && Z_TYPE_P(stream) != IS_NULL && Z_TYPE_P(stream) != IS_FALSE)
            || (redirects != NULL && Z_TYPE_P(redirects) != IS_NULL && Z_TYPE_P(redirects) != IS_FALSE)) {
            zend_throw_exception_ex(king_ce_protocol_exception, 0,
                "Async HTTP supports bounded buffered responses without automatic redirects; response_stream and follow_redirects must be false.");
            goto fail;
        }
    }
    if (options.capture_push) {
        zend_throw_exception_ex(king_ce_protocol_exception, 0, "Async HTTP capture_push is not supported; use the explicit multi-request push API.");
        goto fail;
    }
    if (king_http_async_count >= 1024) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "Async HTTP in-flight request limit (1024) exceeded.");
        goto fail;
    }
    if ((protocol == 2 ? king_http2_ensure_libcurl_ready() : king_http_ensure_libcurl_ready()) != SUCCESS) {
        zend_throw_exception_ex(king_ce_protocol_exception, 0, "%s", king_http2_libcurl.load_error);
        goto fail;
    }
    if (king_http_async_multi == NULL) {
        king_http_async_multi = king_http2_libcurl.curl_multi_init_fn();
        if (king_http_async_multi == NULL) goto fail;
        king_http2_libcurl.curl_multi_setopt_fn(king_http_async_multi, CURLMOPT_PIPELINING, (long) CURLPIPE_MULTIPLEX);
        king_http2_libcurl.curl_multi_setopt_fn(king_http_async_multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 64L);
    }
    array_init(&payload);
    if (owner != NULL) { ZVAL_COPY(&copy, owner); add_assoc_zval(&payload, "owner", &copy); }
    if (options_array != NULL) { ZVAL_COPY(&copy, options_array); add_assoc_zval(&payload, "options", &copy); }
    if (king_awaitable_create(out, operation, strlen(operation), king_http_async_pending, &payload, options.cancel_token) != SUCCESS) goto fail;
    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(out));
    if (king_awaitable_check_cancel(intern)) {
        php_url_free(parsed); zval_ptr_dtor(&payload);
        return SUCCESS;
    }
    state = ecalloc(1, sizeof(*state));
    state->awaitable = intern; state->response_object = response_object; state->protocol = protocol;
    intern->native_data = state; intern->native_poll = king_http_async_poll;
    intern->native_cancel = king_http_async_release; intern->native_free = king_http_async_release;
    state->next = king_http_async_head; king_http_async_head = state; ++king_http_async_count;
    state->transfer.url = zend_string_init(url, url_len, 0);
    state->transfer.method = zend_string_init(method, method_len, 0);
    state->transfer.secure_transport = king_http2_url_is_https(parsed);
    if (body != NULL && Z_TYPE_P(body) != IS_NULL) {
        state->transfer.body_string = zval_get_string(body); state->transfer.body_was_supplied = true;
        if (EG(exception) != NULL) goto fail;
    }
    if (king_telemetry_has_injectable_current_context()) {
        if (king_telemetry_inject_current_context_headers(&state->transfer.effective_headers, headers) != SUCCESS) goto fail;
        state->transfer.effective_headers_initialized = true;
        headers = &state->transfer.effective_headers;
    }
    if (king_http2_prepare_headers(&state->transfer.request_headers, headers,
        state->transfer.body_string, state->transfer.body_was_supplied, operation) != SUCCESS
        || EG(exception) != NULL) goto fail;
    state->transfer.easy = king_http2_easy_create();
    if (state->transfer.easy == NULL) goto fail;
    if (king_http_request_configure_handle(state->transfer.easy, url, method,
        state->transfer.secure_transport, &options, state->transfer.request_headers,
        state->transfer.body_was_supplied, state->transfer.body_string,
        &state->transfer.response, operation, protocol) != SUCCESS) goto fail;
    code = king_http2_libcurl.curl_multi_add_handle_fn(king_http_async_multi, state->transfer.easy);
    if (code != CURLM_OK) { king_set_error(king_http2_libcurl.curl_multi_strerror_fn(code)); goto fail; }
    state->attached = true; intern->started = true;
    php_url_free(parsed); zval_ptr_dtor(&payload);
    /* Initial nonblocking kick starts connections for all submitted requests. */
    return king_http_async_tick(0);
fail:
    if (parsed != NULL) php_url_free(parsed);
    if (!Z_ISUNDEF(payload)) zval_ptr_dtor(&payload);
    if (!Z_ISUNDEF_P(out)) { zval_ptr_dtor(out); ZVAL_UNDEF(out); }
    return FAILURE;
}

/* Batch results keep the synchronous API's ordered list of raw responses.
 * The parent owns its private children; abandoning/cancelling it detaches them. */
static void king_http_async_batch_release(king_awaitable_object *intern)
{
    zval *value;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(intern->payload), value) {
        king_awaitable_object *child = php_king_awaitable_obj_from_zend(Z_OBJ_P(value));
        if (child->status == KING_AWAITABLE_PENDING) {
            child->cancel_requested = true;
            king_awaitable_check_cancel(child);
        }
    } ZEND_HASH_FOREACH_END();
    intern->native_data = NULL;
}

static zend_result king_http_async_batch_poll(king_awaitable_object *intern, zend_long timeout_ms)
{
    uint64_t start = king_http_async_now(), elapsed, order;
    zval *value, results, copy;
    do {
        bool pending = false;
        if (king_awaitable_check_cancel(intern)) return SUCCESS;
        if (king_http_async_tick(0) != SUCCESS) return FAILURE;
        order = 0;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL(intern->payload), value) {
            king_awaitable_object *child = php_king_awaitable_obj_from_zend(Z_OBJ_P(value));
            if (child->status == KING_AWAITABLE_PENDING) pending = true;
            else if (child->status != KING_AWAITABLE_RESOLVED) {
                king_awaitable_reject(intern, child->error_ce != NULL ? child->error_ce : king_ce_runtime_exception,
                    Z_TYPE(child->error) == IS_STRING ? Z_STRVAL(child->error) : "Async HTTP batch child was cancelled.");
                king_http_async_batch_release(intern);
                return SUCCESS;
            }
            if (child->completion_order > order) order = child->completion_order;
        } ZEND_HASH_FOREACH_END();
        if (!pending) {
            array_init(&results);
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL(intern->payload), value) {
                ZVAL_COPY(&copy, &php_king_awaitable_obj_from_zend(Z_OBJ_P(value))->result);
                add_next_index_zval(&results, &copy);
            } ZEND_HASH_FOREACH_END();
            king_awaitable_resolve(intern, &results);
            if (order != 0) intern->completion_order = order;
            zval_ptr_dtor(&results);
            intern->native_data = NULL;
            return SUCCESS;
        }
        elapsed = king_http_async_now() - start;
        if (timeout_ms == 0 || (timeout_ms > 0 && elapsed >= (uint64_t) timeout_ms)) return SUCCESS;
        zend_long slice = timeout_ms < 0 ? 10 : (zend_long) ((uint64_t) timeout_ms - elapsed);
        if (slice > 10) slice = 10;
        king_process_pending_interrupts();
        if (EG(exception) != NULL) return FAILURE;
        if (king_http_async_tick(slice) != SUCCESS) return FAILURE;
    } while (true);
}

static zend_result king_http_async_start_batch(zval *out, zval *requests, zval *options)
{
    const char *operation = "king_http2_request_send_multi_async";
    zval children, *request, child;
    king_awaitable_object *intern;
    ZVAL_UNDEF(out);
    if (zend_hash_num_elements(Z_ARRVAL_P(requests)) == 0) {
        king_set_error("Async HTTP batch requires at least one request definition.");
        return FAILURE;
    }
    array_init(&children);
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(requests), request) {
        zval *url, *method, *headers, *body;
        zend_string *url_str, *method_str;
        if (Z_TYPE_P(request) != IS_ARRAY) {
            king_set_error("Async HTTP batch request must be an array.");
            goto fail;
        }
        url = zend_hash_str_find(Z_ARRVAL_P(request), "url", sizeof("url") - 1);
        method = zend_hash_str_find(Z_ARRVAL_P(request), "method", sizeof("method") - 1);
        headers = zend_hash_str_find(Z_ARRVAL_P(request), "headers", sizeof("headers") - 1);
        body = zend_hash_str_find(Z_ARRVAL_P(request), "body", sizeof("body") - 1);
        if (url == NULL || Z_TYPE_P(url) != IS_STRING || Z_STRLEN_P(url) == 0
            || (method != NULL && Z_TYPE_P(method) != IS_NULL && Z_TYPE_P(method) != IS_STRING)
            || (headers != NULL && Z_TYPE_P(headers) != IS_NULL && Z_TYPE_P(headers) != IS_ARRAY)) {
            king_set_error("Async HTTP batch requires a URL string, optional method string and optional header array.");
            goto fail;
        }
        url_str = Z_STR_P(url);
        method_str = method != NULL && Z_TYPE_P(method) == IS_STRING ? Z_STR_P(method) : NULL;
        if (king_http_async_start(&child, ZSTR_VAL(url_str), ZSTR_LEN(url_str),
            method_str != NULL ? ZSTR_VAL(method_str) : "GET", method_str != NULL ? ZSTR_LEN(method_str) : 3,
            headers, body, options, operation, false, 2, NULL) != SUCCESS) goto fail;
        add_next_index_zval(&children, &child);
    } ZEND_HASH_FOREACH_END();
    if (king_awaitable_create(out, operation, strlen(operation), king_http_async_pending,
        &children, king_transport_cancel_token_from_options(options)) != SUCCESS) goto fail;
    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(out));
    intern->native_data = intern; /* ownership marker; no separate allocation */
    intern->native_poll = king_http_async_batch_poll;
    intern->native_cancel = king_http_async_batch_release;
    intern->native_free = king_http_async_batch_release;
    intern->started = true;
    zval_ptr_dtor(&children);
    return SUCCESS;
fail:
    zval_ptr_dtor(&children); /* private child destructors remove pending handles */
    return FAILURE;
}
