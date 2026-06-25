#define KING_INFERENCE_DEFAULT_KV_PAGE_TOKENS 16
#define KING_INFERENCE_DEFAULT_KV_ELEMENT_BYTES 2

static zval *king_inference_paged_kv_config(king_inference_model_object *model)
{
    zval *config = king_inference_array_find(&model->config, "paged_attention");

    if (config != NULL && Z_TYPE_P(config) == IS_ARRAY) {
        return config;
    }

    config = king_inference_array_find(&model->config, "kv_cache");
    return config != NULL && Z_TYPE_P(config) == IS_ARRAY ? config : NULL;
}

static zend_ulong king_inference_paged_kv_positive_long(zval *array, const char *key, zend_ulong fallback)
{
    zval *value = king_inference_array_find(array, key);

    return value != NULL && Z_TYPE_P(value) == IS_LONG && Z_LVAL_P(value) > 0
        ? (zend_ulong) Z_LVAL_P(value)
        : fallback;
}

static bool king_inference_paged_kv_mul(zend_ulong a, zend_ulong b, zend_ulong *result)
{
    if (a != 0 && b > ZEND_ULONG_MAX / a) {
        *result = 0;
        return false;
    }

    *result = a * b;
    return true;
}

static bool king_inference_paged_kv_add(zend_ulong a, zend_ulong b, zend_ulong *result)
{
    if (b > ZEND_ULONG_MAX - a) {
        *result = 0;
        return false;
    }

    *result = a + b;
    return true;
}

static bool king_inference_paged_kv_page_bytes(
    zend_ulong page_tokens,
    zend_ulong kv_heads,
    zend_ulong key_dim,
    zend_ulong value_dim,
    zend_ulong element_bytes,
    zend_ulong *page_bytes_per_layer
) {
    zend_ulong key_units;
    zend_ulong value_units;

    if (!king_inference_paged_kv_mul(page_tokens, kv_heads, &key_units)
        || !king_inference_paged_kv_mul(key_units, key_dim, &key_units)
        || !king_inference_paged_kv_mul(key_units, element_bytes, &key_units)
        || !king_inference_paged_kv_mul(page_tokens, kv_heads, &value_units)
        || !king_inference_paged_kv_mul(value_units, value_dim, &value_units)
        || !king_inference_paged_kv_mul(value_units, element_bytes, &value_units)
        || !king_inference_paged_kv_add(key_units, value_units, page_bytes_per_layer)) {
        *page_bytes_per_layer = 0;
        return false;
    }

    return true;
}

static void king_inference_paged_kv_missing_fields(
    king_inference_model_object *model,
    zend_ulong max_context_tokens,
    zend_ulong key_dim,
    zend_ulong value_dim,
    zend_ulong kv_heads,
    zval *return_value
) {
    array_init(return_value);
    if (max_context_tokens == 0) {
        add_next_index_string(return_value, "context_length");
    }
    if (model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT] == 0) {
        add_next_index_string(return_value, "block_count");
    }
    if (model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT] == 0) {
        add_next_index_string(return_value, "attention_head_count");
    }
    if (kv_heads == 0) {
        add_next_index_string(return_value, "attention_head_count_kv");
    }
    if (key_dim == 0) {
        add_next_index_string(return_value, "attention_key_length");
    }
    if (value_dim == 0) {
        add_next_index_string(return_value, "attention_value_length");
    }
}

static void king_inference_paged_kv_cache_plan(
    king_inference_model_object *model,
    zval *request,
    zval *return_value
) {
    zval *config = king_inference_paged_kv_config(model);
    zend_ulong page_tokens = king_inference_paged_kv_positive_long(config, "page_tokens", KING_INFERENCE_DEFAULT_KV_PAGE_TOKENS);
    zend_ulong element_bytes = king_inference_paged_kv_positive_long(config, "element_bytes", KING_INFERENCE_DEFAULT_KV_ELEMENT_BYTES);
    zend_ulong max_context_tokens = king_inference_paged_kv_positive_long(
        config,
        "max_context_tokens",
        model->gguf.architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH]
    );
    zend_ulong kv_heads = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV] > 0
        ? model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV]
        : model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT];
    zend_ulong key_dim = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH];
    zend_ulong value_dim = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH];
    zend_ulong pages_per_sequence;
    zend_ulong page_bytes_per_layer = 0;
    zend_ulong page_bytes_all_layers = 0;
    zend_ulong sequence_bytes = 0;
    bool enabled = king_inference_bool_from_array(config, "enabled", true);
    bool bytes_fit;
    zval missing;

    if (key_dim == 0
        && model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH] > 0
        && model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT] > 0
        && model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH]
            % model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT] == 0) {
        key_dim = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH]
            / model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT];
    }
    if (value_dim == 0) {
        value_dim = key_dim;
    }
    if (request != NULL && Z_TYPE_P(request) == IS_ARRAY) {
        max_context_tokens = king_inference_paged_kv_positive_long(request, "max_context_tokens", max_context_tokens);
    }

    pages_per_sequence = page_tokens > 0 && max_context_tokens > 0
        ? (max_context_tokens + page_tokens - 1) / page_tokens
        : 0;
    bytes_fit = king_inference_paged_kv_page_bytes(page_tokens, kv_heads, key_dim, value_dim, element_bytes, &page_bytes_per_layer)
        && king_inference_paged_kv_mul(
            page_bytes_per_layer,
            model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT],
            &page_bytes_all_layers
        )
        && king_inference_paged_kv_mul(page_bytes_all_layers, pages_per_sequence, &sequence_bytes);

    array_init(return_value);
    add_assoc_bool(return_value, "enabled", enabled);
    add_assoc_bool(return_value, "ready", enabled && bytes_fit && pages_per_sequence > 0 && page_bytes_all_layers > 0);
    add_assoc_string(return_value, "layout", "paged_kv_cache");
    add_assoc_long(return_value, "page_tokens", (zend_long) page_tokens);
    add_assoc_long(return_value, "max_context_tokens", (zend_long) max_context_tokens);
    add_assoc_long(return_value, "pages_per_sequence", (zend_long) pages_per_sequence);
    add_assoc_long(return_value, "layers", (zend_long) model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT]);
    add_assoc_long(return_value, "attention_heads", (zend_long) model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT]);
    add_assoc_long(return_value, "kv_heads", (zend_long) kv_heads);
    add_assoc_long(return_value, "key_dim", (zend_long) key_dim);
    add_assoc_long(return_value, "value_dim", (zend_long) value_dim);
    add_assoc_long(return_value, "element_bytes", (zend_long) element_bytes);
    add_assoc_long(return_value, "page_bytes_per_layer", (zend_long) page_bytes_per_layer);
    add_assoc_long(return_value, "page_bytes_all_layers", (zend_long) page_bytes_all_layers);
    add_assoc_long(return_value, "max_sequence_bytes", (zend_long) sequence_bytes);
    add_assoc_bool(return_value, "block_table_ready", pages_per_sequence > 0);
    add_assoc_bool(return_value, "copy_on_write_ready", false);
    add_assoc_bool(return_value, "overflow_safe", bytes_fit);
    king_inference_paged_kv_missing_fields(model, max_context_tokens, key_dim, value_dim, kv_heads, &missing);
    add_assoc_zval(return_value, "missing_fields", &missing);
}
