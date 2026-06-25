#define KING_INFERENCE_GRAPH_DEFAULT_KV_CACHE "default"

static zend_result king_inference_graph_state_init(zval *graph, zval *state)
{
    zval *source = king_inference_array_find(graph, "state");

    if (source == NULL) {
        array_init(state);
        return SUCCESS;
    }
    if (Z_TYPE_P(source) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph state must be an array.");
        return FAILURE;
    }

    ZVAL_COPY(state, source);
    SEPARATE_ARRAY(state);
    return SUCCESS;
}

static zend_result king_inference_graph_kv_slot(zval *op, zend_ulong *slot)
{
    zval *field = king_inference_array_find(op, "slot");

    if (field == NULL || Z_TYPE_P(field) != IS_LONG || Z_LVAL_P(field) < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV operation requires non-negative integer slot.");
        return FAILURE;
    }

    *slot = (zend_ulong) Z_LVAL_P(field);
    return SUCCESS;
}

static zend_result king_inference_graph_kv_optional_ulong(
    zval *op,
    const char *key,
    zend_ulong fallback,
    zend_ulong *value
) {
    zval *field = king_inference_array_find(op, key);

    if (field == NULL) {
        *value = fallback;
        return SUCCESS;
    }
    if (Z_TYPE_P(field) != IS_LONG || Z_LVAL_P(field) < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV field '%s' must be a non-negative integer.", key);
        return FAILURE;
    }

    *value = (zend_ulong) Z_LVAL_P(field);
    return SUCCESS;
}

static zend_result king_inference_graph_kv_positive_ulong(
    zval *op,
    const char *primary,
    const char *alternate,
    zend_ulong *value
) {
    zval *field = king_inference_array_find(op, primary);

    if (field == NULL && alternate != NULL) {
        field = king_inference_array_find(op, alternate);
    }
    if (field == NULL || Z_TYPE_P(field) != IS_LONG || Z_LVAL_P(field) <= 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV field '%s' must be a positive integer.", primary);
        return FAILURE;
    }

    *value = (zend_ulong) Z_LVAL_P(field);
    return SUCCESS;
}

static zend_result king_inference_graph_kv_range(
    zval *op,
    zend_ulong *slot_start,
    zend_ulong *slot_count
) {
    if (king_inference_graph_kv_optional_ulong(op, "slot_start", 0, slot_start) != SUCCESS
        || king_inference_graph_kv_positive_ulong(op, "slot_count", "count", slot_count) != SUCCESS) {
        return FAILURE;
    }
    if (*slot_start > ZEND_ULONG_MAX - (*slot_count - 1)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV range exceeds supported slot limits.");
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_graph_kv_kind(zval *op, zend_string **kind)
{
    if (king_inference_graph_required_string(op, "kind", kind) != SUCCESS) {
        return FAILURE;
    }
    if (!zend_string_equals_literal(*kind, "key") && !zend_string_equals_literal(*kind, "value")) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV kind must be 'key' or 'value'.");
        return FAILURE;
    }

    return SUCCESS;
}

static zend_string *king_inference_graph_kv_cache_name(zval *op, bool *owned)
{
    zval *field = king_inference_array_find(op, "cache");

    *owned = false;
    if (field == NULL) {
        *owned = true;
        return zend_string_init(KING_INFERENCE_GRAPH_DEFAULT_KV_CACHE, sizeof(KING_INFERENCE_GRAPH_DEFAULT_KV_CACHE) - 1, 0);
    }
    if (Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV cache must be a non-empty string.");
        return NULL;
    }

    return Z_STR_P(field);
}

static zend_string *king_inference_graph_kv_entry_key(zend_string *cache, zend_ulong slot, const char *kind)
{
    return strpprintf(0, "%s/%lu/%s", ZSTR_VAL(cache), slot, kind);
}

static zval *king_inference_graph_kv_container(zval *state)
{
    zval *container = king_inference_array_find(state, "kv_cache");
    zval fresh;

    if (container != NULL && Z_TYPE_P(container) == IS_ARRAY) {
        SEPARATE_ARRAY(container);
        return container;
    }
    if (container != NULL) {
        zend_hash_str_del(Z_ARRVAL_P(state), "kv_cache", sizeof("kv_cache") - 1);
    }

    array_init(&fresh);
    zend_hash_str_update(Z_ARRVAL_P(state), "kv_cache", sizeof("kv_cache") - 1, &fresh);
    return king_inference_array_find(state, "kv_cache");
}

static void king_inference_graph_kv_write_payload(
    zval *container,
    zend_string *cache,
    zend_ulong slot,
    const char *kind,
    king_inference_graph_vector *vector
) {
    zend_string *entry_key = king_inference_graph_kv_entry_key(cache, slot, kind);
    zval payload;
    zval values;
    zend_ulong i;

    array_init(&payload);
    add_assoc_str(&payload, "cache", zend_string_copy(cache));
    add_assoc_long(&payload, "slot", (zend_long) slot);
    add_assoc_string(&payload, "kind", kind);
    add_assoc_long(&payload, "length", (zend_long) vector->length);
    array_init(&values);
    for (i = 0; i < vector->length; i++) {
        add_next_index_double(&values, vector->values[i]);
    }
    add_assoc_zval(&payload, "values", &values);
    zend_hash_update(Z_ARRVAL_P(container), entry_key, &payload);
    zend_string_release(entry_key);
}

static zend_result king_inference_graph_kv_read_payload(
    zval *state,
    zend_string *cache,
    zend_ulong slot,
    const char *kind,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    zval *container = king_inference_array_find(state, "kv_cache");
    zend_string *entry_key;
    zval *payload;
    zval *values;

    *values_out = NULL;
    *length_out = 0;
    if (container == NULL || Z_TYPE_P(container) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV state has no kv_cache entries.");
        return FAILURE;
    }

    entry_key = king_inference_graph_kv_entry_key(cache, slot, kind);
    payload = zend_hash_find(Z_ARRVAL_P(container), entry_key);
    zend_string_release(entry_key);
    if (payload == NULL || Z_TYPE_P(payload) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV state entry was not found.");
        return FAILURE;
    }

    values = king_inference_array_find(payload, "values");
    if (values == NULL || Z_TYPE_P(values) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV state entry has invalid values.");
        return FAILURE;
    }

    return king_inference_graph_vector_from_zval(values, max_values, values_out, length_out);
}

static zend_result king_inference_graph_optional_vector(
    king_inference_graph_vector *vectors,
    zval *op,
    const char *field_name,
    king_inference_graph_vector **vector_out,
    bool *present
) {
    zval *field = king_inference_array_find(op, field_name);

    *vector_out = NULL;
    *present = false;
    if (field == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV vector field '%s' must be a vector name.", field_name);
        return FAILURE;
    }
    *vector_out = king_inference_graph_vector_find(vectors, Z_STR_P(field));
    if (*vector_out == NULL) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph KV vector '%s' was not produced.", Z_STRVAL_P(field));
        return FAILURE;
    }
    *present = true;
    return SUCCESS;
}

