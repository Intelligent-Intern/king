#define KING_INFERENCE_ARCH_CONTEXT_LENGTH 0
#define KING_INFERENCE_ARCH_EMBEDDING_LENGTH 1
#define KING_INFERENCE_ARCH_BLOCK_COUNT 2
#define KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT 3
#define KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV 4
#define KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH 5
#define KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH 6
#define KING_INFERENCE_ARCH_FEED_FORWARD_LENGTH 7
#define KING_INFERENCE_ARCH_ATTENTION_SLIDING_WINDOW 8

static bool king_inference_gguf_architecture_equals(
    king_inference_gguf_metadata *metadata,
    const char *architecture,
    size_t architecture_len
) {
    return metadata->architecture != NULL
        && ZSTR_LEN(metadata->architecture) == architecture_len
        && memcmp(ZSTR_VAL(metadata->architecture), architecture, architecture_len) == 0;
}

static bool king_inference_gguf_architecture_is_gemma3(king_inference_gguf_metadata *metadata)
{
    return king_inference_gguf_architecture_equals(metadata, "gemma3", sizeof("gemma3") - 1);
}

static bool king_inference_gguf_architecture_is_gemma4(king_inference_gguf_metadata *metadata)
{
    return king_inference_gguf_architecture_equals(metadata, "gemma4", sizeof("gemma4") - 1);
}

static bool king_inference_gguf_head_dim_derivable(king_inference_gguf_metadata *metadata)
{
    zend_ulong embedding = metadata->architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong heads = metadata->architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT];

    return embedding > 0 && heads > 0 && embedding % heads == 0;
}

static void king_inference_gguf_add_missing_arch_field(
    zval *missing,
    bool present,
    const char *name
) {
    if (!present) {
        add_next_index_string(missing, name);
    }
}

static bool king_inference_gguf_architecture_shape_ready(
    king_inference_gguf_metadata *metadata,
    zval *missing
) {
    bool derivable_head_dim = king_inference_gguf_head_dim_derivable(metadata);

    array_init(missing);
    king_inference_gguf_add_missing_arch_field(
        missing,
        metadata->architecture != NULL && ZSTR_LEN(metadata->architecture) > 0,
        "general.architecture"
    );
    king_inference_gguf_add_missing_arch_field(
        missing,
        metadata->architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH] > 0,
        "context_length"
    );
    king_inference_gguf_add_missing_arch_field(
        missing,
        metadata->architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH] > 0,
        "embedding_length"
    );
    king_inference_gguf_add_missing_arch_field(
        missing,
        metadata->architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT] > 0,
        "block_count"
    );
    king_inference_gguf_add_missing_arch_field(
        missing,
        metadata->architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT] > 0,
        "attention.head_count"
    );
    king_inference_gguf_add_missing_arch_field(
        missing,
        metadata->architecture_params[KING_INFERENCE_ARCH_FEED_FORWARD_LENGTH] > 0,
        "feed_forward_length"
    );
    king_inference_gguf_add_missing_arch_field(
        missing,
        metadata->architecture_params[KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH] > 0 || derivable_head_dim,
        "attention.key_length_or_derivable_head_dim"
    );
    king_inference_gguf_add_missing_arch_field(
        missing,
        metadata->architecture_params[KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH] > 0 || derivable_head_dim,
        "attention.value_length_or_derivable_head_dim"
    );

    return zend_hash_num_elements(Z_ARRVAL_P(missing)) == 0;
}

static void king_inference_add_gguf_architecture_support_array(
    king_inference_gguf_metadata *metadata,
    zval *gguf
) {
    zval supported;
    zval missing;
    bool gemma3 = king_inference_gguf_architecture_is_gemma3(metadata);
    bool gemma4 = king_inference_gguf_architecture_is_gemma4(metadata);
    bool supported_architecture = gemma3 || gemma4;
    bool shape_ready = king_inference_gguf_architecture_shape_ready(metadata, &missing);
    const char *profile = gemma3 ? "gemma3" : (gemma4 ? "gemma4" : "unsupported");
    const char *status = "unsupported_architecture";

    if (metadata->architecture == NULL || ZSTR_LEN(metadata->architecture) == 0) {
        status = "missing_architecture";
    } else if (supported_architecture && shape_ready) {
        status = "supported";
    } else if (supported_architecture) {
        status = "supported_missing_shape_metadata";
    }

    array_init(&supported);
    add_next_index_string(&supported, "gemma3");
    add_next_index_string(&supported, "gemma4");

    add_assoc_zval(gguf, "supported_architectures", &supported);
    add_assoc_bool(gguf, "architecture_supported", supported_architecture);
    add_assoc_string(gguf, "architecture_family", supported_architecture ? "gemma" : "unknown");
    add_assoc_long(gguf, "architecture_generation", gemma3 ? 3 : (gemma4 ? 4 : 0));
    add_assoc_string(gguf, "decoder_profile", profile);
    add_assoc_bool(gguf, "decoder_shape_ready", shape_ready);
    add_assoc_bool(gguf, "decoder_ready", supported_architecture && shape_ready);
    add_assoc_string(gguf, "architecture_support_status", status);
    add_assoc_zval(gguf, "architecture_missing_fields", &missing);
}

static bool king_inference_gguf_key_has_suffix(zend_string *key, const char *suffix)
{
    size_t suffix_len = strlen(suffix);

    return ZSTR_LEN(key) >= suffix_len
        && memcmp(ZSTR_VAL(key) + ZSTR_LEN(key) - suffix_len, suffix, suffix_len) == 0;
}

static zend_result king_inference_gguf_read_architecture_numeric(
    FILE *file,
    zend_ulong file_size,
    zend_string *path,
    zend_string *key,
    zend_ulong type,
    king_inference_gguf_metadata *metadata,
    bool *handled
) {
    zend_ulong index = 0;
    zend_long numeric_value;

    *handled = true;
    if (king_inference_gguf_key_has_suffix(key, ".context_length")) {
        index = KING_INFERENCE_ARCH_CONTEXT_LENGTH;
    } else if (king_inference_gguf_key_has_suffix(key, ".embedding_length")) {
        index = KING_INFERENCE_ARCH_EMBEDDING_LENGTH;
    } else if (king_inference_gguf_key_has_suffix(key, ".block_count")) {
        index = KING_INFERENCE_ARCH_BLOCK_COUNT;
    } else if (king_inference_gguf_key_has_suffix(key, ".attention.head_count_kv")) {
        index = KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV;
    } else if (king_inference_gguf_key_has_suffix(key, ".attention.head_count")) {
        index = KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT;
    } else if (king_inference_gguf_key_has_suffix(key, ".attention.key_length")) {
        index = KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH;
    } else if (king_inference_gguf_key_has_suffix(key, ".attention.value_length")) {
        index = KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH;
    } else if (king_inference_gguf_key_has_suffix(key, ".feed_forward_length")) {
        index = KING_INFERENCE_ARCH_FEED_FORWARD_LENGTH;
    } else if (king_inference_gguf_key_has_suffix(key, ".attention.sliding_window")) {
        index = KING_INFERENCE_ARCH_ATTENTION_SLIDING_WINDOW;
    } else {
        *handled = false;
        return SUCCESS;
    }

    if (king_inference_gguf_read_numeric_long(file, file_size, type, path, &numeric_value) != SUCCESS) {
        return FAILURE;
    }
    if (numeric_value > 0) {
        metadata->architecture_params[index] = (zend_ulong) numeric_value;
    }

    return SUCCESS;
}
