static const char *king_inference_token_embedding_candidates[] = {
    "token_embd.weight",
    "tok_embeddings.weight",
    "model.tok_embeddings.weight",
    "model.embed_tokens.weight",
    "embed_tokens.weight",
    "decoder.embed_tokens.weight",
    "model.decoder.embed_tokens.weight",
    "language_model.embed_tokens.weight",
    "language_model.model.embed_tokens.weight",
    "transformer.wte.weight",
    "gpt_neox.embed_in.weight",
    "word_embeddings.weight",
    "embeddings.word_embeddings.weight",
    NULL
};

static bool king_inference_tensor_descriptor_dimension(
    zval *descriptor,
    zend_ulong index,
    zend_ulong *value
) {
    zval *dimensions;

    if (descriptor == NULL || Z_TYPE_P(descriptor) != IS_ARRAY) {
        return false;
    }
    dimensions = zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1);
    if (dimensions == NULL || Z_TYPE_P(dimensions) != IS_ARRAY) {
        return false;
    }

    return king_inference_tensor_dimension_at(dimensions, index, value) == SUCCESS;
}

static bool king_inference_tensor_descriptor_rank(zval *descriptor, zend_ulong *rank)
{
    return king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, rank);
}

static bool king_inference_token_embedding_descriptor_valid(
    king_inference_model_object *model,
    zval *descriptor
) {
    zend_ulong rank;
    zend_ulong columns;
    zend_ulong rows;
    zend_ulong embedding_length = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong tokenizer_rows = model->gguf.tokenizer_token_count;

    if (!king_inference_tensor_descriptor_rank(descriptor, &rank)
        || rank != 2
        || !king_inference_tensor_descriptor_dimension(descriptor, 0, &columns)
        || !king_inference_tensor_descriptor_dimension(descriptor, 1, &rows)
        || columns == 0
        || rows == 0) {
        return false;
    }
    if (embedding_length > 0 && columns != embedding_length) {
        return false;
    }
    if (tokenizer_rows > 0 && rows < tokenizer_rows) {
        return false;
    }

    return true;
}

static bool king_inference_tensor_name_contains(zend_string *name, const char *needle)
{
    return php_memnstr(
        ZSTR_VAL(name),
        needle,
        strlen(needle),
        ZSTR_VAL(name) + ZSTR_LEN(name)
    ) != NULL;
}

static bool king_inference_token_embedding_name_hint(zend_string *name)
{
    if (king_inference_tensor_name_contains(name, "output")
        || king_inference_tensor_name_contains(name, "lm_head")
        || king_inference_tensor_name_contains(name, "classifier")) {
        return false;
    }

    return king_inference_tensor_name_contains(name, "embd")
        || king_inference_tensor_name_contains(name, "embed")
        || king_inference_tensor_name_contains(name, "embedding")
        || king_inference_tensor_name_contains(name, "tok")
        || king_inference_tensor_name_contains(name, "wte")
        || king_inference_tensor_name_contains(name, "word");
}

static zend_string *king_inference_tensor_configured_name_from_array(
    zval *source,
    bool allow_tensor_field,
    const char **source_name
) {
    zval *field;

    if (source == NULL || Z_TYPE_P(source) != IS_ARRAY) {
        return NULL;
    }

    if (allow_tensor_field) {
        field = king_inference_array_find(source, "tensor");
        if (field != NULL && Z_TYPE_P(field) == IS_STRING && Z_STRLEN_P(field) > 0) {
            if (source_name != NULL) {
                *source_name = "tensor";
            }
            return zend_string_copy(Z_STR_P(field));
        }
    }

    field = king_inference_array_find(source, "embedding_tensor");
    if (field != NULL && Z_TYPE_P(field) == IS_STRING && Z_STRLEN_P(field) > 0) {
        if (source_name != NULL) {
            *source_name = "embedding_tensor";
        }
        return zend_string_copy(Z_STR_P(field));
    }

    field = king_inference_array_find(source, "token_embedding_tensor");
    if (field != NULL && Z_TYPE_P(field) == IS_STRING && Z_STRLEN_P(field) > 0) {
        if (source_name != NULL) {
            *source_name = "token_embedding_tensor";
        }
        return zend_string_copy(Z_STR_P(field));
    }

    return NULL;
}