static zend_result king_inference_graph_op_kv_read(
    zval *op,
    zval *state,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    zend_ulong slot;
    zend_string *kind;
    zend_string *cache;
    bool cache_owned;
    zend_result status;

    if (king_inference_graph_kv_slot(op, &slot) != SUCCESS
        || king_inference_graph_kv_kind(op, &kind) != SUCCESS) {
        return FAILURE;
    }
    cache = king_inference_graph_kv_cache_name(op, &cache_owned);
    if (cache == NULL) {
        return FAILURE;
    }

    status = king_inference_graph_kv_read_payload(state, cache, slot, ZSTR_VAL(kind), max_values, values_out, length_out);
    if (cache_owned) {
        zend_string_release(cache);
    }
    return status;
}

static zend_result king_inference_graph_op_kv_write(
    zval *op,
    king_inference_graph_vector *vectors,
    zval *state,
    double **values_out,
    zend_ulong *length_out
) {
    zend_ulong slot;
    zend_string *cache;
    bool cache_owned;
    king_inference_graph_vector *key_vector = NULL;
    king_inference_graph_vector *value_vector = NULL;
    bool has_key = false;
    bool has_value = false;
    double *marker;
    zval *container;

    if (king_inference_graph_kv_slot(op, &slot) != SUCCESS
        || king_inference_graph_optional_vector(vectors, op, "key", &key_vector, &has_key) != SUCCESS
        || king_inference_graph_optional_vector(vectors, op, "value", &value_vector, &has_value) != SUCCESS) {
        return FAILURE;
    }
    if (!has_key && !has_value) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph kv_write requires key or value vector.");
        return FAILURE;
    }

    cache = king_inference_graph_kv_cache_name(op, &cache_owned);
    if (cache == NULL) {
        return FAILURE;
    }
    container = king_inference_graph_kv_container(state);
    if (has_key) {
        king_inference_graph_kv_write_payload(container, cache, slot, "key", key_vector);
    }
    if (has_value) {
        king_inference_graph_kv_write_payload(container, cache, slot, "value", value_vector);
    }
    if (cache_owned) {
        zend_string_release(cache);
    }

    marker = ecalloc(3, sizeof(double));
    marker[0] = (double) slot;
    marker[1] = has_key ? 1.0 : 0.0;
    marker[2] = has_value ? 1.0 : 0.0;
    *values_out = marker;
    *length_out = 3;
    return SUCCESS;
}

