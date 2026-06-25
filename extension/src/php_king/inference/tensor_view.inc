typedef struct _king_inference_tensor_type_info {
    const char *name;
    zend_ulong block_size;
    zend_ulong type_size;
} king_inference_tensor_type_info;

static bool king_inference_tensor_type_lookup(
    zend_ulong type,
    king_inference_tensor_type_info *info
) {
    info->name = "unknown";
    info->block_size = 0;
    info->type_size = 0;

    switch (type) {
        case 0: info->name = "F32"; info->block_size = 1; info->type_size = 4; return true;
        case 1: info->name = "F16"; info->block_size = 1; info->type_size = 2; return true;
        case 2: info->name = "Q4_0"; info->block_size = 32; info->type_size = 18; return true;
        case 3: info->name = "Q4_1"; info->block_size = 32; info->type_size = 20; return true;
        case 6: info->name = "Q5_0"; info->block_size = 32; info->type_size = 22; return true;
        case 7: info->name = "Q5_1"; info->block_size = 32; info->type_size = 24; return true;
        case 8: info->name = "Q8_0"; info->block_size = 32; info->type_size = 34; return true;
        case 9: info->name = "Q8_1"; info->block_size = 32; info->type_size = 40; return true;
        case 10: info->name = "Q2_K"; info->block_size = 256; info->type_size = 84; return true;
        case 11: info->name = "Q3_K"; info->block_size = 256; info->type_size = 110; return true;
        case 12: info->name = "Q4_K"; info->block_size = 256; info->type_size = 144; return true;
        case 13: info->name = "Q5_K"; info->block_size = 256; info->type_size = 176; return true;
        case 14: info->name = "Q6_K"; info->block_size = 256; info->type_size = 210; return true;
        case 15: info->name = "Q8_K"; info->block_size = 256; info->type_size = 292; return true;
        case 16: info->name = "IQ2_XXS"; info->block_size = 256; info->type_size = 66; return true;
        case 17: info->name = "IQ2_XS"; info->block_size = 256; info->type_size = 74; return true;
        case 18: info->name = "IQ3_XXS"; info->block_size = 256; info->type_size = 98; return true;
        case 19: info->name = "IQ1_S"; info->block_size = 256; info->type_size = 50; return true;
        case 20: info->name = "IQ4_NL"; info->block_size = 32; info->type_size = 18; return true;
        case 21: info->name = "IQ3_S"; info->block_size = 256; info->type_size = 110; return true;
        case 22: info->name = "IQ2_S"; info->block_size = 256; info->type_size = 70; return true;
        case 23: info->name = "IQ4_XS"; info->block_size = 256; info->type_size = 136; return true;
        case 24: info->name = "I8"; info->block_size = 1; info->type_size = 1; return true;
        case 25: info->name = "I16"; info->block_size = 1; info->type_size = 2; return true;
        case 26: info->name = "I32"; info->block_size = 1; info->type_size = 4; return true;
        case 27: info->name = "I64"; info->block_size = 1; info->type_size = 8; return true;
        case 28: info->name = "F64"; info->block_size = 1; info->type_size = 8; return true;
        case 29: info->name = "IQ1_M"; info->block_size = 256; info->type_size = 56; return true;
        case 30: info->name = "BF16"; info->block_size = 1; info->type_size = 2; return true;
        case 31: info->name = "Q4_0_4_4"; info->block_size = 32; info->type_size = 18; return true;
        case 32: info->name = "Q4_0_4_8"; info->block_size = 32; info->type_size = 18; return true;
        case 33: info->name = "Q4_0_8_8"; info->block_size = 32; info->type_size = 18; return true;
    }

    return false;
}

