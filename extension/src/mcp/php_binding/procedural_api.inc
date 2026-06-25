PHP_FUNCTION(king_mcp_connect)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *options = NULL;
    king_mcp_state *state;
    zend_long default_timeout_ms = 0;

    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (Z_TYPE_P(config) == IS_ARRAY) {
        if (!king_globals.is_userland_override_allowed && zend_hash_num_elements(Z_ARRVAL_P(config)) > 0) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "Configuration override is disabled by system policy."
            );
            RETURN_THROWS();
        }
    }

    if (
        king_mcp_connection_options_parse(
            options,
            "king_mcp_connect",
            NULL,
            &default_timeout_ms
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    state = king_mcp_state_create(
        host,
        host_len,
        port,
        config
    );
    if (state == NULL) {
        RETURN_FALSE;
    }
    state->default_timeout_ms = default_timeout_ms;

    RETURN_RES(zend_register_resource(state, le_king_mcp));
}

PHP_FUNCTION(king_mcp_request)
{
    zval *connection;
    char *service_name = NULL;
    char *method_name = NULL;
    size_t service_name_len = 0;
    size_t method_name_len = 0;
    zend_string *request_payload_str;
    zend_string *response_payload;
    zval *options = NULL;
    king_mcp_state *state;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_STR(request_payload_str)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_fetch_state(connection, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->closed) {
        king_set_error("king_mcp_request() cannot run on a closed MCP connection.");
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_parse_for_state(
            state,
            options,
            NULL,
            "king_mcp_request",
            NULL,
            &control
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_check(
            &control,
            "king_mcp_request",
            NULL,
            NULL
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    if (king_mcp_request(
            state,
            service_name,
            service_name_len,
            method_name,
            method_name_len,
            request_payload_str,
            &response_payload,
            &control
        ) != SUCCESS) {
        if (king_mcp_runtime_control_check(
                &control,
                "king_mcp_request",
                NULL,
                NULL
            ) != SUCCESS) {
            RETURN_FALSE;
        }
        RETURN_FALSE;
    }

    if (response_payload == NULL) {
        state->last_error_kind = KING_MCP_ERROR_PROTOCOL;
        king_set_error("king_mcp_request() received an invalid empty response payload from the remote MCP peer.");
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_check(
            &control,
            "king_mcp_request",
            NULL,
            NULL
        ) != SUCCESS) {
        zend_string_release(response_payload);
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_STR(response_payload);
}

PHP_FUNCTION(king_mcp_request_async)
{
    zval *connection;
    char *service_name = NULL;
    char *method_name = NULL;
    size_t service_name_len = 0;
    size_t method_name_len = 0;
    zend_string *request_payload_str;
    zval *options = NULL;
    zval params[5];
    uint32_t index;

    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_STR(request_payload_str)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_COPY(&params[0], connection);
    ZVAL_STRINGL(&params[1], service_name, service_name_len);
    ZVAL_STRINGL(&params[2], method_name, method_name_len);
    ZVAL_STR_COPY(&params[3], request_payload_str);
    if (options != NULL) {
        ZVAL_COPY(&params[4], options);
    } else {
        ZVAL_NULL(&params[4]);
    }

    if (king_awaitable_create_function_call(
            return_value,
            "king_mcp_request_async",
            sizeof("king_mcp_request_async") - 1,
            "king_mcp_request",
            sizeof("king_mcp_request") - 1,
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

PHP_FUNCTION(king_mcp_request_iibin)
{
    zval *connection;
    char *service_name = NULL;
    char *method_name = NULL;
    size_t service_name_len = 0;
    size_t method_name_len = 0;
    zval *request_data;
    zval *options = NULL;
    zval decoded_response;
    zend_string *request_payload = NULL;
    zend_string *response_payload = NULL;
    king_mcp_state *state;
    king_mcp_runtime_control_t control;
    king_mcp_iibin_route_t route;

    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_ZVAL(request_data)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_fetch_state(connection, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->closed) {
        king_set_error("king_mcp_request_iibin() cannot run on a closed MCP connection.");
        RETURN_FALSE;
    }

    if (king_mcp_iibin_route_resolve(
            state,
            service_name,
            service_name_len,
            method_name,
            method_name_len,
            "king_mcp_request_iibin",
            NULL,
            &route
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    if (king_mcp_iibin_encode_request_payload(
            route.request_schema,
            request_data,
            &request_payload
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse_for_state(
            state,
            options,
            NULL,
            "king_mcp_request_iibin",
            NULL,
            &control
        ) != SUCCESS) {
        zend_string_release(request_payload);
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_check(
            &control,
            "king_mcp_request_iibin",
            NULL,
            NULL
        ) != SUCCESS) {
        zend_string_release(request_payload);
        RETURN_FALSE;
    }

    if (king_mcp_request(
            state,
            service_name,
            service_name_len,
            method_name,
            method_name_len,
            request_payload,
            &response_payload,
            &control
        ) != SUCCESS) {
        zend_string_release(request_payload);
        if (king_mcp_runtime_control_check(
                &control,
                "king_mcp_request_iibin",
                NULL,
                NULL
            ) != SUCCESS) {
            RETURN_FALSE;
        }
        RETURN_FALSE;
    }

    zend_string_release(request_payload);

    if (response_payload == NULL) {
        state->last_error_kind = KING_MCP_ERROR_PROTOCOL;
        king_set_error("king_mcp_request_iibin() received an invalid empty response payload from the remote MCP peer.");
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_check(
            &control,
            "king_mcp_request_iibin",
            NULL,
            NULL
        ) != SUCCESS) {
        zend_string_release(response_payload);
        RETURN_FALSE;
    }

    if (route.response_schema != NULL) {
        ZVAL_UNDEF(&decoded_response);
        if (king_mcp_iibin_decode_response_payload(
                route.response_schema,
                response_payload,
                route.decode_as_object,
                &decoded_response
            ) != SUCCESS) {
            zend_string_release(response_payload);
            RETURN_THROWS();
        }
        zend_string_release(response_payload);
        king_set_error("");
        RETURN_ZVAL(&decoded_response, 0, 1);
    }

    king_set_error("");
    RETURN_STR(response_payload);
}

PHP_FUNCTION(king_mcp_request_iibin_async)
{
    zval *connection;
    char *service_name = NULL;
    char *method_name = NULL;
    size_t service_name_len = 0;
    size_t method_name_len = 0;
    zval *request_data;
    zval *options = NULL;
    zval params[5];
    uint32_t index;

    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_ZVAL(request_data)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_COPY(&params[0], connection);
    ZVAL_STRINGL(&params[1], service_name, service_name_len);
    ZVAL_STRINGL(&params[2], method_name, method_name_len);
    ZVAL_COPY(&params[3], request_data);
    if (options != NULL) {
        ZVAL_COPY(&params[4], options);
    } else {
        ZVAL_NULL(&params[4]);
    }

    if (king_awaitable_create_function_call(
            return_value,
            "king_mcp_request_iibin_async",
            sizeof("king_mcp_request_iibin_async") - 1,
            "king_mcp_request_iibin",
            sizeof("king_mcp_request_iibin") - 1,
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

PHP_FUNCTION(king_mcp_close)
{
    zval *connection;
    king_mcp_state *state;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(connection)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_fetch_state(connection, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    king_mcp_state_close(state);
    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_mcp_upload_from_stream)
{
    zval *connection;
    char *service_name = NULL;
    char *method_name = NULL;
    char *stream_identifier = NULL;
    size_t service_name_len = 0;
    size_t method_name_len = 0;
    size_t stream_identifier_len = 0;
    zval *stream_zv;
    zval *options = NULL;
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(5, 6)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_STRING(stream_identifier, stream_identifier_len)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_fetch_state(connection, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->closed) {
        king_set_error("king_mcp_upload_from_stream() cannot run on a closed MCP connection.");
        RETURN_FALSE;
    }

    if (
        king_mcp_validate_transfer_args(
            service_name,
            service_name_len,
            method_name,
            method_name_len,
            "stream identifier",
            stream_identifier,
            stream_identifier_len,
            "king_mcp_upload_from_stream",
            NULL
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    stream = king_mcp_fetch_stream_resource(stream_zv, 5);
    if (stream == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse_for_state(
            state,
            options,
            NULL,
            "king_mcp_upload_from_stream",
            NULL,
            &control
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    payload = king_mcp_read_stream_payload(stream, "king_mcp_upload_from_stream", NULL, NULL, &control);
    if (payload == NULL) {
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_check(
            &control,
            "king_mcp_upload_from_stream",
            NULL,
            NULL
        ) != SUCCESS) {
        zend_string_release(payload);
        RETURN_FALSE;
    }

    if (king_mcp_transfer_store(
        state,
        service_name,
        service_name_len,
        method_name,
        method_name_len,
        stream_identifier,
        stream_identifier_len,
        payload,
        &control
    ) != SUCCESS) {
        zend_string_release(payload);
        if (king_mcp_runtime_control_check(
                &control,
                "king_mcp_upload_from_stream",
                NULL,
                NULL
            ) != SUCCESS) {
            RETURN_FALSE;
        }
        if (king_get_error()[0] == '\0') {
            king_set_error("king_mcp_upload_from_stream() failed to persist the MCP transfer state.");
        }
        RETURN_FALSE;
    }

    zend_string_release(payload);
    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_mcp_download_to_stream)
{
    zval *connection;
    char *service_name = NULL;
    char *method_name = NULL;
    char *request_payload = NULL;
    size_t service_name_len = 0;
    size_t method_name_len = 0;
    size_t request_payload_len = 0;
    zval *stream_zv;
    zval *options = NULL;
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(5, 6)
        Z_PARAM_ZVAL(connection)
        Z_PARAM_STRING(service_name, service_name_len)
        Z_PARAM_STRING(method_name, method_name_len)
        Z_PARAM_STRING(request_payload, request_payload_len)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_fetch_state(connection, 1);
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (state->closed) {
        king_set_error("king_mcp_download_to_stream() cannot run on a closed MCP connection.");
        RETURN_FALSE;
    }

    if (
        king_mcp_validate_transfer_args(
            service_name,
            service_name_len,
            method_name,
            method_name_len,
            "request payload",
            request_payload,
            request_payload_len,
            "king_mcp_download_to_stream",
            NULL
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    stream = king_mcp_fetch_stream_resource(stream_zv, 5);
    if (stream == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse_for_state(
            state,
            options,
            NULL,
            "king_mcp_download_to_stream",
            NULL,
            &control
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    if (king_mcp_runtime_control_check(
            &control,
            "king_mcp_download_to_stream",
            NULL,
            NULL
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    payload = king_mcp_transfer_find(
        state,
        service_name,
        service_name_len,
        method_name,
        method_name_len,
        request_payload,
        request_payload_len,
        &control
    );
    if (payload == NULL) {
        if (king_mcp_runtime_control_check(
                &control,
                "king_mcp_download_to_stream",
                NULL,
                NULL
            ) != SUCCESS) {
            RETURN_FALSE;
        }
        if (king_get_error()[0] == '\0') {
            king_set_error(
                "king_mcp_download_to_stream() could not find an MCP transfer for the requested payload identifier."
            );
        }
        RETURN_FALSE;
    }

    if (
        king_mcp_write_payload_to_stream(
            stream,
            payload,
            "king_mcp_download_to_stream",
            NULL,
            NULL,
            &control
        ) != SUCCESS
    ) {
        zend_string_release(payload);
        RETURN_FALSE;
    }

    if (king_mcp_transfer_acknowledge(
            state,
            service_name,
            service_name_len,
            method_name,
            method_name_len,
            request_payload,
            request_payload_len
        ) != SUCCESS) {
        zend_string_release(payload);
        RETURN_FALSE;
    }

    zend_string_release(payload);
    king_set_error("");
    RETURN_TRUE;
}
