#define KING_GGUF_TYPE_UINT8 0
#define KING_GGUF_TYPE_INT8 1
#define KING_GGUF_TYPE_UINT16 2
#define KING_GGUF_TYPE_INT16 3
#define KING_GGUF_TYPE_UINT32 4
#define KING_GGUF_TYPE_INT32 5
#define KING_GGUF_TYPE_FLOAT32 6
#define KING_GGUF_TYPE_BOOL 7
#define KING_GGUF_TYPE_STRING 8
#define KING_GGUF_TYPE_ARRAY 9
#define KING_GGUF_TYPE_UINT64 10
#define KING_GGUF_TYPE_INT64 11
#define KING_GGUF_TYPE_FLOAT64 12

static zend_ulong king_inference_read_le32(const unsigned char *bytes)
{
    return ((zend_ulong) bytes[0])
        | ((zend_ulong) bytes[1] << 8)
        | ((zend_ulong) bytes[2] << 16)
        | ((zend_ulong) bytes[3] << 24);
}

static zend_ulong king_inference_read_le64(const unsigned char *bytes)
{
    return ((zend_ulong) bytes[0])
        | ((zend_ulong) bytes[1] << 8)
        | ((zend_ulong) bytes[2] << 16)
        | ((zend_ulong) bytes[3] << 24)
        | ((zend_ulong) bytes[4] << 32)
        | ((zend_ulong) bytes[5] << 40)
        | ((zend_ulong) bytes[6] << 48)
        | ((zend_ulong) bytes[7] << 56);
}

static void king_inference_gguf_metadata_release(king_inference_gguf_metadata *metadata)
{
    if (metadata->architecture != NULL) {
        zend_string_release(metadata->architecture);
    }
    if (metadata->general_name != NULL) {
        zend_string_release(metadata->general_name);
    }
    if (metadata->tokenizer_model != NULL) {
        zend_string_release(metadata->tokenizer_model);
    }

    memset(metadata, 0, sizeof(*metadata));
}

static zend_result king_inference_gguf_read_exact(
    FILE *file,
    void *buffer,
    size_t length,
    zend_string *path,
    const char *what
) {
    if (length == 0) {
        return SUCCESS;
    }

    if (fread(buffer, 1, length, file) != length) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Model artifact '%s' ended while reading GGUF %s.",
            ZSTR_VAL(path),
            what
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_gguf_read_u32(
    FILE *file,
    zend_string *path,
    const char *what,
    zend_ulong *value
) {
    unsigned char bytes[4];

    if (king_inference_gguf_read_exact(file, bytes, sizeof(bytes), path, what) != SUCCESS) {
        return FAILURE;
    }

    *value = king_inference_read_le32(bytes);
    return SUCCESS;
}

static zend_result king_inference_gguf_read_u64(
    FILE *file,
    zend_string *path,
    const char *what,
    zend_ulong *value
) {
    unsigned char bytes[8];

    if (king_inference_gguf_read_exact(file, bytes, sizeof(bytes), path, what) != SUCCESS) {
        return FAILURE;
    }

    *value = king_inference_read_le64(bytes);
    return SUCCESS;
}

static zend_result king_inference_gguf_skip_bytes(
    FILE *file,
    zend_ulong file_size,
    zend_ulong length,
    zend_string *path,
    const char *what
) {
    off_t position = ftello(file);
    zend_ulong remaining = length;

    if (position < 0 || (zend_ulong) position > file_size || length > file_size - (zend_ulong) position) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Model artifact '%s' has an invalid GGUF %s boundary.",
            ZSTR_VAL(path),
            what
        );
        return FAILURE;
    }

    while (remaining > 0) {
        off_t chunk = remaining > 1073741824UL ? 1073741824 : (off_t) remaining;
        if (fseeko(file, chunk, SEEK_CUR) != 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Model artifact '%s' could not skip GGUF %s.",
                ZSTR_VAL(path),
                what
            );
            return FAILURE;
        }
        remaining -= (zend_ulong) chunk;
    }

    return SUCCESS;
}

