typedef enum _king_inference_attention_role {
    KING_INFERENCE_ATTENTION_QUERY = 0,
    KING_INFERENCE_ATTENTION_KEY = 1,
    KING_INFERENCE_ATTENTION_VALUE = 2,
    KING_INFERENCE_ATTENTION_OUTPUT = 3
} king_inference_attention_role;

static const char *king_inference_attention_role_keys[] = {
    "query",
    "key",
    "value",
    "output"
};

static const char *king_inference_attention_config_fields[] = {
    "attention_query_tensor_pattern",
    "attention_key_tensor_pattern",
    "attention_value_tensor_pattern",
    "attention_output_tensor_pattern"
};

static const char *king_inference_attention_query_patterns[] = {
    "blk.{layer}.attn_q.weight",
    "blk.{layer}.attention_q.weight",
    "model.layers.{layer}.self_attn.q_proj.weight",
    "layers.{layer}.self_attn.q_proj.weight",
    "decoder.layers.{layer}.self_attn.q_proj.weight",
    "transformer.h.{layer}.attn.q_proj.weight",
    "transformer.blocks.{layer}.attn.q_proj.weight",
    NULL
};

static const char *king_inference_attention_key_patterns[] = {
    "blk.{layer}.attn_k.weight",
    "blk.{layer}.attention_k.weight",
    "model.layers.{layer}.self_attn.k_proj.weight",
    "layers.{layer}.self_attn.k_proj.weight",
    "decoder.layers.{layer}.self_attn.k_proj.weight",
    "transformer.h.{layer}.attn.k_proj.weight",
    "transformer.blocks.{layer}.attn.k_proj.weight",
    NULL
};

static const char *king_inference_attention_value_patterns[] = {
    "blk.{layer}.attn_v.weight",
    "blk.{layer}.attention_v.weight",
    "model.layers.{layer}.self_attn.v_proj.weight",
    "layers.{layer}.self_attn.v_proj.weight",
    "decoder.layers.{layer}.self_attn.v_proj.weight",
    "transformer.h.{layer}.attn.v_proj.weight",
    "transformer.blocks.{layer}.attn.v_proj.weight",
    NULL
};

static const char *king_inference_attention_output_patterns[] = {
    "blk.{layer}.attn_output.weight",
    "blk.{layer}.attn_o.weight",
    "blk.{layer}.attention_output.weight",
    "model.layers.{layer}.self_attn.o_proj.weight",
    "layers.{layer}.self_attn.o_proj.weight",
    "decoder.layers.{layer}.self_attn.o_proj.weight",
    "transformer.h.{layer}.attn.out_proj.weight",
    "transformer.blocks.{layer}.attn.out_proj.weight",
    NULL
};

static const char **king_inference_attention_role_patterns(king_inference_attention_role role)
{
    switch (role) {
        case KING_INFERENCE_ATTENTION_QUERY:
            return king_inference_attention_query_patterns;
        case KING_INFERENCE_ATTENTION_KEY:
            return king_inference_attention_key_patterns;
        case KING_INFERENCE_ATTENTION_VALUE:
            return king_inference_attention_value_patterns;
        case KING_INFERENCE_ATTENTION_OUTPUT:
            return king_inference_attention_output_patterns;
    }

    return king_inference_attention_query_patterns;
}

static zend_string *king_inference_attention_layer_pattern_name(
    const char *pattern,
    zend_ulong layer
) {
    const char *placeholder = strstr(pattern, "{layer}");
    const char *suffix;
    char layer_buf[32];
    int layer_len;
    size_t prefix_len;
    size_t suffix_len;
    size_t total_len;
    zend_string *name;

    if (placeholder == NULL) {
        return NULL;
    }

    layer_len = snprintf(layer_buf, sizeof(layer_buf), "%lu", (unsigned long) layer);
    if (layer_len <= 0 || (size_t) layer_len >= sizeof(layer_buf)) {
        return NULL;
    }

    prefix_len = (size_t) (placeholder - pattern);
    suffix = placeholder + sizeof("{layer}") - 1;
    suffix_len = strlen(suffix);
    total_len = prefix_len + (size_t) layer_len + suffix_len;
    name = zend_string_alloc(total_len, 0);
    memcpy(ZSTR_VAL(name), pattern, prefix_len);
    memcpy(ZSTR_VAL(name) + prefix_len, layer_buf, (size_t) layer_len);
    memcpy(ZSTR_VAL(name) + prefix_len + (size_t) layer_len, suffix, suffix_len);
    ZSTR_VAL(name)[total_len] = '\0';

    return name;
}

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

