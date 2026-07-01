typedef struct _king_mcp_iibin_route {
    zend_string *request_schema;
    zend_string *response_schema;
    zval *decode_as_object;
} king_mcp_iibin_route_t;

static zend_string *king_mcp_iibin_route_key_create(
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    char separator)
{
    zend_string *key = zend_string_alloc(service_len + 1 + method_len, 0);

    memcpy(ZSTR_VAL(key), service, service_len);
    ZSTR_VAL(key)[service_len] = separator;
    memcpy(ZSTR_VAL(key) + service_len + 1, method, method_len);
    ZSTR_VAL(key)[service_len + 1 + method_len] = '\0';

    return key;
}

static zval *king_mcp_iibin_find_route_value(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len)
{
    zend_string *key;
    zval *route;
    zval *service_routes;

    if (
        state == NULL
        || Z_ISUNDEF(state->iibin_routes)
        || Z_TYPE(state->iibin_routes) != IS_ARRAY
    ) {
        return NULL;
    }

    key = king_mcp_iibin_route_key_create(service, service_len, method, method_len, '/');
    route = zend_hash_find(Z_ARRVAL(state->iibin_routes), key);
    zend_string_release(key);
    if (route != NULL) {
        return route;
    }

    key = king_mcp_iibin_route_key_create(service, service_len, method, method_len, '.');
    route = zend_hash_find(Z_ARRVAL(state->iibin_routes), key);
    zend_string_release(key);
    if (route != NULL) {
        return route;
    }

    service_routes = zend_hash_str_find(Z_ARRVAL(state->iibin_routes), service, service_len);
    if (service_routes != NULL && Z_TYPE_P(service_routes) == IS_ARRAY) {
        return zend_hash_str_find(Z_ARRVAL_P(service_routes), method, method_len);
    }

    return NULL;
}

static zend_result king_mcp_iibin_set_error(
    zend_class_entry *exception_ce,
    const char *message)
{
    king_set_error(message);

    if (exception_ce != NULL) {
        zend_throw_exception_ex(exception_ce, 0, "%s", message);
    }

    return FAILURE;
}

static zend_result king_mcp_iibin_route_resolve(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *function_name,
    zend_class_entry *exception_ce,
    king_mcp_iibin_route_t *route)
{
    zval *route_config;
    zval *request_schema;
    zval *response_schema;
    zval *decode_as_object;
    char message[KING_ERR_LEN];

    if (route == NULL) {
        return FAILURE;
    }

    route->request_schema = NULL;
    route->response_schema = NULL;
    route->decode_as_object = NULL;

    route_config = king_mcp_iibin_find_route_value(
        state,
        service,
        service_len,
        method,
        method_len
    );
    if (route_config == NULL || Z_TYPE_P(route_config) != IS_ARRAY) {
        snprintf(
            message,
            sizeof(message),
            "%s() has no configured mcp.iibin_routes entry for '%.*s/%.*s'.",
            function_name,
            (int) service_len,
            service,
            (int) method_len,
            method
        );
        return king_mcp_iibin_set_error(exception_ce, message);
    }

    request_schema = zend_hash_str_find(
        Z_ARRVAL_P(route_config),
        "request_schema",
        sizeof("request_schema") - 1
    );
    if (
        request_schema == NULL
        || Z_TYPE_P(request_schema) != IS_STRING
        || Z_STRLEN_P(request_schema) == 0
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() route '%.*s/%.*s' requires a non-empty request_schema.",
            function_name,
            (int) service_len,
            service,
            (int) method_len,
            method
        );
        return king_mcp_iibin_set_error(exception_ce, message);
    }

    response_schema = zend_hash_str_find(
        Z_ARRVAL_P(route_config),
        "response_schema",
        sizeof("response_schema") - 1
    );
    if (
        response_schema != NULL
        && Z_TYPE_P(response_schema) != IS_NULL
        && Z_TYPE_P(response_schema) != IS_STRING
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() route '%.*s/%.*s' response_schema must be null or string.",
            function_name,
            (int) service_len,
            service,
            (int) method_len,
            method
        );
        return king_mcp_iibin_set_error(exception_ce, message);
    }

    decode_as_object = zend_hash_str_find(
        Z_ARRVAL_P(route_config),
        "decode_as_object",
        sizeof("decode_as_object") - 1
    );

    route->request_schema = Z_STR_P(request_schema);
    route->response_schema = (
        response_schema != NULL
        && Z_TYPE_P(response_schema) == IS_STRING
        && Z_STRLEN_P(response_schema) > 0
    ) ? Z_STR_P(response_schema) : NULL;
    route->decode_as_object = decode_as_object;

    return SUCCESS;
}

static zend_result king_mcp_iibin_encode_request_payload(
    zend_string *request_schema,
    zval *request_data,
    zend_string **payload_out)
{
    smart_str encoded = {0};

    if (payload_out == NULL) {
        return FAILURE;
    }

    *payload_out = NULL;

    if (king_iibin_encode(request_schema, request_data, &encoded) != SUCCESS) {
        smart_str_free(&encoded);
        return FAILURE;
    }

    smart_str_0(&encoded);
    if (encoded.s == NULL) {
        *payload_out = zend_string_init("", 0, 0);
    } else {
        *payload_out = encoded.s;
    }

    return SUCCESS;
}

static zend_result king_mcp_iibin_decode_response_payload(
    zend_string *response_schema,
    zend_string *response_payload,
    zval *decode_as_object,
    zval *decoded_out)
{
    if (decoded_out == NULL || response_schema == NULL || response_payload == NULL) {
        return FAILURE;
    }

    ZVAL_UNDEF(decoded_out);
    return king_iibin_decode(
        response_schema,
        response_payload,
        decode_as_object,
        decoded_out
    );
}