static zend_result king_inference_gguf_read_string(
    FILE *file,
    zend_ulong file_size,
    zend_string *path,
    const char *what,
    zend_string **value
) {
    zend_ulong length;
    off_t position;

    *value = NULL;
    if (king_inference_gguf_read_u64(file, path, what, &length) != SUCCESS) {
        return FAILURE;
    }

    position = ftello(file);
    if (position < 0
        || length > (zend_ulong) ZEND_LONG_MAX
        || (zend_ulong) position > file_size
        || length > file_size - (zend_ulong) position) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Model artifact '%s' has an invalid GGUF %s string length.",
            ZSTR_VAL(path),
            what
        );
        return FAILURE;
    }

    *value = zend_string_alloc((size_t) length, 0);
    if (king_inference_gguf_read_exact(file, ZSTR_VAL(*value), (size_t) length, path, what) != SUCCESS) {
        zend_string_release(*value);
        *value = NULL;
        return FAILURE;
    }
    ZSTR_VAL(*value)[length] = '\0';

    return SUCCESS;
}

static size_t king_inference_gguf_scalar_size(zend_ulong type)
{
    switch (type) {
        case KING_GGUF_TYPE_UINT8:
        case KING_GGUF_TYPE_INT8:
        case KING_GGUF_TYPE_BOOL:
            return 1;
        case KING_GGUF_TYPE_UINT16:
        case KING_GGUF_TYPE_INT16:
            return 2;
        case KING_GGUF_TYPE_UINT32:
        case KING_GGUF_TYPE_INT32:
        case KING_GGUF_TYPE_FLOAT32:
            return 4;
        case KING_GGUF_TYPE_UINT64:
        case KING_GGUF_TYPE_INT64:
        case KING_GGUF_TYPE_FLOAT64:
            return 8;
    }

    return 0;
}

static zend_result king_inference_gguf_skip_array_payload(
    FILE *file,
    zend_ulong file_size,
    zend_ulong array_type,
    zend_ulong array_count,
    zend_string *path,
    zend_ulong depth
);

static zend_result king_inference_gguf_skip_value(
    FILE *file,
    zend_ulong file_size,
    zend_ulong type,
    zend_string *path,
    zend_ulong depth
) {
    zend_ulong array_type;
    zend_ulong array_count;
    size_t scalar_size;

    scalar_size = king_inference_gguf_scalar_size(type);
    if (scalar_size > 0) {
        return king_inference_gguf_skip_bytes(file, file_size, (zend_ulong) scalar_size, path, "scalar value");
    }

    if (type == KING_GGUF_TYPE_STRING) {
        zend_string *ignored;
        if (king_inference_gguf_read_string(file, file_size, path, "string value", &ignored) != SUCCESS) {
            return FAILURE;
        }
        zend_string_release(ignored);
        return SUCCESS;
    }

    if (type == KING_GGUF_TYPE_ARRAY) {
        if (depth > 4) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "Model artifact '%s' has nested GGUF arrays that are too deep.", ZSTR_VAL(path));
            return FAILURE;
        }
        if (king_inference_gguf_read_u32(file, path, "array type", &array_type) != SUCCESS
            || king_inference_gguf_read_u64(file, path, "array count", &array_count) != SUCCESS) {
            return FAILURE;
        }

        return king_inference_gguf_skip_array_payload(file, file_size, array_type, array_count, path, depth);
    }

    zend_throw_exception_ex(king_ce_validation_exception, 0, "Model artifact '%s' contains unsupported GGUF value type %lu.", ZSTR_VAL(path), type);
    return FAILURE;
}

static zend_result king_inference_gguf_skip_array_payload(
    FILE *file,
    zend_ulong file_size,
    zend_ulong array_type,
    zend_ulong array_count,
    zend_string *path,
    zend_ulong depth
) {
    size_t scalar_size = king_inference_gguf_scalar_size(array_type);
    zend_ulong i;

    if (scalar_size > 0) {
        if (array_count > ZEND_ULONG_MAX / (zend_ulong) scalar_size) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "Model artifact '%s' has an oversized GGUF array.", ZSTR_VAL(path));
            return FAILURE;
        }
        return king_inference_gguf_skip_bytes(
            file,
            file_size,
            array_count * (zend_ulong) scalar_size,
            path,
            "array payload"
        );
    }

    for (i = 0; i < array_count; i++) {
        if (king_inference_gguf_skip_value(file, file_size, array_type, path, depth + 1) != SUCCESS) {
            return FAILURE;
        }
    }

    return SUCCESS;
}

