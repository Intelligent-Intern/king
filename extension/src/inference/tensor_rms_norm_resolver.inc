typedef enum _king_inference_rms_norm_role {
    KING_INFERENCE_RMS_NORM_ATTENTION = 0,
    KING_INFERENCE_RMS_NORM_FEED_FORWARD = 1
} king_inference_rms_norm_role;

static const char *king_inference_rms_norm_role_keys[] = {
    "attention",
    "feed_forward"
};

static const char *king_inference_rms_norm_config_fields[] = {
    "rms_norm_attention_tensor_pattern",
    "rms_norm_ffn_tensor_pattern"
};

static const char *king_inference_rms_norm_attention_patterns[] = {
    "blk.{layer}.attn_norm.weight",
    "blk.{layer}.attention_norm.weight",
    "model.layers.{layer}.input_layernorm.weight",
    "layers.{layer}.input_layernorm.weight",
    "decoder.layers.{layer}.input_layernorm.weight",
    "model.layers.{layer}.self_attn_layer_norm.weight",
    "decoder.layers.{layer}.self_attn_layer_norm.weight",
    "transformer.h.{layer}.ln_1.weight",
    "transformer.blocks.{layer}.ln_1.weight",
    NULL
};

static const char *king_inference_rms_norm_feed_forward_patterns[] = {
    "blk.{layer}.ffn_norm.weight",
    "blk.{layer}.post_attention_norm.weight",
    "model.layers.{layer}.post_attention_layernorm.weight",
    "layers.{layer}.post_attention_layernorm.weight",
    "decoder.layers.{layer}.post_attention_layernorm.weight",
    "model.layers.{layer}.mlp_layer_norm.weight",
    "decoder.layers.{layer}.final_layer_norm.weight",
    "transformer.h.{layer}.ln_2.weight",
    "transformer.blocks.{layer}.ln_2.weight",
    NULL
};

static const char *king_inference_rms_norm_final_names[] = {
    "output_norm.weight",
    "model.norm.weight",
    "norm.weight",
    "decoder.norm.weight",
    "model.decoder.norm.weight",
    "language_model.model.norm.weight",
    "final_layernorm.weight",
    "transformer.ln_f.weight",
    NULL
};

static const char **king_inference_rms_norm_role_patterns(king_inference_rms_norm_role role)
{
    switch (role) {
        case KING_INFERENCE_RMS_NORM_ATTENTION:
            return king_inference_rms_norm_attention_patterns;
        case KING_INFERENCE_RMS_NORM_FEED_FORWARD:
            return king_inference_rms_norm_feed_forward_patterns;
    }

    return king_inference_rms_norm_attention_patterns;
}

