static zend_string *king_inference_openai_payload_model_name(zval *model, zval *payload)
{
    zval *requested_model = king_inference_array_find(payload, "model");

    if (requested_model != NULL && Z_TYPE_P(requested_model) == IS_STRING && Z_STRLEN_P(requested_model) > 0) {
        return zend_string_copy(Z_STR_P(requested_model));
    }
    if (model != NULL && Z_TYPE_P(model) == IS_OBJECT) {
        king_inference_model_object *model_object = php_king_inference_model_obj_from_zend(Z_OBJ_P(model));

        if (model_object->name != NULL) {
            return zend_string_copy(model_object->name);
        }
    }

    return zend_string_init("king-native", sizeof("king-native") - 1, 0);
}

static void king_inference_openai_static_completion_payload(
    zval *response_payload,
    zval *model,
    zval *payload,
    zend_string *content)
{
    zend_long created_at = (zend_long) time(NULL);
    zend_string *model_name = king_inference_openai_payload_model_name(model, payload);
    zend_string *completion_id = strpprintf(0, "chatcmpl_king_static_" ZEND_LONG_FMT, created_at);
    zval choices;
    zval choice;
    zval message;

    array_init(response_payload);
    add_assoc_str(response_payload, "id", completion_id);
    add_assoc_string(response_payload, "object", "chat.completion");
    add_assoc_long(response_payload, "created", created_at);
    add_assoc_str(response_payload, "model", model_name);

    array_init(&choices);
    array_init(&choice);
    add_assoc_long(&choice, "index", 0);
    array_init(&message);
    add_assoc_string(&message, "role", "assistant");
    add_assoc_str(&message, "content", zend_string_copy(content));
    add_assoc_zval(&choice, "message", &message);
    add_assoc_string(&choice, "finish_reason", "stop");
    add_next_index_zval(&choices, &choice);
    add_assoc_zval(response_payload, "choices", &choices);
    king_inference_openai_add_completion_usage(response_payload, model, payload, content);
}

static zend_result king_inference_openai_static_completion_sse_append_chunk(
    smart_str *sse,
    zend_string *completion_id,
    zend_string *model_name,
    zend_long created_at,
    zend_string *content,
    bool terminal)
{
    zval chunk;
    zval choices;
    zval choice;
    zval delta;
    zend_result result;

    array_init(&chunk);
    add_assoc_str(&chunk, "id", zend_string_copy(completion_id));
    add_assoc_string(&chunk, "object", "chat.completion.chunk");
    add_assoc_long(&chunk, "created", created_at);
    add_assoc_str(&chunk, "model", zend_string_copy(model_name));
    array_init(&choices);
    array_init(&choice);
    add_assoc_long(&choice, "index", 0);
    array_init(&delta);
    if (!terminal) {
        add_assoc_string(&delta, "role", "assistant");
        add_assoc_str(&delta, "content", zend_string_copy(content));
        add_assoc_null(&choice, "finish_reason");
    } else {
        add_assoc_stringl(&delta, "content", "", 0);
        add_assoc_string(&choice, "finish_reason", "stop");
    }
    add_assoc_zval(&choice, "delta", &delta);
    add_next_index_zval(&choices, &choice);
    add_assoc_zval(&chunk, "choices", &choices);
    result = king_inference_openai_sse_append(sse, &chunk);
    zval_ptr_dtor(&chunk);
    return result;
}

static zend_result king_inference_openai_static_completion_sse_response(
    zval *return_value,
    zval *model,
    zval *payload,
    zend_string *content)
{
    smart_str sse = {0};
    zend_long created_at = (zend_long) time(NULL);
    zend_string *model_name = king_inference_openai_payload_model_name(model, payload);
    zend_string *completion_id = strpprintf(0, "chatcmpl_king_static_" ZEND_LONG_FMT, created_at);
    zend_result result;

    result = king_inference_openai_static_completion_sse_append_chunk(
        &sse,
        completion_id,
        model_name,
        created_at,
        content,
        false
    );
    if (result == SUCCESS) {
        result = king_inference_openai_static_completion_sse_append_chunk(
            &sse,
            completion_id,
            model_name,
            created_at,
            content,
            true
        );
    }
    if (result == SUCCESS && king_inference_openai_stream_usage_requested(payload)) {
        zval usage_chunk;
        zval choices;

        array_init(&usage_chunk);
        add_assoc_str(&usage_chunk, "id", zend_string_copy(completion_id));
        add_assoc_string(&usage_chunk, "object", "chat.completion.chunk");
        add_assoc_long(&usage_chunk, "created", created_at);
        add_assoc_str(&usage_chunk, "model", zend_string_copy(model_name));
        array_init(&choices);
        add_assoc_zval(&usage_chunk, "choices", &choices);
        king_inference_openai_add_completion_usage(&usage_chunk, model, payload, content);
        result = king_inference_openai_sse_append(&sse, &usage_chunk);
        zval_ptr_dtor(&usage_chunk);
    }
    if (result == SUCCESS) {
        smart_str_appends(&sse, "data: [DONE]\n\n");
        smart_str_0(&sse);
        king_inference_openai_http_array_response(return_value, 200, "text/event-stream", sse.s, true);
    }

    zend_string_release(model_name);
    zend_string_release(completion_id);
    smart_str_free(&sse);
    return result;
}

static zend_result king_inference_openai_static_completion_response(
    zval *return_value,
    zval *model,
    zval *payload,
    zend_string *content)
{
    if (king_inference_bool_from_array(payload, "stream", false)) {
        return king_inference_openai_static_completion_sse_response(return_value, model, payload, content);
    }

    {
        zval response_payload;
        zend_string *json = NULL;
        zend_result result;

        king_inference_openai_static_completion_payload(&response_payload, model, payload, content);
        result = king_inference_openai_json_encode(&response_payload, &json);
        zval_ptr_dtor(&response_payload);
        if (result != SUCCESS) {
            return FAILURE;
        }
        king_inference_openai_http_array_response(return_value, 200, "application/json", json, false);
        zend_string_release(json);
    }

    return SUCCESS;
}
