typedef enum _king_inference_ffn_role {
    KING_INFERENCE_FFN_GATE = 0,
    KING_INFERENCE_FFN_UP = 1,
    KING_INFERENCE_FFN_DOWN = 2
} king_inference_ffn_role;

static const char *king_inference_ffn_role_keys[] = {
    "gate",
    "up",
    "down"
};

static const char *king_inference_ffn_config_fields[] = {
    "ffn_gate_tensor_pattern",
    "ffn_up_tensor_pattern",
    "ffn_down_tensor_pattern"
};

static const char *king_inference_ffn_gate_patterns[] = {
    "blk.{layer}.ffn_gate.weight",
    "blk.{layer}.feed_forward.w1.weight",
    "model.layers.{layer}.mlp.gate_proj.weight",
    "layers.{layer}.mlp.gate_proj.weight",
    "decoder.layers.{layer}.mlp.gate_proj.weight",
    "model.layers.{layer}.feed_forward.w1.weight",
    "layers.{layer}.feed_forward.w1.weight",
    "transformer.h.{layer}.mlp.gate_proj.weight",
    "transformer.blocks.{layer}.mlp.gate_proj.weight",
    NULL
};

static const char *king_inference_ffn_up_patterns[] = {
    "blk.{layer}.ffn_up.weight",
    "blk.{layer}.feed_forward.w3.weight",
    "model.layers.{layer}.mlp.up_proj.weight",
    "layers.{layer}.mlp.up_proj.weight",
    "decoder.layers.{layer}.mlp.up_proj.weight",
    "model.layers.{layer}.feed_forward.w3.weight",
    "layers.{layer}.feed_forward.w3.weight",
    "transformer.h.{layer}.mlp.up_proj.weight",
    "transformer.blocks.{layer}.mlp.up_proj.weight",
    NULL
};

static const char *king_inference_ffn_down_patterns[] = {
    "blk.{layer}.ffn_down.weight",
    "blk.{layer}.feed_forward.w2.weight",
    "model.layers.{layer}.mlp.down_proj.weight",
    "layers.{layer}.mlp.down_proj.weight",
    "decoder.layers.{layer}.mlp.down_proj.weight",
    "model.layers.{layer}.feed_forward.w2.weight",
    "layers.{layer}.feed_forward.w2.weight",
    "transformer.h.{layer}.mlp.down_proj.weight",
    "transformer.blocks.{layer}.mlp.down_proj.weight",
    NULL
};

static const char **king_inference_ffn_role_patterns(king_inference_ffn_role role)
{
    switch (role) {
        case KING_INFERENCE_FFN_GATE:
            return king_inference_ffn_gate_patterns;
        case KING_INFERENCE_FFN_UP:
            return king_inference_ffn_up_patterns;
        case KING_INFERENCE_FFN_DOWN:
            return king_inference_ffn_down_patterns;
    }

    return king_inference_ffn_gate_patterns;
}

