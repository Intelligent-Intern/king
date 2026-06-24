/*
 * =========================================================================
 * FILENAME:   src/client/http1/request/api.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Public PHP entry point for the direct HTTP/1 request leaf. This slice only
 * parses the userland signature and forwards into the shared HTTP/1 dispatch
 * path, which may return either a buffered response array or a live
 * `King\\HttpRequestContext` when `response_stream` is enabled.
 * =========================================================================
 */

PHP_FUNCTION(king_http1_request_send)
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

    if (king_http1_request_dispatch(
            return_value,
            url_str,
            url_len,
            method_str,
            method_len,
            headers_array,
            body_zval,
            options_array,
            "king_http1_request_send"
        ) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }
}

PHP_FUNCTION(king_http1_request_send_async)
{
    char *url_str;
    size_t url_len;
    char *method_str = "GET";
    size_t method_len = sizeof("GET") - 1;
    zval *headers_array = NULL;
    zval *body_zval = NULL;
    zval *options_array = NULL;
    zval params[5];
    uint32_t index;

    ZEND_PARSE_PARAMETERS_START(1, 5)
        Z_PARAM_STRING(url_str, url_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(method_str, method_len)
        Z_PARAM_ARRAY_OR_NULL(headers_array)
        Z_PARAM_ZVAL_OR_NULL(body_zval)
        Z_PARAM_ARRAY_OR_NULL(options_array)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_STRINGL(&params[0], url_str, url_len);
    ZVAL_STRINGL(&params[1], method_str, method_len);
    if (headers_array != NULL) {
        ZVAL_COPY(&params[2], headers_array);
    } else {
        ZVAL_NULL(&params[2]);
    }
    if (body_zval != NULL) {
        ZVAL_COPY(&params[3], body_zval);
    } else {
        ZVAL_NULL(&params[3]);
    }
    if (options_array != NULL) {
        ZVAL_COPY(&params[4], options_array);
    } else {
        ZVAL_NULL(&params[4]);
    }

    if (king_awaitable_create_function_call(
            return_value,
            "king_http1_request_send_async",
            sizeof("king_http1_request_send_async") - 1,
            "king_http1_request_send",
            sizeof("king_http1_request_send") - 1,
            params,
            5,
            NULL
        ) != SUCCESS) {
        for (index = 0; index < 5; index++) {
            zval_ptr_dtor(&params[index]);
        }
        RETURN_FALSE;
    }

    for (index = 0; index < 5; index++) {
        zval_ptr_dtor(&params[index]);
    }
}