#include "../metadata/gguf_metadata_helpers.inc"
#include "../metadata/gguf_architecture_metadata.inc"

static zend_result king_inference_gguf_read_metadata_value(
    FILE *file,
    zend_ulong file_size,
    zend_string *path,
    zend_string *key,
    zend_ulong type,
    king_inference_gguf_metadata *metadata,
    zval *tokenizer_tokens,
    zval *tokenizer_lookup,
    zval *tokenizer_scores,
    zval *tokenizer_types,
    zval *tokenizer_merges
) {
    zend_string *string_value;
    zend_ulong array_type;
    zend_ulong array_count;
    zend_long numeric_value;
    double floating_value;
    bool architecture_numeric = false;

    if (type == KING_GGUF_TYPE_STRING) {
        if (king_inference_gguf_read_string(file, file_size, path, "metadata value", &string_value) != SUCCESS) {
            return FAILURE;
        }
        if (zend_string_equals_literal(key, "general.architecture")) {
            king_inference_gguf_store_string(&metadata->architecture, string_value);
        } else if (zend_string_equals_literal(key, "general.name")) {
            king_inference_gguf_store_string(&metadata->general_name, string_value);
        } else if (zend_string_equals_literal(key, "tokenizer.ggml.model")) {
            king_inference_gguf_store_string(&metadata->tokenizer_model, string_value);
        } else {
            zend_string_release(string_value);
        }
        return SUCCESS;
    }

    if (type == KING_GGUF_TYPE_ARRAY) {
        if (king_inference_gguf_read_u32(file, path, "metadata array type", &array_type) != SUCCESS
            || king_inference_gguf_read_u64(file, path, "metadata array count", &array_count) != SUCCESS) {
            return FAILURE;
        }
        if (zend_string_equals_literal(key, "tokenizer.ggml.tokens")) {
            metadata->tokenizer_token_count = array_count;
            if (array_type == KING_GGUF_TYPE_STRING) {
                if (king_inference_gguf_read_string_array(
                    file,
                    file_size,
                    array_count,
                    path,
                    "tokenizer token",
                    tokenizer_tokens,
                    tokenizer_lookup,
                    &metadata->tokenizer_max_token_bytes
                ) != SUCCESS) {
                    return FAILURE;
                }
                metadata->tokenizer_tokens_loaded = true;
                metadata->tokenizer_lookup_loaded = true;
                return SUCCESS;
            }
        } else if (zend_string_equals_literal(key, "tokenizer.ggml.scores")) {
            metadata->tokenizer_score_count = array_count;
            if (array_type == KING_GGUF_TYPE_FLOAT32 || array_type == KING_GGUF_TYPE_FLOAT64) {
                if (king_inference_gguf_read_double_array(
                    file,
                    file_size,
                    array_type,
                    array_count,
                    path,
                    tokenizer_scores
                ) != SUCCESS) {
                    return FAILURE;
                }
                metadata->tokenizer_scores_loaded = true;
                return SUCCESS;
            }
        } else if (zend_string_equals_literal(key, "tokenizer.ggml.token_type")) {
            metadata->tokenizer_type_count = array_count;
            if (array_type == KING_GGUF_TYPE_UINT8
                || array_type == KING_GGUF_TYPE_INT8
                || array_type == KING_GGUF_TYPE_UINT16
                || array_type == KING_GGUF_TYPE_INT16
                || array_type == KING_GGUF_TYPE_UINT32
                || array_type == KING_GGUF_TYPE_INT32
                || array_type == KING_GGUF_TYPE_UINT64
                || array_type == KING_GGUF_TYPE_INT64) {
                if (king_inference_gguf_read_long_array(
                    file,
                    file_size,
                    array_type,
                    array_count,
                    path,
                    tokenizer_types
                ) != SUCCESS) {
                    return FAILURE;
                }
                metadata->tokenizer_types_loaded = true;
                return SUCCESS;
            }
        } else if (zend_string_equals_literal(key, "tokenizer.ggml.merges")) {
            metadata->tokenizer_merge_count = array_count;
            if (array_type == KING_GGUF_TYPE_STRING) {
                if (king_inference_gguf_read_string_array(
                    file,
                    file_size,
                    array_count,
                    path,
                    "tokenizer merge",
                    tokenizer_merges,
                    NULL,
                    NULL
                ) != SUCCESS) {
                    return FAILURE;
                }
                metadata->tokenizer_merges_loaded = true;
                return SUCCESS;
            }
        }

        if (array_count == 0) {
            return SUCCESS;
        }
        return king_inference_gguf_skip_array_payload(file, file_size, array_type, array_count, path, 0);
    }

    if (zend_string_equals_literal(key, "general.file_type")) {
        if (king_inference_gguf_read_numeric_long(file, file_size, type, path, &numeric_value) != SUCCESS) {
            return FAILURE;
        }
        metadata->file_type = numeric_value;
        return SUCCESS;
    }

    if (zend_string_equals_literal(key, "tokenizer.ggml.bos_token_id")) {
        if (king_inference_gguf_read_numeric_long(file, file_size, type, path, &numeric_value) != SUCCESS) {
            return FAILURE;
        }
        metadata->tokenizer_bos_id = numeric_value;
        return SUCCESS;
    }

    if (zend_string_equals_literal(key, "tokenizer.ggml.eos_token_id")) {
        if (king_inference_gguf_read_numeric_long(file, file_size, type, path, &numeric_value) != SUCCESS) {
            return FAILURE;
        }
        metadata->tokenizer_eos_id = numeric_value;
        return SUCCESS;
    }

    if (zend_string_equals_literal(key, "tokenizer.ggml.unknown_token_id")) {
        if (king_inference_gguf_read_numeric_long(file, file_size, type, path, &numeric_value) != SUCCESS) {
            return FAILURE;
        }
        metadata->tokenizer_unknown_id = numeric_value;
        return SUCCESS;
    }

    if (zend_string_equals_literal(key, "tokenizer.ggml.padding_token_id")) {
        if (king_inference_gguf_read_numeric_long(file, file_size, type, path, &numeric_value) != SUCCESS) {
            return FAILURE;
        }
        metadata->tokenizer_pad_id = numeric_value;
        return SUCCESS;
    }

    if (king_inference_gguf_key_has_suffix(key, ".rope.freq_base")
        || king_inference_gguf_key_has_suffix(key, ".rope.global.freq_base")) {
        if (king_inference_gguf_read_numeric_double(file, file_size, type, path, &floating_value) != SUCCESS) {
            return FAILURE;
        }
        if (floating_value > 0.0 && isfinite(floating_value)) {
            metadata->rope_freq_base = floating_value;
        }
        return SUCCESS;
    }

    if (king_inference_gguf_key_has_suffix(key, ".rope.freq_base_swa")
        || king_inference_gguf_key_has_suffix(key, ".rope.local.freq_base")) {
        if (king_inference_gguf_read_numeric_double(file, file_size, type, path, &floating_value) != SUCCESS) {
            return FAILURE;
        }
        if (floating_value > 0.0 && isfinite(floating_value)) {
            metadata->rope_freq_base_swa = floating_value;
        }
        return SUCCESS;
    }

    if (king_inference_gguf_key_has_suffix(key, ".attention.layer_norm_rms_epsilon")) {
        if (king_inference_gguf_read_numeric_double(file, file_size, type, path, &floating_value) != SUCCESS) {
            return FAILURE;
        }
        if (floating_value > 0.0 && isfinite(floating_value)) {
            metadata->attention_layer_norm_rms_epsilon = floating_value;
        }
        return SUCCESS;
    }

    if (king_inference_gguf_key_has_suffix(key, ".final_logit_softcapping")) {
        if (king_inference_gguf_read_numeric_double(file, file_size, type, path, &floating_value) != SUCCESS) {
            return FAILURE;
        }
        if (floating_value > 0.0 && isfinite(floating_value)) {
            metadata->final_logit_softcapping = floating_value;
        }
        return SUCCESS;
    }

    if (king_inference_gguf_read_architecture_numeric(
        file,
        file_size,
        path,
        key,
        type,
        metadata,
        &architecture_numeric
    ) != SUCCESS) {
        return FAILURE;
    }
    if (architecture_numeric) {
        return SUCCESS;
    }

    if (zend_string_equals_literal(key, "general.alignment")) {
        if (king_inference_gguf_read_numeric_long(file, file_size, type, path, &numeric_value) != SUCCESS) {
            return FAILURE;
        }
        if (numeric_value > 0) {
            metadata->tensor_data_alignment = (zend_ulong) numeric_value;
        }
        return SUCCESS;
    }

    return king_inference_gguf_skip_value(file, file_size, type, path, 0);
}

