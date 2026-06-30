/*
 * GGUF metadata export helpers.
 */

static void king_inference_add_gguf_metadata_array(
    king_inference_gguf_metadata *metadata,
    zval *return_value
) {
    zval gguf;
    zval tensor_types;
    zend_ulong i;

    array_init(&gguf);
    add_assoc_bool(&gguf, "loaded", metadata->loaded);
    add_assoc_bool(&gguf, "header_loaded", metadata->loaded);
    add_assoc_bool(&gguf, "metadata_parsed", metadata->metadata_parsed);
    add_assoc_bool(&gguf, "tensor_directory_parsed", metadata->tensor_directory_parsed);
    add_assoc_long(&gguf, "version", (zend_long) metadata->version);
    add_assoc_long(&gguf, "tensor_count", (zend_long) metadata->tensor_count);
    add_assoc_long(&gguf, "metadata_count", (zend_long) metadata->metadata_count);
    add_assoc_long(&gguf, "parsed_metadata_count", (zend_long) metadata->parsed_metadata_count);
    add_assoc_long(&gguf, "tensor_directory_count", (zend_long) metadata->tensor_directory_count);
    add_assoc_long(&gguf, "tensor_data_offset", (zend_long) metadata->tensor_data_offset);
    add_assoc_long(&gguf, "tensor_data_alignment", (zend_long) metadata->tensor_data_alignment);
    add_assoc_long(&gguf, "file_size", (zend_long) metadata->file_size);
    add_assoc_long(&gguf, "file_type", metadata->file_type);
    add_assoc_long(&gguf, "context_length", (zend_long) metadata->architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH]);
    add_assoc_long(&gguf, "embedding_length", (zend_long) metadata->architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH]);
    add_assoc_long(&gguf, "block_count", (zend_long) metadata->architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT]);
    add_assoc_long(&gguf, "attention_head_count", (zend_long) metadata->architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT]);
    add_assoc_long(&gguf, "attention_head_count_kv", (zend_long) metadata->architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV]);
    add_assoc_long(&gguf, "attention_key_length", (zend_long) metadata->architecture_params[KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH]);
    add_assoc_long(&gguf, "attention_value_length", (zend_long) metadata->architecture_params[KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH]);
    add_assoc_long(&gguf, "feed_forward_length", (zend_long) metadata->architecture_params[KING_INFERENCE_ARCH_FEED_FORWARD_LENGTH]);
    add_assoc_long(&gguf, "attention_sliding_window", (zend_long) metadata->architecture_params[KING_INFERENCE_ARCH_ATTENTION_SLIDING_WINDOW]);
    add_assoc_double(&gguf, "rope_freq_base", metadata->rope_freq_base);
    add_assoc_double(&gguf, "rope_freq_base_swa", metadata->rope_freq_base_swa);
    add_assoc_double(&gguf, "attention_layer_norm_rms_epsilon", metadata->attention_layer_norm_rms_epsilon);
    add_assoc_double(&gguf, "final_logit_softcapping", metadata->final_logit_softcapping);
    king_inference_add_gguf_architecture_support_array(metadata, &gguf);
    add_assoc_long(&gguf, "tokenizer_token_count", (zend_long) metadata->tokenizer_token_count);
    add_assoc_long(&gguf, "tokenizer_score_count", (zend_long) metadata->tokenizer_score_count);
    add_assoc_long(&gguf, "tokenizer_type_count", (zend_long) metadata->tokenizer_type_count);
    add_assoc_long(&gguf, "tokenizer_merge_count", (zend_long) metadata->tokenizer_merge_count);
    add_assoc_long(&gguf, "tokenizer_max_token_bytes", (zend_long) metadata->tokenizer_max_token_bytes);
    add_assoc_bool(&gguf, "tokenizer_tokens_loaded", metadata->tokenizer_tokens_loaded);
    add_assoc_bool(&gguf, "tokenizer_lookup_loaded", metadata->tokenizer_lookup_loaded);
    add_assoc_bool(&gguf, "tokenizer_merges_loaded", metadata->tokenizer_merges_loaded);
    add_assoc_long(&gguf, "tokenizer_bos_id", metadata->tokenizer_bos_id);
    add_assoc_long(&gguf, "tokenizer_eos_id", metadata->tokenizer_eos_id);
    add_assoc_long(&gguf, "tokenizer_unknown_id", metadata->tokenizer_unknown_id);
    add_assoc_long(&gguf, "tokenizer_pad_id", metadata->tokenizer_pad_id);
    add_assoc_long(&gguf, "max_tensor_rank", (zend_long) metadata->max_tensor_rank);
    add_assoc_long(&gguf, "max_tensor_elements", (zend_long) metadata->max_tensor_elements);
    if (metadata->architecture != NULL) {
        add_assoc_str(&gguf, "architecture", zend_string_copy(metadata->architecture));
    }
    if (metadata->general_name != NULL) {
        add_assoc_str(&gguf, "general_name", zend_string_copy(metadata->general_name));
    }
    if (metadata->tokenizer_model != NULL) {
        add_assoc_str(&gguf, "tokenizer_model", zend_string_copy(metadata->tokenizer_model));
    }

    array_init(&tensor_types);
    for (i = 0; i < 32; i++) {
        if (metadata->tensor_type_counts[i] > 0) {
            add_index_long(&tensor_types, i, (zend_long) metadata->tensor_type_counts[i]);
        }
    }
    add_assoc_zval(&gguf, "tensor_type_counts", &tensor_types);
    add_assoc_zval(return_value, "gguf", &gguf);
}
