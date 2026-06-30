static bool king_inference_tokenizer_uses_sentencepiece(king_inference_model_object *model)
{
    zend_string *tokenizer = model->gguf.tokenizer_model;

    if (tokenizer == NULL) {
        return false;
    }

    return zend_string_equals_literal(tokenizer, "llama")
        || zend_string_equals_literal(tokenizer, "sentencepiece")
        || zend_string_equals_literal(tokenizer, "spm")
        || zend_string_equals_literal(tokenizer, "gemma");
}

static bool king_inference_tokenizer_control_token_at(
    king_inference_model_object *model,
    const char *input,
    size_t length,
    size_t offset,
    size_t *token_length
) {
    static const char *controls[] = {
        "<|turn>",
        "<turn|>",
        "<start_of_turn>",
        "<end_of_turn>",
        "<bos>",
        "<eos>",
        "<pad>",
        "<unk>"
    };
    size_t index;

    if (token_length != NULL) {
        *token_length = 0;
    }
    if (Z_TYPE(model->tokenizer_lookup) != IS_ARRAY || offset >= length || input[offset] != '<') {
        return false;
    }
    for (index = 0; index < sizeof(controls) / sizeof(controls[0]); index++) {
        size_t control_length = strlen(controls[index]);

        if (offset + control_length <= length
            && memcmp(input + offset, controls[index], control_length) == 0
            && zend_hash_str_exists(Z_ARRVAL(model->tokenizer_lookup), controls[index], control_length)) {
            if (token_length != NULL) {
                *token_length = control_length;
            }
            return true;
        }
    }
    return false;
}

static size_t king_inference_tokenizer_next_control_token_offset(
    king_inference_model_object *model,
    const char *input,
    size_t length,
    size_t offset
) {
    size_t i;

    if (offset >= length) {
        return length;
    }
    for (i = offset; i < length; i++) {
        if (king_inference_tokenizer_control_token_at(model, input, length, i, NULL)) {
            return i;
        }
    }
    return length;
}

static zend_string *king_inference_tokenizer_normalize_text(
    king_inference_model_object *model,
    zend_string *text,
    const char **normalization
) {
    smart_str normalized = {0};
    const char *input = ZSTR_VAL(text);
    size_t length = ZSTR_LEN(text);
    size_t i;

    if (!king_inference_tokenizer_uses_sentencepiece(model)) {
        *normalization = "raw";
        return zend_string_copy(text);
    }

    *normalization = "sentencepiece_space";
    if (length > 0 && !king_inference_tokenizer_control_token_at(model, input, length, 0, NULL)
        && !(length >= 3
            && (unsigned char) input[0] == 0xE2
            && (unsigned char) input[1] == 0x96
            && (unsigned char) input[2] == 0x81)) {
        smart_str_appendl(&normalized, "\xE2\x96\x81", 3);
    }

    for (i = 0; i < length; i++) {
        size_t control_length = 0;

        if (king_inference_tokenizer_control_token_at(model, input, length, i, &control_length)) {
            smart_str_appendl(&normalized, input + i, control_length);
            i += control_length - 1;
        } else if (input[i] == ' ') {
            smart_str_appendl(&normalized, "\xE2\x96\x81", 3);
        } else {
            smart_str_appendc(&normalized, input[i]);
        }
    }

    smart_str_0(&normalized);
    return normalized.s != NULL ? normalized.s : zend_string_init("", 0, 0);
}

static bool king_inference_tokenizer_add_byte_fallback(
    king_inference_model_object *model,
    unsigned char byte,
    zval *token_ids
) {
    char fallback[7];
    zval *id;

    snprintf(fallback, sizeof(fallback), "<0x%02X>", byte);
    id = zend_hash_str_find(Z_ARRVAL(model->tokenizer_lookup), fallback, strlen(fallback));
    if (id == NULL || Z_TYPE_P(id) != IS_LONG) {
        return false;
    }

    add_next_index_long(token_ids, Z_LVAL_P(id));
    return true;
}

