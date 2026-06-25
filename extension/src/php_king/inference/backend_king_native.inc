static zend_result king_inference_king_native_cpu_stream_start(
    king_inference_stream_object *stream,
    const char *function_name
) {
    king_inference_model_object *model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));

    if (king_inference_check_gpu_policy(&model->config, function_name) != SUCCESS) {
        return FAILURE;
    }

    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "%s() loaded the GGUF model through King native metadata code, but native token generation is not wired yet. No external inference runtime was used.",
        function_name
    );
    return FAILURE;
}

static void king_inference_king_native_model_info(
    king_inference_model_object *intern,
    zval *return_value
) {
    add_assoc_string(return_value, "engine", "king_native_cpu");
    add_assoc_string(return_value, "loader", "king_gguf_structure");
    add_assoc_bool(return_value, "external_runtime", false);
    add_assoc_bool(return_value, "tokenization_ready", intern->gguf.tokenizer_lookup_loaded);
    add_assoc_bool(return_value, "token_generation_ready", false);
    add_assoc_string(return_value, "generation_runtime", "pending_native_graph");
    add_assoc_long(return_value, "native_file_bytes", (zend_long) intern->gguf.file_size);
    add_assoc_bool(return_value, "native_metadata_ready", intern->gguf.metadata_parsed);
    add_assoc_bool(return_value, "native_tensor_directory_ready", intern->gguf.tensor_directory_parsed);
    add_assoc_bool(return_value, "native_model_mapped", intern->native_map_loaded);
    if (!Z_ISUNDEF(intern->paged_kv_cache_plan) && Z_TYPE(intern->paged_kv_cache_plan) == IS_ARRAY) {
        zval *ready = zend_hash_str_find(Z_ARRVAL(intern->paged_kv_cache_plan), "ready", sizeof("ready") - 1);
        add_assoc_bool(return_value, "paged_kv_cache_ready", ready != NULL && zend_is_true(ready));
    }
    add_assoc_bool(return_value, "native_tokenizer_tokens_loaded", intern->gguf.tokenizer_tokens_loaded);
    add_assoc_bool(return_value, "native_tokenizer_lookup_loaded", intern->gguf.tokenizer_lookup_loaded);
    add_assoc_bool(return_value, "native_tokenizer_merges_loaded", intern->gguf.tokenizer_merges_loaded);
    add_assoc_long(return_value, "native_map_bytes", (zend_long) intern->native_map_size);
    add_assoc_long(
        return_value,
        "native_tensor_index_count",
        (zend_long) zend_hash_num_elements(Z_ARRVAL(intern->tensor_index))
    );
    add_assoc_long(
        return_value,
        "native_tokenizer_token_count",
        (zend_long) zend_hash_num_elements(Z_ARRVAL(intern->tokenizer_tokens))
    );
    add_assoc_long(
        return_value,
        "native_tokenizer_merge_count",
        (zend_long) zend_hash_num_elements(Z_ARRVAL(intern->tokenizer_merges))
    );
    add_assoc_long(return_value, "tokenizer_bos_id", intern->gguf.tokenizer_bos_id);
    add_assoc_long(return_value, "tokenizer_eos_id", intern->gguf.tokenizer_eos_id);
    add_assoc_long(return_value, "tokenizer_unknown_id", intern->gguf.tokenizer_unknown_id);
    add_assoc_long(return_value, "tokenizer_pad_id", intern->gguf.tokenizer_pad_id);
    if (intern->gguf.architecture != NULL) {
        add_assoc_str(return_value, "architecture", zend_string_copy(intern->gguf.architecture));
    }
    if (intern->gguf.tokenizer_model != NULL) {
        add_assoc_str(return_value, "tokenizer_model", zend_string_copy(intern->gguf.tokenizer_model));
    }
}
