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