static zend_string *king_inference_token_embedding_configured_name(
    king_inference_model_object *model,
    zval *override,
    const char **source_name
) {
    zend_string *name = king_inference_tensor_configured_name_from_array(override, true, source_name);

    if (name != NULL) {
        return name;
    }

    return king_inference_tensor_configured_name_from_array(&model->config, false, source_name);
}

static zval *king_inference_tensor_index_descriptor(
    king_inference_model_object *model,
    zend_string *name
) {
    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        return NULL;
    }

    return zend_hash_find(Z_ARRVAL(model->tensor_index), name);
}

static zend_string *king_inference_token_embedding_known_name(
    king_inference_model_object *model
) {
    size_t i;

    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        return NULL;
    }

    for (i = 0; king_inference_token_embedding_candidates[i] != NULL; i++) {
        const char *candidate = king_inference_token_embedding_candidates[i];
        zval *descriptor = zend_hash_str_find(Z_ARRVAL(model->tensor_index), candidate, strlen(candidate));
        if (descriptor != NULL && king_inference_token_embedding_descriptor_valid(model, descriptor)) {
            return zend_string_init(candidate, strlen(candidate), 0);
        }
    }

    return NULL;
}

static zend_string *king_inference_token_embedding_shape_scan(
    king_inference_model_object *model,
    const char **status
) {
    zval *descriptor;
    zend_string *name;
    zend_string *matched = NULL;
    zend_ulong matches = 0;

    if (model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH] == 0
        || model->gguf.tokenizer_token_count == 0
        || Z_ISUNDEF(model->tensor_index)
        || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        if (status != NULL) {
            *status = "shape_scan_unavailable";
        }
        return NULL;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(model->tensor_index), name, descriptor) {
        if (name == NULL
            || !king_inference_token_embedding_name_hint(name)
            || !king_inference_token_embedding_descriptor_valid(model, descriptor)) {
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

static zend_result king_inference_resolve_token_embedding_tensor_name(
    king_inference_model_object *model,
    zval *override,
    zend_string **name_out,
    const char **source_out,
    const char **status_out
) {
    const char *source = NULL;
    const char *scan_status = "not_found";
    zend_string *configured = king_inference_token_embedding_configured_name(model, override, &source);
    zval *descriptor;

    *name_out = NULL;
    if (source_out != NULL) {
        *source_out = NULL;
    }
    if (status_out != NULL) {
        *status_out = "not_found";
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
        if (!king_inference_token_embedding_descriptor_valid(model, descriptor)) {
            zend_string_release(configured);
            if (status_out != NULL) {
                *status_out = "configured_tensor_invalid_shape";
            }
            return FAILURE;
        }
        *name_out = configured;
        if (source_out != NULL) {
            *source_out = source != NULL ? source : "configured";
        }
        if (status_out != NULL) {
            *status_out = "resolved";
        }
        return SUCCESS;
    }

    *name_out = king_inference_token_embedding_known_name(model);
    if (*name_out != NULL) {
        if (source_out != NULL) {
            *source_out = "known_name";
        }
        if (status_out != NULL) {
            *status_out = "resolved";
        }
        return SUCCESS;
    }

    *name_out = king_inference_token_embedding_shape_scan(model, &scan_status);
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

static void king_inference_add_token_embedding_resolution_info(
    king_inference_model_object *model,
    zval *target
) {
    zend_string *name = NULL;
    const char *source = NULL;
    const char *status = NULL;
    zval resolved;
    zval token_embedding;
    zval candidates;
    size_t i;
    zend_result result = king_inference_resolve_token_embedding_tensor_name(
        model,
        NULL,
        &name,
        &source,
        &status
    );

    array_init(&token_embedding);
    add_assoc_bool(&token_embedding, "resolved", result == SUCCESS && name != NULL);
    add_assoc_string(&token_embedding, "status", status != NULL ? status : "not_found");
    if (source != NULL) {
        add_assoc_string(&token_embedding, "source", source);
    }
    if (name != NULL) {
        add_assoc_str(&token_embedding, "name", name);
    }
    array_init(&candidates);
    for (i = 0; king_inference_token_embedding_candidates[i] != NULL; i++) {
        add_next_index_string(&candidates, king_inference_token_embedding_candidates[i]);
    }
    add_assoc_zval(&token_embedding, "known_names", &candidates);

    array_init(&resolved);
    add_assoc_zval(&resolved, "token_embedding", &token_embedding);
    add_assoc_zval(target, "resolved_tensors", &resolved);
}
