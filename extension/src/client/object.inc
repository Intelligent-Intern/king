ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Response_getStatusCode_ret, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Response_getHeaders, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Response_getBody, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Response_read, 0, 0, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, length, IS_LONG, 0, "8192")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Response_isEndOfBody, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Client_HttpClient___construct, 0, 0, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, config, King\\Config, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Client_HttpClient_request, 0, 2, King\\Response, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 0, "[]")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, body, IS_STRING, 0, "\"\"")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Client_HttpClient_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static zval *king_response_object_read_member(
    king_response_object *intern,
    const char *name,
    size_t name_len)
{
    if (Z_ISUNDEF(intern->payload) || Z_TYPE(intern->payload) != IS_ARRAY) {
        return NULL;
    }

    return zend_hash_str_find(Z_ARRVAL(intern->payload), name, name_len);
}

static zend_result king_response_object_require_payload(king_response_object *intern)
{
    if (!Z_ISUNDEF(intern->payload) && Z_TYPE(intern->payload) == IS_ARRAY) {
        return SUCCESS;
    }

    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "King\\Response object is not initialized."
    );
    return FAILURE;
}

zend_result king_response_object_init_from_array(zval *target, zval *payload)
{
    king_response_object *intern;

    if (Z_TYPE_P(payload) != IS_ARRAY) {
        return FAILURE;
    }

    object_init_ex(target, king_ce_response);
    intern = php_king_response_obj_from_zend(Z_OBJ_P(target));
    ZVAL_COPY(&intern->payload, payload);
    intern->read_offset = 0;

    return SUCCESS;
}

zend_result king_response_object_init_from_context(
    zval *target,
    zval *payload,
    zval *request_context)
{
    king_response_object *intern;

    if (Z_TYPE_P(payload) != IS_ARRAY || Z_TYPE_P(request_context) != IS_RESOURCE) {
        return FAILURE;
    }

    object_init_ex(target, king_ce_response);
    intern = php_king_response_obj_from_zend(Z_OBJ_P(target));
    ZVAL_COPY(&intern->payload, payload);
    ZVAL_COPY(&intern->request_context, request_context);
    intern->read_offset = 0;

    return SUCCESS;
}

static king_http1_request_context *king_response_object_fetch_request_context(
    king_response_object *intern,
    const char *function_name)
{
    king_http1_request_context *context;

    if (Z_ISUNDEF(intern->request_context) || Z_TYPE(intern->request_context) != IS_RESOURCE) {
        return NULL;
    }

    context = (king_http1_request_context *) zend_fetch_resource(
        Z_RES(intern->request_context),
        "King\\HttpRequestContext",
        le_king_request_context
    );

    if (context == NULL) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() lost its active HTTP/1 request context.",
            function_name
        );
    }

    return context;
}

static void king_http_client_set_preference_from_scope(
    king_http_client_object *intern,
    zend_class_entry *scope)
{
    intern->preferred_protocol = KING_CLIENT_PROTOCOL_AUTO;

    if (scope == king_ce_client_http1) {
        intern->preferred_protocol = KING_CLIENT_PROTOCOL_HTTP1;
    } else if (scope == king_ce_client_http2) {
        intern->preferred_protocol = KING_CLIENT_PROTOCOL_HTTP2;
    } else if (scope == king_ce_client_http3) {
        intern->preferred_protocol = KING_CLIENT_PROTOCOL_HTTP3;
    }
}

