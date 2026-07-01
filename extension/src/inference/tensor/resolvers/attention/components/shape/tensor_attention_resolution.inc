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

