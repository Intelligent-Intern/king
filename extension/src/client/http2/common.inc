static void king_http2_set_error(const char *format, ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static void king_http2_throw(
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

static bool king_http2_is_token_char(unsigned char c)
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

static bool king_http2_string_has_crlf(const char *value, size_t value_len)
{
    size_t i;

    for (i = 0; i < value_len; ++i) {
        if (value[i] == '\r' || value[i] == '\n') {
            return true;
        }
    }

    return false;
}

static zend_result king_http2_validate_method(
    const char *method_str,
    size_t method_len,
    const char *function_name)
{
    size_t i;

    if (method_len == 0) {
        king_http2_set_error("%s() requires a non-empty HTTP method.", function_name);
        return FAILURE;
    }

    for (i = 0; i < method_len; ++i) {
        if (!king_http2_is_token_char((unsigned char) method_str[i])) {
            king_http2_set_error(
                "%s() method contains invalid HTTP token characters.",
                function_name
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_http2_validate_positive_timeout(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        king_http2_set_error(
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    if (Z_LVAL_P(value) <= 0) {
        king_http2_set_error(
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_http2_parse_options(
    zval *options_array,
    king_http2_request_options_t *options,
    const char *function_name)
{
    zval *option_value;

    options->config = NULL;
    options->connect_timeout_ms = king_tcp_transport_config.connect_timeout_ms > 0
        ? king_tcp_transport_config.connect_timeout_ms
        : 5000;
    options->timeout_ms = KING_HTTP2_DEFAULT_TIMEOUT_MS;
    options->tcp_enable = king_tcp_transport_config.enable;
    options->tcp_keepalive_enable = king_tcp_transport_config.keepalive_enable;
    options->tcp_nodelay_enable = king_tcp_transport_config.nodelay_enable;
    options->http2_enable = king_http2_config.enable;
    options->http2_enable_push = king_http2_config.enable_push;
    options->capture_push = false;
    options->http2_max_concurrent_streams = king_http2_config.max_concurrent_streams;
    options->tls_verify_peer = king_tls_and_crypto_config.tls_verify_peer;
    options->tls_default_ca_file = king_tls_and_crypto_config.tls_default_ca_file;
    options->tls_default_cert_file = king_tls_and_crypto_config.tls_default_cert_file;
    options->tls_default_key_file = king_tls_and_crypto_config.tls_default_key_file;
    options->cancel_token = king_transport_cancel_token_from_options(options_array);
    options->cancel_function_name = king_transport_cancel_function_name_from_options(options_array);
    options->cancel_exception_ce = king_transport_cancel_exception_ce_from_options(options_array);

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        goto validate;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connection_config",
        sizeof("connection_config") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        options->config = king_fetch_config(option_value);
        if (options->config == NULL) {
            king_http2_set_error(
                "%s() option 'connection_config' must be a King\\Config resource or object.",
                function_name
            );
            return FAILURE;
        }

        options->connect_timeout_ms = options->config->tcp.connect_timeout_ms > 0
            ? options->config->tcp.connect_timeout_ms
            : options->connect_timeout_ms;
        options->timeout_ms = options->connect_timeout_ms > KING_HTTP2_DEFAULT_TIMEOUT_MS
            ? options->connect_timeout_ms
            : KING_HTTP2_DEFAULT_TIMEOUT_MS;
        options->tcp_enable = options->config->tcp.enable;
        options->tcp_keepalive_enable = options->config->tcp.keepalive_enable;
        options->tcp_nodelay_enable = options->config->tcp.nodelay_enable;
        options->http2_enable = options->config->http2.enable;
        options->http2_enable_push = options->config->http2.enable_push;
        options->http2_max_concurrent_streams = options->config->http2.max_concurrent_streams;
        options->tls_verify_peer = options->config->tls.tls_verify_peer;
        options->tls_default_ca_file = options->config->tls.tls_default_ca_file;
        options->tls_default_cert_file = options->config->tls.tls_default_cert_file;
        options->tls_default_key_file = options->config->tls.tls_default_key_file;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connect_timeout_ms",
        sizeof("connect_timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_http2_validate_positive_timeout(
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
        if (king_http2_validate_positive_timeout(
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
        "capture_push",
        sizeof("capture_push") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (Z_TYPE_P(option_value) != IS_TRUE && Z_TYPE_P(option_value) != IS_FALSE) {
            king_http2_set_error(
                "%s() option 'capture_push' must be provided as a boolean.",
                function_name
            );
            return FAILURE;
        }

        options->capture_push = Z_TYPE_P(option_value) == IS_TRUE;
    }

validate:
    if (!options->tcp_enable) {
        king_http2_set_error(
            "%s() cannot issue an HTTP/2 request while tcp.enable is disabled.",
            function_name
        );
        return FAILURE;
    }

    if (!options->http2_enable) {
        king_http2_set_error(
            "%s() cannot issue an HTTP/2 request while http2.enable is disabled.",
            function_name
        );
        return FAILURE;
    }

    if (options->capture_push && !options->http2_enable_push) {
        king_http2_set_error(
            "%s() option 'capture_push' requires http2.enable_push to be enabled.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static bool king_http2_url_is_https(php_url *parsed_url)
{
    return parsed_url != NULL
        && parsed_url->scheme != NULL
        && strcasecmp(ZSTR_VAL(parsed_url->scheme), "https") == 0;
}

static zend_long king_http2_resolve_default_port(php_url *parsed_url)
{
    if (parsed_url != NULL && parsed_url->port > 0) {
        return parsed_url->port;
    }

    return king_http2_url_is_https(parsed_url) ? 443 : 80;
}

static zend_result king_http2_build_origin_key(
    php_url *parsed_url,
    zend_long port,
    const king_http2_request_options_t *options,
    char **origin_out)
{
    zend_string *lower_scheme;
    zend_string *lower_host;
    const char *ca_file = "";
    const char *cert_file = "";
    const char *key_file = "";

    lower_scheme = zend_string_tolower(parsed_url->scheme);
    lower_host = zend_string_tolower(parsed_url->host);

    if (options != NULL) {
        ca_file = options->tls_default_ca_file != NULL ? options->tls_default_ca_file : "";
        cert_file = options->tls_default_cert_file != NULL ? options->tls_default_cert_file : "";
        key_file = options->tls_default_key_file != NULL ? options->tls_default_key_file : "";
    }

    spprintf(
        origin_out,
        0,
        "%s://%s:%ld|verify=%d|ca=%s|cert=%s|key=%s",
        ZSTR_VAL(lower_scheme),
        ZSTR_VAL(lower_host),
        port,
        options != NULL && options->tls_verify_peer ? 1 : 0,
        ca_file,
        cert_file,
        key_file
    );
    zend_string_release(lower_scheme);
    zend_string_release(lower_host);

    return *origin_out != NULL ? SUCCESS : FAILURE;
}
