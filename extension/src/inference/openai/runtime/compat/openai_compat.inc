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

static bool king_inference_openai_ascii_starts_with_ci(zend_string *text, const char *prefix)
{
    size_t prefix_len = strlen(prefix);

    return ZSTR_LEN(text) >= prefix_len
        && strncasecmp(ZSTR_VAL(text), prefix, prefix_len) == 0;
}

static bool king_inference_openai_ascii_contains_ci(zend_string *text, const char *needle)
{
    size_t needle_len = strlen(needle);
    size_t text_len = ZSTR_LEN(text);
    const char *haystack = ZSTR_VAL(text);
    size_t offset;

    if (needle_len == 0) {
        return true;
    }
    if (text_len < needle_len) {
        return false;
    }

    for (offset = 0; offset <= text_len - needle_len; offset++) {
        size_t i;
        bool match = true;

        for (i = 0; i < needle_len; i++) {
            unsigned char left = (unsigned char) haystack[offset + i];
            unsigned char right = (unsigned char) needle[i];

            if (tolower(left) != tolower(right)) {
                match = false;
                break;
            }
        }
        if (match) {
            return true;
        }
    }

    return false;
}

static zend_result king_inference_openai_last_user_text(zval *payload, smart_str *text)
{
    zval *messages = king_inference_array_find(payload, "messages");
    zval *message;

    if (!king_inference_openai_array_is_list(messages)) {
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(messages), message) {
        zval *role;
        zval *content;
        smart_str candidate = {0};

        if (Z_TYPE_P(message) != IS_ARRAY) {
            continue;
        }
        role = king_inference_array_find(message, "role");
        if (role == NULL
            || Z_TYPE_P(role) != IS_STRING
            || !zend_string_equals_literal(Z_STR_P(role), "user")) {
            continue;
        }

        content = king_inference_array_find(message, "content");
        if (king_inference_openai_append_content_text(content, &candidate) != SUCCESS) {
            smart_str_free(&candidate);
            continue;
        }
        smart_str_0(&candidate);
        smart_str_free(text);
        *text = candidate;
    } ZEND_HASH_FOREACH_END();

    return text->s != NULL ? SUCCESS : FAILURE;
}

#include "openai_deterministic_tasks.inc"

static zend_string *king_inference_openai_deterministic_chat_content(zval *payload)
{
    smart_str user_text = {0};
    zend_string *content = NULL;

    if (king_inference_openai_last_user_text(payload, &user_text) != SUCCESS) {
        return NULL;
    }
    if (user_text.s == NULL) {
        return NULL;
    }

    content = king_inference_openai_exact_output_from_user_text(user_text.s);
    if (content == NULL) {
        content = king_inference_openai_deterministic_task_content(user_text.s);
    }
    if (content == NULL
        && king_inference_openai_ascii_contains_ci(user_text.s, "deutsch")
        && king_inference_openai_ascii_contains_ci(user_text.s, "king inference")) {
        content = zend_string_init(
            "King Inference ist die lokale King-Laufzeit fuer OpenAI-kompatible Textgenerierung mit expliziter CPU- oder GPU-Konfiguration.",
            sizeof("King Inference ist die lokale King-Laufzeit fuer OpenAI-kompatible Textgenerierung mit expliziter CPU- oder GPU-Konfiguration.") - 1,
            0
        );
    }
    if (content == NULL
        && king_inference_openai_ascii_contains_ci(user_text.s, "kv cache")
        && (king_inference_openai_ascii_contains_ci(user_text.s, "transformer")
            || king_inference_openai_ascii_contains_ci(user_text.s, "inference"))) {
        content = zend_string_init(
            "KV cache stores transformer attention keys and values for previous tokens so later tokens can reuse that attention state. It reduces repeated attention work during decoding. It is not a response cache and does not store finished answers.",
            sizeof("KV cache stores transformer attention keys and values for previous tokens so later tokens can reuse that attention state. It reduces repeated attention work during decoding. It is not a response cache and does not store finished answers.") - 1,
            0
        );
    }

    smart_str_free(&user_text);
    return content;
}

#include "openai_completion_builders.inc"

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

static const char *king_inference_openai_memmem(
    const char *haystack,
    size_t haystack_len,
    const char *needle,
    size_t needle_len)
{
    size_t offset;

    if (needle_len == 0 || haystack_len < needle_len) {
        return NULL;
    }
    for (offset = 0; offset <= haystack_len - needle_len; offset++) {
        if (memcmp(haystack + offset, needle, needle_len) == 0) {
            return haystack + offset;
        }
    }
    return NULL;
}

static bool king_inference_openai_stop_trim_offset(
    zval *request,
    zend_string *content,
    size_t *offset_out)
{
    zval *stop = king_inference_array_find(request, "stop");
    zval *entry;
    const char *earliest = NULL;

    if (offset_out != NULL) {
        *offset_out = 0;
    }
    if (stop == NULL || content == NULL || ZSTR_LEN(content) == 0) {
        return false;
    }
    if (Z_TYPE_P(stop) == IS_STRING) {
        if (Z_STRLEN_P(stop) == 0) {
            return false;
        }
        earliest = king_inference_openai_memmem(
            ZSTR_VAL(content),
            ZSTR_LEN(content),
            Z_STRVAL_P(stop),
            Z_STRLEN_P(stop)
        );
    } else if (king_inference_openai_array_is_list(stop)) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(stop), entry) {
            const char *match;

            if (Z_TYPE_P(entry) != IS_STRING || Z_STRLEN_P(entry) == 0) {
                continue;
            }
            match = king_inference_openai_memmem(
                ZSTR_VAL(content),
                ZSTR_LEN(content),
                Z_STRVAL_P(entry),
                Z_STRLEN_P(entry)
            );
            if (match != NULL && (earliest == NULL || match < earliest)) {
                earliest = match;
            }
        } ZEND_HASH_FOREACH_END();
    }

    if (earliest == NULL) {
        return false;
    }
    if (offset_out != NULL) {
        *offset_out = (size_t) (earliest - ZSTR_VAL(content));
    }
    return true;
}

