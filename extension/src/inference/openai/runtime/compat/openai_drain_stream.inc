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

    king_inference_stream_timeout_state(stream);
    return FAILURE;
}