static zend_result king_inference_gguf_parse_metadata(
    FILE *file,
    zend_string *path,
    king_inference_gguf_metadata *metadata,
    zval *tokenizer_tokens,
    zval *tokenizer_lookup,
    zval *tokenizer_scores,
    zval *tokenizer_types,
    zval *tokenizer_merges
) {
    zend_ulong i;

    for (i = 0; i < metadata->metadata_count; i++) {
        zend_string *key;
        zend_ulong type;

        if (king_inference_gguf_read_string(file, metadata->file_size, path, "metadata key", &key) != SUCCESS) {
            return FAILURE;
        }
        if (king_inference_gguf_read_u32(file, path, "metadata value type", &type) != SUCCESS) {
            zend_string_release(key);
            return FAILURE;
        }
        if (king_inference_gguf_read_metadata_value(
            file,
            metadata->file_size,
            path,
            key,
            type,
            metadata,
            tokenizer_tokens,
            tokenizer_lookup,
            tokenizer_scores,
            tokenizer_types,
            tokenizer_merges
        ) != SUCCESS) {
            zend_string_release(key);
            return FAILURE;
        }
        zend_string_release(key);
        metadata->parsed_metadata_count++;
    }

    metadata->metadata_parsed = true;
    return SUCCESS;
}

static zend_ulong king_inference_gguf_aligned_offset(zend_ulong offset, zend_ulong alignment)
{
    zend_ulong remainder;

    if (alignment == 0) {
        return offset;
    }
    remainder = offset % alignment;
    if (remainder == 0) {
        return offset;
    }
    if (offset > ZEND_ULONG_MAX - (alignment - remainder)) {
        return ZEND_ULONG_MAX;
    }

    return offset + (alignment - remainder);
}