static zend_string *king_inference_ffn_layer_pattern_name(
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

static bool king_inference_ffn_expected_dimensions(
    king_inference_model_object *model,
    king_inference_ffn_role role,
    zend_ulong *columns_out,
    zend_ulong *rows_out
) {
    zend_ulong embedding = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong feed_forward = model->gguf.architecture_params[KING_INFERENCE_ARCH_FEED_FORWARD_LENGTH];

    *columns_out = 0;
    *rows_out = 0;
    if (embedding == 0 || feed_forward == 0) {
        return false;
    }

    if (role == KING_INFERENCE_FFN_DOWN) {
        *columns_out = feed_forward;
        *rows_out = embedding;
    } else {
        *columns_out = embedding;
        *rows_out = feed_forward;
    }

    return true;
}

static bool king_inference_ffn_descriptor_valid(
    king_inference_model_object *model,
    king_inference_ffn_role role,
    zval *descriptor
) {
    zend_ulong rank;
    zend_ulong columns;
    zend_ulong rows;
    zend_ulong expected_columns;
    zend_ulong expected_rows;

    if (!king_inference_ffn_expected_dimensions(model, role, &expected_columns, &expected_rows)
        || !king_inference_tensor_descriptor_rank(descriptor, &rank)
        || rank != 2
        || !king_inference_tensor_descriptor_dimension(descriptor, 0, &columns)
        || !king_inference_tensor_descriptor_dimension(descriptor, 1, &rows)) {
        return false;
    }

    return columns == expected_columns && rows == expected_rows;
}

static bool king_inference_ffn_name_matches_layer(zend_string *name, zend_ulong layer)
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

static bool king_inference_ffn_name_hint(
    zend_string *name,
    king_inference_ffn_role role,
    zend_ulong layer
) {
    if (!king_inference_ffn_name_matches_layer(name, layer)
        || king_inference_tensor_name_contains(name, "norm")
        || king_inference_tensor_name_contains(name, "attn")
        || king_inference_tensor_name_contains(name, "attention")
        || king_inference_tensor_name_contains(name, "q_proj")
        || king_inference_tensor_name_contains(name, "k_proj")
        || king_inference_tensor_name_contains(name, "v_proj")
        || king_inference_tensor_name_contains(name, "o_proj")
        || king_inference_tensor_name_contains(name, "token")
        || king_inference_tensor_name_contains(name, "embed")
        || king_inference_tensor_name_contains(name, "lm_head")
        || king_inference_tensor_name_contains(name, "output_norm")) {
        return false;
    }

    switch (role) {
        case KING_INFERENCE_FFN_GATE:
            return king_inference_tensor_name_contains(name, "ffn_gate")
                || king_inference_tensor_name_contains(name, "gate_proj")
                || king_inference_tensor_name_contains(name, "feed_forward.w1")
                || king_inference_tensor_name_contains(name, "mlp.gate");
        case KING_INFERENCE_FFN_UP:
            return king_inference_tensor_name_contains(name, "ffn_up")
                || king_inference_tensor_name_contains(name, "up_proj")
                || king_inference_tensor_name_contains(name, "feed_forward.w3")
                || king_inference_tensor_name_contains(name, "mlp.up");
        case KING_INFERENCE_FFN_DOWN:
            return king_inference_tensor_name_contains(name, "ffn_down")
                || king_inference_tensor_name_contains(name, "down_proj")
                || king_inference_tensor_name_contains(name, "feed_forward.w2")
                || king_inference_tensor_name_contains(name, "mlp.down");
    }

    return false;
}

static zend_string *king_inference_ffn_known_name(
    king_inference_model_object *model,
    king_inference_ffn_role role,
    zend_ulong layer
) {
    const char **patterns = king_inference_ffn_role_patterns(role);
    size_t i;

    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        return NULL;
    }

    for (i = 0; patterns[i] != NULL; i++) {
        zend_string *name = king_inference_ffn_layer_pattern_name(patterns[i], layer);
        zval *descriptor;

        if (name == NULL) {
            continue;
        }
        descriptor = king_inference_tensor_index_descriptor(model, name);
        if (descriptor != NULL && king_inference_ffn_descriptor_valid(model, role, descriptor)) {
            return name;
        }
        zend_string_release(name);
    }

    return NULL;
}

