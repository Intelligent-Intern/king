static zend_string *king_mcp_public_first_header_value(zval *headers, const char *name)
{
    zval *value;
    zend_string *key;

    if (headers == NULL || Z_TYPE_P(headers) != IS_ARRAY) {
        return NULL;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(headers), key, value) {
        if (key == NULL || strcasecmp(ZSTR_VAL(key), name) != 0) {
            continue;
        }

        if (Z_TYPE_P(value) == IS_STRING) {
            return Z_STR_P(value);
        }
        if (Z_TYPE_P(value) == IS_ARRAY) {
            zval *first = zend_hash_index_find(Z_ARRVAL_P(value), 0);
            if (first != NULL && Z_TYPE_P(first) == IS_STRING) {
                return Z_STR_P(first);
            }
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static bool king_mcp_public_header_contains(zval *request, const char *name, const char *needle)
{
    zval *headers = zend_hash_str_find(Z_ARRVAL_P(request), "headers", sizeof("headers") - 1);
    zend_string *value = king_mcp_public_first_header_value(headers, name);

    if (value == NULL) {
        return false;
    }

    return php_memnstr(ZSTR_VAL(value), needle, strlen(needle), ZSTR_VAL(value) + ZSTR_LEN(value)) != NULL;
}

static bool king_mcp_public_http_prefers_sse(zval *server, zval *request)
{
    zval *http = king_mcp_public_server_get(server, "streamable_http");
    zval *prefer_sse;

    if (!king_mcp_public_header_contains(request, "accept", "text/event-stream")) {
        return false;
    }

    if (http == NULL || Z_TYPE_P(http) != IS_ARRAY) {
        return false;
    }

    prefer_sse = zend_hash_str_find(Z_ARRVAL_P(http), "prefer_sse", sizeof("prefer_sse") - 1);
    return prefer_sse != NULL && zend_is_true(prefer_sse);
}

static bool king_mcp_public_http_origin_allowed(zval *server, zval *request)
{
    zval *headers = zend_hash_str_find(Z_ARRVAL_P(request), "headers", sizeof("headers") - 1);
    zend_string *origin = king_mcp_public_first_header_value(headers, "origin");
    zval *http;
    zval *allowed_origins;
    zval *allowed;

    if (origin == NULL || ZSTR_LEN(origin) == 0) {
        return true;
    }

    http = king_mcp_public_server_get(server, "streamable_http");
    if (http == NULL || Z_TYPE_P(http) != IS_ARRAY) {
        return false;
    }

    allowed_origins = zend_hash_str_find(
        Z_ARRVAL_P(http),
        "allowed_origins",
        sizeof("allowed_origins") - 1
    );
    if (allowed_origins == NULL || Z_TYPE_P(allowed_origins) != IS_ARRAY) {
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(allowed_origins), allowed) {
        if (Z_TYPE_P(allowed) != IS_STRING) {
            continue;
        }
        if (
            zend_string_equals_literal(Z_STR_P(allowed), "*")
            || zend_string_equals(Z_STR_P(allowed), origin)
        ) {
            return true;
        }
    } ZEND_HASH_FOREACH_END();

    return false;
}

static void king_mcp_public_http_response(
    zval *return_value,
    zend_long status,
    const char *content_type,
    zend_string *body)
{
    zval headers;

    array_init(return_value);
    add_assoc_long(return_value, "status", status);
    array_init(&headers);
    if (content_type != NULL) {
        add_assoc_string(&headers, "content-type", content_type);
    }
    add_assoc_zval(return_value, "headers", &headers);
    if (body != NULL) {
        add_assoc_str(return_value, "body", zend_string_copy(body));
    } else {
        add_assoc_string(return_value, "body", "");
    }
}

static zend_string *king_mcp_public_sse_body(zend_string *json)
{
    smart_str body = {0};

    smart_str_appends(&body, "event: message\n");
    smart_str_appends(&body, "data: ");
    smart_str_append(&body, json);
    smart_str_appends(&body, "\n\n");
    smart_str_0(&body);

    return body.s != NULL ? body.s : zend_string_init("", 0, 0);
}
