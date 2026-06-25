static bool king_inference_openai_array_is_list(zval *value)
{
    return value != NULL && Z_TYPE_P(value) == IS_ARRAY && zend_array_is_list(Z_ARRVAL_P(value));
}

static zend_result king_inference_openai_append_content_part_text(zval *part, smart_str *buffer)
{
    zval *text;

    if (part == NULL) {
        return FAILURE;
    }
    if (Z_TYPE_P(part) == IS_STRING) {
        smart_str_append(buffer, Z_STR_P(part));
        return SUCCESS;
    }
    if (Z_TYPE_P(part) != IS_ARRAY) {
        return FAILURE;
    }

    text = king_inference_array_find(part, "text");
    if (text == NULL) {
        text = king_inference_array_find(part, "content");
    }
    if (text == NULL) {
        text = king_inference_array_find(part, "refusal");
    }
    if (text == NULL || Z_TYPE_P(text) != IS_STRING) {
        return FAILURE;
    }

    smart_str_append(buffer, Z_STR_P(text));
    return SUCCESS;
}

static zend_result king_inference_openai_append_content_text(zval *content, smart_str *buffer)
{
    zval *part;
    bool has_text = false;

    if (content == NULL) {
        return FAILURE;
    }
    if (Z_TYPE_P(content) == IS_STRING) {
        smart_str_append(buffer, Z_STR_P(content));
        return SUCCESS;
    }
    if (!king_inference_openai_array_is_list(content)) {
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(content), part) {
        if (king_inference_openai_append_content_part_text(part, buffer) != SUCCESS) {
            return FAILURE;
        }
        has_text = true;
    } ZEND_HASH_FOREACH_END();

    return has_text ? SUCCESS : FAILURE;
}