static zend_string *king_inference_attention_gemma4_value_tied_key_name(
    king_inference_model_object *model,
    zend_ulong layer
) {
    zend_string *name;
    zval *descriptor;

    if (!king_inference_gguf_architecture_is_gemma4(&model->gguf)) {
        return NULL;
    }

    name = king_inference_attention_layer_pattern_name("blk.{layer}.attn_k.weight", layer);
    if (name == NULL) {
        return NULL;
    }
    descriptor = king_inference_tensor_index_descriptor(model, name);
    if (descriptor != NULL
        && king_inference_attention_descriptor_valid(
            model,
            KING_INFERENCE_ATTENTION_VALUE,
            layer,
            descriptor
        )) {
        return name;
    }

    zend_string_release(name);
    return NULL;
}

static bool king_inference_attention_name_matches_layer(zend_string *name, zend_ulong layer)
{
    char token[64];

    snprintf(token, sizeof(token), "blk.%lu.", (unsigned long) layer);
    if (king_inference_tensor_name_contains(name, token)) {
        return true;
    }
    snprintf(token, sizeof(token), "layers.%lu.", (unsigned long) layer);
    if (king_inference_tensor_name_contains(name, token)) {
        return true;
    }
    snprintf(token, sizeof(token), "blocks.%lu.", (unsigned long) layer);
    if (king_inference_tensor_name_contains(name, token)) {
        return true;
    }
    snprintf(token, sizeof(token), "h.%lu.", (unsigned long) layer);
    return king_inference_tensor_name_contains(name, token);
}

static bool king_inference_attention_name_hint(
    zend_string *name,
    king_inference_attention_role role,
    zend_ulong layer
) {
    if (!king_inference_attention_name_matches_layer(name, layer)
        || king_inference_tensor_name_contains(name, "norm")
        || king_inference_tensor_name_contains(name, "ffn")
        || king_inference_tensor_name_contains(name, "feed_forward")
        || king_inference_tensor_name_contains(name, "mlp")
        || king_inference_tensor_name_contains(name, "token")
        || king_inference_tensor_name_contains(name, "embed")
        || king_inference_tensor_name_contains(name, "lm_head")) {
        return false;
    }

    switch (role) {
        case KING_INFERENCE_ATTENTION_QUERY:
            return king_inference_tensor_name_contains(name, "attn_q")
                || king_inference_tensor_name_contains(name, "attention_q")
                || king_inference_tensor_name_contains(name, "q_proj")
                || king_inference_tensor_name_contains(name, "query");
        case KING_INFERENCE_ATTENTION_KEY:
            return king_inference_tensor_name_contains(name, "attn_k")
                || king_inference_tensor_name_contains(name, "attention_k")
                || king_inference_tensor_name_contains(name, "k_proj")
                || king_inference_tensor_name_contains(name, "key");
        case KING_INFERENCE_ATTENTION_VALUE:
            return king_inference_tensor_name_contains(name, "attn_v")
                || king_inference_tensor_name_contains(name, "attention_v")
                || king_inference_tensor_name_contains(name, "v_proj")
                || king_inference_tensor_name_contains(name, "value");
        case KING_INFERENCE_ATTENTION_OUTPUT:
            return king_inference_tensor_name_contains(name, "attn_output")
                || king_inference_tensor_name_contains(name, "attn_o")
                || king_inference_tensor_name_contains(name, "attention_output")
                || king_inference_tensor_name_contains(name, "o_proj")
                || king_inference_tensor_name_contains(name, "out_proj");
    }

    return false;
}

static zend_string *king_inference_attention_known_name(
    king_inference_model_object *model,
    king_inference_attention_role role,
    zend_ulong layer
) {
    const char **patterns = king_inference_attention_role_patterns(role);
    size_t i;

    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        return NULL;
    }

    for (i = 0; patterns[i] != NULL; i++) {
        zend_string *name = king_inference_attention_layer_pattern_name(patterns[i], layer);
        zval *descriptor;

        if (name == NULL) {
            continue;
        }
        descriptor = king_inference_tensor_index_descriptor(model, name);
        if (descriptor != NULL && king_inference_attention_descriptor_valid(model, role, layer, descriptor)) {
            return name;
        }
        zend_string_release(name);
    }

    if (role == KING_INFERENCE_ATTENTION_VALUE) {
        return king_inference_attention_gemma4_value_tied_key_name(model, layer);
    }

    return NULL;
}