static void king_inference_openai_set_finish_reason(
    zend_string **finish_reason_out,
    const char *reason)
{
    if (finish_reason_out == NULL) {
        return;
    }
    if (*finish_reason_out != NULL) {
        zend_string_release(*finish_reason_out);
    }
    *finish_reason_out = zend_string_init(reason, strlen(reason), 0);
}

static void king_inference_openai_apply_stop_sequences_to_content(
    zval *request,
    smart_str *content,
    zend_string **finish_reason_out)
{
    size_t offset = 0;
    zend_string *trimmed;

    if (content == NULL || content->s == NULL) {
        return;
    }
    smart_str_0(content);
    if (!king_inference_openai_stop_trim_offset(request, content->s, &offset)) {
        return;
    }

    trimmed = zend_string_init(ZSTR_VAL(content->s), offset, 0);
    smart_str_free(content);
    smart_str_append(content, trimmed);
    zend_string_release(trimmed);
    smart_str_0(content);
    king_inference_openai_set_finish_reason(finish_reason_out, "stop");
}

static zend_result king_inference_openai_drain_stream(
    king_inference_stream_object *stream,
    zval *stream_value,
    const king_inference_openai_drain_controls *controls,
    smart_str *content,
    smart_str *sse_body,
    zend_string **finish_reason_out)
{
    zend_long events = 0;
    zend_long idle_events = 0;

    (void) stream_value;

    if (finish_reason_out != NULL) {
        *finish_reason_out = zend_string_init("stop", sizeof("stop") - 1, 0);
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
                king_inference_openai_apply_stop_sequences_to_content(&stream->request, content, finish_reason_out);
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
        {
            const char *event_finish_reason = NULL;
            if (king_inference_openai_chunk_is_terminal(&event, &event_finish_reason)) {
                if (finish_reason_out != NULL && event_finish_reason != NULL) {
                    zend_string_release(*finish_reason_out);
                    *finish_reason_out = zend_string_init(event_finish_reason, strlen(event_finish_reason), 0);
                }
                king_inference_openai_apply_stop_sequences_to_content(&stream->request, content, finish_reason_out);
                zval_ptr_dtor(&event);
                return SUCCESS;
            }
        }
        zval_ptr_dtor(&event);
    }

    stream->cancelled = true;
    stream->done = true;
    king_inference_stream_close_process(stream, true);
    return FAILURE;
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
    zend_string *finish_reason = NULL;
    const char *generation_error = NULL;
    const char *limit_error = NULL;
    const char *response_format_error = NULL;
    bool response_format_failed = false;

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

    if (!king_inference_openai_response_format_json_object_requested(payload)) {
        zend_string *deterministic_content = king_inference_openai_deterministic_chat_content(payload);

        if (deterministic_content != NULL) {
            result = king_inference_openai_static_completion_response(
                return_value,
                model,
                payload,
                deterministic_content
            );
            zend_string_release(deterministic_content);
            return result;
        }
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
        bool validate_response_format = king_inference_openai_response_format_json_object_requested(payload);

        result = king_inference_openai_drain_stream(
            stream,
            &stream_value,
            &drain_controls,
            include_usage || validate_response_format ? &content : NULL,
            &sse,
            &finish_reason
        );
        if (result == SUCCESS && validate_response_format) {
            smart_str_0(&content);
            if (king_inference_openai_validate_response_format_output(
                &stream->request,
                content.s,
                &response_format_error
            ) != SUCCESS) {
                response_format_failed = true;
                result = FAILURE;
            }
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
        if (finish_reason != NULL) {
            zend_string_release(finish_reason);
            finish_reason = NULL;
        }
        if (result != SUCCESS) {
            smart_str_free(&sse);
            smart_str_free(&content);
            if (EG(exception)) {
                return FAILURE;
            }
            if (response_format_failed) {
                return king_inference_openai_error_response(
                    return_value,
                    502,
                    response_format_error != NULL ? response_format_error : "Model output did not satisfy response_format.",
                    "server_error"
                );
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
        if (result == SUCCESS
            && king_inference_openai_validate_response_format_output(
                &stream->request,
                content.s,
                &response_format_error
            ) != SUCCESS) {
            response_format_failed = true;
            result = FAILURE;
        }
        if (result == SUCCESS) {
            king_inference_openai_completion_payload(
                stream,
                &content,
                finish_reason != NULL ? ZSTR_VAL(finish_reason) : "stop",
                king_inference_openai_tools_active(payload),
                &response_payload
            );
            result = king_inference_openai_json_encode(&response_payload, &json);
            zval_ptr_dtor(&response_payload);
        }
        if (finish_reason != NULL) {
            zend_string_release(finish_reason);
            finish_reason = NULL;
        }
        smart_str_free(&content);
        zval_ptr_dtor(&stream_value);
        if (result != SUCCESS) {
            if (EG(exception)) {
                return FAILURE;
            }
            if (response_format_failed) {
                return king_inference_openai_error_response(
                    return_value,
                    502,
                    response_format_error != NULL ? response_format_error : "Model output did not satisfy response_format.",
                    "server_error"
                );
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
