static zend_result king_inference_gguf_read_string_array(
    FILE *file,
    zend_ulong file_size,
    zend_ulong array_count,
    zend_string *path,
    const char *what,
    zval *values,
    zval *lookup,
    zend_ulong *max_entry_bytes
) {
    zend_ulong i;

    for (i = 0; i < array_count; i++) {
        zend_string *entry;
        if (king_inference_gguf_read_string(file, file_size, path, what, &entry) != SUCCESS) {
            return FAILURE;
        }
        if (lookup != NULL && Z_TYPE_P(lookup) == IS_ARRAY) {
            zval id;
            ZVAL_LONG(&id, i > (zend_ulong) ZEND_LONG_MAX ? ZEND_LONG_MAX : (zend_long) i);
            zend_hash_update(Z_ARRVAL_P(lookup), entry, &id);
        }
        if (max_entry_bytes != NULL && ZSTR_LEN(entry) > *max_entry_bytes) {
            *max_entry_bytes = (zend_ulong) ZSTR_LEN(entry);
        }
        if (values != NULL && Z_TYPE_P(values) == IS_ARRAY) {
            add_next_index_str(values, entry);
        } else {
            zend_string_release(entry);
        }
    }

    return SUCCESS;
}

static zend_result king_inference_gguf_read_numeric_long(
    FILE *file,
    zend_ulong file_size,
    zend_ulong type,
    zend_string *path,
    zend_long *value
) {
    zend_ulong unsigned_value;
    unsigned char bytes[8];

    (void) file_size;

    if (type == KING_GGUF_TYPE_UINT32 || type == KING_GGUF_TYPE_INT32) {
        if (king_inference_gguf_read_u32(file, path, "numeric metadata value", &unsigned_value) != SUCCESS) {
            return FAILURE;
        }
        *value = (zend_long) unsigned_value;
        return SUCCESS;
    }

    if (type == KING_GGUF_TYPE_UINT64 || type == KING_GGUF_TYPE_INT64) {
        if (king_inference_gguf_read_u64(file, path, "numeric metadata value", &unsigned_value) != SUCCESS) {
            return FAILURE;
        }
        *value = unsigned_value > (zend_ulong) ZEND_LONG_MAX ? ZEND_LONG_MAX : (zend_long) unsigned_value;
        return SUCCESS;
    }

    if (type == KING_GGUF_TYPE_UINT8 || type == KING_GGUF_TYPE_INT8) {
        if (king_inference_gguf_read_exact(file, bytes, 1, path, "numeric metadata value") != SUCCESS) {
            return FAILURE;
        }
        *value = (zend_long) bytes[0];
        return SUCCESS;
    }

    if (type == KING_GGUF_TYPE_UINT16 || type == KING_GGUF_TYPE_INT16) {
        if (king_inference_gguf_read_exact(file, bytes, 2, path, "numeric metadata value") != SUCCESS) {
            return FAILURE;
        }
        *value = (zend_long) (((zend_ulong) bytes[0]) | ((zend_ulong) bytes[1] << 8));
        return SUCCESS;
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "Model artifact '%s' contains a non-integer GGUF value where an integer was required.",
        ZSTR_VAL(path)
    );
    return FAILURE;
}

static void king_inference_gguf_store_string(zend_string **target, zend_string *value)
{
    if (*target != NULL) {
        zend_string_release(*target);
    }
    *target = value;
}
