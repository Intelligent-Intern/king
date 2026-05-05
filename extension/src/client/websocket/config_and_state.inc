static zend_result king_websocket_require_open(
    king_ws_state *state,
    const char *function_name
)
{
    if (state->state == KING_WS_STATE_OPEN && !state->closed) {
        return SUCCESS;
    }

    king_websocket_set_error(
        "%s() cannot run on a closed WebSocket connection.",
        function_name
    );
    return FAILURE;
}

static zend_result king_websocket_validate_close_inputs(
    zend_long status_code,
    size_t reason_len,
    const char *function_name
)
{
    if (status_code < 1000 || status_code > 4999) {
        king_websocket_set_error(
            "%s() close status code must be between 1000 and 4999.",
            function_name
        );
        return FAILURE;
    }

    if (reason_len > KING_WS_CLOSE_REASON_MAX_LEN) {
        king_websocket_set_error(
            "%s() close reason cannot exceed %d bytes.",
            function_name,
            KING_WS_CLOSE_REASON_MAX_LEN
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_websocket_validate_positive_option(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name
)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        king_websocket_set_error(
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    if (Z_LVAL_P(value) <= 0) {
        king_websocket_set_error(
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_long king_websocket_resolve_default_port(php_url *parsed_url)
{
    if (parsed_url->port > 0) {
        return (zend_long) parsed_url->port;
    }

    if (
        parsed_url->scheme != NULL
        && strcasecmp(ZSTR_VAL(parsed_url->scheme), "wss") == 0
    ) {
        return 443;
    }

    return 80;
}

static zend_result king_websocket_validate_raw_url_line_breaks(
    const char *url_str,
    size_t url_len,
    const char *function_name
)
{
    bool found_line_break = false;
    bool has_scheme_authority = false;
    bool has_query = false;
    size_t first_line_break = 0;
    size_t authority_start = 0;
    size_t authority_end = 0;
    size_t query_start = 0;
    size_t i;

    for (i = 0; i < url_len; ++i) {
        if (url_str[i] == '\r' || url_str[i] == '\n') {
            first_line_break = i;
            found_line_break = true;
            break;
        }
    }

    if (!found_line_break) {
        return SUCCESS;
    }

    for (i = 0; i + 2 < url_len; ++i) {
        if (url_str[i] == ':' && url_str[i + 1] == '/' && url_str[i + 2] == '/') {
            authority_start = i + 3;
            has_scheme_authority = true;
            break;
        }
    }

    if (!has_scheme_authority) {
        king_websocket_set_error(
            "%s() URL path contains invalid line breaks.",
            function_name
        );
        return FAILURE;
    }

    authority_end = url_len;
    for (i = authority_start; i < url_len; ++i) {
        if (url_str[i] == '/' || url_str[i] == '?' || url_str[i] == '#') {
            authority_end = i;
            break;
        }
    }

    if (first_line_break < authority_end) {
        king_websocket_set_error(
            "%s() URL host contains invalid line breaks.",
            function_name
        );
        return FAILURE;
    }

    for (i = authority_end; i < url_len; ++i) {
        if (url_str[i] == '#') {
            break;
        }

        if (url_str[i] == '?') {
            has_query = true;
            query_start = i + 1;
            break;
        }
    }

    if (has_query && first_line_break >= query_start) {
        king_websocket_set_error(
            "%s() URL query contains invalid line breaks.",
            function_name
        );
        return FAILURE;
    }

    king_websocket_set_error(
        "%s() URL path contains invalid line breaks.",
        function_name
    );
    return FAILURE;
}

static zend_result king_websocket_parse_url(
    const char *url_str,
    size_t url_len,
    const char *function_name,
    php_url **parsed_url_out
)
{
    php_url *parsed_url;

    if (url_len == 0) {
        king_websocket_set_error(
            "%s() requires a non-empty WebSocket URL.",
            function_name
        );
        return FAILURE;
    }

    if (
        king_websocket_validate_raw_url_line_breaks(
            url_str,
            url_len,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    parsed_url = php_url_parse_ex(url_str, url_len);
    if (parsed_url == NULL || parsed_url->scheme == NULL || parsed_url->host == NULL) {
        if (parsed_url != NULL) {
            php_url_free(parsed_url);
        }

        king_websocket_set_error(
            "%s() requires a valid absolute ws:// or wss:// URL.",
            function_name
        );
        return FAILURE;
    }

    if (
        strcasecmp(ZSTR_VAL(parsed_url->scheme), "ws") != 0
        && strcasecmp(ZSTR_VAL(parsed_url->scheme), "wss") != 0
    ) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() currently supports only absolute ws:// and wss:// URLs.",
            function_name
        );
        return FAILURE;
    }

    if (parsed_url->user != NULL || parsed_url->pass != NULL) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() does not support embedding credentials in the connection URL.",
            function_name
        );
        return FAILURE;
    }

    if (parsed_url->fragment != NULL) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() does not support URL fragments in the connection URL.",
            function_name
        );
        return FAILURE;
    }

    if (
        king_websocket_string_has_crlf(
            ZSTR_VAL(parsed_url->host),
            ZSTR_LEN(parsed_url->host)
        )
    ) {
        php_url_free(parsed_url);
        king_websocket_set_error(
            "%s() URL host contains invalid line breaks.",
            function_name
        );
        return FAILURE;
    }

    *parsed_url_out = parsed_url;
    return SUCCESS;
}

static zend_result king_websocket_build_request_target(
    php_url *parsed_url,
    const char *function_name,
    zend_string **request_target_out
)
{
    smart_str request_target = {0};
    const char *path;
    size_t path_len;

    path = parsed_url->path != NULL ? ZSTR_VAL(parsed_url->path) : "/";
    path_len = parsed_url->path != NULL ? ZSTR_LEN(parsed_url->path) : 1;

    if (path_len == 0) {
        path = "/";
        path_len = 1;
    }

    if (king_websocket_string_has_crlf(path, path_len)) {
        king_websocket_set_error(
            "%s() URL path contains invalid line breaks.",
            function_name
        );
        return FAILURE;
    }

    smart_str_appendl(&request_target, path, path_len);

    if (parsed_url->query != NULL && ZSTR_LEN(parsed_url->query) > 0) {
        if (
            king_websocket_string_has_crlf(
                ZSTR_VAL(parsed_url->query),
                ZSTR_LEN(parsed_url->query)
            )
        ) {
            smart_str_free(&request_target);
            king_websocket_set_error(
                "%s() URL query contains invalid line breaks.",
                function_name
            );
            return FAILURE;
        }

        smart_str_appendc(&request_target, '?');
        smart_str_append(&request_target, parsed_url->query);
    }

    smart_str_0(&request_target);
    *request_target_out = request_target.s;
    return SUCCESS;
}

static zend_result king_websocket_apply_options(
    king_ws_state *state,
    zval *options_array,
    const char *function_name
)
{
    king_cfg_t *config = NULL;
    zval *option_value;

    state->max_payload_size =
        king_app_protocols_config.websocket_default_max_payload_size > 0
            ? king_app_protocols_config.websocket_default_max_payload_size
            : 16777216;
    state->max_queued_messages =
        king_app_protocols_config.websocket_default_max_queued_messages > 0
            ? king_app_protocols_config.websocket_default_max_queued_messages
            : 64;
    state->max_queued_bytes =
        king_app_protocols_config.websocket_default_max_queued_bytes > 0
            ? king_app_protocols_config.websocket_default_max_queued_bytes
            : 67108864;
    state->ping_interval_ms =
        king_app_protocols_config.websocket_default_ping_interval_ms > 0
            ? king_app_protocols_config.websocket_default_ping_interval_ms
            : 25000;
    state->handshake_timeout_ms =
        king_app_protocols_config.websocket_handshake_timeout_ms > 0
            ? king_app_protocols_config.websocket_handshake_timeout_ms
            : 5000;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "connection_config",
        sizeof("connection_config") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        config = (king_cfg_t *) king_fetch_config(option_value);
        if (config == NULL) {
            king_websocket_set_error(
                "%s() option 'connection_config' must be a King\\Config resource or object.",
                function_name
            );
            return FAILURE;
        }

        ZVAL_COPY(&state->config, option_value);
        state->max_payload_size = config->app_protocols.websocket_default_max_payload_size;
        state->max_queued_messages = config->app_protocols.websocket_default_max_queued_messages;
        state->max_queued_bytes = config->app_protocols.websocket_default_max_queued_bytes;
        state->ping_interval_ms = config->app_protocols.websocket_default_ping_interval_ms;
        state->handshake_timeout_ms = config->app_protocols.websocket_handshake_timeout_ms;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "max_payload_size",
        sizeof("max_payload_size") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "max_payload_size",
                &state->max_payload_size,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "max_queued_messages",
        sizeof("max_queued_messages") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "max_queued_messages",
                &state->max_queued_messages,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "max_queued_bytes",
        sizeof("max_queued_bytes") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "max_queued_bytes",
                &state->max_queued_bytes,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "ping_interval_ms",
        sizeof("ping_interval_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "ping_interval_ms",
                &state->ping_interval_ms,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        "handshake_timeout_ms",
        sizeof("handshake_timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            king_websocket_validate_positive_option(
                option_value,
                "handshake_timeout_ms",
                &state->handshake_timeout_ms,
                function_name
            ) != SUCCESS
        ) {
            return FAILURE;
        }
    }

    if (state->max_queued_bytes < state->max_payload_size) {
        king_websocket_set_error(
            "%s() option 'max_queued_bytes' must be >= max_payload_size.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static king_ws_state *king_websocket_state_create(
    const char *url_str,
    size_t url_len,
    zval *headers_array,
    zval *options_array,
    const char *function_name
)
{
    php_url *parsed_url = NULL;
    zend_string *request_target = NULL;
    king_ws_state *state = NULL;

    if (
        king_websocket_parse_url(
            url_str,
            url_len,
            function_name,
            &parsed_url
        ) != SUCCESS
    ) {
        return NULL;
    }

    if (
        king_websocket_build_request_target(
            parsed_url,
            function_name,
            &request_target
        ) != SUCCESS
    ) {
        php_url_free(parsed_url);
        return NULL;
    }

    state = ecalloc(1, sizeof(*state));
    state->url = zend_string_init(url_str, url_len, 0);
    state->connection_id = zend_string_copy(state->url);
    state->scheme = zend_string_copy(parsed_url->scheme);
    state->host = zend_string_copy(parsed_url->host);
    state->request_target = request_target;
    state->port = king_websocket_resolve_default_port(parsed_url);
    state->state = KING_WS_STATE_CONNECTING;
    state->last_close_status_code = 1000;
    state->secure = strcasecmp(ZSTR_VAL(parsed_url->scheme), "wss") == 0;
    state->handshake_complete = false;
    state->close_frame_sent = false;
    state->closed = false;
    state->server_owner = NULL;
    ZVAL_UNDEF(&state->config);
    ZVAL_UNDEF(&state->headers);

    if (
        king_websocket_apply_options(
            state,
            options_array,
            function_name
        ) != SUCCESS
    ) {
        king_ws_state_free(state);
        php_url_free(parsed_url);
        return NULL;
    }

    if (headers_array != NULL && Z_TYPE_P(headers_array) != IS_NULL) {
        ZVAL_COPY(&state->headers, headers_array);
    }

    php_url_free(parsed_url);
    if (king_websocket_complete_handshake(state, function_name) != SUCCESS) {
        king_ws_state_free(state);
        return NULL;
    }

    king_set_error("");
    return state;
}

void king_ws_state_free(king_ws_state *state)
{
    if (state == NULL) {
        return;
    }

    if (state->server_owner != NULL) {
        king_ws_server_registry_detach(state->server_owner, state);
    }
    if (state->url != NULL) {
        zend_string_release(state->url);
    }
    if (state->connection_id != NULL) {
        zend_string_release(state->connection_id);
    }
    if (state->scheme != NULL) {
        zend_string_release(state->scheme);
    }
    if (state->host != NULL) {
        zend_string_release(state->host);
    }
    if (state->request_target != NULL) {
        zend_string_release(state->request_target);
    }
    if (state->transport_stream != NULL) {
        php_stream_close(state->transport_stream);
        state->transport_stream = NULL;
    }
    if (state->last_close_reason != NULL) {
        zend_string_release(state->last_close_reason);
    }
    if (state->last_ping_payload != NULL) {
        zend_string_release(state->last_ping_payload);
    }
    if (!Z_ISUNDEF(state->config)) {
        zval_ptr_dtor(&state->config);
    }
    if (!Z_ISUNDEF(state->headers)) {
        zval_ptr_dtor(&state->headers);
    }
    king_websocket_message_queue_clear(state);

    efree(state);
}

static void king_websocket_info_append_values(zval *target, zval *value)
{
    if (Z_TYPE_P(value) == IS_ARRAY) {
        zval *entry;

        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), entry)
        {
            add_next_index_str(target, zval_get_string(entry));
        }
        ZEND_HASH_FOREACH_END();
        return;
    }

    add_next_index_str(target, zval_get_string(value));
}

void king_websocket_state_build_info_array(zval *return_value, king_ws_state *state)
{
    zval headers;

    array_init(return_value);
    add_assoc_str(return_value, "id", zend_string_copy(state->url));
    add_assoc_str(
        return_value,
        "connection_id",
        zend_string_copy(state->connection_id != NULL ? state->connection_id : state->url)
    );
    add_assoc_str(
        return_value,
        "remote_addr",
        strpprintf(0, "%s:" ZEND_LONG_FMT, ZSTR_VAL(state->host), state->port)
    );
    add_assoc_str(return_value, "protocol", zend_string_copy(state->scheme));
    add_assoc_long(return_value, "max_payload_size", state->max_payload_size);
    add_assoc_long(return_value, "max_queued_messages", state->max_queued_messages);
    add_assoc_long(return_value, "max_queued_bytes", state->max_queued_bytes);
    add_assoc_long(return_value, "queued_message_count", state->queued_message_count);
    add_assoc_long(return_value, "queued_bytes", state->queued_bytes);
    add_assoc_bool(return_value, "closed", state->closed);

    array_init(&headers);
    if (!Z_ISUNDEF(state->headers) && Z_TYPE(state->headers) == IS_ARRAY) {
        zend_string *header_name;
        zval *header_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(state->headers), header_name, header_value)
        {
            zval normalized;

            if (header_name == NULL) {
                continue;
            }

            array_init(&normalized);
            king_websocket_info_append_values(&normalized, header_value);
            zend_hash_update(Z_ARRVAL(headers), header_name, &normalized);
        }
        ZEND_HASH_FOREACH_END();
    }
    add_assoc_zval(return_value, "headers", &headers);
}

static void king_websocket_throw_last_error(
    zend_class_entry *exception_ce,
    const char *fallback
)
{
    zend_throw_exception_ex(
        exception_ce,
        0,
        "%s",
        king_get_error()[0] != '\0' ? king_get_error() : fallback
    );
}
