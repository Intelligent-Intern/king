PHP_FUNCTION(king_mcp_server_create)
{
    zval *definition;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(definition)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_mcp_public_server_normalize(definition, return_value) != SUCCESS) {
        RETURN_FALSE;
    }

    if (king_mcp_public_server_apply_creation_options(return_value, options) != SUCCESS) {
        zval_ptr_dtor(return_value);
        RETURN_FALSE;
    }

    king_set_error("");
}

PHP_FUNCTION(king_mcp_server_handle_jsonrpc)
{
    zval *server;
    zval *message;
    zval *context = NULL;
    zend_string *json = NULL;
    bool has_response = false;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ARRAY(server)
        Z_PARAM_ZVAL(message)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(context)
    ZEND_PARSE_PARAMETERS_END();

    if (
        king_mcp_public_handle_jsonrpc_encoded(
            server,
            message,
            context,
            &json,
            &has_response
        ) != SUCCESS
    ) {
        if (EG(exception)) {
            RETURN_THROWS();
        }
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            king_get_error()[0] != '\0' ? king_get_error() : "MCP JSON-RPC handling failed."
        );
        RETURN_THROWS();
    }

    if (!has_response) {
        RETURN_NULL();
    }

    king_set_error("");
    RETURN_STR(json);
}

PHP_FUNCTION(king_mcp_server_handle_http)
{
    zval *server;
    zval *request;
    zval *context = NULL;
    zval context_with_request;
    zval request_copy;
    zval *method;
    zval *body;
    zend_string *json = NULL;
    zend_string *sse = NULL;
    bool has_response = false;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ARRAY(server)
        Z_PARAM_ARRAY(request)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(context)
    ZEND_PARSE_PARAMETERS_END();

    method = zend_hash_str_find(Z_ARRVAL_P(request), "method", sizeof("method") - 1);
    if (method == NULL || Z_TYPE_P(method) != IS_STRING) {
        king_mcp_public_http_response(return_value, 400, "application/json", NULL);
        return;
    }

    if (!king_mcp_public_http_origin_allowed(server, request)) {
        zend_string *body_json = zend_string_init(
            "{\"jsonrpc\":\"2.0\",\"id\":null,\"error\":{\"code\":-32600,\"message\":\"MCP HTTP Origin is not allowed.\"}}",
            sizeof("{\"jsonrpc\":\"2.0\",\"id\":null,\"error\":{\"code\":-32600,\"message\":\"MCP HTTP Origin is not allowed.\"}}") - 1,
            0
        );
        king_mcp_public_http_response(return_value, 403, "application/json", body_json);
        zend_string_release(body_json);
        return;
    }

    if (strcasecmp(Z_STRVAL_P(method), "OPTIONS") == 0) {
        zval headers;
        array_init(return_value);
        add_assoc_long(return_value, "status", 204);
        array_init(&headers);
        add_assoc_string(&headers, "allow", "GET, POST, OPTIONS");
        add_assoc_string(&headers, "accept-post", "application/json");
        add_assoc_zval(return_value, "headers", &headers);
        add_assoc_string(return_value, "body", "");
        return;
    }

    if (strcasecmp(Z_STRVAL_P(method), "GET") == 0) {
        if (king_mcp_public_header_contains(request, "accept", "text/event-stream")) {
            zend_string *open = zend_string_init(": king-mcp stream\n\n", sizeof(": king-mcp stream\n\n") - 1, 0);
            king_mcp_public_http_response(return_value, 200, "text/event-stream", open);
            zend_string_release(open);
            return;
        }
        king_mcp_public_http_response(return_value, 405, "application/json", NULL);
        return;
    }

    if (strcasecmp(Z_STRVAL_P(method), "POST") != 0) {
        king_mcp_public_http_response(return_value, 405, "application/json", NULL);
        return;
    }

    if (
        !king_mcp_public_header_contains(request, "accept", "application/json")
        || !king_mcp_public_header_contains(request, "accept", "text/event-stream")
    ) {
        zend_string *body_json = zend_string_init(
            "{\"jsonrpc\":\"2.0\",\"id\":null,\"error\":{\"code\":-32600,\"message\":\"MCP Streamable HTTP requires Accept: application/json, text/event-stream.\"}}",
            sizeof("{\"jsonrpc\":\"2.0\",\"id\":null,\"error\":{\"code\":-32600,\"message\":\"MCP Streamable HTTP requires Accept: application/json, text/event-stream.\"}}") - 1,
            0
        );
        king_mcp_public_http_response(return_value, 406, "application/json", body_json);
        zend_string_release(body_json);
        return;
    }

    body = zend_hash_str_find(Z_ARRVAL_P(request), "body", sizeof("body") - 1);
    if (body == NULL || Z_TYPE_P(body) != IS_STRING) {
        king_mcp_public_http_response(return_value, 400, "application/json", NULL);
        return;
    }

    array_init(&context_with_request);
    if (context != NULL && Z_TYPE_P(context) == IS_ARRAY) {
        zend_hash_copy(
            Z_ARRVAL(context_with_request),
            Z_ARRVAL_P(context),
            zval_add_ref
        );
    }
    ZVAL_COPY(&request_copy, request);
    add_assoc_zval(&context_with_request, "http_request", &request_copy);

    if (
        king_mcp_public_handle_jsonrpc_encoded(
            server,
            body,
            &context_with_request,
            &json,
            &has_response
        ) != SUCCESS
    ) {
        zval_ptr_dtor(&context_with_request);
        if (EG(exception)) {
            RETURN_THROWS();
        }
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            king_get_error()[0] != '\0' ? king_get_error() : "MCP HTTP handling failed."
        );
        RETURN_THROWS();
    }
    zval_ptr_dtor(&context_with_request);

    if (!has_response) {
        king_mcp_public_http_response(return_value, 202, NULL, NULL);
        return;
    }

    if (king_mcp_public_http_prefers_sse(server, request)) {
        sse = king_mcp_public_sse_body(json);
        king_mcp_public_http_response(return_value, 200, "text/event-stream", sse);
        zend_string_release(sse);
    } else {
        king_mcp_public_http_response(return_value, 200, "application/json", json);
    }
    zend_string_release(json);
    king_set_error("");
}

