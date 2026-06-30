/*
 * Inference PHP entry points and OO method glue.
 */

static zend_result king_inference_next_async_runner(
    king_awaitable_object *intern,
    zend_long timeout_ms,
    zval *result)
{
    zval *stream_object;
    zval *timeout;
    zval event;
    zend_long read_timeout_ms = timeout_ms > 0 ? timeout_ms : 0;
    king_inference_stream_object *stream;
    zend_result status;

    if (Z_TYPE(intern->payload) != IS_ARRAY) {
        king_set_error("King inference async stream reader has an invalid native payload.");
        return FAILURE;
    }

    stream_object = zend_hash_str_find(
        Z_ARRVAL(intern->payload),
        "stream",
        sizeof("stream") - 1
    );
    timeout = zend_hash_str_find(
        Z_ARRVAL(intern->payload),
        "timeout_ms",
        sizeof("timeout_ms") - 1
    );
    if (stream_object == NULL
        || Z_TYPE_P(stream_object) != IS_OBJECT
        || !instanceof_function(Z_OBJCE_P(stream_object), king_ce_inference_stream)) {
        king_set_error("King inference async stream reader requires a valid stream.");
        return FAILURE;
    }
    if (timeout != NULL && Z_TYPE_P(timeout) == IS_LONG) {
        read_timeout_ms = Z_LVAL_P(timeout);
    }

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(stream_object));
    ZVAL_UNDEF(&event);
    status = king_inference_stream_next_event(stream, read_timeout_ms, &event);
    if (status != SUCCESS) {
        if (!Z_ISUNDEF(event)) {
            zval_ptr_dtor(&event);
        }
        return FAILURE;
    }

    if (Z_TYPE(event) == IS_NULL && !stream->done) {
        zval_ptr_dtor(&event);
        ZVAL_UNDEF(result);
        return SUCCESS;
    }

    ZVAL_COPY_VALUE(result, &event);
    return SUCCESS;
}

static zend_result king_inference_next_async_create(
    zval *return_value,
    zval *stream_object,
    zend_long timeout_ms,
    const char *operation,
    size_t operation_len)
{
    zval payload;
    zval stream_copy;
    zend_result status;

    array_init(&payload);
    ZVAL_COPY(&stream_copy, stream_object);
    add_assoc_zval(&payload, "stream", &stream_copy);
    add_assoc_long(&payload, "timeout_ms", timeout_ms);

    status = king_awaitable_create(
        return_value,
        operation,
        operation_len,
        king_inference_next_async_runner,
        &payload,
        NULL
    );
    zval_ptr_dtor(&payload);

    return status;
}

