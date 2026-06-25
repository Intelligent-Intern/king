static zend_string *king_inference_completion_id(king_inference_stream_object *stream)
{
    return strpprintf(0, "cmpl_king_%ld_%lu", stream->created_at, (zend_ulong) stream->std.handle);
}

static zend_result king_inference_completion_prompt_to_chat_payload(
    zval *payload,
    zval *prompt,
    zval *chat_payload)
{
    zval *field;
    zval messages;
    zval message;

    if (prompt == NULL || Z_TYPE_P(prompt) != IS_STRING) {
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
    array_init(&message);
    add_assoc_string(&message, "role", "user");
    add_assoc_str(&message, "content", zend_string_copy(Z_STR_P(prompt)));
    add_next_index_zval(&messages, &message);
    add_assoc_zval(chat_payload, "messages", &messages);
    add_assoc_bool(chat_payload, "stream", king_inference_bool_from_array(payload, "stream", false));

    field = king_inference_array_find(payload, "max_tokens");
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

static void king_inference_completion_choice(
    zval *choice,
    zend_long index,
    zend_string *text,
    const char *finish_reason)
{
    array_init(choice);
    add_assoc_long(choice, "index", index);
    add_assoc_str(choice, "text", text != NULL ? zend_string_copy(text) : zend_string_init("", 0, 0));
    add_assoc_null(choice, "logprobs");
    if (finish_reason != NULL) {
        add_assoc_string(choice, "finish_reason", finish_reason);
    } else {
        add_assoc_null(choice, "finish_reason");
    }
}

static zend_result king_inference_completion_payload(
    zval *model,
    zval *payload,
    zval *choices,
    zval *return_value)
{
    zval *requested_model = king_inference_array_find(payload, "model");
    king_inference_model_object *model_object = php_king_inference_model_obj_from_zend(Z_OBJ_P(model));
    zend_long created_at = (zend_long) time(NULL);
    zend_string *completion_id = strpprintf(0, "cmpl_king_%ld_%lu", created_at, (zend_ulong) Z_OBJ_HANDLE_P(model));

    array_init(return_value);
    add_assoc_str(return_value, "id", completion_id);
    add_assoc_string(return_value, "object", "text_completion");
    add_assoc_long(return_value, "created", created_at);
    if (requested_model != NULL && Z_TYPE_P(requested_model) == IS_STRING && Z_STRLEN_P(requested_model) > 0) {
        add_assoc_str(return_value, "model", zend_string_copy(Z_STR_P(requested_model)));
    } else if (model_object->name != NULL) {
        add_assoc_str(return_value, "model", zend_string_copy(model_object->name));
    } else {
        add_assoc_string(return_value, "model", "king-native");
    }
    king_inference_openai_add_text_completion_usage(return_value, model, payload, choices);
    add_assoc_zval(return_value, "choices", choices);
    return SUCCESS;
}

static zend_result king_inference_completion_run_prompt(
    zval *model,
    zval *payload,
    zval *prompt,
    zval *options,
    zend_long choice_index,
    zval *choice_out)
{
    zval chat_payload;
    zval stream_value;
    king_inference_stream_object *stream;
    smart_str content = {0};
    const char *finish_reason = "stop";
    zend_result result;

    if (king_inference_completion_prompt_to_chat_payload(payload, prompt, &chat_payload) != SUCCESS) {
        return FAILURE;
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
    result = king_inference_openai_drain_stream(stream, &stream_value, options, &content, NULL, &finish_reason);
    if (content.s == NULL) {
        smart_str_appends(&content, "");
    }
    smart_str_0(&content);
    if (result == SUCCESS) {
        king_inference_completion_choice(
            choice_out,
            choice_index,
            content.s,
            finish_reason
        );
    }
    smart_str_free(&content);
    zval_ptr_dtor(&stream_value);
    zval_ptr_dtor(&chat_payload);
    return result;
}

static zend_result king_inference_completion_stream_response(
    zval *return_value,
    zval *model,
    zval *payload,
    zval *prompt,
    zval *options)
{
    zval chat_payload;
    zval stream_value;
    king_inference_stream_object *stream;
    zend_string *completion_id;
    zend_string *model_name;
    zend_long timeout_ms = king_inference_openai_option_long(options, "read_timeout_ms", 250);
    zend_long max_events = king_inference_openai_option_long(options, "max_events", 4096);
    zend_long max_idle_events = king_inference_openai_option_long(options, "max_idle_events", 240);
    zend_long events = 0;
    zend_long idle_events = 0;
    bool finished = false;
    smart_str sse = {0};

    if (king_inference_completion_prompt_to_chat_payload(payload, prompt, &chat_payload) != SUCCESS) {
        return FAILURE;
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
    completion_id = king_inference_completion_id(stream);
    model_name = king_inference_stream_openai_model_name(stream);

    while (events < max_events && idle_events < max_idle_events) {
        zval chunk;
        zval *delta_text;

        ZVAL_UNDEF(&chunk);
        if (king_inference_stream_next_event(stream, timeout_ms, &chunk) != SUCCESS) {
            if (!Z_ISUNDEF(chunk)) {
                zval_ptr_dtor(&chunk);
            }
            zend_string_release(completion_id);
            zend_string_release(model_name);
            zval_ptr_dtor(&stream_value);
            zval_ptr_dtor(&chat_payload);
            smart_str_free(&sse);
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
            zval event;
            zval choices;
            zval choice;
            array_init(&event);
            add_assoc_str(&event, "id", zend_string_copy(completion_id));
            add_assoc_string(&event, "object", "text_completion");
            add_assoc_long(&event, "created", stream->created_at);
            add_assoc_str(&event, "model", zend_string_copy(model_name));
            array_init(&choices);
            king_inference_completion_choice(&choice, 0, Z_STR_P(delta_text), NULL);
            add_next_index_zval(&choices, &choice);
            add_assoc_zval(&event, "choices", &choices);
            if (king_inference_openai_sse_append(&sse, &event) != SUCCESS) {
                zval_ptr_dtor(&event);
                zval_ptr_dtor(&chunk);
                zend_string_release(completion_id);
                zend_string_release(model_name);
                zval_ptr_dtor(&stream_value);
                zval_ptr_dtor(&chat_payload);
                smart_str_free(&sse);
                return FAILURE;
            }
            zval_ptr_dtor(&event);
        }
        if (king_inference_openai_chunk_is_terminal(&chunk, NULL)) {
            zval_ptr_dtor(&chunk);
            finished = true;
            break;
        }
        zval_ptr_dtor(&chunk);
    }

    zend_string_release(completion_id);
    zend_string_release(model_name);
    if (!finished) {
        zval_ptr_dtor(&stream_value);
        zval_ptr_dtor(&chat_payload);
        smart_str_free(&sse);
        return FAILURE;
    }

    {
        zval event;
        zval choices;
        zval choice;
        array_init(&event);
        add_assoc_str(&event, "id", king_inference_completion_id(stream));
        add_assoc_string(&event, "object", "text_completion");
        add_assoc_long(&event, "created", stream->created_at);
        add_assoc_str(&event, "model", king_inference_stream_openai_model_name(stream));
        array_init(&choices);
        king_inference_completion_choice(&choice, 0, NULL, "stop");
        add_next_index_zval(&choices, &choice);
        add_assoc_zval(&event, "choices", &choices);
        if (king_inference_openai_sse_append(&sse, &event) != SUCCESS) {
            zval_ptr_dtor(&event);
            zval_ptr_dtor(&stream_value);
            zval_ptr_dtor(&chat_payload);
            smart_str_free(&sse);
            return FAILURE;
        }
        zval_ptr_dtor(&event);
    }

    zval_ptr_dtor(&stream_value);
    zval_ptr_dtor(&chat_payload);
    smart_str_appends(&sse, "data: [DONE]\n\n");
    smart_str_0(&sse);
    king_inference_openai_http_array_response(return_value, 200, "text/event-stream", sse.s, true);
    smart_str_free(&sse);
    return SUCCESS;
}

static zend_result king_inference_openai_completions_payload_response(
    zval *return_value,
    zval *model,
    zval *payload,
    zval *options)
{
    zval *prompt = king_inference_array_find(payload, "prompt");
    zval choices;
    zval response_payload;
    zend_string *json = NULL;
    zend_result result;
    bool stream_requested = king_inference_bool_from_array(payload, "stream", false);

    if (prompt == NULL) {
        return king_inference_openai_error_response(
            return_value,
            400,
            "Completions request requires prompt.",
            "invalid_request_error"
        );
    }
    if (stream_requested && Z_TYPE_P(prompt) != IS_STRING) {
        return king_inference_openai_error_response(
            return_value,
            400,
            "Streaming completions require a string prompt.",
            "invalid_request_error"
        );
    }
    if (stream_requested) {
        result = king_inference_completion_stream_response(return_value, model, payload, prompt, options);
        if (result != SUCCESS && !EG(exception)) {
            return king_inference_openai_error_response(
                return_value,
                504,
                "Completion stream did not finish within the configured bounded drain window.",
                "server_error"
            );
        }
        return result;
    }

    array_init(&choices);
    if (Z_TYPE_P(prompt) == IS_STRING) {
        zval choice;
        result = king_inference_completion_run_prompt(model, payload, prompt, options, 0, &choice);
        if (result != SUCCESS) {
            zval_ptr_dtor(&choices);
            if (EG(exception)) {
                return FAILURE;
            }
            return king_inference_openai_error_response(
                return_value,
                504,
                "Completion request did not finish within the configured bounded drain window.",
                "server_error"
            );
        }
        add_next_index_zval(&choices, &choice);
    } else if (king_inference_openai_array_is_list(prompt)) {
        zval *entry;
        zend_long index = 0;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(prompt), entry) {
            zval choice;
            if (Z_TYPE_P(entry) != IS_STRING) {
                zval_ptr_dtor(&choices);
                return king_inference_openai_error_response(
                    return_value,
                    400,
                    "Prompt arrays must contain only strings.",
                    "invalid_request_error"
                );
            }
            result = king_inference_completion_run_prompt(model, payload, entry, options, index++, &choice);
            if (result != SUCCESS) {
                zval_ptr_dtor(&choices);
                if (EG(exception)) {
                    return FAILURE;
                }
                return king_inference_openai_error_response(
                    return_value,
                    504,
                    "Completion request did not finish within the configured bounded drain window.",
                    "server_error"
                );
            }
            add_next_index_zval(&choices, &choice);
        } ZEND_HASH_FOREACH_END();
    } else {
        zval_ptr_dtor(&choices);
        return king_inference_openai_error_response(
            return_value,
            400,
            "Completions prompt must be a string or a list of strings.",
            "invalid_request_error"
        );
    }

    {
        zval *first_choice = zend_hash_index_find(Z_ARRVAL(choices), 0);
        if (first_choice == NULL) {
            zval_ptr_dtor(&choices);
            return king_inference_openai_error_response(
                return_value,
                400,
                "Completions prompt list must not be empty.",
                "invalid_request_error"
            );
        }
    }

    result = king_inference_completion_payload(model, payload, &choices, &response_payload);
    if (result == SUCCESS) {
        result = king_inference_openai_json_encode(&response_payload, &json);
        zval_ptr_dtor(&response_payload);
    }
    if (result != SUCCESS) {
        return FAILURE;
    }
    king_inference_openai_http_array_response(return_value, 200, "application/json", json, false);
    zend_string_release(json);
    return SUCCESS;
}