static zend_result king_http_client_request_to_response_object(
    zval *return_value,
    king_http_client_object *intern,
    const char *method_str,
    size_t method_len,
    const char *url_str,
    size_t url_len,
    zval *headers_array,
    zend_string *body,
    zval *cancel)
{
    zval options_array;
    zval response_payload;
    zval config_copy;
    zval body_zval;
    zval cancel_copy;
    zval *options_ptr = NULL;

    if (intern->closed) {
        king_set_error("HttpClient::request() cannot run on a closed client.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "HttpClient::request() cannot run on a closed client."
        );
        return FAILURE;
    }

    if (cancel != NULL && Z_TYPE_P(cancel) != IS_NULL) {
        king_cancel_token_object *cancel_intern = php_king_cancel_token_obj_from_zend(Z_OBJ_P(cancel));

        if (cancel_intern->cancelled) {
            king_set_error("HttpClient::request() received a CancelToken that is already cancelled.");
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "HttpClient::request() received a CancelToken that is already cancelled."
            );
            return FAILURE;
        }

    }

    ZVAL_UNDEF(&response_payload);
    ZVAL_STR_COPY(&body_zval, body);
    array_init(&options_array);

    if (!Z_ISUNDEF(intern->config) && Z_TYPE(intern->config) != IS_NULL) {
        ZVAL_COPY(&config_copy, &intern->config);
        add_assoc_zval(
            &options_array,
            "connection_config",
            &config_copy
        );
    }

    if (intern->preferred_protocol != KING_CLIENT_PROTOCOL_AUTO) {
        add_assoc_string(
            &options_array,
            "preferred_protocol",
            (char *) king_client_protocol_preference_name(intern->preferred_protocol)
        );
    }

    if (cancel != NULL && Z_TYPE_P(cancel) == IS_OBJECT) {
        ZVAL_COPY(&cancel_copy, cancel);
        add_assoc_zval(
            &options_array,
            KING_INTERNAL_OPTION_CANCEL_TOKEN,
            &cancel_copy
        );
        add_assoc_string(
            &options_array,
            KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME,
            "HttpClient::request"
        );
    }

    if (zend_hash_num_elements(Z_ARRVAL(options_array)) > 0) {
        options_ptr = &options_array;
    }

    if (king_client_send_request_dispatch(
            &response_payload,
            url_str,
            url_len,
            method_str,
            method_len,
            headers_array,
            &body_zval,
            options_ptr,
            "King\\Client\\HttpClient::request"
        ) != SUCCESS) {
        zval_ptr_dtor(&body_zval);
        zval_ptr_dtor(&options_array);

        if (EG(exception) != NULL) {
            return FAILURE;
        }

        zend_throw_exception_ex(
            king_ce_exception,
            0,
            "%s",
            king_get_error()[0] != '\0'
                ? king_get_error()
                : "King\\Client\\HttpClient::request() failed."
        );
        return FAILURE;
    }

    zval_ptr_dtor(&body_zval);
    zval_ptr_dtor(&options_array);

    if (king_response_object_init_from_array(return_value, &response_payload) != SUCCESS) {
        zval_ptr_dtor(&response_payload);
        king_set_error("King\\Client\\HttpClient::request() failed to initialize the response object.");
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "King\\Client\\HttpClient::request() failed to initialize the response object."
        );
        return FAILURE;
    }

    zval_ptr_dtor(&response_payload);
    return SUCCESS;
}

PHP_METHOD(King_Response, getStatusCode)
{
    king_response_object *intern;
    zval *value;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_response_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (king_response_object_require_payload(intern) != SUCCESS) {
        RETURN_THROWS();
    }

    value = king_response_object_read_member(intern, "status", sizeof("status") - 1);
    if (value == NULL) {
        RETURN_LONG(0);
    }

    RETURN_LONG(zval_get_long(value));
}

PHP_METHOD(King_Response, getHeaders)
{
    king_response_object *intern;
    zval *value;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_response_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (king_response_object_require_payload(intern) != SUCCESS) {
        RETURN_THROWS();
    }

    value = king_response_object_read_member(intern, "headers", sizeof("headers") - 1);
    if (value == NULL || Z_TYPE_P(value) != IS_ARRAY) {
        array_init(return_value);
        return;
    }

    RETURN_COPY(value);
}

PHP_METHOD(King_Response, getBody)
{
    king_response_object *intern;
    king_http1_request_context *request_context;
    zval *value;
    zend_string *body;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_response_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (king_response_object_require_payload(intern) != SUCCESS) {
        RETURN_THROWS();
    }

    request_context = king_response_object_fetch_request_context(intern, "Response::getBody");
    if (request_context != NULL) {
        zval body_zval;

        if (king_http1_request_context_get_body(
                request_context,
                &body,
                "Response::getBody"
            ) != SUCCESS) {
            RETURN_THROWS();
        }

        ZVAL_STR_COPY(&body_zval, body);
        zend_hash_str_update(
            Z_ARRVAL(intern->payload),
            "body",
            sizeof("body") - 1,
            &body_zval
        );
        RETVAL_STR(body);
        return;
    }
    if (!Z_ISUNDEF(intern->request_context)) {
        RETURN_THROWS();
    }

    value = king_response_object_read_member(intern, "body", sizeof("body") - 1);
    if (value == NULL) {
        RETURN_EMPTY_STRING();
    }

    body = zval_get_string(value);
    RETVAL_STR(body);
}

PHP_METHOD(King_Response, read)
{
    king_response_object *intern;
    king_http1_request_context *request_context;
    zval *value;
    zend_long length = 8192;
    zend_string *body;
    size_t remaining;
    size_t chunk_len;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(length)
    ZEND_PARSE_PARAMETERS_END();

    if (length < 0) {
        zend_argument_value_error(1, "must be greater than or equal to 0");
        RETURN_THROWS();
    }

    intern = php_king_response_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (king_response_object_require_payload(intern) != SUCCESS) {
        RETURN_THROWS();
    }

    request_context = king_response_object_fetch_request_context(intern, "Response::read");
    if (request_context != NULL) {
        zend_string *chunk;

        if (king_http1_request_context_read(
                request_context,
                intern->read_offset,
                (size_t) length,
                &chunk,
                "Response::read"
            ) != SUCCESS) {
            RETURN_THROWS();
        }

        intern->read_offset += (zend_long) ZSTR_LEN(chunk);
        RETURN_STR(chunk);
    }
    if (!Z_ISUNDEF(intern->request_context)) {
        RETURN_THROWS();
    }

    value = king_response_object_read_member(intern, "body", sizeof("body") - 1);
    if (value == NULL) {
        RETURN_EMPTY_STRING();
    }

    body = zval_get_string(value);
    if ((size_t) intern->read_offset >= ZSTR_LEN(body)) {
        zend_string_release(body);
        RETURN_EMPTY_STRING();
    }

    remaining = ZSTR_LEN(body) - (size_t) intern->read_offset;
    chunk_len = (size_t) length < remaining ? (size_t) length : remaining;
    RETVAL_STRINGL(ZSTR_VAL(body) + intern->read_offset, chunk_len);
    intern->read_offset += (zend_long) chunk_len;
    zend_string_release(body);
}

