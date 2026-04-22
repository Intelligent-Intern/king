#if defined(KING_HTTP3_BACKEND_LSQUIC)
static zend_result king_http3_execute_multi_requests_lsquic(
    zval *return_value,
    zval *requests_array,
    const king_http3_request_options_t *options,
    const char *function_name)
{
    king_http3_multi_request_t *requests = NULL;
    king_http3_lsquic_request_state_t *lsquic_requests = NULL;
    size_t request_count;
    size_t i = 0;
    zval *request_entry;
    king_http3_request_runtime_t runtime;
    zend_long started_at_ms;
    zend_long connect_deadline_ms;
    zend_long overall_deadline_ms;

    request_count = zend_hash_num_elements(Z_ARRVAL_P(requests_array));
    if (request_count == 0) {
        king_http3_set_error("%s() requires at least one request definition.", function_name);
        return FAILURE;
    }

    requests = ecalloc(request_count, sizeof(*requests));
    lsquic_requests = ecalloc(request_count, sizeof(*lsquic_requests));
    memset(&runtime, 0, sizeof(runtime));
    runtime.socket_fd = -1;

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(requests_array), request_entry) {
        zval *url_value;
        zval *method_value;
        zval *headers_value;
        zval *body_value;

        if (Z_TYPE_P(request_entry) != IS_ARRAY) {
            king_http3_set_error(
                "%s() request #%zu must be provided as an array.",
                function_name,
                i + 1
            );
            goto failure;
        }

        url_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "url",
            sizeof("url") - 1
        );
        if (url_value == NULL || Z_TYPE_P(url_value) == IS_NULL) {
            king_http3_set_error(
                "%s() request #%zu requires a non-empty 'url'.",
                function_name,
                i + 1
            );
            goto failure;
        }

        requests[i].url = zval_get_string(url_value);
        if (ZSTR_LEN(requests[i].url) == 0) {
            king_http3_set_error(
                "%s() request #%zu requires a non-empty 'url'.",
                function_name,
                i + 1
            );
            goto failure;
        }

        if (king_http3_request_target_from_url(
                ZSTR_VAL(requests[i].url),
                ZSTR_LEN(requests[i].url),
                &requests[i].parsed_url,
                &requests[i].target,
                function_name
            ) != SUCCESS) {
            goto failure;
        }

        if (i > 0
            && !zend_string_equals(
                requests[0].target.authority,
                requests[i].target.authority
            )) {
            king_http3_set_error(
                "%s() currently requires all requests to target the same HTTP/3 origin and TLS profile.",
                function_name
            );
            goto failure;
        }

        method_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "method",
            sizeof("method") - 1
        );
        if (method_value == NULL || Z_TYPE_P(method_value) == IS_NULL) {
            requests[i].method = zend_string_init("GET", sizeof("GET") - 1, 0);
        } else {
            requests[i].method = zval_get_string(method_value);
        }

        if (king_http3_validate_method(
                ZSTR_VAL(requests[i].method),
                ZSTR_LEN(requests[i].method),
                function_name
            ) != SUCCESS) {
            goto failure;
        }

        headers_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "headers",
            sizeof("headers") - 1
        );
        if (headers_value != NULL
            && Z_TYPE_P(headers_value) != IS_NULL
            && Z_TYPE_P(headers_value) != IS_ARRAY) {
            king_http3_set_error(
                "%s() request #%zu option 'headers' must be provided as an array or null.",
                function_name,
                i + 1
            );
            goto failure;
        }

        body_value = zend_hash_str_find(
            Z_ARRVAL_P(request_entry),
            "body",
            sizeof("body") - 1
        );
        if (body_value != NULL && Z_TYPE_P(body_value) != IS_NULL) {
            requests[i].body_string = zval_get_string(body_value);
        }

        if (king_telemetry_has_injectable_current_context()) {
            if (king_telemetry_inject_current_context_headers(
                    &requests[i].effective_headers,
                    headers_value != NULL && Z_TYPE_P(headers_value) == IS_ARRAY ? headers_value : NULL
                ) != SUCCESS) {
                goto failure;
            }
            requests[i].effective_headers_initialized = true;
        }

        if (king_http3_prepare_request_headers(
                &requests[i].target,
                ZSTR_VAL(requests[i].method),
                ZSTR_LEN(requests[i].method),
                requests[i].effective_headers_initialized
                    ? &requests[i].effective_headers
                    : (headers_value != NULL && Z_TYPE_P(headers_value) == IS_ARRAY ? headers_value : NULL),
                &requests[i].request_headers,
                &requests[i].request_header_count,
                &requests[i].owned_strings,
                &requests[i].owned_string_count,
                function_name
            ) != SUCCESS) {
            goto failure;
        }

        array_init(&requests[i].response.headers);
        requests[i].response.headers_initialized = true;
        requests[i].request_stream_id = UINT64_MAX;
        i++;
    } ZEND_HASH_FOREACH_END();

    if (king_http3_runtime_open_udp_socket(&runtime, &requests[0].target, function_name) != SUCCESS) {
        goto failure;
    }

    started_at_ms = king_http3_now_ms();
    connect_deadline_ms = started_at_ms + options->connect_timeout_ms;
    overall_deadline_ms = started_at_ms + options->timeout_ms;

    if (king_http3_lsquic_runtime_init(&runtime, &requests[0].target, options, function_name) != SUCCESS) {
        goto failure;
    }

    for (i = 0; i < request_count; ++i) {
        king_http3_lsquic_request_state_init(
            &lsquic_requests[i],
            &runtime,
            requests[i].request_headers,
            requests[i].request_header_count,
            requests[i].body_string,
            &requests[i].response
        );
    }

    if (king_http3_lsquic_runtime_prepare_requests(
            &runtime,
            lsquic_requests,
            request_count,
            function_name
        ) != SUCCESS) {
        goto failure;
    }

    if (king_http3_lsquic_drive_requests(
            &runtime,
            lsquic_requests,
            request_count,
            options,
            connect_deadline_ms,
            overall_deadline_ms,
            "responses",
            function_name
        ) != SUCCESS) {
        goto failure;
    }

    king_http3_refresh_tls_state(&runtime);
    array_init(return_value);
    for (i = 0; i < request_count; ++i) {
        zval response_zv;

        ZVAL_UNDEF(&response_zv);
        if (king_http3_materialize_response(
                &response_zv,
                &requests[i].response,
                &runtime,
                ZSTR_VAL(requests[i].url)
            ) != SUCCESS) {
            if (!Z_ISUNDEF(response_zv)) {
                zval_ptr_dtor(&response_zv);
            }
            goto failure;
        }

        smart_str_free(&requests[i].response.body);
        add_next_index_zval(return_value, &response_zv);
    }

    efree(lsquic_requests);
    king_http3_runtime_destroy(&runtime);
    king_http3_multi_requests_destroy(requests, request_count);
    king_set_error("");
    return SUCCESS;

failure:
    if (lsquic_requests != NULL) {
        efree(lsquic_requests);
    }
    king_http3_runtime_destroy(&runtime);
    king_http3_multi_requests_destroy(requests, request_count);
    return FAILURE;
}
#endif
