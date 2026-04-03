PHP_METHOD(King_MCP, __construct)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config = NULL;
    king_mcp_state *state;
    king_mcp_object *intern;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(config, king_ce_config)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_state_create(
        host,
        host_len,
        port,
        config
    );
    if (state == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s",
            king_get_error()[0] != '\0'
                ? king_get_error()
                : "King\\MCP construction failed."
        );
        RETURN_THROWS();
    }

    intern = php_king_mcp_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
    }
    ZVAL_RES(&intern->resource, zend_register_resource(state, le_king_mcp));
    king_set_error("");
}

PHP_METHOD(King_MCP, request)
{
    char *service = NULL;
    char *method = NULL;
    size_t service_len = 0;
    size_t method_len = 0;
    zend_string *response_payload;
    king_mcp_state *state;
    zend_string *payload;
    zval *cancel = NULL;
    zval *options = NULL;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(3, 5)
        Z_PARAM_STRING(service, service_len)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STR(payload)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_object_fetch_state(ZEND_THIS, "MCP::request");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_state_require_open(state, "MCP::request") != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse(
            options,
            cancel,
            "MCP::request",
            king_ce_validation_exception,
            &control
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_check(
            &control,
            "MCP::request",
            king_ce_mcp_timeout,
            king_ce_runtime_exception
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_mcp_request(
            state,
            service,
            service_len,
            method,
            method_len,
            payload,
            &response_payload,
            &control
        ) != SUCCESS) {
        if (king_mcp_runtime_control_check(
                &control,
                "MCP::request",
                king_ce_mcp_timeout,
                king_ce_runtime_exception
            ) != SUCCESS) {
            RETURN_THROWS();
        }
        king_mcp_throw_state_error(
            state,
            king_ce_mcp_connection_error,
            "MCP::request() failed to exchange the remote MCP request."
        );
        RETURN_THROWS();
    }

    if (response_payload == NULL) {
        state->last_error_kind = KING_MCP_ERROR_PROTOCOL;
        king_set_error("MCP::request() received an invalid empty response payload from the remote MCP peer.");
        king_mcp_throw_state_error(
            state,
            king_ce_mcp_protocol_error,
            "MCP::request() received an invalid empty response payload from the remote MCP peer."
        );
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_check(
            &control,
            "MCP::request",
            king_ce_mcp_timeout,
            king_ce_runtime_exception
        ) != SUCCESS) {
        zend_string_release(response_payload);
        RETURN_THROWS();
    }

    king_set_error("");
    RETURN_STR(response_payload);
}

PHP_METHOD(King_MCP, close)
{
    king_mcp_state *state;

    ZEND_PARSE_PARAMETERS_NONE();

    state = king_mcp_object_fetch_state(ZEND_THIS, "MCP::close");
    if (state == NULL) {
        RETURN_THROWS();
    }

    king_mcp_state_close(state);
    king_set_error("");
}

PHP_METHOD(King_MCP, uploadFromStream)
{
    char *service = NULL;
    char *method = NULL;
    char *stream_identifier = NULL;
    size_t service_len = 0;
    size_t method_len = 0;
    size_t stream_identifier_len = 0;
    zval *stream_zv;
    zval *options = NULL;
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_STRING(service, service_len)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STRING(stream_identifier, stream_identifier_len)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_object_fetch_state(ZEND_THIS, "MCP::uploadFromStream");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_state_require_open(state, "MCP::uploadFromStream") != SUCCESS) {
        RETURN_THROWS();
    }

    if (
        king_mcp_validate_transfer_args(
            service,
            service_len,
            method,
            method_len,
            "stream identifier",
            stream_identifier,
            stream_identifier_len,
            "MCP::uploadFromStream",
            king_ce_validation_exception
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }

    stream = king_mcp_fetch_stream_resource(stream_zv, 4);
    if (stream == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse(
            options,
            NULL,
            "MCP::uploadFromStream",
            king_ce_validation_exception,
            &control
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    payload = king_mcp_read_stream_payload(
        stream,
        "MCP::uploadFromStream",
        king_ce_mcp_timeout,
        king_ce_mcp_data_error,
        &control
    );
    if (payload == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_check(
            &control,
            "MCP::uploadFromStream",
            king_ce_mcp_timeout,
            king_ce_mcp_data_error
        ) != SUCCESS) {
        zend_string_release(payload);
        RETURN_THROWS();
    }

    if (king_mcp_transfer_store(
        state,
        service,
        service_len,
        method,
        method_len,
        stream_identifier,
        stream_identifier_len,
        payload,
        &control
    ) != SUCCESS) {
        zend_string_release(payload);
        if (king_mcp_runtime_control_check(
                &control,
                "MCP::uploadFromStream",
                king_ce_mcp_timeout,
                king_ce_mcp_data_error
            ) != SUCCESS) {
            RETURN_THROWS();
        }
        if (king_get_error()[0] == '\0') {
            king_set_error("MCP::uploadFromStream() failed to persist the MCP transfer state.");
        }
        king_mcp_throw_state_error(
            state,
            king_ce_mcp_data_error,
            king_get_error()
        );
        RETURN_THROWS();
    }

    zend_string_release(payload);
    king_set_error("");
}

PHP_METHOD(King_MCP, downloadToStream)
{
    char *service = NULL;
    char *method = NULL;
    size_t service_len = 0;
    size_t method_len = 0;
    zend_string *request_payload;
    zval *stream_zv;
    zval *options = NULL;
    king_mcp_state *state;
    php_stream *stream;
    zend_string *payload;
    king_mcp_runtime_control_t control;

    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_STRING(service, service_len)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STR(request_payload)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    state = king_mcp_object_fetch_state(ZEND_THIS, "MCP::downloadToStream");
    if (state == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_state_require_open(state, "MCP::downloadToStream") != SUCCESS) {
        RETURN_THROWS();
    }

    if (
        king_mcp_validate_transfer_args(
            service,
            service_len,
            method,
            method_len,
            "request payload",
            ZSTR_VAL(request_payload),
            ZSTR_LEN(request_payload),
            "MCP::downloadToStream",
            king_ce_validation_exception
        ) != SUCCESS
    ) {
        RETURN_THROWS();
    }

    stream = king_mcp_fetch_stream_resource(stream_zv, 4);
    if (stream == NULL) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_parse(
            options,
            NULL,
            "MCP::downloadToStream",
            king_ce_validation_exception,
            &control
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_mcp_runtime_control_check(
            &control,
            "MCP::downloadToStream",
            king_ce_mcp_timeout,
            king_ce_mcp_data_error
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    payload = king_mcp_transfer_find(
        state,
        service,
        service_len,
        method,
        method_len,
        ZSTR_VAL(request_payload),
        ZSTR_LEN(request_payload),
        &control
    );
    if (payload == NULL) {
        if (king_mcp_runtime_control_check(
                &control,
                "MCP::downloadToStream",
                king_ce_mcp_timeout,
                king_ce_mcp_data_error
            ) != SUCCESS) {
            RETURN_THROWS();
        }
        if (king_get_error()[0] == '\0') {
            king_set_error(
                "MCP::downloadToStream() could not find an MCP transfer for the requested payload identifier."
            );
        }
        king_mcp_throw_state_error(
            state,
            king_ce_mcp_data_error,
            king_get_error()
        );
        RETURN_THROWS();
    }

    if (
        king_mcp_write_payload_to_stream(
            stream,
            payload,
            "MCP::downloadToStream",
            king_ce_mcp_timeout,
            king_ce_mcp_data_error,
            &control
        ) != SUCCESS
    ) {
        zend_string_release(payload);
        RETURN_THROWS();
    }

    if (king_mcp_transfer_acknowledge(
            state,
            service,
            service_len,
            method,
            method_len,
            ZSTR_VAL(request_payload),
            ZSTR_LEN(request_payload)
        ) != SUCCESS) {
        zend_string_release(payload);
        king_mcp_throw_state_error(
            state,
            king_ce_mcp_data_error,
            king_get_error()[0] != '\0'
                ? king_get_error()
                : "MCP::downloadToStream() failed to finalize the local MCP transfer state."
        );
        RETURN_THROWS();
    }

    zend_string_release(payload);
    king_set_error("");
}

const zend_function_entry king_mcp_class_methods[] = {
    PHP_ME(King_MCP, __construct, arginfo_class_King_MCP___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_MCP, request, arginfo_class_King_MCP_request, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, uploadFromStream, arginfo_class_King_MCP_uploadFromStream, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, downloadToStream, arginfo_class_King_MCP_downloadToStream, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, close, arginfo_class_King_MCP_close, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
