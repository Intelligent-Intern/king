static zend_result king_inference_openai_validate_payload(
    zval *payload,
    zval *return_value,
    bool *has_error_response,
    zend_long max_messages)
{
    zval *messages;
    zval *message;
    const char *stop_error = NULL;
    const char *stream_options_error = NULL;
    const char *stream_error = NULL;
    const char *model_error = NULL;
    const char *choice_count_error = NULL;
    const char *feature_error = NULL;

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
    if ((zend_ulong) zend_hash_num_elements(Z_ARRVAL_P(messages)) > (zend_ulong) max_messages) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            "Chat completions messages array exceeds configured router limits.",
            "invalid_request_error"
        );
    }
    if (!king_inference_openai_model_field_valid(payload, &model_error)) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            model_error != NULL ? model_error : "OpenAI model is invalid.",
            "invalid_request_error"
        );
    }
    if (!king_inference_openai_stop_option_valid(payload, &stop_error)) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            stop_error != NULL ? stop_error : "OpenAI stop is invalid.",
            "invalid_request_error"
        );
    }
    if (!king_inference_openai_stream_options_valid(payload, &stream_options_error)) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            stream_options_error != NULL ? stream_options_error : "OpenAI stream_options is invalid.",
            "invalid_request_error"
        );
    }
    if (!king_inference_openai_stream_field_valid(payload, &stream_error)) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            stream_error != NULL ? stream_error : "OpenAI stream is invalid.",
            "invalid_request_error"
        );
    }
    if (!king_inference_openai_single_choice_option_valid(payload, &choice_count_error)) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            choice_count_error != NULL ? choice_count_error : "OpenAI n is invalid.",
            "invalid_request_error"
        );
    }
    if (!king_inference_openai_chat_feature_options_valid(payload, &feature_error)) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            feature_error != NULL ? feature_error : "OpenAI requested feature is unsupported.",
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
        if (!king_inference_openai_message_tool_calls_valid(message, &feature_error)) {
            if (has_error_response != NULL) {
                *has_error_response = true;
            }
            return king_inference_openai_error_response(
                return_value,
                400,
                feature_error != NULL ? feature_error : "Assistant tool_calls are invalid.",
                "invalid_request_error"
            );
        }
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
        {
            smart_str text = {0};
            if (king_inference_openai_append_content_text(content, &text) != SUCCESS) {
                if (zend_string_equals_literal(Z_STR_P(role), "assistant")
                    && king_inference_openai_message_has_tool_calls(message)) {
                    smart_str_free(&text);
                    continue;
                }
                smart_str_free(&text);
                if (has_error_response != NULL) {
                    *has_error_response = true;
                }
                return king_inference_openai_error_response(
                    return_value,
                    400,
                    "Each chat message requires string content, text content parts, or assistant tool_calls.",
                    "invalid_request_error"
                );
            }
            smart_str_free(&text);
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

typedef struct _king_inference_openai_drain_controls {
    zend_long read_timeout_ms;
    zend_long max_events;
    zend_long max_idle_events;
} king_inference_openai_drain_controls;

static bool king_inference_openai_positive_router_limit(
    zval *options,
    const char *key,
    zend_long fallback,
    zend_long *limit_out,
    const char **message)
{
    zval *field = king_inference_array_find(options, key);

    if (message != NULL) {
        *message = NULL;
    }
    if (limit_out == NULL) {
        return false;
    }
    *limit_out = fallback;
    if (field == NULL) {
        return true;
    }
    if (Z_TYPE_P(field) != IS_LONG || Z_LVAL_P(field) <= 0) {
        if (message != NULL) {
            *message = "King OpenAI router limit and drain control options must be positive integers.";
        }
        return false;
    }

    *limit_out = Z_LVAL_P(field);
    return true;
}

static bool king_inference_openai_drain_controls_from_options(
    zval *options,
    king_inference_openai_drain_controls *controls,
    const char **message)
{
    if (message != NULL) {
        *message = NULL;
    }
    if (controls == NULL) {
        return false;
    }

    return king_inference_openai_positive_router_limit(
        options,
        "read_timeout_ms",
        250,
        &controls->read_timeout_ms,
        message
    ) && king_inference_openai_positive_router_limit(
        options,
        "max_events",
        4096,
        &controls->max_events,
        message
    ) && king_inference_openai_positive_router_limit(
        options,
        "max_idle_events",
        240,
        &controls->max_idle_events,
        message
    );
}

#include "openai_backend.inc"

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
    king_inference_openai_copy_chat_generation_options(&stream->request, &stream->request);
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

static zval *king_inference_openai_chunk_delta_content(zval *chunk)
{
    zval *choices = king_inference_array_find(chunk, "choices");
    zval *choice;
    zval *delta;

    if (!king_inference_openai_array_is_list(choices)) {
        return NULL;
    }

    choice = zend_hash_index_find(Z_ARRVAL_P(choices), 0);
    if (choice == NULL || Z_TYPE_P(choice) != IS_ARRAY) {
        return NULL;
    }

    delta = king_inference_array_find(choice, "delta");
    if (delta == NULL || Z_TYPE_P(delta) != IS_ARRAY) {
        return NULL;
    }

    return king_inference_array_find(delta, "content");
}

static zend_result king_inference_openai_drain_stream(
    king_inference_stream_object *stream,
    zval *stream_value,
    const king_inference_openai_drain_controls *controls,
    smart_str *content,
    smart_str *sse_body,
    const char **finish_reason_out)
{
    zend_long events = 0;
    zend_long idle_events = 0;

    (void) stream_value;

    if (finish_reason_out != NULL) {
        *finish_reason_out = "stop";
    }

    while (events < controls->max_events && idle_events < controls->max_idle_events) {
        zval event;

        ZVAL_UNDEF(&event);
        if (king_inference_stream_next_event(stream, controls->read_timeout_ms, &event) != SUCCESS) {
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
    zval tool_calls;
    bool has_tool_calls = false;

    ZVAL_UNDEF(&tool_calls);
    if (content != NULL && content->s != NULL) {
        has_tool_calls = king_inference_openai_tool_calls_from_content(content->s, &tool_calls);
    }

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
    if (has_tool_calls) {
        add_assoc_null(&message, "content");
        add_assoc_zval(&message, "tool_calls", &tool_calls);
    } else if (content != NULL && content->s != NULL) {
        add_assoc_str(&message, "content", zend_string_copy(content->s));
    } else {
        add_assoc_string(&message, "content", "");
    }
    add_assoc_zval(&choice, "message", &message);
    add_assoc_string(&choice, "finish_reason", has_tool_calls ? "tool_calls" : (finish_reason != NULL ? finish_reason : "stop"));
    add_next_index_zval(&choices, &choice);
    add_assoc_zval(return_value, "choices", &choices);
    king_inference_openai_add_completion_usage(
        return_value,
        &stream->model,
        &stream->request,
        content != NULL ? content->s : NULL
    );
    return SUCCESS;
}

static zend_result king_inference_openai_sse_append_content_chunk(
    king_inference_stream_object *stream,
    zend_string *content,
    smart_str *sse)
{
    zval chunk;
    zend_result result;

    if (content == NULL || ZSTR_LEN(content) == 0) {
        return SUCCESS;
    }

    king_inference_stream_openai_chunk(
        stream,
        NULL,
        ZSTR_VAL(content),
        ZSTR_LEN(content),
        NULL,
        &chunk
    );
    result = king_inference_openai_sse_append(sse, &chunk);
    zval_ptr_dtor(&chunk);
    return result;
}

static zend_result king_inference_openai_sse_append_finish_chunk(
    king_inference_stream_object *stream,
    const char *finish_reason,
    smart_str *sse)
{
    zval chunk;
    zend_result result;

    king_inference_stream_openai_chunk(
        stream,
        NULL,
        NULL,
        0,
        finish_reason != NULL ? finish_reason : "stop",
        &chunk
    );
    result = king_inference_openai_sse_append(sse, &chunk);
    zval_ptr_dtor(&chunk);
    return result;
}

static zend_result king_inference_openai_sse_append_tool_call_chunk(
    king_inference_stream_object *stream,
    zval *tool_calls,
    smart_str *sse)
{
    zval chunk;
    zval choices;
    zval choice;
    zval delta;
    zval stream_tool_calls;
    zval *call;
    zend_long index = 0;
    zend_result result;

    king_inference_stream_openai_chunk_base(stream, &chunk);
    array_init(&choices);
    array_init(&choice);
    add_assoc_long(&choice, "index", 0);
    array_init(&delta);
    add_assoc_string(&delta, "role", "assistant");
    array_init(&stream_tool_calls);
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tool_calls), call) {
        zval stream_call;
        zval *id;
        zval *type;
        zval *function;

        if (!king_inference_openai_array_is_object(call)) {
            continue;
        }
        id = king_inference_array_find(call, "id");
        type = king_inference_array_find(call, "type");
        function = king_inference_array_find(call, "function");

        array_init(&stream_call);
        add_assoc_long(&stream_call, "index", index++);
        if (id != NULL) {
            Z_TRY_ADDREF_P(id);
            add_assoc_zval(&stream_call, "id", id);
        }
        if (type != NULL) {
            Z_TRY_ADDREF_P(type);
            add_assoc_zval(&stream_call, "type", type);
        }
        if (function != NULL) {
            Z_TRY_ADDREF_P(function);
            add_assoc_zval(&stream_call, "function", function);
        }
        add_next_index_zval(&stream_tool_calls, &stream_call);
    } ZEND_HASH_FOREACH_END();
    add_assoc_zval(&delta, "tool_calls", &stream_tool_calls);
    add_assoc_zval(&choice, "delta", &delta);
    add_assoc_null(&choice, "finish_reason");
    add_next_index_zval(&choices, &choice);
    add_assoc_zval(&chunk, "choices", &choices);
    if (king_inference_openai_stream_usage_requested(&stream->request)) {
        add_assoc_null(&chunk, "usage");
    }

    result = king_inference_openai_sse_append(sse, &chunk);
    zval_ptr_dtor(&chunk);
    return result;
}

static zend_result king_inference_openai_sse_append_buffered_chat(
    king_inference_stream_object *stream,
    smart_str *content,
    const char *finish_reason,
    smart_str *sse)
{
    zval tool_calls;
    bool has_tool_calls = false;
    zend_result result;

    ZVAL_UNDEF(&tool_calls);
    if (content != NULL && content->s != NULL) {
        has_tool_calls = king_inference_openai_tool_calls_from_content(content->s, &tool_calls);
    }

    if (has_tool_calls) {
        result = king_inference_openai_sse_append_tool_call_chunk(stream, &tool_calls, sse);
        zval_ptr_dtor(&tool_calls);
        if (result != SUCCESS) {
            return FAILURE;
        }
        return king_inference_openai_sse_append_finish_chunk(stream, "tool_calls", sse);
    }

    if (content != NULL && content->s != NULL) {
        result = king_inference_openai_sse_append_content_chunk(stream, content->s, sse);
        if (result != SUCCESS) {
            return FAILURE;
        }
    }
    return king_inference_openai_sse_append_finish_chunk(stream, finish_reason, sse);
}

static zend_result king_inference_openai_append_stream_usage_chunk(
    king_inference_stream_object *stream,
    smart_str *content,
    smart_str *sse)
{
    zval chunk;
    zval choices;
    zend_result result;

    king_inference_stream_openai_chunk_base(stream, &chunk);
    array_init(&choices);
    add_assoc_zval(&chunk, "choices", &choices);
    king_inference_openai_add_completion_usage(
        &chunk,
        &stream->model,
        &stream->request,
        content != NULL ? content->s : NULL
    );
    result = king_inference_openai_sse_append(sse, &chunk);
    zval_ptr_dtor(&chunk);
    return result;
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
    zend_long max_chat_messages = 0;
    king_inference_openai_drain_controls drain_controls;
    bool stream_requested;
    bool has_error_response = false;
    const char *finish_reason = "stop";
    const char *generation_error = NULL;
    const char *limit_error = NULL;

    if (!king_inference_openai_positive_router_limit(
        options,
        "max_chat_messages",
        256,
        &max_chat_messages,
        &limit_error
    )) {
        return king_inference_openai_error_response(
            return_value,
            500,
            limit_error != NULL ? limit_error : "King OpenAI router limit is invalid.",
            "server_error"
        );
    }
    if (!king_inference_openai_drain_controls_from_options(options, &drain_controls, &limit_error)) {
        return king_inference_openai_error_response(
            return_value,
            500,
            limit_error != NULL ? limit_error : "King OpenAI router drain controls are invalid.",
            "server_error"
        );
    }
    if (king_inference_openai_validate_payload(
        payload,
        return_value,
        &has_error_response,
        max_chat_messages
    ) != SUCCESS) {
        return FAILURE;
    }
    if (has_error_response) {
        return SUCCESS;
    }
    stream_requested = king_inference_bool_from_array(payload, "stream", false);
    if (!king_inference_openai_chat_generation_options_valid(payload, &generation_error)) {
        return king_inference_openai_error_response(
            return_value,
            400,
            generation_error != NULL ? generation_error : "OpenAI generation options are invalid.",
            "invalid_request_error"
        );
    }

    if (king_inference_openai_require_generation_backend(
        return_value,
        model,
        payload,
        "Chat completions endpoint",
        &has_error_response
    ) != SUCCESS) {
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
        smart_str content = {0};
        bool include_usage = king_inference_openai_stream_usage_requested(payload);
        bool tools_active = king_inference_openai_tools_active(payload);

        result = king_inference_openai_drain_stream(
            stream,
            &stream_value,
            &drain_controls,
            include_usage || tools_active ? &content : NULL,
            tools_active ? NULL : &sse,
            &finish_reason
        );
        if (result == SUCCESS && tools_active) {
            smart_str_0(&content);
            result = king_inference_openai_sse_append_buffered_chat(stream, &content, finish_reason, &sse);
        }
        if (result == SUCCESS && include_usage) {
            smart_str_0(&content);
            result = king_inference_openai_append_stream_usage_chunk(stream, &content, &sse);
        }
        if (result == SUCCESS) {
            smart_str_appends(&sse, "data: [DONE]\n\n");
        }
        smart_str_0(&sse);
        zval_ptr_dtor(&stream_value);
        if (result != SUCCESS) {
            smart_str_free(&sse);
            smart_str_free(&content);
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
        smart_str_free(&content);
        smart_str_free(&sse);
        return SUCCESS;
    }

    {
        smart_str content = {0};
        result = king_inference_openai_drain_stream(stream, &stream_value, &drain_controls, &content, NULL, &finish_reason);
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
    zval decoded;
    zend_result result;
    bool has_error_response = false;

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

    result = king_inference_openai_decode_object_body(
        request,
        "Chat completions HTTP request requires a JSON string body.",
        "Chat completions HTTP request body must be a JSON object.",
        return_value,
        &decoded,
        &has_error_response
    );
    if (result != SUCCESS) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            king_get_error()[0] != '\0' ? king_get_error() : "OpenAI-compatible inference HTTP response failed."
        );
        RETURN_THROWS();
    }
    if (has_error_response) {
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
