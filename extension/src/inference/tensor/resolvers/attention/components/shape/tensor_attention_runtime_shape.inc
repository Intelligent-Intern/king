static bool king_inference_attention_tensor_rows(
    king_inference_model_object *model,
    zend_string *name,
    zend_ulong *rows_out
) {
    zval *descriptor;
    zval *dimensions;
    zend_ulong rank;
    zend_ulong rows;

    *rows_out = 0;
    if (name == NULL) {
        return false;
    }
    descriptor = king_inference_tensor_index_descriptor(model, name);
    dimensions = descriptor != NULL
        ? zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1)
        : NULL;
    if (descriptor == NULL
        || dimensions == NULL
        || Z_TYPE_P(dimensions) != IS_ARRAY
        || !king_inference_tensor_descriptor_rank(descriptor, &rank)
        || rank != 2
        || king_inference_tensor_dimension_at(dimensions, 1, &rows) != SUCCESS
        || rows == 0) {
        return false;
    }

    *rows_out = rows;
    return true;
}

static bool king_inference_attention_refine_runtime_shape_from_tensors_for_layer(
    king_inference_model_object *model,
    zend_ulong layer,
    zend_ulong *heads,
    zend_ulong *kv_heads,
    zend_ulong *key_length,
    zend_ulong *value_length
) {
    zend_string *attn_q = NULL;
    zend_string *attn_k = NULL;
    zend_string *attn_v = NULL;
    const char *status = NULL;
    zend_ulong query_rows = 0;
    zend_ulong key_rows = 0;
    zend_ulong value_rows = 0;
    zend_ulong derived_key_length;
    zend_ulong derived_kv_heads;

    if (*heads == 0
        || king_inference_resolve_attention_tensor_name(model, KING_INFERENCE_ATTENTION_QUERY, layer, &attn_q, NULL, &status) != SUCCESS
        || king_inference_resolve_attention_tensor_name(model, KING_INFERENCE_ATTENTION_KEY, layer, &attn_k, NULL, &status) != SUCCESS
        || king_inference_resolve_attention_tensor_name(model, KING_INFERENCE_ATTENTION_VALUE, layer, &attn_v, NULL, &status) != SUCCESS
        || !king_inference_attention_tensor_rows(model, attn_q, &query_rows)
        || !king_inference_attention_tensor_rows(model, attn_k, &key_rows)
        || !king_inference_attention_tensor_rows(model, attn_v, &value_rows)) {
        if (attn_q != NULL) {
            zend_string_release(attn_q);
        }
        if (attn_k != NULL) {
            zend_string_release(attn_k);
        }
        if (attn_v != NULL) {
            zend_string_release(attn_v);
        }
        return false;
    }

    if (query_rows >= *heads && (query_rows % *heads) == 0) {
        derived_key_length = query_rows / *heads;
        if (derived_key_length > 0) {
            *key_length = derived_key_length;
        }
    }
    if (*key_length > 0 && key_rows >= *key_length && (key_rows % *key_length) == 0) {
        derived_kv_heads = key_rows / *key_length;
        if (derived_kv_heads > 0 && derived_kv_heads <= *heads) {
            *kv_heads = derived_kv_heads;
        }
    }
    if (*kv_heads > 0 && value_rows >= *kv_heads && (value_rows % *kv_heads) == 0) {
        *value_length = value_rows / *kv_heads;
    }

    zend_string_release(attn_q);
    zend_string_release(attn_k);
    zend_string_release(attn_v);
    return true;
}

static void king_inference_attention_refine_runtime_shape_from_tensors(
    king_inference_model_object *model,
    zend_ulong *heads,
    zend_ulong *kv_heads,
    zend_ulong *key_length,
    zend_ulong *value_length
) {
    king_inference_attention_refine_runtime_shape_from_tensors_for_layer(
        model,
        0,
        heads,
        kv_heads,
        key_length,
        value_length
    );
}

static void king_inference_attention_refine_runtime_cache_shape_from_tensors(
    king_inference_model_object *model,
    zend_ulong layers,
    zend_ulong *heads,
    zend_ulong *kv_heads,
    zend_ulong *key_length,
    zend_ulong *value_length
) {
    zend_ulong layer;
    zend_ulong max_heads = 0;
    zend_ulong max_kv_heads = 0;
    zend_ulong max_key_length = 0;
    zend_ulong max_value_length = 0;
    bool found_layer_shape = false;

    for (layer = 0; layer < layers; layer++) {
        zend_ulong layer_heads = *heads;
        zend_ulong layer_kv_heads = *kv_heads;
        zend_ulong layer_key_length = *key_length;
        zend_ulong layer_value_length = *value_length;

        if (!king_inference_attention_refine_runtime_shape_from_tensors_for_layer(
            model,
            layer,
            &layer_heads,
            &layer_kv_heads,
            &layer_key_length,
            &layer_value_length
        )) {
            continue;
        }
        found_layer_shape = true;
        if (layer_heads > max_heads) {
            max_heads = layer_heads;
        }
        if (layer_kv_heads > max_kv_heads) {
            max_kv_heads = layer_kv_heads;
        }
        if (layer_key_length > max_key_length) {
            max_key_length = layer_key_length;
        }
        if (layer_value_length > max_value_length) {
            max_value_length = layer_value_length;
        }
    }

    if (found_layer_shape) {
        *heads = max_heads;
        *kv_heads = max_kv_heads;
        *key_length = max_key_length;
        *value_length = max_value_length;
    }
}

