/*
 * =========================================================================
 * FILENAME:   src/client/http2/request/dispatch/api.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Core one-shot HTTP/2 dispatch entry for the libcurl-backed client path.
 * This slice ties together option parsing, URL validation, reusable pool
 * lookup, easy-handle setup, optional push capture, transfer execution, and
 * final response materialization for `king_http2_request_send()`.
 * =========================================================================
 */

zend_result king_http2_request_dispatch(
    zval *return_value,
    const char *url_str,
    size_t url_len,
    const char *method_str,
    size_t method_len,
    zval *headers_array,
    zval *body_zval,
    zval *options_array,
    const char *function_name)
{
    php_url *parsed_url = NULL;
    zend_string *body_string = NULL;
    bool body_was_supplied = false;
    zend_long port;
    bool secure_transport = false;
    king_http2_request_options_t options;
    king_http2_pool_entry_t *pool_entry;
    CURL *easy_handle = NULL;
    king_http2_response_buffer_t response;
    king_http2_multi_transfer_t batch_transfer;
    struct curl_slist *request_headers = NULL;
    char *origin = NULL;
    long response_code = 0;
    long http_version = CURL_HTTP_VERSION_NONE;
    char *effective_url = NULL;
    zval effective_headers;
    zval *request_headers_array = headers_array;

    memset(&response, 0, sizeof(response));
    memset(&batch_transfer, 0, sizeof(batch_transfer));
    ZVAL_UNDEF(&effective_headers);

    if (king_http2_validate_method(method_str, method_len, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_http2_parse_options(options_array, &options, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_http2_request_parse_url(url_str, url_len, function_name, &parsed_url) != SUCCESS) {
        return FAILURE;
    }

    secure_transport = king_http2_url_is_https(parsed_url);

    if (king_http2_ensure_libcurl_ready() != SUCCESS) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() %s",
            function_name,
            king_http2_libcurl.load_error
        );
        goto cleanup;
    }

    port = king_http2_resolve_default_port(parsed_url);
    if (king_http2_build_origin_key(parsed_url, port, &options, &origin) != SUCCESS) {
        king_http2_set_error("%s() failed to build an HTTP/2 origin key.", function_name);
        goto cleanup;
    }

    if (body_zval != NULL && Z_TYPE_P(body_zval) != IS_NULL) {
        body_string = zval_get_string(body_zval);
        body_was_supplied = true;
    }

    if (king_telemetry_has_injectable_current_context()) {
        if (king_telemetry_inject_current_context_headers(&effective_headers, headers_array) != SUCCESS) {
            goto cleanup;
        }
        request_headers_array = &effective_headers;
    }

    if (king_http2_prepare_headers(
            &request_headers,
            request_headers_array,
            body_string,
            body_was_supplied,
            function_name
        ) != SUCCESS) {
        goto cleanup;
    }

    pool_entry = king_http2_pool_get_or_create(origin);
    if (pool_entry == NULL) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() failed to allocate a reusable libcurl handle for origin '%s'.",
            function_name,
            origin
        );
        goto cleanup;
    }

    easy_handle = king_http2_easy_create();
    if (easy_handle == NULL) {
        king_http2_throw(
            king_ce_protocol_exception,
            "%s() failed to allocate an HTTP/2 transfer handle for origin '%s'.",
            function_name,
            origin
        );
        goto cleanup;
    }

    if (king_http2_request_configure_handle(
            easy_handle,
            url_str,
            method_str,
            secure_transport,
            &options,
            request_headers,
            body_was_supplied,
            body_string,
            &response,
            function_name
        ) != SUCCESS) {
        goto cleanup;
    }

    if (options.capture_push) {
        batch_transfer.easy = easy_handle;
        batch_transfer.response = response;
        memset(&response, 0, sizeof(response));
        batch_transfer.url = zend_string_init(url_str, url_len, 0);
        batch_transfer.method = zend_string_init(method_str, method_len, 0);
        batch_transfer.body_string = body_string;
        batch_transfer.request_headers = request_headers;
        batch_transfer.secure_transport = secure_transport;
        batch_transfer.body_was_supplied = body_was_supplied;
        batch_transfer.request_index = 0;

        if (king_http2_libcurl.curl_easy_setopt_fn(
                easy_handle,
                CURLOPT_WRITEDATA,
                &batch_transfer.response
            ) != CURLE_OK
            || king_http2_libcurl.curl_easy_setopt_fn(
                easy_handle,
                CURLOPT_HEADERDATA,
                &batch_transfer.response
            ) != CURLE_OK) {
            king_http2_throw(
                king_ce_protocol_exception,
                "%s() failed to retarget the active HTTP/2 response buffer for push capture.",
                function_name
            );
            goto cleanup;
        }

        easy_handle = NULL;
        request_headers = NULL;
        body_string = NULL;

        if (king_http2_request_execute_multi_batch(
                return_value,
                pool_entry,
                &batch_transfer,
                1,
                &options,
                function_name,
                true
            ) != SUCCESS) {
            goto cleanup;
        }

        king_set_error("");
        goto cleanup;
    }

    if (king_http2_pool_prepare_multi(pool_entry, &options, function_name) != SUCCESS) {
        goto cleanup;
    }

    if (king_http2_request_collect_transfer_meta(
            pool_entry,
            easy_handle,
            &response,
            &options,
            function_name,
            &response_code,
            &http_version,
            &effective_url
        ) != SUCCESS) {
        goto cleanup;
    }

    if (king_http2_request_materialize_response(
            return_value,
            &response,
            response_code,
            http_version,
            secure_transport,
            effective_url,
            url_str,
            "request"
        ) != SUCCESS) {
        goto cleanup;
    }

    king_set_error("");

cleanup:
    if (parsed_url != NULL) {
        php_url_free(parsed_url);
    }
    if (origin != NULL) {
        efree(origin);
    }
    if (body_string != NULL) {
        zend_string_release(body_string);
    }
    if (request_headers != NULL) {
        king_http2_libcurl.curl_slist_free_all_fn(request_headers);
    }
    if (Z_TYPE(effective_headers) != IS_UNDEF) {
        zval_ptr_dtor(&effective_headers);
    }
    king_http2_easy_destroy(easy_handle);
    king_http2_request_response_destroy(&response);
    king_http2_multi_transfer_destroy(&batch_transfer);

    return EG(exception) == NULL && Z_TYPE_P(return_value) == IS_ARRAY
        ? SUCCESS
        : FAILURE;
}
