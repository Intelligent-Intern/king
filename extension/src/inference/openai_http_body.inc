static bool king_inference_openai_json_body_space(char c)
{
    return c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == '\f';
}

static bool king_inference_openai_json_body_looks_object(zend_string *body)
{
    const char *bytes;
    size_t first = 0;
    size_t last;

    if (body == NULL || ZSTR_LEN(body) == 0) {
        return false;
    }

    bytes = ZSTR_VAL(body);
    last = ZSTR_LEN(body);
    while (first < last && king_inference_openai_json_body_space(bytes[first])) {
        first++;
    }
    while (last > first && king_inference_openai_json_body_space(bytes[last - 1])) {
        last--;
    }

    return first < last && bytes[first] == '{' && bytes[last - 1] == '}';
}

static zend_result king_inference_openai_decode_object_body(
    zval *request,
    const char *string_body_message,
    const char *object_body_message,
    zval *return_value,
    zval *decoded,
    bool *has_error_response)
{
    zval *body = king_inference_array_find(request, "body");

    if (has_error_response != NULL) {
        *has_error_response = false;
    }
    if (body == NULL || Z_TYPE_P(body) != IS_STRING) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            string_body_message,
            "invalid_request_error"
        );
    }
    if (!king_inference_openai_json_body_looks_object(Z_STR_P(body))) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            object_body_message,
            "invalid_request_error"
        );
    }

    if (king_inference_openai_json_decode(Z_STR_P(body), decoded) != SUCCESS) {
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(return_value, 400, king_get_error(), "invalid_request_error");
    }

    if (Z_TYPE_P(decoded) != IS_ARRAY) {
        zval_ptr_dtor(decoded);
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            object_body_message,
            "invalid_request_error"
        );
    }

    return SUCCESS;
}
