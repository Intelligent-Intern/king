static zend_result king_inference_decode_graph_double_option(
    zval *options,
    const char *key,
    double fallback,
    double *value_out
) {
    zval *value = king_inference_array_find(options, key);

    *value_out = fallback;
    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) == IS_LONG) {
        *value_out = (double) Z_LVAL_P(value);
    } else if (Z_TYPE_P(value) == IS_DOUBLE) {
        *value_out = Z_DVAL_P(value);
    } else {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph option '%s' must be a finite number.", key);
        return FAILURE;
    }
    if (!isfinite(*value_out)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph option '%s' must be a finite number.", key);
        return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_inference_decode_graph_non_negative_long_option(
    zval *options,
    const char *key,
    zend_long fallback,
    zend_long *value_out
) {
    zval *value = king_inference_array_find(options, key);

    *value_out = fallback;
    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph option '%s' must be a non-negative integer.", key);
        return FAILURE;
    }

    *value_out = Z_LVAL_P(value);
    return SUCCESS;
}

static bool king_inference_decode_graph_bool_option(zval *options, const char *key, bool fallback)
{
    zval *value = king_inference_array_find(options, key);

    if (value == NULL) {
        return fallback;
    }
    return zend_is_true(value);
}

static zend_result king_inference_decode_graph_sampler_mode(
    zval *options,
    const char **mode_out,
    double *temperature_out
) {
    zval *sampler = king_inference_array_find(options, "sampler");
    double temperature = 0.7;

    *mode_out = "sample";
    *temperature_out = temperature;
    if (king_inference_decode_graph_double_option(options, "temperature", 0.7, &temperature) != SUCCESS) {
        return FAILURE;
    }
    if (temperature < 0.0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph temperature must be non-negative.");
        return FAILURE;
    }
    if (sampler != NULL) {
        if (Z_TYPE_P(sampler) != IS_STRING || Z_STRLEN_P(sampler) == 0) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph sampler must be 'argmax' or 'sample'.");
            return FAILURE;
        }
        if (zend_string_equals_literal(Z_STR_P(sampler), "argmax")) {
            *mode_out = "argmax";
            *temperature_out = 0.0;
            return SUCCESS;
        }
        if (!zend_string_equals_literal(Z_STR_P(sampler), "sample")) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "King inference token decode graph sampler '%s' is not supported; expected 'argmax' or 'sample'.",
                Z_STRVAL_P(sampler)
            );
            return FAILURE;
        }
    }

    *temperature_out = temperature;
    *mode_out = temperature == 0.0 ? "argmax" : "sample";
    return SUCCESS;
}

static zend_result king_inference_decode_graph_token_id_from_source(
    king_inference_model_object *model,
    zval *source,
    zend_ulong position,
    zend_ulong *token_id_out
) {
    zval *token = source;

    if (source != NULL && Z_TYPE_P(source) == IS_ARRAY) {
        zval *tokens = king_inference_array_find(source, "tokens");

        if (tokens == NULL) {
            tokens = source;
        }
        if (Z_TYPE_P(tokens) != IS_ARRAY) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph tokenizer output must contain a tokens array.");
            return FAILURE;
        }
        token = zend_hash_index_find(Z_ARRVAL_P(tokens), position);
        if (token == NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph tokenizer output does not contain tokens[%lu].", (unsigned long) position);
            return FAILURE;
        }
    }
    if (token == NULL || Z_TYPE_P(token) != IS_LONG || Z_LVAL_P(token) < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph token must be a non-negative integer or tokenizer output containing tokens[position].");
        return FAILURE;
    }
    if (model->gguf.tokenizer_token_count > 0
        && (zend_ulong) Z_LVAL_P(token) >= model->gguf.tokenizer_token_count) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph token id exceeds the loaded tokenizer vocabulary.");
        return FAILURE;
    }

    *token_id_out = (zend_ulong) Z_LVAL_P(token);
    return SUCCESS;
}
