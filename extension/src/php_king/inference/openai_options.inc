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
}

static void king_inference_openai_copy_completion_generation_options(zval *source, zval *target)
{
    static const char * const max_token_keys[] = {
        "max_tokens",
        NULL
    };

    king_inference_openai_copy_generation_options(source, target, max_token_keys);
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
