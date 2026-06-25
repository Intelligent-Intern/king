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
            *message = "OpenAI stop must not be an empty string.";
        }
        return false;
    }
    if (!king_inference_openai_array_is_list(stop)) {
        if (message != NULL) {
            *message = "OpenAI stop must be a string or an array of strings.";
        }
        return false;
    }
    if (zend_hash_num_elements(Z_ARRVAL_P(stop)) == 0 || zend_hash_num_elements(Z_ARRVAL_P(stop)) > 4) {
        if (message != NULL) {
            *message = "OpenAI stop arrays must contain between 1 and 4 strings.";
        }
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(stop), entry) {
        if (Z_TYPE_P(entry) != IS_STRING || Z_STRLEN_P(entry) == 0) {
            if (message != NULL) {
                *message = "OpenAI stop arrays must contain only non-empty strings.";
            }
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
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
}

static void king_inference_openai_copy_completion_generation_options(zval *source, zval *target)
{
    static const char * const max_token_keys[] = {
        "max_tokens",
        NULL
    };

    king_inference_openai_copy_generation_options(source, target, max_token_keys);
    king_inference_openai_copy_stop_option(source, target);
}

static void king_inference_openai_copy_response_generation_options(zval *source, zval *target)
{
    static const char * const max_token_keys[] = {
        "max_output_tokens",
        "max_tokens",
        NULL
    };

    king_inference_openai_copy_generation_options(source, target, max_token_keys);
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
