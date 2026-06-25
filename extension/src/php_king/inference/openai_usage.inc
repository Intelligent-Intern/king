static bool king_inference_openai_token_count_from_text(
    zval *model_value,
    zend_string *text,
    zend_long *count_out)
{
    king_inference_model_object *model;
    zval encoded;
    zval *count;

    *count_out = 0;
    if (text == NULL || ZSTR_LEN(text) == 0) {
        return true;
    }

    model = php_king_inference_model_obj_from_zend(Z_OBJ_P(model_value));
    if (king_inference_tokenizer_encode(model, text, &encoded) != SUCCESS) {
        if (EG(exception)) {
            zend_clear_exception();
        }
        king_set_error("");
        return false;
    }

    count = king_inference_array_find(&encoded, "token_count");
    if (count == NULL || Z_TYPE_P(count) != IS_LONG || Z_LVAL_P(count) < 0) {
        zval_ptr_dtor(&encoded);
        return false;
    }

    *count_out = Z_LVAL_P(count);
    zval_ptr_dtor(&encoded);
    return true;
}

static bool king_inference_openai_prompt_token_count(
    zval *model,
    zval *request,
    zend_long *prompt_tokens)
{
    zend_string *prompt = king_inference_king_local_prompt_from_request(request);
    bool counted;

    *prompt_tokens = 0;
    if (prompt == NULL) {
        if (EG(exception)) {
            zend_clear_exception();
        }
        king_set_error("");
        return false;
    }

    counted = king_inference_openai_token_count_from_text(model, prompt, prompt_tokens);
    zend_string_release(prompt);
    return counted;
}

static bool king_inference_openai_completion_usage_payload(
    zval *model,
    zval *request,
    zend_string *completion,
    zval *usage)
{
    zend_long prompt_tokens;
    zend_long completion_tokens;

    if (!king_inference_openai_prompt_token_count(model, request, &prompt_tokens)
        || !king_inference_openai_token_count_from_text(model, completion, &completion_tokens)) {
        return false;
    }

    array_init(usage);
    add_assoc_long(usage, "prompt_tokens", prompt_tokens);
    add_assoc_long(usage, "completion_tokens", completion_tokens);
    add_assoc_long(usage, "total_tokens", prompt_tokens + completion_tokens);
    return true;
}

static void king_inference_openai_add_completion_usage(
    zval *target,
    zval *model,
    zval *request,
    zend_string *completion)
{
    zval usage;

    if (king_inference_openai_completion_usage_payload(model, request, completion, &usage)) {
        add_assoc_zval(target, "usage", &usage);
        return;
    }

    add_assoc_null(target, "usage");
}

static bool king_inference_openai_response_usage_payload(
    zval *model,
    zval *request,
    zend_string *output,
    zval *usage)
{
    zend_long input_tokens;
    zend_long output_tokens;

    if (!king_inference_openai_prompt_token_count(model, request, &input_tokens)
        || !king_inference_openai_token_count_from_text(model, output, &output_tokens)) {
        return false;
    }

    array_init(usage);
    add_assoc_long(usage, "input_tokens", input_tokens);
    add_assoc_long(usage, "output_tokens", output_tokens);
    add_assoc_long(usage, "total_tokens", input_tokens + output_tokens);
    return true;
}

static void king_inference_openai_add_response_usage(
    zval *target,
    zval *model,
    zval *request,
    zend_string *output)
{
    zval usage;

    if (king_inference_openai_response_usage_payload(model, request, output, &usage)) {
        add_assoc_zval(target, "usage", &usage);
        return;
    }

    add_assoc_null(target, "usage");
}

static bool king_inference_openai_text_prompt_token_count(
    zval *model,
    zval *prompt,
    zend_long *prompt_tokens)
{
    zval *entry;
    zend_long total = 0;

    *prompt_tokens = 0;
    if (prompt == NULL) {
        return false;
    }
    if (Z_TYPE_P(prompt) == IS_STRING) {
        return king_inference_openai_token_count_from_text(model, Z_STR_P(prompt), prompt_tokens);
    }
    if (Z_TYPE_P(prompt) != IS_ARRAY || !zend_array_is_list(Z_ARRVAL_P(prompt))) {
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(prompt), entry) {
        zend_long count;
        if (Z_TYPE_P(entry) != IS_STRING
            || !king_inference_openai_token_count_from_text(model, Z_STR_P(entry), &count)) {
            return false;
        }
        total += count;
    } ZEND_HASH_FOREACH_END();

    *prompt_tokens = total;
    return true;
}

static bool king_inference_openai_choices_completion_token_count(
    zval *model,
    zval *choices,
    zend_long *completion_tokens)
{
    zval *choice;
    zend_long total = 0;

    *completion_tokens = 0;
    if (choices == NULL || Z_TYPE_P(choices) != IS_ARRAY) {
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(choices), choice) {
        zval *text;
        zend_long count;
        if (Z_TYPE_P(choice) != IS_ARRAY) {
            return false;
        }
        text = king_inference_array_find(choice, "text");
        if (text == NULL || Z_TYPE_P(text) != IS_STRING
            || !king_inference_openai_token_count_from_text(model, Z_STR_P(text), &count)) {
            return false;
        }
        total += count;
    } ZEND_HASH_FOREACH_END();

    *completion_tokens = total;
    return true;
}

static void king_inference_openai_add_text_completion_usage(
    zval *target,
    zval *model,
    zval *payload,
    zval *choices)
{
    zval *prompt = king_inference_array_find(payload, "prompt");
    zend_long prompt_tokens;
    zend_long completion_tokens;
    zval usage;

    if (!king_inference_openai_text_prompt_token_count(model, prompt, &prompt_tokens)
        || !king_inference_openai_choices_completion_token_count(model, choices, &completion_tokens)) {
        add_assoc_null(target, "usage");
        return;
    }

    array_init(&usage);
    add_assoc_long(&usage, "prompt_tokens", prompt_tokens);
    add_assoc_long(&usage, "completion_tokens", completion_tokens);
    add_assoc_long(&usage, "total_tokens", prompt_tokens + completion_tokens);
    add_assoc_zval(target, "usage", &usage);
}
