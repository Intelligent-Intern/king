#define KING_INFERENCE_ARCH_CONTEXT_LENGTH 0
#define KING_INFERENCE_ARCH_EMBEDDING_LENGTH 1
#define KING_INFERENCE_ARCH_BLOCK_COUNT 2
#define KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT 3
#define KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV 4
#define KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH 5
#define KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH 6
#define KING_INFERENCE_ARCH_FEED_FORWARD_LENGTH 7
#define KING_INFERENCE_ARCH_ATTENTION_SLIDING_WINDOW 8

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
