PHP_FUNCTION(king_inference_model_load)
{
    zval *config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    object_init_ex(return_value, king_ce_inference_model);
    if (king_inference_model_init(return_value, config, "king_inference_model_load") != SUCCESS) {
        zval_ptr_dtor(return_value);
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_model_info)
{
    zval *model;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
    ZEND_PARSE_PARAMETERS_END();

    king_inference_model_info_array(php_king_inference_model_obj_from_zend(Z_OBJ_P(model)), return_value);
}

PHP_FUNCTION(king_inference_tokenize)
{
    zval *model;
    zend_string *text;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_STR(text)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tokenizer_encode(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        text,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_token_decode)
{
    zval *model;
    zend_long token_id;
    zend_string *decoded;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_LONG(token_id)
    ZEND_PARSE_PARAMETERS_END();

    if (token_id < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token id must be non-negative.");
        RETURN_THROWS();
    }

    decoded = king_inference_tokenizer_decode_token(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        (zend_ulong) token_id
    );
    if (decoded == NULL) {
        RETURN_THROWS();
    }
    RETURN_STR(decoded);
}

PHP_FUNCTION(king_inference_token_decode_graph)
{
    zval *model, *token, *options = NULL;
    zend_long position;
    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_ZVAL(token)
        Z_PARAM_LONG(position)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();
    if (position < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph position must be non-negative.");
        RETURN_THROWS();
    }
    if (king_inference_token_decode_graph_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        token,
        (zend_ulong) position,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_kv_cache_plan)
{
    zval *model;
    zval *request = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(request)
    ZEND_PARSE_PARAMETERS_END();

    king_inference_paged_kv_cache_plan(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        request,
        return_value
    );
}

PHP_FUNCTION(king_inference_tensor_view)
{
    zval *model;
    zend_string *name;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_STR(name)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_view_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        name,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_tensor_index)
{
    zval *model;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    king_inference_tensor_index_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        options,
        return_value
    );
}

PHP_FUNCTION(king_inference_tensor_dequantize)
{
    zval *model;
    zend_string *name;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_STR(name)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_dequantize_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        name,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_tensor_matmul)
{
    zval *model;
    zend_string *name;
    zval *input;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_STR(name)
        Z_PARAM_ARRAY(input)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_matmul_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        name,
        input,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_graph_run)
{
    zval *model;
    zval *graph;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_ARRAY(graph)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_graph_run_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        graph,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_stream)
{
    zval *model;
    zval *request;
    zval *options = NULL;
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_ARRAY(request)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    object_init_ex(return_value, king_ce_inference_stream);
    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(return_value));
    ZVAL_COPY(&stream->model, model);
    ZVAL_COPY(&stream->request, request);
    if (options != NULL) {
        ZVAL_COPY(&stream->options, options);
    } else {
        array_init(&stream->options);
    }
    if (king_inference_stream_validate_request_options(
        &stream->request,
        &stream->options,
        "king_inference_stream"
    ) != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_UNDEF(return_value);
        RETURN_THROWS();
    }
    stream->openai_compatible = king_inference_stream_openai_requested(&stream->request, &stream->options);
    stream->created_at = (zend_long) time(NULL);
    stream->native_event_index = 0;
    if (stream->openai_compatible) {
        king_inference_stream_prepare_openai(stream);
    }

    if (king_inference_stream_start(stream, "king_inference_stream") != SUCCESS) {
        king_inference_stream_throw_start_failure(stream, "king_inference_stream");
        zval_ptr_dtor(return_value);
        ZVAL_UNDEF(return_value);
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_openai_chat_stream)
{
    zval *model;
    zval *payload;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_ARRAY(payload)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_openai_create_stream(
            return_value,
            model,
            payload,
            options,
            "king_inference_openai_chat_stream"
    ) != SUCCESS) {
        if (EG(exception)) {
            RETURN_THROWS();
        }
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            king_get_error()[0] != '\0'
                ? king_get_error()
                : "OpenAI-compatible inference stream failed."
        );
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_next)
{
    zval *stream_object;
    zend_long timeout_ms = 0;
    bool timeout_is_null = true;
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(stream_object, king_ce_inference_stream)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_is_null) {
        timeout_ms = 0;
    }

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(stream_object));
    if (king_inference_stream_next_event(stream, timeout_ms, return_value) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_next_async)
{
    zval *stream_object;
    zend_long timeout_ms = 0;
    bool timeout_is_null = true;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(stream_object, king_ce_inference_stream)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_is_null) {
        timeout_ms = 0;
    }

    if (king_inference_next_async_create(
        return_value,
        stream_object,
        timeout_ms,
        "king_inference_next_async",
        sizeof("king_inference_next_async") - 1
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_cancel)
{
    zval *stream_object;
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(stream_object, king_ce_inference_stream)
    ZEND_PARSE_PARAMETERS_END();

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(stream_object));
    king_inference_stream_cancel_state(stream);
    RETURN_TRUE;
}
