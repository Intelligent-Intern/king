static bool king_inference_attention_descriptor_dimensions(
    zval *descriptor,
    zend_ulong *columns_out,
    zend_ulong *rows_out
) {
    zend_ulong rank;

    if (!king_inference_tensor_descriptor_rank(descriptor, &rank)
        || rank != 2
        || !king_inference_tensor_descriptor_dimension(descriptor, 0, columns_out)
        || !king_inference_tensor_descriptor_dimension(descriptor, 1, rows_out)) {
        return false;
    }

    return *columns_out > 0 && *rows_out > 0;
}

static bool king_inference_attention_norm_width(
    king_inference_model_object *model,
    const char *name,
    zend_ulong *width_out
) {
    zend_string *tensor_name;
    zval *descriptor;
    zend_ulong rank;

    tensor_name = zend_string_init(name, strlen(name), 0);
    descriptor = king_inference_tensor_index_descriptor(model, tensor_name);
    zend_string_release(tensor_name);
    if (descriptor == NULL
        || !king_inference_tensor_descriptor_rank(descriptor, &rank)
        || rank != 1
        || !king_inference_tensor_descriptor_dimension(descriptor, 0, width_out)) {
        return false;
    }

    return *width_out > 0;
}

static bool king_inference_attention_layer_norm_width(
    king_inference_model_object *model,
    zend_ulong layer,
    const char *suffix,
    zend_ulong *width_out
) {
    char name[96];

    if (snprintf(name, sizeof(name), "blk.%lu.%s", (unsigned long) layer, suffix) >= (int) sizeof(name)) {
        return false;
    }

    return king_inference_attention_norm_width(model, name, width_out);
}

static bool king_inference_attention_layer_dimensions(
    king_inference_model_object *model,
    king_inference_attention_role role,
    zend_ulong layer,
    zend_ulong *columns_out,
    zend_ulong *rows_out
) {
    const char **patterns = king_inference_attention_role_patterns(role);
    size_t i;

    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        return false;
    }

    for (i = 0; patterns[i] != NULL; i++) {
        zend_string *name = king_inference_attention_layer_pattern_name(patterns[i], layer);
        zval *descriptor;
        bool valid;

        if (name == NULL) {
            continue;
        }
        descriptor = king_inference_tensor_index_descriptor(model, name);
        valid = king_inference_attention_descriptor_dimensions(descriptor, columns_out, rows_out);
        zend_string_release(name);
        if (valid) {
            return true;
        }
    }

    return false;
}

static bool king_inference_attention_gemma4_expected_dimensions(
    king_inference_model_object *model,
    king_inference_attention_role role,
    zend_ulong layer,
    zend_ulong *columns_out,
    zend_ulong *rows_out
) {
    zend_ulong embedding = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong head_count = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT];
    zend_ulong q_norm_width;
    zend_ulong k_norm_width;
    zend_ulong columns;
    zend_ulong rows;
    zend_ulong query_rows;
    zend_ulong max_kv_rows;
    king_inference_attention_role dimension_role = role;

    if (!king_inference_gguf_architecture_is_gemma4(&model->gguf)
        || embedding == 0
        || head_count == 0
        || !king_inference_attention_layer_norm_width(model, layer, "attn_q_norm.weight", &q_norm_width)
        || !king_inference_attention_layer_norm_width(model, layer, "attn_k_norm.weight", &k_norm_width)
        || head_count > ZEND_ULONG_MAX / q_norm_width
        || head_count > ZEND_ULONG_MAX / k_norm_width) {
        return false;
    }

    query_rows = head_count * q_norm_width;
    max_kv_rows = head_count * k_norm_width;
    if (role == KING_INFERENCE_ATTENTION_VALUE
        && !king_inference_attention_layer_dimensions(model, role, layer, &columns, &rows)) {
        dimension_role = KING_INFERENCE_ATTENTION_KEY;
    }
    if (!king_inference_attention_layer_dimensions(model, dimension_role, layer, &columns, &rows)) {
        return false;
    }

    switch (role) {
        case KING_INFERENCE_ATTENTION_QUERY:
            if (columns != embedding || rows != query_rows) {
                return false;
            }
            break;
        case KING_INFERENCE_ATTENTION_KEY:
        case KING_INFERENCE_ATTENTION_VALUE:
            if (columns != embedding || rows == 0 || rows > max_kv_rows || rows % k_norm_width != 0) {
                return false;
            }
            break;
        case KING_INFERENCE_ATTENTION_OUTPUT:
            if (columns != query_rows || rows != embedding) {
                return false;
            }
            break;
    }

    *columns_out = columns;
    *rows_out = rows;
    return true;
}

static bool king_inference_attention_expected_dimensions(
    king_inference_model_object *model,
    king_inference_attention_role role,
    zend_ulong layer,
    zend_ulong *columns_out,
    zend_ulong *rows_out
) {
    zend_ulong embedding = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong head_count = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT];
    zend_ulong kv_head_count = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV];
    zend_ulong key_length = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH];
    zend_ulong value_length = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH];

    *columns_out = 0;
    *rows_out = 0;
    if (king_inference_attention_gemma4_expected_dimensions(model, role, layer, columns_out, rows_out)) {
        return true;
    }
    if (embedding == 0 || head_count == 0) {
        return false;
    }
    if (kv_head_count == 0) {
        kv_head_count = head_count;
    }
    if (key_length == 0) {
        if (embedding % head_count != 0) {
            return false;
        }
        key_length = embedding / head_count;
    }
    if (value_length == 0) {
        if (embedding % head_count != 0) {
            return false;
        }
        value_length = embedding / head_count;
    }

    *columns_out = embedding;
    switch (role) {
        case KING_INFERENCE_ATTENTION_QUERY:
            if (head_count > ZEND_ULONG_MAX / key_length) {
                return false;
            }
            *rows_out = head_count * key_length;
            break;
        case KING_INFERENCE_ATTENTION_KEY:
            if (kv_head_count > ZEND_ULONG_MAX / key_length) {
                return false;
            }
            *rows_out = kv_head_count * key_length;
            break;
        case KING_INFERENCE_ATTENTION_VALUE:
            if (kv_head_count > ZEND_ULONG_MAX / value_length) {
                return false;
            }
            *rows_out = kv_head_count * value_length;
            break;
        case KING_INFERENCE_ATTENTION_OUTPUT:
            if (head_count > ZEND_ULONG_MAX / value_length) {
                return false;
            }
            *columns_out = head_count * value_length;
            *rows_out = embedding;
            break;
    }

    return *columns_out > 0 && *rows_out > 0;
}

static bool king_inference_attention_descriptor_valid(
    king_inference_model_object *model,
    king_inference_attention_role role,
    zend_ulong layer,
    zval *descriptor
) {
    zend_ulong rank;
    zend_ulong columns;
    zend_ulong rows;
    zend_ulong expected_columns;
    zend_ulong expected_rows;

    if (!king_inference_attention_expected_dimensions(model, role, layer, &expected_columns, &expected_rows)
        || !king_inference_tensor_descriptor_rank(descriptor, &rank)
        || rank != 2
        || !king_inference_tensor_descriptor_dimension(descriptor, 0, &columns)
        || !king_inference_tensor_descriptor_dimension(descriptor, 1, &rows)) {
        return false;
    }

    return columns == expected_columns && rows == expected_rows;
}