static bool king_inference_tensor_descriptor_ulong(
    zval *descriptor,
    const char *key,
    size_t key_len,
    zend_ulong *value
) {
    zval *field;

    if (descriptor == NULL || Z_TYPE_P(descriptor) != IS_ARRAY) {
        return false;
    }

    field = zend_hash_str_find(Z_ARRVAL_P(descriptor), key, key_len);
    if (field == NULL || Z_TYPE_P(field) != IS_LONG || Z_LVAL_P(field) < 0) {
        return false;
    }

    *value = (zend_ulong) Z_LVAL_P(field);
    return true;
}

static bool king_inference_tensor_mul_checked(
    zend_ulong left,
    zend_ulong right,
    zend_ulong *result
) {
    if (right != 0 && left > ZEND_ULONG_MAX / right) {
        return false;
    }

    *result = left * right;
    return true;
}

static bool king_inference_tensor_add_checked(
    zend_ulong left,
    zend_ulong right,
    zend_ulong *result
) {
    if (left > ZEND_ULONG_MAX - right) {
        return false;
    }

    *result = left + right;
    return true;
}

static zend_result king_inference_tensor_descriptor_view_array(
    king_inference_model_object *model,
    zend_string *name,
    zval *descriptor,
    zval *return_value
) {
    king_inference_tensor_type_info type_info;
    zend_ulong rank;
    zend_ulong type;
    zend_ulong elements;
    zend_ulong relative_offset;
    zend_ulong blocks = 0;
    zend_ulong byte_length = 0;
    zend_ulong absolute_offset = 0;
    zend_ulong byte_end = 0;
    bool known_type;
    bool byte_length_safe = false;
    bool absolute_offset_safe = false;
    bool byte_end_safe = false;
    bool bounds_safe = false;
    zval *dimensions;
    zval dimensions_copy;

    if (!king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)
        || !king_inference_tensor_descriptor_ulong(descriptor, "elements", sizeof("elements") - 1, &elements)
        || !king_inference_tensor_descriptor_ulong(descriptor, "relative_offset", sizeof("relative_offset") - 1, &relative_offset)) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King inference tensor '%s' has an invalid internal descriptor.",
            ZSTR_VAL(name)
        );
        return FAILURE;
    }

    dimensions = zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1);
    if (dimensions == NULL || Z_TYPE_P(dimensions) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King inference tensor '%s' has no valid dimension vector.",
            ZSTR_VAL(name)
        );
        return FAILURE;
    }

    known_type = king_inference_tensor_type_lookup(type, &type_info);
    if (known_type && type_info.block_size > 0) {
        blocks = elements == 0 ? 0 : 1 + ((elements - 1) / type_info.block_size);
        byte_length_safe = king_inference_tensor_mul_checked(blocks, type_info.type_size, &byte_length);
    }
    absolute_offset_safe = king_inference_tensor_add_checked(
        model->gguf.tensor_data_offset,
        relative_offset,
        &absolute_offset
    );
    byte_end_safe = absolute_offset_safe
        && byte_length_safe
        && king_inference_tensor_add_checked(absolute_offset, byte_length, &byte_end);
    bounds_safe = known_type
        && byte_length_safe
        && byte_end_safe
        && byte_end <= model->gguf.file_size;

    array_init(return_value);
    add_assoc_str(return_value, "name", zend_string_copy(name));
    add_assoc_long(return_value, "rank", (zend_long) rank);
    ZVAL_COPY(&dimensions_copy, dimensions);
    add_assoc_zval(return_value, "dimensions", &dimensions_copy);
    add_assoc_long(return_value, "type", (zend_long) type);
    add_assoc_string(return_value, "type_name", type_info.name);
    add_assoc_bool(return_value, "known_type", known_type);
    add_assoc_bool(return_value, "quantized", type_info.block_size > 1);
    add_assoc_long(return_value, "block_size", (zend_long) type_info.block_size);
    add_assoc_long(return_value, "type_size", (zend_long) type_info.type_size);
    add_assoc_long(return_value, "elements", (zend_long) elements);
    add_assoc_long(return_value, "blocks", (zend_long) blocks);
    add_assoc_long(return_value, "relative_offset", (zend_long) relative_offset);
    add_assoc_long(return_value, "tensor_data_offset", (zend_long) model->gguf.tensor_data_offset);
    add_assoc_long(return_value, "absolute_offset", (zend_long) absolute_offset);
    add_assoc_long(return_value, "byte_length", (zend_long) byte_length);
    add_assoc_long(return_value, "byte_end", (zend_long) byte_end);
    add_assoc_bool(return_value, "bounds_safe", bounds_safe);
    add_assoc_bool(return_value, "native_mapped", model->native_map_loaded);
    add_assoc_long(return_value, "native_map_bytes", (zend_long) model->native_map_size);
    add_assoc_bool(return_value, "native_view_ready", bounds_safe && model->native_map_loaded);

    return SUCCESS;
}

