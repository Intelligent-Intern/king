static zval *king_inference_openai_first_long_option(zval *source, const char * const *keys)
{
    size_t i;

    for (i = 0; keys[i] != NULL; i++) {
        zval *field = king_inference_array_find(source, keys[i]);
        if (field != NULL && Z_TYPE_P(field) == IS_LONG) {
            return field;
        }
    }

    return NULL;
}

static void king_inference_openai_copy_numeric_option(
    zval *source,
    zval *target,
    const char *key)
{
    zval *field = king_inference_array_find(source, key);
    zval copy;

    if (field == NULL || (Z_TYPE_P(field) != IS_LONG && Z_TYPE_P(field) != IS_DOUBLE)) {
        return;
    }

    ZVAL_COPY(&copy, field);
    add_assoc_zval(target, key, &copy);
}

static void king_inference_openai_copy_long_option(
    zval *source,
    zval *target,
    const char *key)
{
    zval *field = king_inference_array_find(source, key);

    if (field != NULL && Z_TYPE_P(field) == IS_LONG) {
        add_assoc_long(target, key, Z_LVAL_P(field));
    }
}

static bool king_inference_openai_stop_option_valid(zval *source, const char **message)
{
    zval *stop = king_inference_array_find(source, "stop");
    zval *entry;

    if (message != NULL) {
        *message = NULL;
    }
    if (stop == NULL) {
        return true;
    }
    if (Z_TYPE_P(stop) == IS_STRING) {
        if (Z_STRLEN_P(stop) > 0) {
            return true;
        }
        if (message != NULL) {
            *message = "Generation stop must not be an empty string.";
        }
        return false;
    }
    if (!king_inference_openai_array_is_list(stop)) {
        if (message != NULL) {
            *message = "Generation stop must be a string or an array of strings.";
        }
        return false;
    }
    if (zend_hash_num_elements(Z_ARRVAL_P(stop)) == 0 || zend_hash_num_elements(Z_ARRVAL_P(stop)) > 4) {
        if (message != NULL) {
            *message = "Generation stop arrays must contain between 1 and 4 strings.";
        }
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(stop), entry) {
        if (Z_TYPE_P(entry) != IS_STRING || Z_STRLEN_P(entry) == 0) {
            if (message != NULL) {
                *message = "Generation stop arrays must contain only non-empty strings.";
            }
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
}

static bool king_inference_openai_long_option_valid(
    zval *source,
    const char *key,
    bool positive,
    const char **message)
{
    zval *field = king_inference_array_find(source, key);

    if (field == NULL) {
        return true;
    }
    if (Z_TYPE_P(field) != IS_LONG) {
        if (message != NULL) {
            *message = "Generation integer options must be integers.";
        }
        return false;
    }
    if (positive && Z_LVAL_P(field) <= 0) {
        if (message != NULL) {
            *message = "Generation max token options must be positive integers.";
        }
        return false;
    }
    if (!positive && Z_LVAL_P(field) < 0) {
        if (message != NULL) {
            *message = "Generation top_k must be a non-negative integer.";
        }
        return false;
    }

    return true;
}

static bool king_inference_openai_integer_option_type_valid(
    zval *source,
    const char *key,
    const char **message)
{
    zval *field = king_inference_array_find(source, key);

    if (field == NULL) {
        return true;
    }
    if (Z_TYPE_P(field) == IS_LONG) {
        return true;
    }
    if (message != NULL) {
        *message = "Generation integer options must be integers.";
    }
    return false;
}

static bool king_inference_openai_numeric_option_value(
    zval *source,
    const char *key,
    bool *present,
    double *value,
    const char **message)
{
    zval *field = king_inference_array_find(source, key);

    *present = false;
    *value = 0.0;
    if (field == NULL) {
        return true;
    }
    if (Z_TYPE_P(field) == IS_DOUBLE) {
        *present = true;
        *value = Z_DVAL_P(field);
        if (!isfinite(*value)) {
            if (message != NULL) {
                *message = "Generation numeric options must be finite numbers.";
            }
            return false;
        }
        return true;
    }
    if (Z_TYPE_P(field) == IS_LONG) {
        *present = true;
        *value = (double) Z_LVAL_P(field);
        return true;
    }
    if (message != NULL) {
        *message = "Generation numeric options must be numbers.";
    }
    return false;
}

static bool king_inference_openai_generation_options_valid(
    zval *source,
    const char * const *max_token_keys,
    const char **message)
{
    size_t i;
    bool present;
    double value;

    if (message != NULL) {
        *message = NULL;
    }
    for (i = 0; max_token_keys[i] != NULL; i++) {
        if (!king_inference_openai_long_option_valid(source, max_token_keys[i], true, message)) {
            return false;
        }
    }
    if (!king_inference_openai_integer_option_type_valid(source, "seed", message)
        || !king_inference_openai_long_option_valid(source, "top_k", false, message)) {
        return false;
    }
    if (!king_inference_openai_numeric_option_value(source, "temperature", &present, &value, message)) {
        return false;
    }
    if (present && value < 0.0) {
        if (message != NULL) {
            *message = "Generation temperature must be non-negative.";
        }
        return false;
    }
    if (!king_inference_openai_numeric_option_value(source, "top_p", &present, &value, message)) {
        return false;
    }
    if (present && (value <= 0.0 || value > 1.0)) {
        if (message != NULL) {
            *message = "Generation top_p must be greater than zero and at most one.";
        }
        return false;
    }

    return true;
}

static bool king_inference_openai_chat_generation_options_valid(zval *source, const char **message)
{
    static const char * const max_token_keys[] = {
        "max_completion_tokens",
        "max_tokens",
        NULL
    };

    return king_inference_openai_generation_options_valid(source, max_token_keys, message);
}

static bool king_inference_openai_completion_generation_options_valid(zval *source, const char **message)
{
    static const char * const max_token_keys[] = {
        "max_tokens",
        NULL
    };

    return king_inference_openai_generation_options_valid(source, max_token_keys, message);
}

static bool king_inference_openai_response_generation_options_valid(zval *source, const char **message)
{
    static const char * const max_token_keys[] = {
        "max_output_tokens",
        "max_tokens",
        NULL
    };

    return king_inference_openai_generation_options_valid(source, max_token_keys, message);
}

static bool king_inference_openai_stream_generation_options_valid(zval *source, const char **message)
{
    static const char * const max_token_keys[] = {
        "max_tokens",
        NULL
    };

    return king_inference_openai_generation_options_valid(source, max_token_keys, message);
}

static bool king_inference_openai_stream_options_valid(zval *source, const char **message)
{
    zval *stream_options = king_inference_array_find(source, "stream_options");
    zval *include_usage;

    if (message != NULL) {
        *message = NULL;
    }
    if (stream_options == NULL) {
        return true;
    }
    if (!king_inference_openai_array_is_object(stream_options)) {
        if (message != NULL) {
            *message = "OpenAI stream_options must be an object when provided.";
        }
        return false;
    }

    include_usage = king_inference_array_find(stream_options, "include_usage");
    if (include_usage != NULL && Z_TYPE_P(include_usage) != IS_TRUE && Z_TYPE_P(include_usage) != IS_FALSE) {
        if (message != NULL) {
            *message = "OpenAI stream_options.include_usage must be a boolean when provided.";
        }
        return false;
    }

    return true;
}

static bool king_inference_openai_stream_field_valid(zval *source, const char **message)
{
    zval *stream = king_inference_array_find(source, "stream");

    if (message != NULL) {
        *message = NULL;
    }
    if (stream == NULL) {
        return true;
    }
    if (Z_TYPE_P(stream) == IS_TRUE || Z_TYPE_P(stream) == IS_FALSE) {
        return true;
    }
    if (message != NULL) {
        *message = "OpenAI stream must be a boolean when provided.";
    }
    return false;
}

static bool king_inference_openai_model_field_valid(zval *source, const char **message)
{
    zval *model = king_inference_array_find(source, "model");

    if (message != NULL) {
        *message = NULL;
    }
    if (model == NULL) {
        return true;
    }
    if (Z_TYPE_P(model) == IS_STRING && Z_STRLEN_P(model) > 0) {
        return true;
    }
    if (message != NULL) {
        *message = "OpenAI model must be a non-empty string when provided.";
    }
    return false;
}

static bool king_inference_openai_single_choice_option_valid(zval *source, const char **message)
{
    zval *choice_count = king_inference_array_find(source, "n");

    if (message != NULL) {
        *message = NULL;
    }
    if (choice_count == NULL) {
        return true;
    }
    if (Z_TYPE_P(choice_count) != IS_LONG || Z_LVAL_P(choice_count) <= 0) {
        if (message != NULL) {
            *message = "OpenAI n must be a positive integer when provided.";
        }
        return false;
    }
    if (Z_LVAL_P(choice_count) != 1) {
        if (message != NULL) {
            *message = "King OpenAI-compatible generation routes require n=1.";
        }
        return false;
    }

    return true;
}

static bool king_inference_openai_unsupported_feature_active(zval *field)
{
    if (field == NULL || Z_TYPE_P(field) == IS_NULL || Z_TYPE_P(field) == IS_FALSE) {
        return false;
    }
    if (Z_TYPE_P(field) == IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(field)) == 0) {
        return false;
    }
    if (Z_TYPE_P(field) == IS_STRING && Z_STRLEN_P(field) == 0) {
        return false;
    }
    if (Z_TYPE_P(field) == IS_LONG && Z_LVAL_P(field) == 0) {
        return false;
    }

    return true;
}

static bool king_inference_openai_no_unsupported_features(
    zval *source,
    const char * const *keys,
    const char **message)
{
    size_t i;

    if (message != NULL) {
        *message = NULL;
    }
    for (i = 0; keys[i] != NULL; i++) {
        if (king_inference_openai_unsupported_feature_active(king_inference_array_find(source, keys[i]))) {
            if (message != NULL) {
                *message = "OpenAI request uses a feature this King local inference route does not support.";
            }
            return false;
        }
    }

    return true;
}

static bool king_inference_openai_response_format_valid(zval *source, const char **message)
{
    zval *format = king_inference_array_find(source, "response_format");
    zval *type;

    if (message != NULL) {
        *message = NULL;
    }
    if (format == NULL || Z_TYPE_P(format) == IS_NULL) {
        return true;
    }
    if (!king_inference_openai_array_is_object(format)) {
        if (message != NULL) {
            *message = "OpenAI response_format must be an object when provided.";
        }
        return false;
    }

    type = king_inference_array_find(format, "type");
    if (type == NULL || Z_TYPE_P(type) != IS_STRING || Z_STRLEN_P(type) == 0) {
        if (message != NULL) {
            *message = "OpenAI response_format.type must be a non-empty string.";
        }
        return false;
    }
    if (zend_string_equals_literal(Z_STR_P(type), "text")) {
        return true;
    }
    if (zend_string_equals_literal(Z_STR_P(type), "json_object")) {
        return true;
    }

    if (message != NULL) {
        *message = "King local inference supports only response_format.type=text or json_object.";
    }
    return false;
}

static bool king_inference_openai_response_format_json_object_requested(zval *source)
{
    zval *format = king_inference_array_find(source, "response_format");
    zval *type;

    if (!king_inference_openai_array_is_object(format)) {
        return false;
    }

    type = king_inference_array_find(format, "type");
    return type != NULL
        && Z_TYPE_P(type) == IS_STRING
        && zend_string_equals_literal(Z_STR_P(type), "json_object");
}

static void king_inference_openai_append_response_format_instruction(zval *source, smart_str *buffer)
{
    if (!king_inference_openai_response_format_json_object_requested(source)) {
        return;
    }

    smart_str_appends(
        buffer,
        "Return only one valid JSON object. Do not wrap it in Markdown. Do not include prose before or after the JSON object.\n"
    );
}

static zend_result king_inference_openai_validate_json_object_output(
    zend_string *content,
    const char **message)
{
    zval decoded;

    if (message != NULL) {
        *message = NULL;
    }
    if (content == NULL || ZSTR_LEN(content) == 0) {
        if (message != NULL) {
            *message = "Model output was empty for response_format.type=json_object.";
        }
        return FAILURE;
    }

    ZVAL_UNDEF(&decoded);
    if (php_json_decode(&decoded, ZSTR_VAL(content), ZSTR_LEN(content), 0, 512) != SUCCESS) {
        if (EG(exception)) {
            zend_clear_exception();
        }
        king_set_error("");
        if (message != NULL) {
            *message = "Model output was not valid JSON for response_format.type=json_object.";
        }
        return FAILURE;
    }
    if (Z_TYPE(decoded) != IS_OBJECT) {
        zval_ptr_dtor(&decoded);
        if (message != NULL) {
            *message = "Model output was valid JSON but not a JSON object for response_format.type=json_object.";
        }
        return FAILURE;
    }

    zval_ptr_dtor(&decoded);
    return SUCCESS;
}

static zend_result king_inference_openai_validate_response_format_output(
    zval *request,
    zend_string *content,
    const char **message)
{
    if (message != NULL) {
        *message = NULL;
    }
    if (!king_inference_openai_response_format_json_object_requested(request)) {
        return SUCCESS;
    }

    return king_inference_openai_validate_json_object_output(content, message);
}

static bool king_inference_openai_response_instructions_valid(zval *source, const char **message)
{
    zval *instructions = king_inference_array_find(source, "instructions");

    if (message != NULL) {
        *message = NULL;
    }
    if (instructions == NULL || Z_TYPE_P(instructions) == IS_NULL) {
        return true;
    }
    if (Z_TYPE_P(instructions) == IS_STRING && Z_STRLEN_P(instructions) > 0) {
        return true;
    }
    if (message != NULL) {
        *message = "Responses instructions must be a non-empty string when provided.";
    }
    return false;
}

static bool king_inference_openai_chat_feature_options_valid(zval *source, const char **message)
{
    static const char * const unsupported_keys[] = {
        "modalities",
        "audio",
        "prediction",
        "logprobs",
        "top_logprobs",
        NULL
    };

    if (!king_inference_openai_response_format_valid(source, message)) {
        return false;
    }

    return king_inference_openai_no_unsupported_features(source, unsupported_keys, message);
}

static bool king_inference_openai_completion_feature_options_valid(zval *source, const char **message)
{
    static const char * const unsupported_keys[] = {
        "suffix",
        "echo",
        "best_of",
        "logprobs",
        NULL
    };

    return king_inference_openai_no_unsupported_features(source, unsupported_keys, message);
}

static bool king_inference_openai_response_feature_options_valid(zval *source, const char **message)
{
    static const char * const unsupported_keys[] = {
        "tools",
        "tool_choice",
        "parallel_tool_calls",
        "reasoning",
        "include",
        "previous_response_id",
        NULL
    };

    if (!king_inference_openai_response_format_valid(source, message)) {
        return false;
    }

    return king_inference_openai_no_unsupported_features(source, unsupported_keys, message);
}

static void king_inference_openai_copy_stop_option(zval *source, zval *target)
{
    zval *stop = king_inference_array_find(source, "stop");
    zval copy;

    if (stop == NULL || !king_inference_openai_stop_option_valid(source, NULL)) {
        return;
    }

    ZVAL_COPY(&copy, stop);
    add_assoc_zval(target, "stop", &copy);
}

static void king_inference_openai_copy_array_option(zval *source, zval *target, const char *key)
{
    zval *field = king_inference_array_find(source, key);
    zval copy;

    if (field == NULL || Z_TYPE_P(field) != IS_ARRAY) {
        return;
    }

    ZVAL_COPY(&copy, field);
    add_assoc_zval(target, key, &copy);
}

static void king_inference_openai_copy_response_format_option(zval *source, zval *target)
{
    zval *format = king_inference_array_find(source, "response_format");
    zval copy;

    if (format == NULL || Z_TYPE_P(format) != IS_ARRAY) {
        return;
    }

    ZVAL_COPY(&copy, format);
    add_assoc_zval(target, "response_format", &copy);
}

static void king_inference_openai_copy_native_graph_options(zval *source, zval *target)
{
    king_inference_openai_copy_array_option(source, target, "graph");
    king_inference_openai_copy_array_option(source, target, "graphs");
    king_inference_openai_copy_array_option(source, target, "graph_options");
}

static void king_inference_openai_copy_generation_options(
    zval *source,
    zval *target,
    const char * const *max_token_keys)
{
    zval *max_tokens = king_inference_openai_first_long_option(source, max_token_keys);

    if (max_tokens != NULL) {
        add_assoc_long(target, "max_tokens", Z_LVAL_P(max_tokens));
    }
    king_inference_openai_copy_numeric_option(source, target, "temperature");
    king_inference_openai_copy_numeric_option(source, target, "top_p");
    king_inference_openai_copy_long_option(source, target, "seed");
    king_inference_openai_copy_long_option(source, target, "top_k");
}

static void king_inference_openai_copy_chat_generation_options(zval *source, zval *target)
{
    static const char * const max_token_keys[] = {
        "max_completion_tokens",
        "max_tokens",
        NULL
    };

    king_inference_openai_copy_generation_options(source, target, max_token_keys);
    king_inference_openai_copy_stop_option(source, target);
    king_inference_openai_copy_response_format_option(source, target);
    king_inference_openai_copy_native_graph_options(source, target);
}

static void king_inference_openai_copy_completion_generation_options(zval *source, zval *target)
{
    static const char * const max_token_keys[] = {
        "max_tokens",
        NULL
    };

    king_inference_openai_copy_generation_options(source, target, max_token_keys);
    king_inference_openai_copy_stop_option(source, target);
    king_inference_openai_copy_native_graph_options(source, target);
}

static void king_inference_openai_copy_response_generation_options(zval *source, zval *target)
{
    static const char * const max_token_keys[] = {
        "max_output_tokens",
        "max_tokens",
        NULL
    };

    king_inference_openai_copy_generation_options(source, target, max_token_keys);
    king_inference_openai_copy_response_format_option(source, target);
    king_inference_openai_copy_native_graph_options(source, target);
}

static bool king_inference_openai_stream_usage_requested(zval *source)
{
    zval *stream_options = king_inference_array_find(source, "stream_options");
    zval *include_usage;

    if (stream_options == NULL || Z_TYPE_P(stream_options) != IS_ARRAY) {
        return false;
    }

    include_usage = king_inference_array_find(stream_options, "include_usage");
    return include_usage != NULL && zend_is_true(include_usage);
}