static zend_result king_inference_graph_op_kv_attention(
    zval *op,
    king_inference_graph_vector *vectors,
    zval *state,
    zend_ulong max_values,
    zend_ulong max_operations,
    double **values_out,
    zend_ulong *length_out
) {
    king_inference_graph_vector *query;
    zend_string *cache;
    bool cache_owned;
    zend_ulong slot_start;
    zend_ulong slot_count;
    zend_ulong value_width = 0;
    zend_ulong i;
    double temperature = king_inference_graph_double_option(op, "temperature", 1.0);
    double scale = king_inference_graph_double_option(op, "scale", 1.0);
    double max_score = 0.0;
    double probability_sum = 0.0;
    double *scores = NULL;
    double *context = NULL;

    *values_out = NULL;
    *length_out = 0;
    if (king_inference_graph_lookup_vector(vectors, op, "query", &query) != SUCCESS
        || king_inference_graph_kv_range(op, &slot_start, &slot_count) != SUCCESS) {
        return FAILURE;
    }
    if (temperature <= 0.0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph kv_attention temperature must be positive.");
        return FAILURE;
    }
    if (query->length == 0
        || query->length > max_values
        || slot_count > max_values
        || slot_count > (zend_ulong) (SIZE_MAX / sizeof(double))) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph kv_attention query or slot_count exceeds configured limits.");
        return FAILURE;
    }
    if (query->length > max_operations / slot_count) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph kv_attention key scoring exceeds configured operation limits.");
        return FAILURE;
    }

    cache = king_inference_graph_kv_cache_name(op, &cache_owned);
    if (cache == NULL) {
        return FAILURE;
    }

    scores = ecalloc((size_t) slot_count, sizeof(double));
    for (i = 0; i < slot_count; i++) {
        double *key_values = NULL;
        zend_ulong key_length = 0;
        zend_ulong dim;
        double score = 0.0;

        if (king_inference_graph_kv_read_payload(
            state,
            cache,
            slot_start + i,
            "key",
            max_values,
            &key_values,
            &key_length
        ) != SUCCESS) {
            efree(scores);
            if (cache_owned) {
                zend_string_release(cache);
            }
            return FAILURE;
        }
        if (key_length != query->length) {
            efree(key_values);
            efree(scores);
            if (cache_owned) {
                zend_string_release(cache);
            }
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph kv_attention key length must match query length.");
            return FAILURE;
        }
        for (dim = 0; dim < query->length; dim++) {
            score += query->values[dim] * key_values[dim];
        }
        efree(key_values);
        scores[i] = score * scale / temperature;
        if (i == 0 || scores[i] > max_score) {
            max_score = scores[i];
        }
    }

    for (i = 0; i < slot_count; i++) {
        scores[i] = king_inference_graph_exp(scores[i] - max_score);
        probability_sum += scores[i];
    }
    if (probability_sum <= 0.0) {
        efree(scores);
        if (cache_owned) {
            zend_string_release(cache);
        }
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference graph kv_attention produced an invalid probability sum.");
        return FAILURE;
    }
    for (i = 0; i < slot_count; i++) {
        scores[i] /= probability_sum;
    }

    for (i = 0; i < slot_count; i++) {
        double *value_values = NULL;
        zend_ulong value_length = 0;
        zend_ulong dim;

        if (king_inference_graph_kv_read_payload(
            state,
            cache,
            slot_start + i,
            "value",
            max_values,
            &value_values,
            &value_length
        ) != SUCCESS) {
            if (context != NULL) {
                efree(context);
            }
            efree(scores);
            if (cache_owned) {
                zend_string_release(cache);
            }
            return FAILURE;
        }
        if (value_length == 0 || value_length > max_values) {
            efree(value_values);
            if (context != NULL) {
                efree(context);
            }
            efree(scores);
            if (cache_owned) {
                zend_string_release(cache);
            }
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph kv_attention value length exceeds configured limits.");
            return FAILURE;
        }
        if (value_width == 0) {
            value_width = value_length;
            if (query->length > ZEND_ULONG_MAX - value_width
                || value_width > (zend_ulong) (SIZE_MAX / sizeof(double))
                || slot_count > max_operations / (query->length + value_width)) {
                efree(value_values);
                efree(scores);
                if (cache_owned) {
                    zend_string_release(cache);
                }
                zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph kv_attention exceeds configured operation limits.");
                return FAILURE;
            }
            context = ecalloc((size_t) value_width, sizeof(double));
        } else if (value_width != value_length) {
            efree(value_values);
            efree(context);
            efree(scores);
            if (cache_owned) {
                zend_string_release(cache);
            }
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph kv_attention value lengths must match.");
            return FAILURE;
        }

        for (dim = 0; dim < value_width; dim++) {
            context[dim] += scores[i] * value_values[dim];
        }
        efree(value_values);
    }

    efree(scores);
    if (cache_owned) {
        zend_string_release(cache);
    }

    *values_out = context;
    *length_out = value_width;
    return SUCCESS;
}
