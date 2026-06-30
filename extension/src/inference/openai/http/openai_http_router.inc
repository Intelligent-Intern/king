static zend_result king_inference_openai_router_chat_response(
    zval *models,
    zval *request,
    zval *options,
    zval *return_value)
{
    zval decoded;
    zval *model;
    zend_result result;
    bool has_error_response = false;

    if (!king_inference_openai_request_method_is(request, "POST")) {
        return king_inference_openai_error_response(
            return_value,
            405,
            "Chat completions endpoint requires POST.",
            "invalid_request_error"
        );
    }

    result = king_inference_openai_decode_object_body(
        request,
        "Chat completions HTTP request requires a JSON string body.",
        "Chat completions HTTP request body must be a JSON object.",
        return_value,
        &decoded,
        &has_error_response
    );
    if (result != SUCCESS || has_error_response) {
        return result;
    }

    result = king_inference_openai_require_model(
        models,
        &decoded,
        "Chat completions request model must be a non-empty string when provided.",
        return_value,
        &model
    );
    if (result != SUCCESS || Z_TYPE_P(return_value) == IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        return result;
    }

    result = king_inference_openai_payload_response(return_value, model, &decoded, options);
    zval_ptr_dtor(&decoded);
    return result;
}

static zend_result king_inference_openai_router_responses_response(
    zval *models,
    zval *request,
    zval *options,
    zval *return_value)
{
    zval decoded;
    zval *model;
    zend_result result;
    bool has_error_response = false;

    if (!king_inference_openai_request_method_is(request, "POST")) {
        return king_inference_openai_error_response(
            return_value,
            405,
            "Responses endpoint requires POST.",
            "invalid_request_error"
        );
    }

    result = king_inference_openai_decode_object_body(
        request,
        "Responses HTTP request requires a JSON string body.",
        "Responses HTTP request body must be a JSON object.",
        return_value,
        &decoded,
        &has_error_response
    );
    if (result != SUCCESS || has_error_response) {
        return result;
    }

    result = king_inference_openai_require_model(
        models,
        &decoded,
        "Responses request model must be a non-empty string when provided.",
        return_value,
        &model
    );
    if (result != SUCCESS || Z_TYPE_P(return_value) == IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        return result;
    }

    result = king_inference_openai_responses_payload_response(return_value, model, &decoded, options);
    zval_ptr_dtor(&decoded);
    return result;
}

static zend_result king_inference_openai_router_completions_response(
    zval *models,
    zval *request,
    zval *options,
    zval *return_value)
{
    zval decoded;
    zval *model;
    zend_result result;
    bool has_error_response = false;

    if (!king_inference_openai_request_method_is(request, "POST")) {
        return king_inference_openai_error_response(
            return_value,
            405,
            "Completions endpoint requires POST.",
            "invalid_request_error"
        );
    }

    result = king_inference_openai_decode_object_body(
        request,
        "Completions HTTP request requires a JSON string body.",
        "Completions HTTP request body must be a JSON object.",
        return_value,
        &decoded,
        &has_error_response
    );
    if (result != SUCCESS || has_error_response) {
        return result;
    }

    result = king_inference_openai_require_model(
        models,
        &decoded,
        "Completions request model must be a non-empty string when provided.",
        return_value,
        &model
    );
    if (result != SUCCESS || Z_TYPE_P(return_value) == IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        return result;
    }

    result = king_inference_openai_completions_payload_response(return_value, model, &decoded, options);
    zval_ptr_dtor(&decoded);
    return result;
}

static zend_result king_inference_openai_router_embeddings_response(
    zval *models,
    zval *request,
    zval *options,
    zval *return_value)
{
    zval decoded;
    zval *model;
    zend_result result;
    bool has_error_response = false;

    if (!king_inference_openai_request_method_is(request, "POST")) {
        return king_inference_openai_error_response(
            return_value,
            405,
            "Embeddings endpoint requires POST.",
            "invalid_request_error"
        );
    }

    result = king_inference_openai_decode_object_body(
        request,
        "Embeddings HTTP request requires a JSON string body.",
        "Embeddings HTTP request body must be a JSON object.",
        return_value,
        &decoded,
        &has_error_response
    );
    if (result != SUCCESS || has_error_response) {
        return result;
    }

    result = king_inference_openai_require_model(
        models,
        &decoded,
        "Embeddings request model must be a non-empty string when provided.",
        return_value,
        &model
    );
    if (result != SUCCESS || Z_TYPE_P(return_value) == IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        return result;
    }

    result = king_inference_openai_embeddings_payload_response(return_value, model, &decoded, options);
    zval_ptr_dtor(&decoded);
    return result;
}

PHP_FUNCTION(king_inference_openai_http_response)
{
    zval *models;
    zval *request;
    zval *options = NULL;
    zval empty_options;
    zval *effective_options;
    zend_result result;
    zend_string *model_id = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ARRAY(models)
        Z_PARAM_ARRAY(request)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (options == NULL) {
        array_init(&empty_options);
        effective_options = &empty_options;
    } else {
        effective_options = options;
    }

    model_id = king_inference_openai_request_path_suffix(request, "/v1/models/");

    if (king_inference_openai_request_path_is(request, "/v1/models")) {
        if (!king_inference_openai_request_method_is(request, "GET")) {
            result = king_inference_openai_error_response(
                return_value,
                405,
                "Models endpoint requires GET.",
                "invalid_request_error"
            );
        } else {
            result = king_inference_openai_models_response(models, effective_options, return_value);
        }
    } else if (model_id != NULL) {
        if (!king_inference_openai_request_method_is(request, "GET")) {
            result = king_inference_openai_error_response(
                return_value,
                405,
                "Model retrieve endpoint requires GET.",
                "invalid_request_error"
            );
        } else {
            result = king_inference_openai_retrieve_model_response(models, model_id, effective_options, return_value);
        }
    } else if (king_inference_openai_request_path_is(request, "/v1/chat/completions")) {
        result = king_inference_openai_router_chat_response(models, request, effective_options, return_value);
    } else if (king_inference_openai_request_path_is(request, "/v1/responses")) {
        result = king_inference_openai_router_responses_response(models, request, effective_options, return_value);
    } else if (king_inference_openai_request_path_is(request, "/v1/completions")) {
        result = king_inference_openai_router_completions_response(models, request, effective_options, return_value);
    } else if (king_inference_openai_request_path_is(request, "/v1/embeddings")) {
        result = king_inference_openai_router_embeddings_response(models, request, effective_options, return_value);
    } else {
        result = king_inference_openai_error_response(
            return_value,
            404,
            "OpenAI-compatible inference route not found.",
            "invalid_request_error"
        );
    }

    if (options == NULL) {
        zval_ptr_dtor(&empty_options);
    }
    if (model_id != NULL) {
        zend_string_release(model_id);
    }
    if (result != SUCCESS) {
        if (EG(exception)) {
            RETURN_THROWS();
        }
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            king_get_error()[0] != '\0' ? king_get_error() : "OpenAI-compatible inference router failed."
        );
        RETURN_THROWS();
    }
}