static zend_result king_inference_tensor_view_array(
    king_inference_model_object *model,
    zend_string *name,
    zval *return_value
) {
    zval *descriptor;

    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King inference model has no native tensor index."
        );
        return FAILURE;
    }

    descriptor = zend_hash_find(Z_ARRVAL(model->tensor_index), name);
    if (descriptor == NULL) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King inference tensor '%s' was not found in the model index.",
            ZSTR_VAL(name)
        );
        return FAILURE;
    }

    return king_inference_tensor_descriptor_view_array(model, name, descriptor, return_value);
}

static bool king_inference_tensor_name_matches_prefix(
    zend_string *name,
    zend_string *prefix
) {
    if (prefix == NULL || ZSTR_LEN(prefix) == 0) {
        return true;
    }
    if (name == NULL || ZSTR_LEN(name) < ZSTR_LEN(prefix)) {
        return false;
    }

    return memcmp(ZSTR_VAL(name), ZSTR_VAL(prefix), ZSTR_LEN(prefix)) == 0;
}

static void king_inference_tensor_index_array(
    king_inference_model_object *model,
    zval *options,
    zval *return_value
) {
    zend_long limit = 256;
    zend_string *prefix = NULL;
    zend_ulong matched = 0;
    zend_ulong returned = 0;
    zval tensors;
    zval *option;
    zend_string *name;
    zval *descriptor;

    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King inference model has no native tensor index."
        );
        RETURN_THROWS();
    }

    if (options != NULL && Z_TYPE_P(options) == IS_ARRAY) {
        option = king_inference_array_find(options, "limit");
        if (option != NULL && Z_TYPE_P(option) == IS_LONG) {
            limit = Z_LVAL_P(option);
        }
        option = king_inference_array_find(options, "prefix");
        if (option != NULL && Z_TYPE_P(option) == IS_STRING) {
            prefix = Z_STR_P(option);
        }
    }

    array_init(&tensors);
    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(model->tensor_index), name, descriptor) {
        zval view;

        if (name == NULL || !king_inference_tensor_name_matches_prefix(name, prefix)) {
            continue;
        }
        matched++;
        if (limit > 0 && returned >= (zend_ulong) limit) {
            continue;
        }
        if (king_inference_tensor_descriptor_view_array(model, name, descriptor, &view) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            RETURN_THROWS();
        }
        zend_hash_update(Z_ARRVAL(tensors), name, &view);
        returned++;
    } ZEND_HASH_FOREACH_END();

    array_init(return_value);
    add_assoc_long(return_value, "count", (zend_long) zend_hash_num_elements(Z_ARRVAL(model->tensor_index)));
    add_assoc_long(return_value, "matched", (zend_long) matched);
    add_assoc_long(return_value, "returned", (zend_long) returned);
    add_assoc_long(return_value, "limit", limit);
    add_assoc_bool(return_value, "truncated", limit > 0 && matched > returned);
    if (prefix != NULL) {
        add_assoc_str(return_value, "prefix", zend_string_copy(prefix));
    }
    add_assoc_zval(return_value, "tensors", &tensors);
}