static zend_string *king_inference_attention_shape_scan(
    king_inference_model_object *model,
    king_inference_attention_role role,
    zend_ulong layer,
    const char **status
) {
    zval *descriptor;
    zend_string *name;
    zend_string *matched = NULL;
    zend_ulong matches = 0;
    zend_ulong columns;
    zend_ulong rows;

    if (!king_inference_attention_expected_dimensions(model, role, layer, &columns, &rows)
        || Z_ISUNDEF(model->tensor_index)
        || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        if (status != NULL) {
            *status = "shape_scan_unavailable";
        }
        return NULL;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(model->tensor_index), name, descriptor) {
        if (name == NULL
            || !king_inference_attention_name_hint(name, role, layer)
            || !king_inference_attention_descriptor_valid(model, role, layer, descriptor)) {
            continue;
        }
        matches++;
        if (matched == NULL) {
            matched = zend_string_copy(name);
        }
    } ZEND_HASH_FOREACH_END();

    if (matches == 1) {
        if (status != NULL) {
            *status = "resolved";
        }
        return matched;
    }
    if (matched != NULL) {
        zend_string_release(matched);
    }
    if (status != NULL) {
        *status = matches > 1 ? "ambiguous_shape_scan" : "not_found";
    }
    return NULL;
}

static zend_string *king_inference_attention_configured_name(
    king_inference_model_object *model,
    king_inference_attention_role role,
    zend_ulong layer,
    const char **source_out,
    const char **status_out
) {
    zval *pattern = king_inference_array_find(
        &model->config,
        king_inference_attention_config_fields[role]
    );
    zend_string *name;

    if (pattern == NULL) {
        return NULL;
    }
    if (Z_TYPE_P(pattern) != IS_STRING || Z_STRLEN_P(pattern) == 0) {
        if (status_out != NULL) {
            *status_out = "configured_pattern_invalid";
        }
        return NULL;
    }
    if (source_out != NULL) {
        *source_out = king_inference_attention_config_fields[role];
    }

    name = king_inference_attention_layer_pattern_name(Z_STRVAL_P(pattern), layer);
    if (name == NULL && status_out != NULL) {
        *status_out = "configured_pattern_missing_layer_placeholder";
    }

    return name;
}

static bool king_inference_attention_configured_pattern_present(
    king_inference_model_object *model,
    king_inference_attention_role role
) {
    return king_inference_array_find(
        &model->config,
        king_inference_attention_config_fields[role]
    ) != NULL;
}

static zend_result king_inference_resolve_attention_tensor_name(
    king_inference_model_object *model,
    king_inference_attention_role role,
    zend_ulong layer,
    zend_string **name_out,
    const char **source_out,
    const char **status_out
) {
    const char *source = NULL;
    const char *scan_status = "not_found";
    bool configured_present = king_inference_attention_configured_pattern_present(model, role);
    zend_string *configured;
    zval *descriptor;

    if (status_out != NULL) {
        *status_out = "not_found";
    }
    configured = king_inference_attention_configured_name(
        model,
        role,
        layer,
        &source,
        status_out
    );

    *name_out = NULL;
    if (source_out != NULL) {
        *source_out = NULL;
    }

    if (configured_present && configured == NULL) {
        if (status_out != NULL && strcmp(*status_out, "not_found") == 0) {
            *status_out = "configured_pattern_invalid";
        }
        return FAILURE;
    }

    if (configured != NULL) {
        descriptor = king_inference_tensor_index_descriptor(model, configured);
        if (descriptor == NULL) {
            zend_string_release(configured);
            if (status_out != NULL) {
                *status_out = "configured_tensor_not_found";
            }
            return FAILURE;
        }
        if (!king_inference_attention_descriptor_valid(model, role, layer, descriptor)) {
            zend_string_release(configured);
            if (status_out != NULL) {
                *status_out = "configured_tensor_invalid_shape";
            }
            return FAILURE;
        }
        *name_out = configured;
        if (source_out != NULL) {
            *source_out = source;
        }
        if (status_out != NULL) {
            *status_out = "resolved";
        }
        return SUCCESS;
    }

    *name_out = king_inference_attention_known_name(model, role, layer);
    if (*name_out != NULL) {
        if (source_out != NULL) {
            *source_out = "known_pattern";
        }
        if (status_out != NULL) {
            *status_out = "resolved";
        }
        return SUCCESS;
    }

    *name_out = king_inference_attention_shape_scan(model, role, layer, &scan_status);
    if (*name_out != NULL) {
        if (source_out != NULL) {
            *source_out = "shape_scan";
        }
        if (status_out != NULL) {
            *status_out = "resolved";
        }
        return SUCCESS;
    }

    if (status_out != NULL) {
        *status_out = scan_status;
    }
    return FAILURE;
}