static zend_result king_inference_gguf_parse_tensor_directory(
    FILE *file,
    zend_string *path,
    king_inference_gguf_metadata *metadata,
    zval *tensor_index
) {
    zend_ulong i;
    off_t position;

    for (i = 0; i < metadata->tensor_count; i++) {
        zend_string *name;
        zend_ulong rank;
        zend_ulong type;
        zend_ulong offset;
        zend_ulong elements = 1;
        zend_ulong d;
        zval descriptor;
        zval dimensions;
        bool dimensions_attached = false;

        if (king_inference_gguf_read_string(file, metadata->file_size, path, "tensor name", &name) != SUCCESS) {
            return FAILURE;
        }

        if (king_inference_gguf_read_u32(file, path, "tensor rank", &rank) != SUCCESS) {
            zend_string_release(name);
            return FAILURE;
        }
        if (rank > 16) {
            zend_string_release(name);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "Model artifact '%s' contains a tensor rank above 16.", ZSTR_VAL(path));
            return FAILURE;
        }
        if (rank > metadata->max_tensor_rank) {
            metadata->max_tensor_rank = rank;
        }

        array_init(&dimensions);
        for (d = 0; d < rank; d++) {
            zend_ulong dimension;
            if (king_inference_gguf_read_u64(file, path, "tensor dimension", &dimension) != SUCCESS) {
                zval_ptr_dtor(&dimensions);
                zend_string_release(name);
                return FAILURE;
            }
            if (dimension == 0) {
                zval_ptr_dtor(&dimensions);
                zend_string_release(name);
                zend_throw_exception_ex(king_ce_validation_exception, 0, "Model artifact '%s' contains a zero-sized tensor dimension.", ZSTR_VAL(path));
                return FAILURE;
            }
            add_next_index_long(&dimensions, (zend_long) dimension);
            if (elements <= ZEND_ULONG_MAX / dimension) {
                elements *= dimension;
            } else {
                elements = ZEND_ULONG_MAX;
            }
        }
        if (elements > metadata->max_tensor_elements) {
            metadata->max_tensor_elements = elements;
        }

        if (king_inference_gguf_read_u32(file, path, "tensor type", &type) != SUCCESS
            || king_inference_gguf_read_u64(file, path, "tensor offset", &offset) != SUCCESS) {
            zval_ptr_dtor(&dimensions);
            zend_string_release(name);
            return FAILURE;
        }
        if (type < 32) {
            metadata->tensor_type_counts[type]++;
        }

        if (tensor_index != NULL && Z_TYPE_P(tensor_index) == IS_ARRAY) {
            array_init(&descriptor);
            add_assoc_long(&descriptor, "rank", (zend_long) rank);
            add_assoc_zval(&descriptor, "dimensions", &dimensions);
            dimensions_attached = true;
            add_assoc_long(&descriptor, "type", (zend_long) type);
            add_assoc_long(&descriptor, "relative_offset", (zend_long) offset);
            add_assoc_long(&descriptor, "elements", (zend_long) elements);
            zend_hash_update(Z_ARRVAL_P(tensor_index), name, &descriptor);
        }

        if (!dimensions_attached) {
            zval_ptr_dtor(&dimensions);
        }
        zend_string_release(name);
        metadata->tensor_directory_count++;
    }

    position = ftello(file);
    if (position < 0 || (zend_ulong) position > metadata->file_size) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Model artifact '%s' has an invalid GGUF tensor data boundary.", ZSTR_VAL(path));
        return FAILURE;
    }

    if (metadata->tensor_data_alignment == 0) {
        metadata->tensor_data_alignment = 32;
    }
    metadata->tensor_data_offset = king_inference_gguf_aligned_offset(
        (zend_ulong) position,
        metadata->tensor_data_alignment
    );
    if (metadata->tensor_data_offset > metadata->file_size) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Model artifact '%s' has a GGUF tensor data offset beyond the file.", ZSTR_VAL(path));
        return FAILURE;
    }

    metadata->tensor_directory_parsed = true;
    return SUCCESS;
}

