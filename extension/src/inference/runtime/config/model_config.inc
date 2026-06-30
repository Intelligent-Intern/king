static zend_result king_inference_config_path_string(
    zval *value,
    const char *field_name,
    const char *function_name,
    zend_string **path_out
) {
    *path_out = NULL;

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a non-empty string filesystem path.",
            function_name,
            field_name
        );
        return FAILURE;
    }

    *path_out = zend_string_copy(Z_STR_P(value));
    return SUCCESS;
}

static zend_result king_inference_validate_optional_string_config(
    zval *config,
    const char *field_name,
    const char *function_name
) {
    zval *value = king_inference_array_find(config, field_name);

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a non-empty string when provided.",
            function_name,
            field_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_validate_optional_positive_long_config(
    zval *config,
    const char *field_name,
    const char *function_name
) {
    zval *value = king_inference_array_find(config, field_name);

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) <= 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a positive integer when provided.",
            function_name,
            field_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_validate_optional_bool_config(
    zval *config,
    const char *field_name,
    const char *function_name
) {
    zval *value = king_inference_array_find(config, field_name);

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s must be a boolean when provided.",
            function_name,
            field_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_validate_optional_bool_alias_config(
    zval *config,
    const char *field_name,
    const char *alias_name,
    const char *function_name
) {
    zval *value = king_inference_array_find(config, field_name);
    zval *alias = king_inference_array_find(config, alias_name);

    if (value != NULL && alias != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() memory config must use either %s or %s, not both.",
            function_name,
            field_name,
            alias_name
        );
        return FAILURE;
    }

    if (king_inference_validate_optional_bool_config(config, field_name, function_name) != SUCCESS
        || king_inference_validate_optional_bool_config(config, alias_name, function_name) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_validate_model_config(zval *config, const char *function_name)
{
    if (king_inference_validate_optional_string_config(config, "name", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "quantization", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "owned_by", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "embedding_tensor", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "token_embedding_tensor", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "output_tensor", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "output_projection_tensor", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "lm_head_tensor", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "attention_query_tensor_pattern", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "attention_key_tensor_pattern", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "attention_value_tensor_pattern", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "attention_output_tensor_pattern", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "rms_norm_attention_tensor_pattern", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "rms_norm_ffn_tensor_pattern", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "rms_norm_final_tensor", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "ffn_gate_tensor_pattern", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "ffn_up_tensor_pattern", function_name) != SUCCESS
        || king_inference_validate_optional_string_config(config, "ffn_down_tensor_pattern", function_name) != SUCCESS
        || king_inference_validate_optional_positive_long_config(config, "context_tokens", function_name) != SUCCESS
        || king_inference_validate_optional_bool_alias_config(config, "with_memory", "with-memory", function_name) != SUCCESS
        || king_inference_validate_gpu_config(config, function_name) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_resolve_artifact_path(
    zval *config,
    const char *function_name,
    zend_string **path_out
)
{
    zval *artifact = king_inference_array_find(config, "artifact");
    zval *path;

    *path_out = NULL;

    if (artifact != NULL) {
        if (Z_TYPE_P(artifact) == IS_STRING) {
            return king_inference_config_path_string(
                artifact,
                "artifact",
                function_name,
                path_out
            );
        }

        if (Z_TYPE_P(artifact) != IS_ARRAY) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() artifact must be a non-empty string path or an array with path.",
                function_name
            );
            return FAILURE;
        }
    }

    if (artifact != NULL) {
        path = king_inference_array_find(artifact, "path");
        if (path != NULL) {
            return king_inference_config_path_string(
                path,
                "artifact.path",
                function_name,
                path_out
            );
        }

        if (king_inference_array_find(artifact, "store") != NULL
            || king_inference_array_find(artifact, "key") != NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "King inference requires a materialized local GGUF model artifact path."
            );
            return FAILURE;
        }

        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() artifact array requires a non-empty path entry.",
            function_name
        );
        return FAILURE;
    }

    path = king_inference_array_find(config, "artifact_path");
    if (path != NULL) {
        return king_inference_config_path_string(
            path,
            "artifact_path",
            function_name,
            path_out
        );
    }

    return SUCCESS;
}

static zend_result king_inference_model_init(zval *target, zval *config, const char *function_name)
{
    zend_string *artifact_path = NULL;
    king_inference_gguf_metadata metadata;
    king_inference_backend_kind backend_kind;
    void *native_map = NULL;
    size_t native_map_size = 0;
    zval tensor_index;
    zval tokenizer_tokens;
    zval tokenizer_lookup;
    zval tokenizer_merges;
    zval *name_value;
    king_inference_model_object *intern;

    if (config == NULL || Z_TYPE_P(config) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "%s() requires a model config array.", function_name);
        return FAILURE;
    }

    if (king_inference_validate_model_config(config, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (king_inference_resolve_artifact_path(config, function_name, &artifact_path) != SUCCESS) {
        return FAILURE;
    }
    if (artifact_path == NULL) {
        if (!EG(exception)) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s() requires artifact or artifact_path.", function_name);
        }
        return FAILURE;
    }

    ZVAL_UNDEF(&tensor_index);
    array_init(&tensor_index);
    ZVAL_UNDEF(&tokenizer_tokens);
    array_init(&tokenizer_tokens);
    ZVAL_UNDEF(&tokenizer_lookup);
    array_init(&tokenizer_lookup);
    ZVAL_UNDEF(&tokenizer_merges);
    array_init(&tokenizer_merges);

    if (king_inference_load_gguf_metadata(
        artifact_path,
        &metadata,
        &tensor_index,
        &tokenizer_tokens,
        &tokenizer_lookup,
        &tokenizer_merges
    ) != SUCCESS) {
        zval_ptr_dtor(&tokenizer_merges);
        zval_ptr_dtor(&tokenizer_lookup);
        zval_ptr_dtor(&tokenizer_tokens);
        zval_ptr_dtor(&tensor_index);
        zend_string_release(artifact_path);
        return FAILURE;
    }
    if (king_inference_backend_kind_from_config(config, &backend_kind, function_name) != SUCCESS
        || king_inference_backend_validate_model_config(config, function_name) != SUCCESS) {
        zval_ptr_dtor(&tokenizer_merges);
        zval_ptr_dtor(&tokenizer_lookup);
        zval_ptr_dtor(&tokenizer_tokens);
        zval_ptr_dtor(&tensor_index);
        king_inference_gguf_metadata_release(&metadata);
        zend_string_release(artifact_path);
        return FAILURE;
    }
    if (king_inference_check_gpu_policy(config, function_name) != SUCCESS) {
        zval_ptr_dtor(&tokenizer_merges);
        zval_ptr_dtor(&tokenizer_lookup);
        zval_ptr_dtor(&tokenizer_tokens);
        zval_ptr_dtor(&tensor_index);
        king_inference_gguf_metadata_release(&metadata);
        zend_string_release(artifact_path);
        return FAILURE;
    }
    if ((backend_kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
            || backend_kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU)
        && king_inference_native_map_artifact(
            artifact_path,
            metadata.file_size,
            &native_map,
            &native_map_size
        ) != SUCCESS) {
        zval_ptr_dtor(&tokenizer_merges);
        zval_ptr_dtor(&tokenizer_lookup);
        zval_ptr_dtor(&tokenizer_tokens);
        zval_ptr_dtor(&tensor_index);
        king_inference_gguf_metadata_release(&metadata);
        zend_string_release(artifact_path);
        return FAILURE;
    }

    intern = php_king_inference_model_obj_from_zend(Z_OBJ_P(target));
    zval_ptr_dtor(&intern->config);
    ZVAL_COPY(&intern->config, config);
    zval_ptr_dtor(&intern->tensor_index);
    ZVAL_COPY_VALUE(&intern->tensor_index, &tensor_index);
    zval_ptr_dtor(&intern->tokenizer_tokens);
    ZVAL_COPY_VALUE(&intern->tokenizer_tokens, &tokenizer_tokens);
    zval_ptr_dtor(&intern->tokenizer_lookup);
    ZVAL_COPY_VALUE(&intern->tokenizer_lookup, &tokenizer_lookup);
    zval_ptr_dtor(&intern->tokenizer_merges);
    ZVAL_COPY_VALUE(&intern->tokenizer_merges, &tokenizer_merges);
    zval_ptr_dtor(&intern->paged_kv_cache_plan);
    ZVAL_UNDEF(&intern->paged_kv_cache_plan);

    if (intern->artifact_path != NULL) {
        zend_string_release(intern->artifact_path);
    }
    king_inference_cuda_logits_readback_release(intern);
    king_inference_cuda_decoder_prompt_loop_release(intern);
    king_inference_cuda_decoder_graph_executor_release(intern);
    king_inference_cuda_output_projection_release(intern);
    king_inference_cuda_ffn_swiglu_release(intern);
    king_inference_cuda_attention_values_release(intern);
    king_inference_cuda_attention_softmax_release(intern);
    king_inference_cuda_attention_scores_release(intern);
    king_inference_cuda_rope_release(intern);
    king_inference_cuda_rms_norm_release(intern);
    king_inference_cuda_device_kv_cache_release(intern);
    king_inference_cuda_device_vector_ops_release(intern);
    king_inference_cuda_embedding_row_release(intern);
    king_inference_cuda_quantized_matvec_release(intern);
    king_inference_cuda_weight_upload_release(intern);
    king_inference_cuda_device_allocator_release(intern);
    king_inference_cuda_context_release(intern);
    king_inference_native_mapping_release(intern);
    king_inference_gguf_metadata_release(&intern->gguf);
    intern->artifact_path = artifact_path;
    intern->gguf = metadata;
    intern->native_map = native_map;
    intern->native_map_size = native_map_size;
    intern->native_map_loaded = native_map != NULL;

    if (intern->name != NULL) {
        zend_string_release(intern->name);
        intern->name = NULL;
    }
    name_value = king_inference_array_find(config, "name");
    intern->name = name_value != NULL && Z_TYPE_P(name_value) == IS_STRING && Z_STRLEN_P(name_value) > 0
        ? zend_string_copy(Z_STR_P(name_value))
        : zend_string_init("local-quantized-model", sizeof("local-quantized-model") - 1, 0);
    king_inference_paged_kv_cache_plan(intern, NULL, &intern->paged_kv_cache_plan);
    king_inference_cuda_context_open(intern, backend_kind);
    king_inference_cuda_device_allocator_init(intern, backend_kind);
    king_inference_cuda_weight_upload_required(intern, backend_kind);
    king_inference_cuda_quantized_matvec_init(intern, backend_kind);
    king_inference_cuda_embedding_row_init(intern, backend_kind);
    king_inference_cuda_device_vector_ops_init(intern, backend_kind);
    king_inference_cuda_device_kv_cache_init(intern, backend_kind);
    king_inference_cuda_rms_norm_init(intern, backend_kind);
    king_inference_cuda_rope_init(intern, backend_kind);
    king_inference_cuda_attention_scores_init(intern, backend_kind);
    king_inference_cuda_attention_softmax_init(intern, backend_kind);
    king_inference_cuda_attention_values_init(intern, backend_kind);
    king_inference_cuda_ffn_swiglu_init(intern, backend_kind);
    king_inference_cuda_output_projection_init(intern, backend_kind);
    king_inference_cuda_logits_readback_init(intern, backend_kind);
    king_inference_cuda_decoder_graph_executor_init(intern, backend_kind);
    king_inference_cuda_decoder_prompt_loop_init(intern, backend_kind);

    king_set_error("");
    return SUCCESS;
}

static void king_inference_model_info_array(king_inference_model_object *intern, zval *return_value)
{
    zval *quantization = king_inference_array_find(&intern->config, "quantization");
    zval runtime_truth;
    zval native_engine_readiness;

    array_init(return_value);
    add_assoc_str(return_value, "name", zend_string_copy(intern->name));
    add_assoc_str(return_value, "artifact_path", zend_string_copy(intern->artifact_path));
    add_assoc_string(return_value, "format", "gguf");
    add_assoc_long(return_value, "artifact_bytes", (zend_long) intern->gguf.file_size);
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
    king_inference_add_gguf_metadata_array(&intern->gguf, return_value);
    king_inference_add_tensor_resolution_info(intern, return_value);
    if (!Z_ISUNDEF(intern->paged_kv_cache_plan)) {
        zval plan;
        ZVAL_COPY(&plan, &intern->paged_kv_cache_plan);
        add_assoc_zval(return_value, "paged_kv_cache", &plan);
    }
    add_assoc_bool(return_value, "gpu_enabled", king_inference_gpu_enabled(&intern->config));
    if (king_inference_gpu_enabled(&intern->config)) {
        zval gpu_runtime;

        king_inference_gpu_runtime_status_from_model(intern, &gpu_runtime);
        add_assoc_zval(return_value, "gpu_runtime", &gpu_runtime);
    }
    king_inference_backend_model_info(intern, return_value);
    king_inference_model_native_engine_readiness_array(intern, &native_engine_readiness);
    add_assoc_zval(return_value, "native_engine_readiness", &native_engine_readiness);
    king_inference_model_runtime_truth_array(intern, &runtime_truth);
    king_inference_model_add_runtime_surface_fields(intern, return_value, &runtime_truth);
    add_assoc_zval(return_value, "runtime_truth", &runtime_truth);

    if (quantization != NULL && Z_TYPE_P(quantization) == IS_STRING) {
        add_assoc_str(return_value, "quantization", zend_string_copy(Z_STR_P(quantization)));
    }
}
