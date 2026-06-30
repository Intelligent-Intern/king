static zend_result king_inference_openai_json_encode(zval *value, zend_string **json_out)
{
    smart_str json = {0};

    if (json_out == NULL) {
        return FAILURE;
    }

    *json_out = NULL;
    if (php_json_encode(&json, value, PHP_JSON_UNESCAPED_SLASHES) != SUCCESS) {
        smart_str_free(&json);
        king_set_error("OpenAI-compatible inference response encoding failed.");
        return FAILURE;
    }

    smart_str_0(&json);
    *json_out = json.s != NULL ? json.s : zend_string_init("", 0, 0);
    return SUCCESS;
}

static zend_result king_inference_openai_json_decode(zend_string *json, zval *decoded_out)
{
    if (json == NULL || decoded_out == NULL) {
        return FAILURE;
    }

    ZVAL_UNDEF(decoded_out);
    if (php_json_decode(decoded_out, ZSTR_VAL(json), ZSTR_LEN(json), 1, 512) != SUCCESS) {
        king_set_error("OpenAI-compatible inference request body is not valid JSON.");
        return FAILURE;
    }

    return SUCCESS;
}

static bool king_inference_openai_method_is_post(zval *request)
{
    zval *method = king_inference_array_find(request, "method");

    return method != NULL && Z_TYPE_P(method) == IS_STRING && strcasecmp(Z_STRVAL_P(method), "POST") == 0;
}

static bool king_inference_openai_uri_matches(zval *request)
{
    zval *uri = king_inference_array_find(request, "uri");

    if (uri == NULL) {
        uri = king_inference_array_find(request, "path");
    }
    if (uri == NULL || Z_TYPE_P(uri) != IS_STRING || Z_STRLEN_P(uri) == 0) {
        return true;
    }

    return zend_string_equals_literal(Z_STR_P(uri), "/v1/chat/completions");
}

static void king_inference_openai_error_payload(
    zval *return_value,
    zend_long status,
    const char *message,
    const char *type,
    const char *explicit_code)
{
    zval error;
    zval king_error;
    const char *code = explicit_code != NULL && explicit_code[0] != '\0'
        ? explicit_code
        : king_inference_error_code_from_message(status, type, message);
    const char *category = king_inference_error_category_from_code(code);

    array_init(return_value);
    array_init(&error);
    add_assoc_string(&error, "message", message);
    add_assoc_string(&error, "type", type);
    add_assoc_null(&error, "param");
    add_assoc_string(&error, "code", code);
    array_init(&king_error);
    add_assoc_long(&king_error, "schema_version", 1);
    add_assoc_string(&king_error, "category", category);
    add_assoc_string(&king_error, "code", code);
    add_assoc_string(&king_error, "human_message", message);
    add_assoc_bool(&king_error, "prompt_included", false);
    add_assoc_bool(&king_error, "request_body_included", false);
    add_assoc_bool(&king_error, "safe_for_logs", true);
    add_assoc_zval(&error, "x_king", &king_error);
    add_assoc_zval(return_value, "error", &error);
}

static void king_inference_openai_http_array_response(
    zval *return_value,
    zend_long status,
    const char *content_type,
    zend_string *body,
    bool event_stream)
{
    zval headers;

    array_init(return_value);
    add_assoc_long(return_value, "status", status);
    array_init(&headers);
    add_assoc_string(&headers, "content-type", content_type);
    if (event_stream) {
        add_assoc_string(&headers, "cache-control", "no-cache");
        add_assoc_string(&headers, "x-accel-buffering", "no");
    }
    add_assoc_zval(return_value, "headers", &headers);
    add_assoc_str(return_value, "body", body != NULL ? zend_string_copy(body) : zend_string_init("", 0, 0));
}

static zend_result king_inference_openai_error_response_classified(
    zval *return_value,
    zend_long status,
    const char *message,
    const char *type,
    const char *code);

static zend_result king_inference_openai_error_response_with_king_classified(
    zval *return_value,
    zend_long status,
    const char *message,
    const char *type,
    const char *code,
    zval *king_context);

static zend_result king_inference_openai_error_response(
    zval *return_value,
    zend_long status,
    const char *message,
    const char *type)
{
    return king_inference_openai_error_response_classified(
        return_value,
        status,
        message,
        type,
        NULL
    );
}

static zend_result king_inference_openai_error_response_classified(
    zval *return_value,
    zend_long status,
    const char *message,
    const char *type,
    const char *code)
{
    zval payload;
    zend_string *json = NULL;
    zend_result result;

    king_inference_openai_error_payload(&payload, status, message, type, code);
    result = king_inference_openai_json_encode(&payload, &json);
    zval_ptr_dtor(&payload);
    if (result != SUCCESS) {
        return FAILURE;
    }

    king_inference_openai_http_array_response(return_value, status, "application/json", json, false);
    zend_string_release(json);
    return SUCCESS;
}

static zend_result king_inference_openai_error_response_with_king(
    zval *return_value,
    zend_long status,
    const char *message,
    const char *type,
    zval *king_context)
{
    return king_inference_openai_error_response_with_king_classified(
        return_value,
        status,
        message,
        type,
        NULL,
        king_context
    );
}

static zend_result king_inference_openai_error_response_with_king_classified(
    zval *return_value,
    zend_long status,
    const char *message,
    const char *type,
    const char *code,
    zval *king_context)
{
    zval payload;
    zval context_copy;
    zend_string *json = NULL;
    zend_result result;

    king_inference_openai_error_payload(&payload, status, message, type, code);
    if (king_context != NULL && Z_TYPE_P(king_context) == IS_ARRAY) {
        ZVAL_COPY(&context_copy, king_context);
        add_assoc_zval(&payload, "x_king", &context_copy);
    }
    result = king_inference_openai_json_encode(&payload, &json);
    zval_ptr_dtor(&payload);
    if (result != SUCCESS) {
        return FAILURE;
    }

    king_inference_openai_http_array_response(return_value, status, "application/json", json, false);
    zend_string_release(json);
    return SUCCESS;
}
