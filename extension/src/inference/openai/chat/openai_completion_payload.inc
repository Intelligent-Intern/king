static zend_result king_inference_openai_completion_payload(
    king_inference_stream_object *stream,
    smart_str *content,
    const char *finish_reason,
    bool allow_tool_calls,
    zval *return_value)
{
    zend_string *model_name = king_inference_stream_openai_model_name(stream);
    zval choices;
    zval choice;
    zval message;
    zval tool_calls;
    bool has_tool_calls;

    ZVAL_UNDEF(&tool_calls);
    has_tool_calls = allow_tool_calls
        && content != NULL
        && content->s != NULL
        && king_inference_openai_tool_calls_from_content(content->s, &tool_calls);

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
