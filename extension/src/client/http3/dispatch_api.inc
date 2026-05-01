zend_result king_http3_request_dispatch(
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
    king_http3_request_options_t options;
    zend_string *body_string = NULL;
    zend_result result;

    if (url_len == 0) {
        king_http3_set_error("%s() requires a non-empty URL.", function_name);
        return FAILURE;
    }

    if (king_http3_validate_method(method_str, method_len, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_http3_parse_options(options_array, &options, function_name) != SUCCESS) {
        return FAILURE;
    }

#if !defined(KING_HTTP3_BACKEND_LSQUIC)
    king_http3_throw_lsquic_build_required(function_name);
    return FAILURE;
#else
    if (king_http3_ensure_lsquic_ready() != SUCCESS) {
        king_http3_throw_lsquic_unavailable(function_name);
        return FAILURE;
    }

    if (body_zval != NULL && Z_TYPE_P(body_zval) != IS_NULL) {
        body_string = zval_get_string(body_zval);
    }

    result = king_http3_execute_request_lsquic(
        return_value,
        url_str,
        method_str,
        method_len,
        headers_array,
        body_string,
        &options,
        function_name
    );

    if (body_string != NULL) {
        zend_string_release(body_string);
    }

    return result;
#endif
}

static zend_result king_http3_multi_request_dispatch(
    zval *return_value,
    zval *requests_array,
    zval *options_array,
    const char *function_name)
{
    king_http3_request_options_t options;

    if (king_http3_parse_options(options_array, &options, function_name) != SUCCESS) {
        return FAILURE;
    }

#if !defined(KING_HTTP3_BACKEND_LSQUIC)
    king_http3_throw_lsquic_build_required(function_name);
    return FAILURE;
#else
    if (king_http3_ensure_lsquic_ready() != SUCCESS) {
        king_http3_throw_lsquic_unavailable(function_name);
        return FAILURE;
    }

    return king_http3_execute_multi_requests_lsquic(
        return_value,
        requests_array,
        &options,
        function_name
    );
#endif
}

PHP_FUNCTION(king_http3_request_send)
{
    char *url_str;
    size_t url_len;
    char *method_str = "GET";
    size_t method_len = sizeof("GET") - 1;
    zval *headers_array = NULL;
    zval *body_zval = NULL;
    zval *options_array = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 5)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(method_str, method_len)
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ZVAL_OR_NULL(body_zval)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    if (king_http3_request_dispatch(
            return_value,
            url_str,
            url_len,
            method_str,
            method_len,
            headers_array,
            body_zval,
            options_array,
            "king_http3_request_send"
        ) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }
}

PHP_FUNCTION(king_http3_request_send_multi)
{
    zval *requests_array;
    zval *options_array = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(requests_array)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    if (king_http3_multi_request_dispatch(
            return_value,
            requests_array,
            options_array,
            "king_http3_request_send_multi"
        ) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }
}