static zend_string *king_inference_rms_norm_layer_pattern_name(
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

static zend_ulong king_inference_rms_norm_expected_width(king_inference_model_object *model)
{
    return model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
}

static bool king_inference_rms_norm_descriptor_valid(
    king_inference_model_object *model,
    zval *descriptor
) {
    zend_ulong rank;
    zend_ulong width;
    zend_ulong expected = king_inference_rms_norm_expected_width(model);

    if (expected == 0
        || !king_inference_tensor_descriptor_rank(descriptor, &rank)
        || rank != 1
        || !king_inference_tensor_descriptor_dimension(descriptor, 0, &width)) {
        return false;
    }

    return width == expected;
}

static bool king_inference_rms_norm_name_matches_layer(zend_string *name, zend_ulong layer)
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

static bool king_inference_rms_norm_layer_name_hint(
    zend_string *name,
    king_inference_rms_norm_role role,
    zend_ulong layer
) {
    if (!king_inference_rms_norm_name_matches_layer(name, layer)
        || !king_inference_tensor_name_contains(name, "norm")
        || king_inference_tensor_name_contains(name, "q_proj")
        || king_inference_tensor_name_contains(name, "k_proj")
        || king_inference_tensor_name_contains(name, "v_proj")
        || king_inference_tensor_name_contains(name, "o_proj")
        || king_inference_tensor_name_contains(name, "attn_q")
        || king_inference_tensor_name_contains(name, "attn_k")
        || king_inference_tensor_name_contains(name, "attn_v")
        || king_inference_tensor_name_contains(name, "attn_o")
        || king_inference_tensor_name_contains(name, "token")
        || king_inference_tensor_name_contains(name, "embed")
        || king_inference_tensor_name_contains(name, "lm_head")) {
        return false;
    }

    switch (role) {
        case KING_INFERENCE_RMS_NORM_ATTENTION:
            return king_inference_tensor_name_contains(name, "attn_norm")
                || king_inference_tensor_name_contains(name, "attention_norm")
                || king_inference_tensor_name_contains(name, "input_layernorm")
                || king_inference_tensor_name_contains(name, "self_attn_layer_norm")
                || king_inference_tensor_name_contains(name, "ln_1");
        case KING_INFERENCE_RMS_NORM_FEED_FORWARD:
            return king_inference_tensor_name_contains(name, "ffn_norm")
                || king_inference_tensor_name_contains(name, "post_attention_norm")
                || king_inference_tensor_name_contains(name, "post_attention_layernorm")
                || king_inference_tensor_name_contains(name, "mlp_layer_norm")
                || king_inference_tensor_name_contains(name, "final_layer_norm")
                || king_inference_tensor_name_contains(name, "ln_2");
    }

    return false;
}

static bool king_inference_rms_norm_final_name_hint(zend_string *name)
{
    if (king_inference_tensor_name_contains(name, "blk.")
        || king_inference_tensor_name_contains(name, "layers.")
        || king_inference_tensor_name_contains(name, "blocks.")
        || king_inference_tensor_name_contains(name, "attn")
        || king_inference_tensor_name_contains(name, "ffn")
        || king_inference_tensor_name_contains(name, "mlp")
        || king_inference_tensor_name_contains(name, "token")
        || king_inference_tensor_name_contains(name, "embed")
        || king_inference_tensor_name_contains(name, "lm_head")
        || !king_inference_tensor_name_contains(name, "norm")) {
        return false;
    }

    return king_inference_tensor_name_contains(name, "output_norm")
        || king_inference_tensor_name_contains(name, "model.norm")
        || king_inference_tensor_name_contains(name, "decoder.norm")
        || king_inference_tensor_name_contains(name, "final_layernorm")
        || king_inference_tensor_name_contains(name, "ln_f")
        || zend_string_equals_literal(name, "norm.weight");
}

static zend_string *king_inference_rms_norm_known_layer_name(
    king_inference_model_object *model,
    king_inference_rms_norm_role role,
    zend_ulong layer
) {
    const char **patterns = king_inference_rms_norm_role_patterns(role);
    size_t i;

    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        return NULL;
    }

    for (i = 0; patterns[i] != NULL; i++) {
        zend_string *name = king_inference_rms_norm_layer_pattern_name(patterns[i], layer);
        zval *descriptor;

        if (name == NULL) {
            continue;
        }
        descriptor = king_inference_tensor_index_descriptor(model, name);
        if (descriptor != NULL && king_inference_rms_norm_descriptor_valid(model, descriptor)) {
            return name;
        }
        zend_string_release(name);
    }

    return NULL;
}

static zend_string *king_inference_rms_norm_known_final_name(king_inference_model_object *model)
{
    size_t i;

    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        return NULL;
    }

    for (i = 0; king_inference_rms_norm_final_names[i] != NULL; i++) {
        const char *candidate = king_inference_rms_norm_final_names[i];
        zval *descriptor = zend_hash_str_find(Z_ARRVAL(model->tensor_index), candidate, strlen(candidate));
        if (descriptor != NULL && king_inference_rms_norm_descriptor_valid(model, descriptor)) {
            return zend_string_init(candidate, strlen(candidate), 0);
        }
    }

    return NULL;
}

