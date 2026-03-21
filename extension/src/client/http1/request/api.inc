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