PHP_FUNCTION(king_mcp_server_stdio)
{
    zval *server;
    zval *options = NULL;
    php_stream *input;
    php_stream *output;
    char *buffer;
    size_t line_len = 0;
    zend_long max_line_bytes = 1024 * 1024;
    zval *option_value;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(server)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (options != NULL && Z_TYPE_P(options) == IS_ARRAY) {
        option_value = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "max_line_bytes",
            sizeof("max_line_bytes") - 1
        );
        if (option_value != NULL && Z_TYPE_P(option_value) == IS_LONG && Z_LVAL_P(option_value) > 0) {
            max_line_bytes = Z_LVAL_P(option_value);
        }
    }

    input = php_stream_open_wrapper("php://stdin", "rb", REPORT_ERRORS, NULL);
    output = php_stream_open_wrapper("php://stdout", "wb", REPORT_ERRORS, NULL);
    if (input == NULL || output == NULL) {
        if (input != NULL) {
            php_stream_close(input);
        }
        if (output != NULL) {
            php_stream_close(output);
        }
        RETURN_FALSE;
    }

    buffer = emalloc((size_t) max_line_bytes + 1);
    while (php_stream_get_line(input, buffer, (size_t) max_line_bytes + 1, &line_len) != NULL) {
        zval message;
        zend_string *json = NULL;
        bool has_response = false;

        while (line_len > 0 && (buffer[line_len - 1] == '\n' || buffer[line_len - 1] == '\r')) {
            buffer[--line_len] = '\0';
        }
        if (line_len == 0) {
            continue;
        }

        ZVAL_STRINGL(&message, buffer, line_len);
        if (
            king_mcp_public_handle_jsonrpc_encoded(
                server,
                &message,
                NULL,
                &json,
                &has_response
            ) != SUCCESS
        ) {
            zval_ptr_dtor(&message);
            efree(buffer);
            php_stream_close(input);
            php_stream_close(output);
            if (EG(exception)) {
                RETURN_THROWS();
            }
            RETURN_FALSE;
        }
        zval_ptr_dtor(&message);

        if (has_response && json != NULL) {
            php_stream_write(output, ZSTR_VAL(json), ZSTR_LEN(json));
            php_stream_write(output, "\n", 1);
            php_stream_flush(output);
            zend_string_release(json);
        }
    }

    efree(buffer);
    php_stream_close(input);
    php_stream_close(output);
    king_set_error("");
    RETURN_TRUE;
}