static zend_result king_inference_tokenizer_encode(
    king_inference_model_object *model,
    zend_string *text,
    zval *return_value
) {
    zend_string *normalized;
    const char *normalization;
    const char *bytes;
    size_t length;
    size_t position = 0;
    zend_long unknown_count = 0;
    zend_long token_count;
    zval token_ids;

    if (Z_TYPE(model->tokenizer_lookup) != IS_ARRAY
        || zend_hash_num_elements(Z_ARRVAL(model->tokenizer_lookup)) == 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King native tokenizer has no loaded token lookup for model '%s'.",
            ZSTR_VAL(model->name)
        );
        return FAILURE;
    }

    normalized = king_inference_tokenizer_normalize_text(model, text, &normalization);
    bytes = ZSTR_VAL(normalized);
    length = ZSTR_LEN(normalized);

    array_init(&token_ids);
    while (position < length) {
        size_t control_length = 0;
        size_t next_control;
        size_t remaining;
        size_t try_len = model->gguf.tokenizer_max_token_bytes > 0
            ? (size_t) model->gguf.tokenizer_max_token_bytes
            : length - position;
        bool matched = false;

        if (king_inference_tokenizer_control_token_at(model, bytes, length, position, &control_length)) {
            zval *id = zend_hash_str_find(Z_ARRVAL(model->tokenizer_lookup), bytes + position, control_length);

            if (id != NULL && Z_TYPE_P(id) == IS_LONG) {
                add_next_index_long(&token_ids, Z_LVAL_P(id));
                position += control_length;
                continue;
            }
        }

        next_control = king_inference_tokenizer_next_control_token_offset(model, bytes, length, position + 1);
        remaining = next_control > position ? next_control - position : length - position;
        if (try_len > remaining) {
            try_len = remaining;
        }
        while (try_len > 0) {
            zval *id = zend_hash_str_find(
                Z_ARRVAL(model->tokenizer_lookup),
                bytes + position,
                try_len
            );
            if (id != NULL && Z_TYPE_P(id) == IS_LONG) {
                add_next_index_long(&token_ids, Z_LVAL_P(id));
                position += try_len;
                matched = true;
                break;
            }
            try_len--;
        }

        if (matched) {
            continue;
        }

        if (king_inference_tokenizer_add_byte_fallback(model, (unsigned char) bytes[position], &token_ids)) {
            position++;
            continue;
        }

        if (model->gguf.tokenizer_unknown_id >= 0) {
            add_next_index_long(&token_ids, model->gguf.tokenizer_unknown_id);
            unknown_count++;
            position++;
            continue;
        }

        zend_string_release(normalized);
        zval_ptr_dtor(&token_ids);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King native tokenizer could not encode byte 0x%02X and the model has no unknown token id.",
            (unsigned int) (unsigned char) bytes[position]
        );
        return FAILURE;
    }

    token_count = (zend_long) zend_hash_num_elements(Z_ARRVAL(token_ids));
    array_init(return_value);
    add_assoc_long(return_value, "token_count", token_count);
    add_assoc_zval(return_value, "tokens", &token_ids);
    add_assoc_long(return_value, "unknown_count", unknown_count);
    add_assoc_long(return_value, "input_bytes", (zend_long) ZSTR_LEN(text));
    add_assoc_long(return_value, "normalized_bytes", (zend_long) length);
    add_assoc_string(return_value, "normalization", normalization);
    if (model->gguf.tokenizer_model != NULL) {
        add_assoc_str(return_value, "tokenizer_model", zend_string_copy(model->gguf.tokenizer_model));
    }
    add_assoc_long(return_value, "bos_token_id", model->gguf.tokenizer_bos_id);
    add_assoc_long(return_value, "eos_token_id", model->gguf.tokenizer_eos_id);

    zend_string_release(normalized);
    return SUCCESS;
}

static bool king_inference_tokenizer_hex_nibble(char c, unsigned char *value)
{
    if (c >= '0' && c <= '9') {
        *value = (unsigned char) (c - '0');
        return true;
    }
    if (c >= 'A' && c <= 'F') {
        *value = (unsigned char) (c - 'A' + 10);
        return true;
    }
    if (c >= 'a' && c <= 'f') {
        *value = (unsigned char) (c - 'a' + 10);
        return true;
    }

    return false;
}

static zend_string *king_inference_tokenizer_decode_token(
    king_inference_model_object *model,
    zend_ulong token_id
) {
    zval *token = zend_hash_index_find(Z_ARRVAL(model->tokenizer_tokens), token_id);
    smart_str decoded = {0};
    const char *bytes;
    size_t length;
    size_t i = 0;

    if (token == NULL || Z_TYPE_P(token) != IS_STRING) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King native tokenizer cannot decode token id %lu for model '%s'.",
            token_id,
            ZSTR_VAL(model->name)
        );
        return NULL;
    }

    bytes = Z_STRVAL_P(token);
    length = Z_STRLEN_P(token);
    if (length == 6
        && bytes[0] == '<'
        && bytes[1] == '0'
        && bytes[2] == 'x'
        && bytes[5] == '>') {
        unsigned char high;
        unsigned char low;
        if (king_inference_tokenizer_hex_nibble(bytes[3], &high)
            && king_inference_tokenizer_hex_nibble(bytes[4], &low)) {
            char value = (char) ((high << 4) | low);
            return zend_string_init(&value, 1, 0);
        }
    }

    while (i < length) {
        if (i + 3 <= length
            && (unsigned char) bytes[i] == 0xE2
            && (unsigned char) bytes[i + 1] == 0x96
            && (unsigned char) bytes[i + 2] == 0x81) {
            smart_str_appendc(&decoded, ' ');
            i += 3;
            continue;
        }
        smart_str_appendc(&decoded, bytes[i]);
        i++;
    }

    smart_str_0(&decoded);
    return decoded.s != NULL ? decoded.s : zend_string_init("", 0, 0);
}
