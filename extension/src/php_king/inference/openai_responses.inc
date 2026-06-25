static zend_string *king_inference_responses_id(king_inference_stream_object *stream)
{
    return strpprintf(0, "resp_king_%ld_%lu", stream->created_at, (zend_ulong) stream->std.handle);
}

static zend_string *king_inference_responses_message_id(king_inference_stream_object *stream)
{
    return strpprintf(0, "msg_king_%ld_%lu", stream->created_at, (zend_ulong) stream->std.handle);
}

static zend_result king_inference_responses_append_content_text(zval *content, smart_str *buffer)
{
    zval *part;

    if (content == NULL) {
        return FAILURE;
    }
    if (Z_TYPE_P(content) == IS_STRING) {
        smart_str_append(buffer, Z_STR_P(content));
        return SUCCESS;
    }
    if (!king_inference_openai_array_is_list(content)) {
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(content), part) {
        zval *text;

        if (Z_TYPE_P(part) == IS_STRING) {
            smart_str_append(buffer, Z_STR_P(part));
            continue;
        }
        if (Z_TYPE_P(part) != IS_ARRAY) {
            continue;
        }

        text = king_inference_array_find(part, "text");
        if (text == NULL) {
            text = king_inference_array_find(part, "content");
        }
        if (text != NULL && Z_TYPE_P(text) == IS_STRING) {
            smart_str_append(buffer, Z_STR_P(text));
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_inference_responses_message_from_item(zval *item, zval *messages)
{
    zval *role;
    zval *content;
    zval message;
    smart_str text = {0};

    if (Z_TYPE_P(item) != IS_ARRAY) {
        return FAILURE;
    }

    role = king_inference_array_find(item, "role");
    content = king_inference_array_find(item, "content");
    if (role == NULL || Z_TYPE_P(role) != IS_STRING || Z_STRLEN_P(role) == 0) {
        return FAILURE;
    }
    if (king_inference_responses_append_content_text(content, &text) != SUCCESS) {
        smart_str_free(&text);
        return FAILURE;
    }
    smart_str_0(&text);

    array_init(&message);
    add_assoc_str(&message, "role", zend_string_copy(Z_STR_P(role)));
    add_assoc_str(&message, "content", text.s != NULL ? zend_string_copy(text.s) : zend_string_init("", 0, 0));
    add_next_index_zval(messages, &message);
    smart_str_free(&text);
    return SUCCESS;
}

static zend_result king_inference_responses_payload_to_chat_payload(zval *payload, zval *chat_payload)
{
    zval *input;
    zval *instructions;
    zval *field;
    zval messages;

    if (payload == NULL || Z_TYPE_P(payload) != IS_ARRAY) {
        return FAILURE;
    }

    input = king_inference_array_find(payload, "input");
    if (input == NULL) {
        return FAILURE;
    }

    array_init(chat_payload);
    field = king_inference_array_find(payload, "model");
    if (field != NULL) {
        zval copy;
        ZVAL_COPY(&copy, field);
        add_assoc_zval(chat_payload, "model", &copy);
    }

    array_init(&messages);
    instructions = king_inference_array_find(payload, "instructions");
    if (instructions != NULL && Z_TYPE_P(instructions) == IS_STRING && Z_STRLEN_P(instructions) > 0) {
        zval message;
        array_init(&message);
        add_assoc_string(&message, "role", "system");
        add_assoc_str(&message, "content", zend_string_copy(Z_STR_P(instructions)));
        add_next_index_zval(&messages, &message);
    }

    if (Z_TYPE_P(input) == IS_STRING) {
        zval message;
        array_init(&message);
        add_assoc_string(&message, "role", "user");
        add_assoc_str(&message, "content", zend_string_copy(Z_STR_P(input)));
        add_next_index_zval(&messages, &message);
    } else if (king_inference_openai_array_is_list(input)) {
        zval *item;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(input), item) {
            if (king_inference_responses_message_from_item(item, &messages) != SUCCESS) {
                zval_ptr_dtor(&messages);
                zval_ptr_dtor(chat_payload);
                ZVAL_UNDEF(chat_payload);
                return FAILURE;
            }
        } ZEND_HASH_FOREACH_END();
    } else {
        zval_ptr_dtor(&messages);
        zval_ptr_dtor(chat_payload);
        ZVAL_UNDEF(chat_payload);
        return FAILURE;
    }

    if (zend_hash_num_elements(Z_ARRVAL(messages)) == 0) {
        zval_ptr_dtor(&messages);
        zval_ptr_dtor(chat_payload);
        ZVAL_UNDEF(chat_payload);
        return FAILURE;
    }

    add_assoc_zval(chat_payload, "messages", &messages);
    add_assoc_bool(chat_payload, "stream", king_inference_bool_from_array(payload, "stream", false));

    field = king_inference_array_find(payload, "max_output_tokens");
    if (field == NULL) {
        field = king_inference_array_find(payload, "max_tokens");
    }
    if (field != NULL && Z_TYPE_P(field) == IS_LONG) {
        add_assoc_long(chat_payload, "max_tokens", Z_LVAL_P(field));
    }
    field = king_inference_array_find(payload, "temperature");
    if (field != NULL && (Z_TYPE_P(field) == IS_LONG || Z_TYPE_P(field) == IS_DOUBLE)) {
        zval copy;
        ZVAL_COPY(&copy, field);
        add_assoc_zval(chat_payload, "temperature", &copy);
    }
    field = king_inference_array_find(payload, "top_p");
    if (field != NULL && (Z_TYPE_P(field) == IS_LONG || Z_TYPE_P(field) == IS_DOUBLE)) {
        zval copy;
        ZVAL_COPY(&copy, field);
        add_assoc_zval(chat_payload, "top_p", &copy);
    }
    field = king_inference_array_find(payload, "seed");
    if (field != NULL && Z_TYPE_P(field) == IS_LONG) {
        add_assoc_long(chat_payload, "seed", Z_LVAL_P(field));
    }

    return SUCCESS;
}

static zend_result king_inference_responses_output_payload(
    king_inference_stream_object *stream,
    zend_string *response_id,
    zend_string *message_id,
    smart_str *content,
    zval *return_value)
{
    zend_string *model_name = king_inference_stream_openai_model_name(stream);
    zval output;
    zval item;
    zval content_list;
    zval content_part;

    array_init(return_value);
    add_assoc_str(return_value, "id", zend_string_copy(response_id));
    add_assoc_string(return_value, "object", "response");
    add_assoc_long(return_value, "created_at", stream->created_at);
    add_assoc_string(return_value, "status", "completed");
    add_assoc_str(return_value, "model", model_name);

    array_init(&output);
    array_init(&item);
    add_assoc_str(&item, "id", zend_string_copy(message_id));
    add_assoc_string(&item, "type", "message");
    add_assoc_string(&item, "status", "completed");
    add_assoc_string(&item, "role", "assistant");
    array_init(&content_list);
    array_init(&content_part);
    add_assoc_string(&content_part, "type", "output_text");
    add_assoc_str(&content_part, "text", content != NULL && content->s != NULL
        ? zend_string_copy(content->s)
        : zend_string_init("", 0, 0));
    add_assoc_empty_array(&content_part, "annotations");
    add_next_index_zval(&content_list, &content_part);
    add_assoc_zval(&item, "content", &content_list);
    add_next_index_zval(&output, &item);
    add_assoc_zval(return_value, "output", &output);
    add_assoc_str(return_value, "output_text", content != NULL && content->s != NULL
        ? zend_string_copy(content->s)
        : zend_string_init("", 0, 0));
    add_assoc_null(return_value, "error");
    add_assoc_null(return_value, "incomplete_details");
    king_inference_openai_add_response_usage(
        return_value,
        &stream->model,
        &stream->request,
        content != NULL ? content->s : NULL
    );
    return SUCCESS;
}

static zend_result king_inference_responses_sse_append(smart_str *sse, const char *event_name, zval *payload)
{
    zend_string *json = NULL;

    if (king_inference_openai_json_encode(payload, &json) != SUCCESS) {
        return FAILURE;
    }

    smart_str_appends(sse, "event: ");
    smart_str_appends(sse, event_name);
    smart_str_appends(sse, "\n");
    smart_str_appends(sse, "data: ");
    smart_str_append(sse, json);
    smart_str_appends(sse, "\n\n");
    zend_string_release(json);
    return SUCCESS;
}

static void king_inference_responses_base_event(
    zval *event,
    const char *type,
    zend_string *response_id,
    zend_string *message_id,
    zend_string *model_name)
{
    array_init(event);
    add_assoc_string(event, "type", type);
    add_assoc_str(event, "response_id", zend_string_copy(response_id));
    add_assoc_str(event, "item_id", zend_string_copy(message_id));
    add_assoc_str(event, "model", zend_string_copy(model_name));
}

static zend_result king_inference_responses_stream_response(
    zval *return_value,
    king_inference_stream_object *stream,
    zend_string *response_id,
    zend_string *message_id,
    zval *options)
{
    zend_long timeout_ms = king_inference_openai_option_long(options, "read_timeout_ms", 250);
    zend_long max_events = king_inference_openai_option_long(options, "max_events", 4096);
    zend_long max_idle_events = king_inference_openai_option_long(options, "max_idle_events", 240);
    zend_long events = 0;
    zend_long idle_events = 0;
    smart_str sse = {0};
    smart_str content = {0};
    zend_string *model_name = king_inference_stream_openai_model_name(stream);
    zval event;
    bool finished = false;

    king_inference_responses_base_event(&event, "response.created", response_id, message_id, model_name);
    if (king_inference_responses_sse_append(&sse, "response.created", &event) != SUCCESS) {
        zval_ptr_dtor(&event);
        zend_string_release(model_name);
        smart_str_free(&sse);
        return FAILURE;
    }
    zval_ptr_dtor(&event);

    while (events < max_events && idle_events < max_idle_events) {
        zval chunk;
        zval *delta_text;

        ZVAL_UNDEF(&chunk);
        if (king_inference_stream_next_event(stream, timeout_ms, &chunk) != SUCCESS) {
            if (!Z_ISUNDEF(chunk)) {
                zval_ptr_dtor(&chunk);
            }
            zend_string_release(model_name);
            smart_str_free(&sse);
            smart_str_free(&content);
            return FAILURE;
        }
        if (Z_TYPE(chunk) == IS_NULL) {
            zval_ptr_dtor(&chunk);
            if (stream->done) {
                finished = true;
                break;
            }
            idle_events++;
            continue;
        }

        idle_events = 0;
        events++;
        delta_text = king_inference_openai_chunk_delta_content(&chunk);
        if (delta_text != NULL && Z_TYPE_P(delta_text) == IS_STRING && Z_STRLEN_P(delta_text) > 0) {
            zval delta_event;
            smart_str_append(&content, Z_STR_P(delta_text));
            king_inference_responses_base_event(
                &delta_event,
                "response.output_text.delta",
                response_id,
                message_id,
                model_name
            );
            add_assoc_str(&delta_event, "delta", zend_string_copy(Z_STR_P(delta_text)));
            if (king_inference_responses_sse_append(&sse, "response.output_text.delta", &delta_event) != SUCCESS) {
                zval_ptr_dtor(&delta_event);
                zval_ptr_dtor(&chunk);
                zend_string_release(model_name);
                smart_str_free(&sse);
                smart_str_free(&content);
                return FAILURE;
            }
            zval_ptr_dtor(&delta_event);
        }
        if (king_inference_openai_chunk_is_terminal(&chunk, NULL)) {
            zval_ptr_dtor(&chunk);
            finished = true;
            break;
        }
        zval_ptr_dtor(&chunk);
    }

    if (!finished) {
        stream->cancelled = true;
        stream->done = true;
        king_inference_stream_close_process(stream, true);
        zend_string_release(model_name);
        smart_str_free(&sse);
        smart_str_free(&content);
        return FAILURE;
    }

    smart_str_0(&content);
    king_inference_responses_base_event(&event, "response.completed", response_id, message_id, model_name);
    if (content.s != NULL) {
        add_assoc_str(&event, "output_text", zend_string_copy(content.s));
    } else {
        add_assoc_string(&event, "output_text", "");
    }
    king_inference_openai_add_response_usage(
        &event,
        &stream->model,
        &stream->request,
        content.s
    );
    if (king_inference_responses_sse_append(&sse, "response.completed", &event) != SUCCESS) {
        zval_ptr_dtor(&event);
        zend_string_release(model_name);
        smart_str_free(&sse);
        smart_str_free(&content);
        return FAILURE;
    }
    zval_ptr_dtor(&event);
    smart_str_0(&sse);

    zend_string_release(model_name);
    smart_str_free(&content);
    king_inference_openai_http_array_response(return_value, 200, "text/event-stream", sse.s, true);
    smart_str_free(&sse);
    return SUCCESS;
}

static zend_result king_inference_openai_responses_payload_response(
    zval *return_value,
    zval *model,
    zval *payload,
    zval *options)
{
    zval chat_payload;
    zval stream_value;
    king_inference_stream_object *stream;
    zend_string *response_id;
    zend_string *message_id;
    bool stream_requested = king_inference_bool_from_array(payload, "stream", false);
    zend_result result;

    if (king_inference_responses_payload_to_chat_payload(payload, &chat_payload) != SUCCESS) {
        return king_inference_openai_error_response(
            return_value,
            400,
            "Responses request requires input as a string or a list of message items.",
            "invalid_request_error"
        );
    }

    if (king_inference_openai_create_stream(
        &stream_value,
        model,
        &chat_payload,
        options,
        "king_inference_openai_http_response"
    ) != SUCCESS) {
        zval_ptr_dtor(&chat_payload);
        return FAILURE;
    }
    stream = php_king_inference_stream_obj_from_zend(Z_OBJ(stream_value));
    response_id = king_inference_responses_id(stream);
    message_id = king_inference_responses_message_id(stream);

    if (stream_requested) {
        result = king_inference_responses_stream_response(return_value, stream, response_id, message_id, options);
    } else {
        smart_str content = {0};
        const char *finish_reason = "stop";
        zval response_payload;
        zend_string *json = NULL;

        result = king_inference_openai_drain_stream(stream, &stream_value, options, &content, NULL, &finish_reason);
        smart_str_0(&content);
        if (result == SUCCESS) {
            result = king_inference_responses_output_payload(
                stream,
                response_id,
                message_id,
                &content,
                &response_payload
            );
            if (result == SUCCESS) {
                result = king_inference_openai_json_encode(&response_payload, &json);
                zval_ptr_dtor(&response_payload);
            }
        }
        smart_str_free(&content);
        if (result == SUCCESS) {
            king_inference_openai_http_array_response(return_value, 200, "application/json", json, false);
            zend_string_release(json);
        }
    }

    zend_string_release(response_id);
    zend_string_release(message_id);
    zval_ptr_dtor(&stream_value);
    zval_ptr_dtor(&chat_payload);
    if (result != SUCCESS && !EG(exception)) {
        return king_inference_openai_error_response(
            return_value,
            504,
            "Responses request did not finish within the configured bounded drain window.",
            "server_error"
        );
    }

    return result;
}