static zend_string *king_inference_ffn_shape_scan(
    king_inference_model_object *model,
    king_inference_ffn_role role,
    zend_ulong layer,
    const char **status
) {
    zval *descriptor;
    zend_string *name;
    zend_string *matched = NULL;
    zend_ulong matches = 0;
    zend_ulong columns;
    zend_ulong rows;

    if (!king_inference_ffn_expected_dimensions(model, role, &columns, &rows)
        || Z_ISUNDEF(model->tensor_index)
        || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        if (status != NULL) {
            *status = "shape_scan_unavailable";
        }
        return NULL;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(model->tensor_index), name, descriptor) {
        if (name == NULL
            || !king_inference_ffn_name_hint(name, role, layer)
            || !king_inference_ffn_descriptor_valid(model, role, descriptor)) {
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

static bool king_inference_ffn_configured_pattern_present(
    king_inference_model_object *model,
    king_inference_ffn_role role
) {
    return king_inference_array_find(
        &model->config,
        king_inference_ffn_config_fields[role]
    ) != NULL;
}

static zend_string *king_inference_ffn_configured_name(
    king_inference_model_object *model,
    king_inference_ffn_role role,
    zend_ulong layer,
    const char **source_out,
    const char **status_out
) {
    zval *pattern = king_inference_array_find(
        &model->config,
        king_inference_ffn_config_fields[role]
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
        *source_out = king_inference_ffn_config_fields[role];
    }

    name = king_inference_ffn_layer_pattern_name(Z_STRVAL_P(pattern), layer);
    if (name == NULL && status_out != NULL) {
        *status_out = "configured_pattern_missing_layer_placeholder";
    }

    return name;
}

static zend_result king_inference_resolve_ffn_tensor_name(
    king_inference_model_object *model,
    king_inference_ffn_role role,
    zend_ulong layer,
    zend_string **name_out,
    const char **source_out,
    const char **status_out
) {
    const char *source = NULL;
    const char *scan_status = "not_found";
    bool configured_present = king_inference_ffn_configured_pattern_present(model, role);
    zend_string *configured;
    zval *descriptor;

    *name_out = NULL;
    if (source_out != NULL) {
        *source_out = NULL;
    }
    if (status_out != NULL) {
        *status_out = "not_found";
    }

    configured = king_inference_ffn_configured_name(model, role, layer, &source, status_out);
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
        if (!king_inference_ffn_descriptor_valid(model, role, descriptor)) {
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

    *name_out = king_inference_ffn_known_name(model, role, layer);
    if (*name_out != NULL) {
        if (source_out != NULL) {
            *source_out = "known_pattern";
        }
        if (status_out != NULL) {
            *status_out = "resolved";
        }
        return SUCCESS;
    }

    *name_out = king_inference_ffn_shape_scan(model, role, layer, &scan_status);
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

static void king_inference_add_ffn_patterns(zval *ffn)
{
    zval known_patterns;
    int role;

    array_init(&known_patterns);
    for (role = KING_INFERENCE_FFN_GATE; role <= KING_INFERENCE_FFN_DOWN; role++) {
        zval patterns;
        const char **known = king_inference_ffn_role_patterns((king_inference_ffn_role) role);
        size_t i;

        array_init(&patterns);
        for (i = 0; known[i] != NULL; i++) {
            add_next_index_string(&patterns, known[i]);
        }
        add_assoc_zval(&known_patterns, king_inference_ffn_role_keys[role], &patterns);
    }
    add_assoc_zval(ffn, "known_patterns", &known_patterns);
}

static void king_inference_add_ffn_config_fields(zval *ffn)
{
    zval fields;
    int role;

    array_init(&fields);
    for (role = KING_INFERENCE_FFN_GATE; role <= KING_INFERENCE_FFN_DOWN; role++) {
        add_assoc_string(&fields, king_inference_ffn_role_keys[role], king_inference_ffn_config_fields[role]);
    }
    add_assoc_zval(ffn, "configured_pattern_fields", &fields);
}

static bool king_inference_add_ffn_role_entry(
    king_inference_model_object *model,
    zval *layer_entry,
    king_inference_ffn_role role,
    zend_ulong layer
) {
    zend_string *name = NULL;
    const char *source = NULL;
    const char *status = "not_found";
    zend_ulong expected_columns = 0;
    zend_ulong expected_rows = 0;
    zend_result result;
    zval entry;

    result = king_inference_resolve_ffn_tensor_name(
        model,
        role,
        layer,
        &name,
        &source,
        &status
    );
    king_inference_ffn_expected_dimensions(model, role, &expected_columns, &expected_rows);

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
    add_assoc_zval(layer_entry, king_inference_ffn_role_keys[role], &entry);

    return result == SUCCESS && name != NULL;
}

static void king_inference_add_ffn_resolution_info(
    king_inference_model_object *model,
    zval *resolved
) {
    zend_ulong block_count = model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT];
    zend_ulong embedding = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong feed_forward = model->gguf.architecture_params[KING_INFERENCE_ARCH_FEED_FORWARD_LENGTH];
    zend_ulong layer;
    zend_ulong resolved_layers = 0;
    zend_ulong resolved_tensors = 0;
    zval ffn;
    zval layers;

    array_init(&ffn);
    array_init(&layers);

    for (layer = 0; layer < block_count; layer++) {
        zval layer_entry;
        bool layer_complete = true;
        int role;

        array_init(&layer_entry);
        add_assoc_long(&layer_entry, "index", (zend_long) layer);
        for (role = KING_INFERENCE_FFN_GATE; role <= KING_INFERENCE_FFN_DOWN; role++) {
            bool role_resolved = king_inference_add_ffn_role_entry(
                model,
                &layer_entry,
                (king_inference_ffn_role) role,
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

    add_assoc_bool(&ffn, "resolved", block_count > 0 && resolved_layers == block_count);
    add_assoc_string(
        &ffn,
        "status",
        block_count == 0 ? "shape_metadata_unavailable" :
            (resolved_layers == block_count ? "resolved" : "partial")
    );
    add_assoc_long(&ffn, "block_count", (zend_long) block_count);
    add_assoc_long(&ffn, "resolved_layers", (zend_long) resolved_layers);
    add_assoc_long(&ffn, "resolved_tensors", (zend_long) resolved_tensors);
    add_assoc_long(&ffn, "embedding_length", (zend_long) embedding);
    add_assoc_long(&ffn, "feed_forward_length", (zend_long) feed_forward);
    king_inference_add_ffn_config_fields(&ffn);
    king_inference_add_ffn_patterns(&ffn);
    add_assoc_zval(&ffn, "layers", &layers);
    add_assoc_zval(resolved, "ffn", &ffn);
}