static zend_result king_inference_load_gguf_metadata(
    zend_string *path,
    king_inference_gguf_metadata *metadata,
    zval *tensor_index,
    zval *tokenizer_tokens,
    zval *tokenizer_lookup,
    zval *tokenizer_scores,
    zval *tokenizer_types,
    zval *tokenizer_merges
) {
    struct stat st;
    FILE *file;
    unsigned char header[24];

    memset(metadata, 0, sizeof(*metadata));
    metadata->file_type = -1;
    metadata->tokenizer_bos_id = -1;
    metadata->tokenizer_eos_id = -1;
    metadata->tokenizer_unknown_id = -1;
    metadata->tokenizer_pad_id = -1;

    if (ZSTR_LEN(path) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Model artifact path must not be empty.");
        return FAILURE;
    }

    if (stat(ZSTR_VAL(path), &st) != 0 || !S_ISREG(st.st_mode)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Model artifact '%s' is not a readable regular file.",
            ZSTR_VAL(path)
        );
        return FAILURE;
    }
    metadata->file_size = (zend_ulong) st.st_size;

    file = fopen(ZSTR_VAL(path), "rb");
    if (file == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Model artifact '%s' could not be opened.",
            ZSTR_VAL(path)
        );
        return FAILURE;
    }

    if (king_inference_gguf_read_exact(file, header, sizeof(header), path, "header") != SUCCESS) {
        fclose(file);
        return FAILURE;
    }

    if (memcmp(header, "GGUF", 4) != 0) {
        fclose(file);
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Model artifact '%s' is not a complete GGUF quantized model artifact.",
            ZSTR_VAL(path)
        );
        return FAILURE;
    }

    metadata->version = king_inference_read_le32(header + 4);
    metadata->tensor_count = king_inference_read_le64(header + 8);
    metadata->metadata_count = king_inference_read_le64(header + 16);
    metadata->loaded = true;

    if (metadata->version == 0 || metadata->tensor_count == 0) {
        fclose(file);
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Model artifact '%s' has an invalid GGUF header.",
            ZSTR_VAL(path)
        );
        return FAILURE;
    }

    if (king_inference_gguf_parse_metadata(
            file,
            path,
            metadata,
            tokenizer_tokens,
            tokenizer_lookup,
            tokenizer_scores,
            tokenizer_types,
            tokenizer_merges
        ) != SUCCESS
        || king_inference_gguf_parse_tensor_directory(file, path, metadata, tensor_index) != SUCCESS) {
        fclose(file);
        king_inference_gguf_metadata_release(metadata);
        return FAILURE;
    }

    fclose(file);
    return SUCCESS;
}

#include "../metadata/gguf_metadata_info.inc"
