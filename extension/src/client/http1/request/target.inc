static zend_result king_http1_prepare_request_target(
    php_url *parsed_url,
    smart_str *request_target,
    const char *function_name
)
{
    const char *path;
    size_t path_len;

    path = parsed_url->path != NULL ? ZSTR_VAL(parsed_url->path) : "/";
    path_len = parsed_url->path != NULL ? ZSTR_LEN(parsed_url->path) : 1;

    if (path_len == 0) {
        path = "/";
        path_len = 1;
    }

    if (king_http1_string_has_crlf(path, path_len)) {
        king_http1_set_error(
            "%s() URL path contains invalid line breaks.",
            function_name
        );
        return FAILURE;
    }

    smart_str_appendl(request_target, path, path_len);

    if (parsed_url->query != NULL && ZSTR_LEN(parsed_url->query) > 0) {
        if (king_http1_string_has_crlf(
                ZSTR_VAL(parsed_url->query),
                ZSTR_LEN(parsed_url->query)
            )) {
            king_http1_set_error(
                "%s() URL query contains invalid line breaks.",
                function_name
            );
            return FAILURE;
        }

        smart_str_appendc(request_target, '?');
        smart_str_append(request_target, parsed_url->query);
    }

    smart_str_0(request_target);
    return SUCCESS;
}
