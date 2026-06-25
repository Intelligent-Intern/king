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

static bool king_inference_openai_array_is_list(zval *value)
{
    return value != NULL && Z_TYPE_P(value) == IS_ARRAY && zend_array_is_list(Z_ARRVAL_P(value));
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
    const char *message,
    const char *type)
{
    zval error;

    array_init(return_value);
    array_init(&error);
    add_assoc_string(&error, "message", message);
    add_assoc_string(&error, "type", type);
    add_assoc_null(&error, "param");
    add_assoc_null(&error, "code");
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

static zend_result king_inference_openai_error_response(
    zval *return_value,
    zend_long status,
    const char *message,
    const char *type)
{
    zval payload;
    zend_string *json = NULL;
    zend_result result;

    king_inference_openai_error_payload(&payload, message, type);
    result = king_inference_openai_json_encode(&payload, &json);
    zval_ptr_dtor(&payload);
    if (result != SUCCESS) {
        return FAILURE;
    }

    king_inference_openai_http_array_response(return_value, status, "application/json", json, false);
    zend_string_release(json);
    return SUCCESS;
}

static zend_result king_inference_openai_validate_payload(zval *payload, zval *return_value, bool *has_error_response)
{
    zval *messages;
    zval *message;

    if (has_error_response != NULL) {
        *has_error_response = false;
    }

    if (payload == NULL || Z_TYPE_P(payload) != IS_ARRAY) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            "Chat completions request body must be a JSON object.",
            "invalid_request_error"
        );
    }

    messages = king_inference_array_find(payload, "messages");
    if (!king_inference_openai_array_is_list(messages) || zend_hash_num_elements(Z_ARRVAL_P(messages)) == 0) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            "Chat completions request requires a non-empty messages array.",
            "invalid_request_error"
        );
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(messages), message) {
        zval *role;
        zval *content;

        if (Z_TYPE_P(message) != IS_ARRAY) {
            if (has_error_response != NULL) {
                *has_error_response = true;
            }
            return king_inference_openai_error_response(
                return_value,
                400,
                "Each chat message must be an object.",
                "invalid_request_error"
            );
        }

        role = king_inference_array_find(message, "role");
        content = king_inference_array_find(message, "content");
        if (role == NULL || Z_TYPE_P(role) != IS_STRING || Z_STRLEN_P(role) == 0) {
            if (has_error_response != NULL) {
                *has_error_response = true;
            }
            return king_inference_openai_error_response(
                return_value,
                400,
                "Each chat message requires a non-empty role string.",
                "invalid_request_error"
            );
        }
        if (content == NULL || Z_TYPE_P(content) != IS_STRING) {
            if (has_error_response != NULL) {
                *has_error_response = true;
            }
            return king_inference_openai_error_response(
                return_value,
                400,
                "Each chat message requires string content.",
                "invalid_request_error"
            );
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_long king_inference_openai_option_long(zval *options, const char *key, zend_long fallback)
{
    zend_long value = king_inference_long_from_array(options, key, fallback);

    return value > 0 ? value : fallback;
}

static zend_result king_inference_openai_create_stream(
    zval *stream_value,
    zval *model,
    zval *payload,
    zval *options,
    const char *function_name)
{
    king_inference_stream_object *stream;

    object_init_ex(stream_value, king_ce_inference_stream);
    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(stream_value));
    ZVAL_COPY(&stream->model, model);
    ZVAL_DUP(&stream->request, payload);
    SEPARATE_ARRAY(&stream->request);
    if (options != NULL && Z_TYPE_P(options) == IS_ARRAY) {
        ZVAL_DUP(&stream->options, options);
        SEPARATE_ARRAY(&stream->options);
    } else {
        array_init(&stream->options);
    }

    add_assoc_bool(&stream->request, "openai_compatible", true);
    add_assoc_string(&stream->request, "format", "openai_chat_completions");
    add_assoc_bool(&stream->options, "openai_compatible", true);
    add_assoc_string(&stream->options, "format", "openai_chat_completions");
    stream->openai_compatible = true;
    stream->created_at = (zend_long) time(NULL);
    king_inference_stream_prepare_openai(stream);

    if (king_inference_stream_start(stream, function_name) != SUCCESS) {
        zval_ptr_dtor(stream_value);
        ZVAL_UNDEF(stream_value);
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_openai_sse_append(smart_str *body, zval *chunk)
{
    zend_string *json = NULL;

    if (king_inference_openai_json_encode(chunk, &json) != SUCCESS) {
        return FAILURE;
    }

    smart_str_appends(body, "data: ");
    smart_str_append(body, json);
    smart_str_appends(body, "\n\n");
    zend_string_release(json);
    return SUCCESS;
}

static bool king_inference_openai_chunk_is_terminal(zval *chunk, const char **finish_reason_out)
{
    zval *choices = king_inference_array_find(chunk, "choices");
    zval *choice;
    zval *finish_reason;

    if (finish_reason_out != NULL) {
        *finish_reason_out = NULL;
    }
    if (!king_inference_openai_array_is_list(choices)) {
        return false;
    }

    choice = zend_hash_index_find(Z_ARRVAL_P(choices), 0);
    if (choice == NULL || Z_TYPE_P(choice) != IS_ARRAY) {
        return false;
    }

    finish_reason = king_inference_array_find(choice, "finish_reason");
    if (finish_reason == NULL || Z_TYPE_P(finish_reason) != IS_STRING || Z_STRLEN_P(finish_reason) == 0) {
        return false;
    }

    if (finish_reason_out != NULL) {
        *finish_reason_out = Z_STRVAL_P(finish_reason);
    }
    return true;
}

static void king_inference_openai_append_delta_content(zval *chunk, smart_str *content)
{
    zval *choices = king_inference_array_find(chunk, "choices");
    zval *choice;
    zval *delta;
    zval *text;

    if (!king_inference_openai_array_is_list(choices)) {
        return;
    }

    choice = zend_hash_index_find(Z_ARRVAL_P(choices), 0);
    if (choice == NULL || Z_TYPE_P(choice) != IS_ARRAY) {
        return;
    }

    delta = king_inference_array_find(choice, "delta");
    if (delta == NULL || Z_TYPE_P(delta) != IS_ARRAY) {
        return;
    }

    text = king_inference_array_find(delta, "content");
    if (text != NULL && Z_TYPE_P(text) == IS_STRING) {
        smart_str_append(content, Z_STR_P(text));
    }
}

static zend_result king_inference_openai_drain_stream(
    king_inference_stream_object *stream,
    zval *stream_value,
    zval *options,
    smart_str *content,
    smart_str *sse_body,
    const char **finish_reason_out)
{
    zend_long timeout_ms = king_inference_openai_option_long(options, "read_timeout_ms", 250);
    zend_long max_events = king_inference_openai_option_long(options, "max_events", 4096);
    zend_long max_idle_events = king_inference_openai_option_long(options, "max_idle_events", 240);
    zend_long events = 0;
    zend_long idle_events = 0;

    (void) stream_value;

    if (finish_reason_out != NULL) {
        *finish_reason_out = "stop";
    }

    while (events < max_events && idle_events < max_idle_events) {
        zval event;

        ZVAL_UNDEF(&event);
        if (king_inference_stream_next_event(stream, timeout_ms, &event) != SUCCESS) {
            if (!Z_ISUNDEF(event)) {
                zval_ptr_dtor(&event);
            }
            return FAILURE;
        }

        if (Z_TYPE(event) == IS_NULL) {
            zval_ptr_dtor(&event);
            if (stream->done) {
                return SUCCESS;
            }
            idle_events++;
            continue;
        }

        idle_events = 0;
        events++;
        if (sse_body != NULL) {
            if (king_inference_openai_sse_append(sse_body, &event) != SUCCESS) {
                zval_ptr_dtor(&event);
                return FAILURE;
            }
        }
        if (content != NULL) {
            king_inference_openai_append_delta_content(&event, content);
        }
        if (king_inference_openai_chunk_is_terminal(&event, finish_reason_out)) {
            zval_ptr_dtor(&event);
            return SUCCESS;
        }
        zval_ptr_dtor(&event);
    }

    stream->cancelled = true;
    stream->done = true;
    king_inference_stream_close_process(stream, true);
    return FAILURE;
}

static zend_result king_inference_openai_completion_payload(
    king_inference_stream_object *stream,
    smart_str *content,
    const char *finish_reason,
    zval *return_value)
{
    zend_string *model_name = king_inference_stream_openai_model_name(stream);
    zval choices;
    zval choice;
    zval message;

    array_init(return_value);
    add_assoc_str(return_value, "id", zend_string_copy(stream->response_id));
    add_assoc_string(return_value, "object", "chat.completion");
    add_assoc_long(return_value, "created", stream->created_at);
    add_assoc_str(return_value, "model", model_name);

    array_init(&choices);
    array_init(&choice);
    add_assoc_long(&choice, "index", 0);
    array_init(&message);
    add_assoc_string(&message, "role", "assistant");
    if (content != NULL && content->s != NULL) {
        add_assoc_str(&message, "content", zend_string_copy(content->s));
    } else {
        add_assoc_string(&message, "content", "");
    }
    add_assoc_zval(&choice, "message", &message);
    add_assoc_string(&choice, "finish_reason", finish_reason != NULL ? finish_reason : "stop");
    add_next_index_zval(&choices, &choice);
    add_assoc_zval(return_value, "choices", &choices);
    add_assoc_null(return_value, "usage");
    return SUCCESS;
}

static zend_result king_inference_openai_payload_response(
    zval *return_value,
    zval *model,
    zval *payload,
    zval *options)
{
    zval stream_value;
    king_inference_stream_object *stream;
    zval response_payload;
    zend_string *json = NULL;
    zend_result result;
    bool stream_requested = king_inference_bool_from_array(payload, "stream", false);
    bool has_error_response = false;
    const char *finish_reason = "stop";

    if (king_inference_openai_validate_payload(payload, return_value, &has_error_response) != SUCCESS) {
        return FAILURE;
    }
    if (has_error_response) {
        return SUCCESS;
    }

    if (king_inference_openai_create_stream(
        &stream_value,
        model,
        payload,
        options,
        "king_inference_openai_chat_http_response"
    ) != SUCCESS) {
        return FAILURE;
    }
    stream = php_king_inference_stream_obj_from_zend(Z_OBJ(stream_value));

    if (stream_requested) {
        smart_str sse = {0};
        result = king_inference_openai_drain_stream(stream, &stream_value, options, NULL, &sse, &finish_reason);
        smart_str_appends(&sse, "data: [DONE]\n\n");
        smart_str_0(&sse);
        zval_ptr_dtor(&stream_value);
        if (result != SUCCESS) {
            smart_str_free(&sse);
            if (EG(exception)) {
                return FAILURE;
            }
            return king_inference_openai_error_response(
                return_value,
                504,
                "Inference stream did not finish within the configured bounded drain window.",
                "server_error"
            );
        }
        king_inference_openai_http_array_response(return_value, 200, "text/event-stream", sse.s, true);
        smart_str_free(&sse);
        return SUCCESS;
    }

    {
        smart_str content = {0};
        result = king_inference_openai_drain_stream(stream, &stream_value, options, &content, NULL, &finish_reason);
        smart_str_0(&content);
        if (result == SUCCESS) {
            king_inference_openai_completion_payload(stream, &content, finish_reason, &response_payload);
            result = king_inference_openai_json_encode(&response_payload, &json);
            zval_ptr_dtor(&response_payload);
        }
        smart_str_free(&content);
        zval_ptr_dtor(&stream_value);
        if (result != SUCCESS) {
            if (EG(exception)) {
                return FAILURE;
            }
            return king_inference_openai_error_response(
                return_value,
                504,
                "Inference response did not finish within the configured bounded drain window.",
                "server_error"
            );
        }
    }

    king_inference_openai_http_array_response(return_value, 200, "application/json", json, false);
    zend_string_release(json);
    return SUCCESS;
}

PHP_FUNCTION(king_inference_openai_chat_http_response)
{
    zval *model;
    zval *request;
    zval *options = NULL;
    zval *body;
    zval decoded;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_ARRAY(request)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_inference_openai_method_is_post(request)) {
        if (king_inference_openai_error_response(
            return_value,
            405,
            "Chat completions endpoint requires POST.",
            "invalid_request_error"
        ) != SUCCESS) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "%s",
                king_get_error()[0] != '\0' ? king_get_error() : "OpenAI-compatible inference HTTP response failed."
            );
            RETURN_THROWS();
        }
        return;
    }
    if (!king_inference_openai_uri_matches(request)) {
        if (king_inference_openai_error_response(
            return_value,
            404,
            "OpenAI-compatible inference route not found.",
            "invalid_request_error"
        ) != SUCCESS) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "%s",
                king_get_error()[0] != '\0' ? king_get_error() : "OpenAI-compatible inference HTTP response failed."
            );
            RETURN_THROWS();
        }
        return;
    }

    body = king_inference_array_find(request, "body");
    if (body == NULL || Z_TYPE_P(body) != IS_STRING) {
        if (king_inference_openai_error_response(
            return_value,
            400,
            "Chat completions HTTP request requires a JSON string body.",
            "invalid_request_error"
        ) != SUCCESS) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "%s",
                king_get_error()[0] != '\0' ? king_get_error() : "OpenAI-compatible inference HTTP response failed."
            );
            RETURN_THROWS();
        }
        return;
    }

    if (king_inference_openai_json_decode(Z_STR_P(body), &decoded) != SUCCESS) {
        if (king_inference_openai_error_response(
            return_value,
            400,
            king_get_error(),
            "invalid_request_error"
        ) != SUCCESS) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "%s",
                king_get_error()[0] != '\0' ? king_get_error() : "OpenAI-compatible inference HTTP response failed."
            );
            RETURN_THROWS();
        }
        return;
    }

    if (king_inference_openai_payload_response(return_value, model, &decoded, options) != SUCCESS) {
        zval_ptr_dtor(&decoded);
        if (EG(exception)) {
            RETURN_THROWS();
        }
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            king_get_error()[0] != '\0' ? king_get_error() : "OpenAI-compatible inference HTTP response failed."
        );
        RETURN_THROWS();
    }

    zval_ptr_dtor(&decoded);
}