static zend_string *king_inference_rms_norm_layer_shape_scan(
    king_inference_model_object *model,
    king_inference_rms_norm_role role,
    zend_ulong layer,
    const char **status
) {
    zval *descriptor;
    zend_string *name;
    zend_string *matched = NULL;
    zend_ulong matches = 0;

    if (king_inference_rms_norm_expected_width(model) == 0
        || Z_ISUNDEF(model->tensor_index)
        || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        if (status != NULL) {
            *status = "shape_scan_unavailable";
        }
        return NULL;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(model->tensor_index), name, descriptor) {
        if (name == NULL
            || !king_inference_rms_norm_layer_name_hint(name, role, layer)
            || !king_inference_rms_norm_descriptor_valid(model, descriptor)) {
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

static zend_string *king_inference_rms_norm_final_shape_scan(
    king_inference_model_object *model,
    const char **status
) {
    zval *descriptor;
    zend_string *name;
    zend_string *matched = NULL;
    zend_ulong matches = 0;

    if (king_inference_rms_norm_expected_width(model) == 0
        || Z_ISUNDEF(model->tensor_index)
        || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        if (status != NULL) {
            *status = "shape_scan_unavailable";
        }
        return NULL;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(model->tensor_index), name, descriptor) {
        if (name == NULL
            || !king_inference_rms_norm_final_name_hint(name)
            || !king_inference_rms_norm_descriptor_valid(model, descriptor)) {
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

static bool king_inference_rms_norm_configured_pattern_present(
    king_inference_model_object *model,
    king_inference_rms_norm_role role
) {
    return king_inference_array_find(
        &model->config,
        king_inference_rms_norm_config_fields[role]
    ) != NULL;
}

static zend_string *king_inference_rms_norm_configured_layer_name(
    king_inference_model_object *model,
    king_inference_rms_norm_role role,
    zend_ulong layer,
    const char **source_out,
    const char **status_out
) {
    zval *pattern = king_inference_array_find(
        &model->config,
        king_inference_rms_norm_config_fields[role]
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
        *source_out = king_inference_rms_norm_config_fields[role];
    }

    name = king_inference_rms_norm_layer_pattern_name(Z_STRVAL_P(pattern), layer);
    if (name == NULL && status_out != NULL) {
        *status_out = "configured_pattern_missing_layer_placeholder";
    }

    return name;
}

static zend_string *king_inference_rms_norm_configured_final_name(
    king_inference_model_object *model,
    const char **source_out
) {
    zval *field = king_inference_array_find(&model->config, "rms_norm_final_tensor");

    if (field == NULL || Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
        return NULL;
    }
    if (source_out != NULL) {
        *source_out = "rms_norm_final_tensor";
    }
    return zend_string_copy(Z_STR_P(field));
}

static zend_result king_inference_resolve_rms_norm_layer_name(
    king_inference_model_object *model,
    king_inference_rms_norm_role role,
    zend_ulong layer,
    zend_string **name_out,
    const char **source_out,
    const char **status_out
) {
    const char *source = NULL;
    const char *scan_status = "not_found";
    bool configured_present = king_inference_rms_norm_configured_pattern_present(model, role);
    zend_string *configured;
    zval *descriptor;

    *name_out = NULL;
    if (source_out != NULL) {
        *source_out = NULL;
    }
    if (status_out != NULL) {
        *status_out = "not_found";
    }

    configured = king_inference_rms_norm_configured_layer_name(
        model,
        role,
        layer,
        &source,
        status_out
    );
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
        if (!king_inference_rms_norm_descriptor_valid(model, descriptor)) {
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

    *name_out = king_inference_rms_norm_known_layer_name(model, role, layer);
    if (*name_out != NULL) {
        if (source_out != NULL) {
            *source_out = "known_pattern";
        }
        if (status_out != NULL) {
            *status_out = "resolved";
        }
        return SUCCESS;
    }

    *name_out = king_inference_rms_norm_layer_shape_scan(model, role, layer, &scan_status);
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

static zend_result king_inference_resolve_rms_norm_final_name(
    king_inference_model_object *model,
    zend_string **name_out,
    const char **source_out,
    const char **status_out
) {
    const char *source = NULL;
    const char *scan_status = "not_found";
    zend_string *configured;
    zval *descriptor;

    *name_out = NULL;
    if (source_out != NULL) {
        *source_out = NULL;
    }
    if (status_out != NULL) {
        *status_out = "not_found";
    }

    configured = king_inference_rms_norm_configured_final_name(model, &source);
    if (configured != NULL) {
        descriptor = king_inference_tensor_index_descriptor(model, configured);
        if (descriptor == NULL) {
            zend_string_release(configured);
            if (status_out != NULL) {
                *status_out = "configured_tensor_not_found";
            }
            return FAILURE;
        }
        if (!king_inference_rms_norm_descriptor_valid(model, descriptor)) {
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

    *name_out = king_inference_rms_norm_known_final_name(model);
    if (*name_out != NULL) {
        if (source_out != NULL) {
            *source_out = "known_name";
        }
        if (status_out != NULL) {
            *status_out = "resolved";
        }
        return SUCCESS;
    }

    *name_out = king_inference_rms_norm_final_shape_scan(model, &scan_status);
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

static void king_inference_add_rms_norm_patterns(zval *rms_norm)
{
    zval patterns;
    zval final_names;
    int role;
    size_t i;

    array_init(&patterns);
    for (role = KING_INFERENCE_RMS_NORM_ATTENTION; role <= KING_INFERENCE_RMS_NORM_FEED_FORWARD; role++) {
        zval role_patterns;
        const char **known = king_inference_rms_norm_role_patterns((king_inference_rms_norm_role) role);

        array_init(&role_patterns);
        for (i = 0; known[i] != NULL; i++) {
            add_next_index_string(&role_patterns, known[i]);
        }
        add_assoc_zval(&patterns, king_inference_rms_norm_role_keys[role], &role_patterns);
    }

    array_init(&final_names);
    for (i = 0; king_inference_rms_norm_final_names[i] != NULL; i++) {
        add_next_index_string(&final_names, king_inference_rms_norm_final_names[i]);
    }
    add_assoc_zval(&patterns, "final", &final_names);
    add_assoc_zval(rms_norm, "known_patterns", &patterns);
}

static void king_inference_add_rms_norm_config_fields(zval *rms_norm)
{
    zval fields;
    int role;

    array_init(&fields);
    for (role = KING_INFERENCE_RMS_NORM_ATTENTION; role <= KING_INFERENCE_RMS_NORM_FEED_FORWARD; role++) {
        add_assoc_string(
            &fields,
            king_inference_rms_norm_role_keys[role],
            king_inference_rms_norm_config_fields[role]
        );
    }
    add_assoc_string(&fields, "final", "rms_norm_final_tensor");
    add_assoc_zval(rms_norm, "configured_fields", &fields);
}

static bool king_inference_add_rms_norm_layer_role_entry(
    king_inference_model_object *model,
    zval *layer_entry,
    king_inference_rms_norm_role role,
    zend_ulong layer
) {
    zend_string *name = NULL;
    const char *source = NULL;
    const char *status = "not_found";
    zend_result result;
    zval entry;
    zend_ulong expected_width = king_inference_rms_norm_expected_width(model);

    result = king_inference_resolve_rms_norm_layer_name(
        model,
        role,
        layer,
        &name,
        &source,
        &status
    );

    array_init(&entry);
    add_assoc_bool(&entry, "resolved", result == SUCCESS && name != NULL);
    add_assoc_string(&entry, "status", status != NULL ? status : "not_found");
    add_assoc_long(&entry, "expected_elements", (zend_long) expected_width);
    if (source != NULL) {
        add_assoc_string(&entry, "source", source);
    }
    if (name != NULL) {
        add_assoc_str(&entry, "name", name);
    }
    add_assoc_zval(layer_entry, king_inference_rms_norm_role_keys[role], &entry);

    return result == SUCCESS && name != NULL;
}

static bool king_inference_add_rms_norm_final_entry(
    king_inference_model_object *model,
    zval *rms_norm
) {
    zend_string *name = NULL;
    const char *source = NULL;
    const char *status = "not_found";
    zend_result result;
    zval entry;
    zend_ulong expected_width = king_inference_rms_norm_expected_width(model);

    result = king_inference_resolve_rms_norm_final_name(
        model,
        &name,
        &source,
        &status
    );

    array_init(&entry);
    add_assoc_bool(&entry, "resolved", result == SUCCESS && name != NULL);
    add_assoc_string(&entry, "status", status != NULL ? status : "not_found");
    add_assoc_long(&entry, "expected_elements", (zend_long) expected_width);
    if (source != NULL) {
        add_assoc_string(&entry, "source", source);
    }
    if (name != NULL) {
        add_assoc_str(&entry, "name", name);
    }
    add_assoc_zval(rms_norm, "final", &entry);

    return result == SUCCESS && name != NULL;
}

static void king_inference_add_rms_norm_resolution_info(
    king_inference_model_object *model,
    zval *resolved
) {
    zend_ulong block_count = model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT];
    zend_ulong layer;
    zend_ulong resolved_layers = 0;
    zend_ulong resolved_tensors = 0;
    bool final_resolved;
    zval rms_norm;
    zval layers;

    array_init(&rms_norm);
    array_init(&layers);

    final_resolved = king_inference_add_rms_norm_final_entry(model, &rms_norm);
    if (final_resolved) {
        resolved_tensors++;
    }

    for (layer = 0; layer < block_count; layer++) {
        zval layer_entry;
        bool layer_complete = true;
        int role;

        array_init(&layer_entry);
        add_assoc_long(&layer_entry, "index", (zend_long) layer);
        for (role = KING_INFERENCE_RMS_NORM_ATTENTION; role <= KING_INFERENCE_RMS_NORM_FEED_FORWARD; role++) {
            bool role_resolved = king_inference_add_rms_norm_layer_role_entry(
                model,
                &layer_entry,
                (king_inference_rms_norm_role) role,
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

    add_assoc_bool(
        &rms_norm,
        "resolved",
        block_count > 0 && resolved_layers == block_count && final_resolved
    );
    add_assoc_string(
        &rms_norm,
        "status",
        block_count == 0 ? "shape_metadata_unavailable" :
            (resolved_layers == block_count && final_resolved ? "resolved" : "partial")
    );
    add_assoc_long(&rms_norm, "block_count", (zend_long) block_count);
    add_assoc_long(&rms_norm, "resolved_layers", (zend_long) resolved_layers);
    add_assoc_long(&rms_norm, "resolved_tensors", (zend_long) resolved_tensors);
    add_assoc_long(&rms_norm, "expected_elements", (zend_long) king_inference_rms_norm_expected_width(model));
    king_inference_add_rms_norm_config_fields(&rms_norm);
    king_inference_add_rms_norm_patterns(&rms_norm);
    add_assoc_zval(&rms_norm, "layers", &layers);
    add_assoc_zval(resolved, "rms_norm", &rms_norm);
}