PHP_METHOD(King_Response, isEndOfBody)
{
    king_response_object *intern;
    king_http1_request_context *request_context;
    zval *value;
    zend_string *body;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_response_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (king_response_object_require_payload(intern) != SUCCESS) {
        RETURN_THROWS();
    }

    request_context = king_response_object_fetch_request_context(intern, "Response::isEndOfBody");
    if (request_context != NULL) {
        RETURN_BOOL(king_http1_request_context_is_end_of_body(
            request_context,
            intern->read_offset
        ));
    }
    if (!Z_ISUNDEF(intern->request_context)) {
        RETURN_THROWS();
    }

    value = king_response_object_read_member(intern, "body", sizeof("body") - 1);
    if (value == NULL) {
        RETURN_TRUE;
    }

    body = zval_get_string(value);
    RETVAL_BOOL((size_t) intern->read_offset >= ZSTR_LEN(body));
    zend_string_release(body);
}

PHP_METHOD(King_Client_HttpClient, __construct)
{
    zval *config = NULL;
    king_http_client_object *intern;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(config, king_ce_config)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_http_client_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    king_http_client_set_preference_from_scope(intern, Z_OBJCE_P(ZEND_THIS));
    intern->closed = false;

    if (!Z_ISUNDEF(intern->config)) {
        zval_ptr_dtor(&intern->config);
        ZVAL_UNDEF(&intern->config);
    }

    if (config != NULL) {
        ZVAL_COPY(&intern->config, config);
    } else {
        ZVAL_NULL(&intern->config);
    }
}

PHP_METHOD(King_Client_HttpClient, request)
{
    zend_string *method;
    zend_string *url;
    zval *headers_array = NULL;
    zend_string *body = NULL;
    bool body_is_temp_empty = false;
    zval *cancel = NULL;
    king_http_client_object *intern;

    ZEND_PARSE_PARAMETERS_START(2, 5)
        Z_PARAM_STR(method)
        Z_PARAM_STR(url)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY(headers_array)
        Z_PARAM_STR(body)
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_http_client_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (body == NULL) {
        body = zend_string_init("", 0, 0);
        body_is_temp_empty = true;
    }

    if (king_http_client_request_to_response_object(
            return_value,
            intern,
            ZSTR_VAL(method),
            ZSTR_LEN(method),
            ZSTR_VAL(url),
            ZSTR_LEN(url),
            headers_array,
            body,
            cancel
        ) != SUCCESS) {
        if (body_is_temp_empty) {
            zend_string_release(body);
        }
        RETURN_THROWS();
    }

    if (body_is_temp_empty) {
        zend_string_release(body);
    }
}

PHP_METHOD(King_Client_HttpClient, close)
{
    king_http_client_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_http_client_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    intern->closed = true;
    if (!Z_ISUNDEF(intern->config)) {
        zval_ptr_dtor(&intern->config);
        ZVAL_NULL(&intern->config);
    }
    king_set_error("");
}

const zend_function_entry king_response_class_methods[] = {
    PHP_ME(King_Response, getStatusCode, arginfo_class_King_Response_getStatusCode_ret, ZEND_ACC_PUBLIC)
    PHP_ME(King_Response, getHeaders, arginfo_class_King_Response_getHeaders, ZEND_ACC_PUBLIC)
    PHP_ME(King_Response, getBody, arginfo_class_King_Response_getBody, ZEND_ACC_PUBLIC)
    PHP_ME(King_Response, read, arginfo_class_King_Response_read, ZEND_ACC_PUBLIC)
    PHP_ME(King_Response, isEndOfBody, arginfo_class_King_Response_isEndOfBody, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

const zend_function_entry king_http_client_class_methods[] = {
    PHP_ME(King_Client_HttpClient, __construct, arginfo_class_King_Client_HttpClient___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_Client_HttpClient, request, arginfo_class_King_Client_HttpClient_request, ZEND_ACC_PUBLIC)
    PHP_ME(King_Client_HttpClient, close, arginfo_class_King_Client_HttpClient_close, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
