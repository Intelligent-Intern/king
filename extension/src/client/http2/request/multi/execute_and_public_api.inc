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

        if (king_telemetry_has_injectable_current_context()) {
            if (king_telemetry_inject_current_context_headers(
                    &transfers[i].effective_headers,
                    headers_value != NULL && Z_TYPE_P(headers_value) == IS_ARRAY ? headers_value : NULL
                ) != SUCCESS) {
                php_url_free(parsed_url);
                goto fail;
            }
            transfers[i].effective_headers_initialized = true;
        }

        if (king_http2_prepare_headers(
                &transfers[i].request_headers,
                transfers[i].effective_headers_initialized
                    ? &transfers[i].effective_headers
                    : (headers_value != NULL && Z_TYPE_P(headers_value) == IS_ARRAY ? headers_value : NULL),
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
