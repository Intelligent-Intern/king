static void king_http1_set_error(const char *format, ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static void king_http1_throw(
    zend_class_entry *ce,
    const char *format,
    ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
    zend_throw_exception_ex(
        ce != NULL ? ce : king_ce_exception,
        0,
        "%s",
        message
    );
}

static bool king_http1_is_token_char(unsigned char c)
{
    return isalnum(c)
        || c == '!'
        || c == '#'
        || c == '$'
        || c == '%'
        || c == '&'
        || c == '\''
        || c == '*'
        || c == '+'
        || c == '-'
        || c == '.'
        || c == '^'
        || c == '_'
        || c == '`'
        || c == '|'
        || c == '~';
}

static bool king_http1_string_has_crlf(const char *value, size_t value_len)
{
    size_t i;

    for (i = 0; i < value_len; ++i) {
        if (value[i] == '\r' || value[i] == '\n') {
            return true;
        }
    }

    return false;
}

static bool king_http1_header_value_has_token(
    const char *value,
    const char *token)
{
    size_t token_len = strlen(token);
    const char *cursor = value;

    while (*cursor != '\0') {
        const char *start;
        const char *end;

        while (*cursor == ' ' || *cursor == '\t' || *cursor == ',') {
            cursor++;
        }

        start = cursor;
        while (*cursor != '\0' && *cursor != ',') {
            cursor++;
        }

        end = cursor;
        while (end > start && (end[-1] == ' ' || end[-1] == '\t')) {
            end--;
        }

        if ((size_t) (end - start) == token_len && strncasecmp(start, token, token_len) == 0) {
            return true;
        }

        if (*cursor == ',') {
            cursor++;
        }
    }

    return false;
}

static zend_result king_http1_validate_method(
    const char *method_str,
    size_t method_len,
    const char *function_name)
{
    size_t i;

    if (method_len == 0) {
        king_http1_set_error("%s() requires a non-empty HTTP method.", function_name);
        return FAILURE;
    }

    for (i = 0; i < method_len; ++i) {
        if (!king_http1_is_token_char((unsigned char) method_str[i])) {
            king_http1_set_error(
                "%s() method contains invalid HTTP token characters.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_http1_validate_positive_timeout(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        king_http1_set_error(
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    if (Z_LVAL_P(value) <= 0) {
        king_http1_set_error(
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_http1_validate_non_negative_long(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        king_http1_set_error(
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    if (Z_LVAL_P(value) < 0) {
        king_http1_set_error(
            "%s() option '%s' must be >= 0.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_http1_validate_bool_option(
    zval *value,
    const char *option_name,
    bool *target,
    const char *function_name)
{
    if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
        king_http1_set_error(
            "%s() option '%s' must be provided as a boolean.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    *target = Z_TYPE_P(value) == IS_TRUE;
    return SUCCESS;
}

static zend_result king_http1_parse_options(
    zval *options_array,
    king_http1_request_options_t *options,
    const char *function_name)
{
    zval *option_value;

    options->config = NULL;
    options->connect_timeout_ms = king_tcp_transport_config.connect_timeout_ms > 0
        ? king_tcp_transport_config.connect_timeout_ms
        : 5000;
    options->timeout_ms = KING_HTTP1_DEFAULT_TIMEOUT_MS;
    options->max_redirects = 5;
    options->tcp_enable = king_tcp_transport_config.enable;
    options->tcp_keepalive_enable = king_tcp_transport_config.keepalive_enable;
    options->tcp_nodelay_enable = king_tcp_transport_config.nodelay_enable;
    options->follow_redirects = false;
    options->response_stream = false;
    options->cancel_token = king_transport_cancel_token_from_options(options_array);
    options->cancel_function_name = king_transport_cancel_function_name_from_options(options_array);
    options->cancel_exception_ce = king_transport_cancel_exception_ce_from_options(options_array);

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connection_config",
        sizeof("connection_config") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        options->config = king_fetch_config(option_value);
        if (options->config == NULL) {
            king_http1_set_error(
                "%s() option 'connection_config' must be a King\\Config resource or object.",
                function_name
            );
            return FAILURE;
        }

        options->connect_timeout_ms = options->config->tcp.connect_timeout_ms > 0
            ? options->config->tcp.connect_timeout_ms
            : options->connect_timeout_ms;
        options->timeout_ms = options->connect_timeout_ms > KING_HTTP1_DEFAULT_TIMEOUT_MS
            ? options->connect_timeout_ms
            : KING_HTTP1_DEFAULT_TIMEOUT_MS;
        options->tcp_enable = options->config->tcp.enable;
        options->tcp_keepalive_enable = options->config->tcp.keepalive_enable;
        options->tcp_nodelay_enable = options->config->tcp.nodelay_enable;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connect_timeout_ms",
        sizeof("connect_timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_http1_validate_positive_timeout(
                option_value,
                "connect_timeout_ms",
                &options->connect_timeout_ms,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "timeout_ms",
        sizeof("timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_http1_validate_positive_timeout(
                option_value,
                "timeout_ms",
                &options->timeout_ms,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "follow_redirects",
        sizeof("follow_redirects") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_http1_validate_bool_option(
                option_value,
                "follow_redirects",
                &options->follow_redirects,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "max_redirects",
        sizeof("max_redirects") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_http1_validate_non_negative_long(
                option_value,
                "max_redirects",
                &options->max_redirects,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "response_stream",
        sizeof("response_stream") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_http1_validate_bool_option(
                option_value,
                "response_stream",
                &options->response_stream,
                function_name
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    if (!options->tcp_enable) {
        king_http1_set_error(
            "%s() cannot issue an HTTP/1 request while tcp.enable is disabled.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_http1_request_allows_keep_alive(
    zval *headers_array,
    bool *allow_keep_alive)
{
    zval *header_value;
    zend_string *header_name;

    *allow_keep_alive = true;

    if (headers_array == NULL || Z_TYPE_P(headers_array) != IS_ARRAY) {
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers_array), header_name, header_value) {
        zend_string *header_value_string;

        if (header_name == NULL || strcasecmp(ZSTR_VAL(header_name), "connection") != 0) {
            continue;
        }

        header_value_string = zval_get_string(header_value);
        if (king_http1_header_value_has_token(ZSTR_VAL(header_value_string), "close")) {
            *allow_keep_alive = false;
        } else if (king_http1_header_value_has_token(ZSTR_VAL(header_value_string), "keep-alive")) {
            *allow_keep_alive = true;
        } else {
            *allow_keep_alive = false;
        }
        zend_string_release(header_value_string);
        return SUCCESS;
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static bool king_http1_status_is_redirect(zend_long status_code)
{
    return status_code == 301
        || status_code == 302
        || status_code == 303
        || status_code == 307
        || status_code == 308;
}

static bool king_http1_status_has_no_body(zend_long status_code)
{
    return (status_code >= 100 && status_code < 200)
        || status_code == 204
        || status_code == 304;
}

static bool king_http1_response_allows_keep_alive(const king_http1_response_meta_t *meta)
{
    if (meta->connection_close) {
        return false;
    }

    if (meta->persistent_by_default) {
        return true;
    }

    return meta->connection_keep_alive;
}

static bool king_http1_response_is_self_delimited(
    const king_http1_response_meta_t *meta,
    bool expect_no_body)
{
    return meta->has_content_length
        || meta->chunked_transfer_encoding
        || expect_no_body
        || king_http1_status_has_no_body(meta->status_code);
}

static bool king_http1_method_expects_no_response_body(
    const char *method_str,
    size_t method_len)
{
    return method_len == sizeof("HEAD") - 1
        && strncasecmp(method_str, "HEAD", sizeof("HEAD") - 1) == 0;
}

static bool king_http1_redirect_rewrites_to_get(
    zend_long status_code,
    const char *method_str,
    size_t method_len)
{
    if (status_code == 303) {
        return strncasecmp(method_str, "HEAD", method_len) != 0 || method_len != sizeof("HEAD") - 1;
    }

    if ((status_code == 301 || status_code == 302) && method_len == sizeof("POST") - 1) {
        return strncasecmp(method_str, "POST", sizeof("POST") - 1) == 0;
    }

    return false;
}

static zend_result king_http1_build_redirect_url(
    php_url *current_url,
    const char *location,
    size_t location_len,
    zend_string **target_url,
    const char *function_name)
{
    smart_str buffer = {0};
    const char *scheme;
    const char *host;
    zend_long port;
    const char *path;
    const char *slash;
    size_t prefix_len;
    size_t path_len;

    *target_url = NULL;

    if (location_len == 0) {
        king_http1_throw(
            king_ce_protocol_exception,
            "%s() received an empty redirect Location header.",
            function_name
        );
        return FAILURE;
    }

    if (king_http1_string_has_crlf(location, location_len)) {
        king_http1_throw(
            king_ce_protocol_exception,
            "%s() received a redirect Location header containing line breaks.",
            function_name
        );
        return FAILURE;
    }

    if (strstr(location, "://") != NULL) {
        *target_url = zend_string_init(location, location_len, 0);
        return SUCCESS;
    }

    if (current_url == NULL || current_url->scheme == NULL || current_url->host == NULL) {
        king_http1_throw(
            king_ce_protocol_exception,
            "%s() cannot resolve a relative redirect without a valid base URL.",
            function_name
        );
        return FAILURE;
    }

    scheme = ZSTR_VAL(current_url->scheme);
    host = ZSTR_VAL(current_url->host);
    port = current_url->port > 0 ? current_url->port : 80;

    smart_str_appends(&buffer, scheme);
    smart_str_appends(&buffer, "://");
    if (strchr(host, ':') != NULL) {
        smart_str_appends(&buffer, "[");
        smart_str_appends(&buffer, host);
        smart_str_appends(&buffer, "]");
    } else {
        smart_str_appends(&buffer, host);
    }

    if (port != 80) {
        smart_str_append_printf(&buffer, ":%ld", port);
    }

    if (location_len >= 2 && location[0] == '/' && location[1] == '/') {
        smart_str_free(&buffer);
        smart_str_appends(&buffer, scheme);
        smart_str_appends(&buffer, ":");
        smart_str_appendl(&buffer, location, location_len);
        smart_str_0(&buffer);
        *target_url = buffer.s;
        return SUCCESS;
    }

    if (location[0] == '/') {
        smart_str_appendl(&buffer, location, location_len);
        smart_str_0(&buffer);
        *target_url = buffer.s;
        return SUCCESS;
    }

    path = current_url->path != NULL ? ZSTR_VAL(current_url->path) : "/";
    path_len = current_url->path != NULL ? ZSTR_LEN(current_url->path) : 1;
    slash = strrchr(path, '/');
    if (slash == NULL) {
        smart_str_appends(&buffer, "/");
    } else {
        prefix_len = (size_t) (slash - path) + 1;
        smart_str_appendl(&buffer, path, prefix_len);
    }

    if (location[0] == '?') {
        if (slash == NULL) {
            smart_str_appends(&buffer, path);
        } else {
            smart_str_appendl(&buffer, path, path_len);
        }
        smart_str_appendl(&buffer, location, location_len);
    } else {
        smart_str_appendl(&buffer, location, location_len);
    }

    smart_str_0(&buffer);
    *target_url = buffer.s;
    return SUCCESS;
}

static zend_result king_http1_build_origin_key(
    php_url *parsed_url,
    zend_long port,
    const king_http1_request_options_t *options,
    char **origin_out)
{
    smart_str origin = {0};
    const char *scheme;
    const char *host;

    *origin_out = NULL;

    if (parsed_url == NULL || parsed_url->scheme == NULL || parsed_url->host == NULL) {
        return FAILURE;
    }

    scheme = ZSTR_VAL(parsed_url->scheme);
    host = ZSTR_VAL(parsed_url->host);

    smart_str_appends(&origin, scheme);
    smart_str_appends(&origin, "://");
    if (strchr(host, ':') != NULL) {
        smart_str_appends(&origin, "[");
        smart_str_appends(&origin, host);
        smart_str_appends(&origin, "]");
    } else {
        smart_str_appends(&origin, host);
    }
    smart_str_append_printf(
        &origin,
        ":%ld|ka=%d|nd=%d",
        port,
        options != NULL && options->tcp_keepalive_enable ? 1 : 0,
        options != NULL && options->tcp_nodelay_enable ? 1 : 0
    );
    smart_str_0(&origin);

    if (origin.s == NULL) {
        return FAILURE;
    }

    *origin_out = estrdup(ZSTR_VAL(origin.s));
    smart_str_free(&origin);
    return *origin_out != NULL ? SUCCESS : FAILURE;
}