static void king_inference_add_attention_known_patterns(zval *attention)
{
    zval known_patterns;
    int role;

    array_init(&known_patterns);
    for (role = KING_INFERENCE_ATTENTION_QUERY; role <= KING_INFERENCE_ATTENTION_OUTPUT; role++) {
        zval patterns;
        const char **role_patterns = king_inference_attention_role_patterns((king_inference_attention_role) role);
        size_t i;

        array_init(&patterns);
        for (i = 0; role_patterns[i] != NULL; i++) {
            add_next_index_string(&patterns, role_patterns[i]);
        }
        add_assoc_zval(&known_patterns, king_inference_attention_role_keys[role], &patterns);
    }
    add_assoc_zval(attention, "known_patterns", &known_patterns);
}

static void king_inference_add_attention_config_fields(zval *attention)
{
    zval fields;
    int role;

    array_init(&fields);
    for (role = KING_INFERENCE_ATTENTION_QUERY; role <= KING_INFERENCE_ATTENTION_OUTPUT; role++) {
        add_assoc_string(
            &fields,
            king_inference_attention_role_keys[role],
            king_inference_attention_config_fields[role]
        );
    }
    add_assoc_zval(attention, "configured_pattern_fields", &fields);
}

static bool king_inference_add_attention_role_entry(
    king_inference_model_object *model,
    zval *layer_entry,
    king_inference_attention_role role,
    zend_ulong layer
) {
    zend_string *name = NULL;
    const char *source = NULL;
    const char *status = "not_found";
    zend_ulong expected_columns = 0;
    zend_ulong expected_rows = 0;
    zend_result result;
    zval entry;

    result = king_inference_resolve_attention_tensor_name(
        model,
        role,
        layer,
        &name,
        &source,
        &status
    );

    king_inference_attention_expected_dimensions(model, role, layer, &expected_columns, &expected_rows);
    array_init(&entry);
    add_assoc_bool(&entry, "resolved", result == SUCCESS && name != NULL);
    add_assoc_string(&entry, "status", status != NULL ? status : "not_found");
    add_assoc_long(&entry, "expected_columns", (zend_long) expected_columns);
    add_assoc_long(&entry, "expected_rows", (zend_long) expected_rows);
    if (source != NULL) {
        add_assoc_string(&entry, "source", source);
    }
    if (name != NULL) {
        add_assoc_str(&entry, "name", name);
    }
    add_assoc_zval(layer_entry, king_inference_attention_role_keys[role], &entry);

    return result == SUCCESS && name != NULL;
}

static void king_inference_add_attention_resolution_info(
    king_inference_model_object *model,
    zval *resolved
) {
    zend_ulong block_count = model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT];
    zend_ulong layer;
    zend_ulong resolved_layers = 0;
    zend_ulong resolved_tensors = 0;
    zval attention;
    zval layers;

    array_init(&attention);
    array_init(&layers);

    for (layer = 0; layer < block_count; layer++) {
        zval layer_entry;
        bool layer_complete = true;
        int role;

        array_init(&layer_entry);
        add_assoc_long(&layer_entry, "index", (zend_long) layer);
        for (role = KING_INFERENCE_ATTENTION_QUERY; role <= KING_INFERENCE_ATTENTION_OUTPUT; role++) {
            bool role_resolved = king_inference_add_attention_role_entry(
                model,
                &layer_entry,
                (king_inference_attention_role) role,
                layer
            );
            if (role_resolved) {
                resolved_tensors++;
            } else {
                layer_complete = false;
            }
        }
        add_assoc_bool(&layer_entry, "resolved", layer_complete);
        if (layer_complete) {
            resolved_layers++;
        }
        add_next_index_zval(&layers, &layer_entry);
    }

    add_assoc_bool(&attention, "resolved", block_count > 0 && resolved_layers == block_count);
    add_assoc_string(
        &attention,
        "status",
        block_count == 0 ? "shape_metadata_unavailable" :
            (resolved_layers == block_count ? "resolved" : "partial")
    );
    add_assoc_long(&attention, "block_count", (zend_long) block_count);
    add_assoc_long(&attention, "resolved_layers", (zend_long) resolved_layers);
    add_assoc_long(&attention, "resolved_tensors", (zend_long) resolved_tensors);
    king_inference_add_attention_config_fields(&attention);
    king_inference_add_attention_known_patterns(&attention);
    add_assoc_zval(&attention, "layers", &layers);
    add_assoc_zval(resolved, "attention", &attention);
}
