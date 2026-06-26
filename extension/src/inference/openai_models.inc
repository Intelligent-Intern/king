static bool king_inference_openai_is_model(zval *value)
{
    return value != NULL
        && Z_TYPE_P(value) == IS_OBJECT
        && instanceof_function(Z_OBJCE_P(value), king_ce_inference_model);
}

static zend_string *king_inference_openai_model_entry_id(
    zval *model_value,
    zend_string *key,
    zend_ulong index)
{
    king_inference_model_object *model;

    if (key != NULL && ZSTR_LEN(key) > 0) {
        return zend_string_copy(key);
    }

    model = php_king_inference_model_obj_from_zend(Z_OBJ_P(model_value));
    if (model->name != NULL && ZSTR_LEN(model->name) > 0) {
        return zend_string_copy(model->name);
    }

    return strpprintf(0, "king-model-%lu", index);
}

static bool king_inference_openai_models_valid(zval *models)
{
    zval *entry;
    zend_string *key;
    zend_ulong index;
    zend_string *listed_id;
    HashTable listed_ids;
    bool valid = true;

    if (models == NULL || Z_TYPE_P(models) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(models)) == 0) {
        return false;
    }

    zend_hash_init(&listed_ids, 0, NULL, NULL, 0);
    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(models), index, key, entry) {
        if (!king_inference_openai_is_model(entry)) {
            valid = false;
            break;
        }

        listed_id = king_inference_openai_model_entry_id(entry, key, index);
        if (zend_hash_exists(&listed_ids, listed_id)
            || zend_hash_add_empty_element(&listed_ids, listed_id) == NULL) {
            zend_string_release(listed_id);
            valid = false;
            break;
        }
        zend_string_release(listed_id);
    } ZEND_HASH_FOREACH_END();
    zend_hash_destroy(&listed_ids);

    return valid;
}

static zend_string *king_inference_openai_model_owner(zval *model_value, zval *options)
{
    king_inference_model_object *model = php_king_inference_model_obj_from_zend(Z_OBJ_P(model_value));
    zval *owner = king_inference_array_find(options, "owned_by");

    if (owner != NULL && Z_TYPE_P(owner) == IS_STRING && Z_STRLEN_P(owner) > 0) {
        return zend_string_copy(Z_STR_P(owner));
    }

    owner = king_inference_array_find(&model->config, "owned_by");
    if (owner != NULL && Z_TYPE_P(owner) == IS_STRING && Z_STRLEN_P(owner) > 0) {
        return zend_string_copy(Z_STR_P(owner));
    }

    return zend_string_init("king", sizeof("king") - 1, 0);
}

static bool king_inference_openai_model_listing_options_valid(zval *options, const char **message)
{
    zval *owner = king_inference_array_find(options, "owned_by");

    if (message != NULL) {
        *message = NULL;
    }
    if (owner == NULL) {
        return true;
    }
    if (Z_TYPE_P(owner) == IS_STRING && Z_STRLEN_P(owner) > 0) {
        return true;
    }
    if (message != NULL) {
        *message = "OpenAI model listing option owned_by must be a non-empty string when provided.";
    }
    return false;
}

static void king_inference_openai_invalid_backend_capabilities(zval *return_value)
{
    array_init(return_value);
    add_assoc_bool(return_value, "implemented", false);
    add_assoc_bool(return_value, "local", false);
    add_assoc_bool(return_value, "streaming", false);
    add_assoc_bool(return_value, "openai_chat_completions_stream", false);
    add_assoc_bool(return_value, "cancellable", false);
    add_assoc_bool(return_value, "model_registration", true);
    add_assoc_bool(return_value, "model_metadata", false);
    add_assoc_bool(return_value, "gguf_metadata", false);
    add_assoc_bool(return_value, "gguf_architecture_classification", false);
    add_assoc_bool(return_value, "process_runner", false);
    add_assoc_bool(return_value, "native_runtime", false);
    add_assoc_bool(return_value, "native_model_loader", false);
    add_assoc_bool(return_value, "native_tensor_directory", false);
    add_assoc_bool(return_value, "native_tensor_views", false);
    add_assoc_bool(return_value, "native_model_mapping", false);
    add_assoc_bool(return_value, "native_tokenization", false);
    add_assoc_bool(return_value, "native_token_decode", false);
    add_assoc_bool(return_value, "paged_kv_cache", false);
    add_assoc_bool(return_value, "native_graph_streaming", false);
    add_assoc_bool(return_value, "native_token_selection", false);
    add_assoc_bool(return_value, "token_generation", false);
    add_assoc_bool(return_value, "openai_generation", false);
    add_assoc_bool(return_value, "embeddings", false);
    add_assoc_bool(return_value, "gpu", false);
    add_assoc_bool(return_value, "gpu_backend", false);
    add_assoc_bool(return_value, "gpu_runtime_status", false);
    add_assoc_bool(return_value, "gpu_cuda_driver_probe", false);
    add_assoc_bool(return_value, "gpu_cuda_context", false);
    add_assoc_bool(return_value, "gpu_cuda_context_owned", false);
    add_assoc_bool(return_value, "gpu_device_memory_allocator", false);
    add_assoc_bool(return_value, "gpu_host_to_device_weight_upload", false);
    add_assoc_bool(return_value, "gpu_uploaded_weight_cache", false);
    add_assoc_bool(return_value, "gpu_quantized_matvec_kernel", false);
    add_assoc_bool(return_value, "gpu_rms_norm_kernel", false);
    add_assoc_bool(return_value, "gpu_rope_kernel", false);
    add_assoc_bool(return_value, "gpu_attention_scores_kernel", false);
    add_assoc_bool(return_value, "gpu_attention_softmax_kernel", false);
    add_assoc_bool(return_value, "gpu_attention_value_aggregation_kernel", false);
    add_assoc_bool(return_value, "gpu_ffn_swiglu_path", false);
    add_assoc_bool(return_value, "gpu_output_projection_path", false);
    add_assoc_bool(return_value, "gpu_minimized_logits_readback", false);
    add_assoc_bool(return_value, "gpu_embedding_row_loader", false);
    add_assoc_bool(return_value, "gpu_device_vector_ops", false);
    add_assoc_bool(return_value, "gpu_device_kv_cache", false);
    add_assoc_bool(return_value, "gpu_decoder_graph_executor", false);
    add_assoc_bool(return_value, "gpu_decoder_graph_result_envelope", false);
    add_assoc_bool(return_value, "gpu_decoder_graph_execution_plan", false);
    add_assoc_bool(return_value, "gpu_decoder_graph_embedding_execution", false);
    add_assoc_bool(return_value, "gpu_decoder_graph_rms_norm_execution", false);
    add_assoc_bool(return_value, "gpu_decoder_graph_linear_execution", false);
    add_assoc_bool(return_value, "gpu_decoder_graph_slice_execution", false);
    add_assoc_bool(return_value, "gpu_prompt_decoder_loop", false);
    add_assoc_bool(return_value, "gpu_plain_text_chat_generation", false);
    add_assoc_bool(return_value, "gpu_vram_admission", false);
    add_assoc_bool(return_value, "gpu_kv_cache_vram_estimate", false);
    add_assoc_bool(return_value, "gpu_thermal_policy", false);
    add_assoc_bool(return_value, "gpu_thermal_preflight", false);
    add_assoc_bool(return_value, "gpu_thermal_stream_abort", false);
    add_assoc_bool(return_value, "gpu_decoder_kernel", false);
    add_assoc_bool(return_value, "gpu_generation", false);
    add_assoc_bool(return_value, "silent_cpu_fallback", false);
    add_assoc_string(return_value, "model_format", "gguf");
}

static void king_inference_openai_model_object(
    zval *return_value,
    zval *model_value,
    zend_string *key,
    zend_ulong index,
    zval *options)
{
    zend_string *id = king_inference_openai_model_entry_id(model_value, key, index);
    zend_string *owner = king_inference_openai_model_owner(model_value, options);
    king_inference_model_object *model = php_king_inference_model_obj_from_zend(Z_OBJ_P(model_value));
    zval *created = king_inference_array_find(&model->config, "created");
    king_inference_backend_kind kind;
    zval king_meta;
    zval capabilities;
    bool backend_config_valid = true;
    bool created_config_valid = created != NULL && Z_TYPE_P(created) == IS_LONG && Z_LVAL_P(created) >= 0;
    bool gpu_enabled = false;

    array_init(return_value);
    add_assoc_str(return_value, "id", id);
    add_assoc_string(return_value, "object", "model");
    if (created_config_valid && created != NULL) {
        add_assoc_long(return_value, "created", Z_LVAL_P(created));
    } else {
        add_assoc_long(return_value, "created", 0);
    }
    add_assoc_str(return_value, "owned_by", owner);

    if (king_inference_backend_kind_from_config(
        &model->config,
        &kind,
        "king_inference_openai_http_response"
    ) != SUCCESS) {
        if (EG(exception)) {
            zend_clear_exception();
        }
        backend_config_valid = false;
        kind = KING_INFERENCE_BACKEND_LOCAL;
    }

    array_init(&king_meta);
    gpu_enabled = backend_config_valid && king_inference_gpu_enabled(&model->config);
    add_assoc_string(
        &king_meta,
        "backend",
        backend_config_valid ? king_inference_backend_kind_name(kind) : "invalid"
    );
    add_assoc_bool(&king_meta, "backend_config_valid", backend_config_valid);
    add_assoc_bool(
        &king_meta,
        "openai_generation",
        backend_config_valid
            && ((kind == KING_INFERENCE_BACKEND_LOCAL && !gpu_enabled)
                || kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU)
    );
    add_assoc_bool(
        &king_meta,
        "native_graph_streaming",
        backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
    );
    add_assoc_bool(
        &king_meta,
        "native_stream_contract",
        backend_config_valid
            && (kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
                || kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU)
    );
    add_assoc_bool(&king_meta, "embeddings", backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU);
    add_assoc_bool(&king_meta, "gpu_enabled", gpu_enabled);
    if (gpu_enabled) {
        zval gpu_runtime;

        king_inference_gpu_runtime_status_from_model(model, &gpu_runtime);
        add_assoc_zval(&king_meta, "gpu_runtime", &gpu_runtime);
    }
    add_assoc_bool(&king_meta, "created_config_valid", created_config_valid);
    if (backend_config_valid) {
        king_inference_backend_capabilities_array(kind, &capabilities);
    } else {
        king_inference_openai_invalid_backend_capabilities(&capabilities);
    }
    add_assoc_zval(&king_meta, "capabilities", &capabilities);
    add_assoc_zval(return_value, "x_king", &king_meta);
}

static zend_result king_inference_openai_models_response(zval *models, zval *options, zval *return_value)
{
    zval payload;
    zval data;
    zval *entry;
    zend_string *key;
    zend_ulong index;
    zend_string *json = NULL;
    zend_result result;
    const char *options_error = NULL;

    if (!king_inference_openai_models_valid(models)) {
        return king_inference_openai_error_response(
            return_value,
            500,
            "OpenAI-compatible inference router requires a non-empty model registry containing only loaded models with unique listed ids.",
            "server_error"
        );
    }
    if (!king_inference_openai_model_listing_options_valid(options, &options_error)) {
        return king_inference_openai_error_response(
            return_value,
            500,
            options_error != NULL ? options_error : "OpenAI model listing options are invalid.",
            "server_error"
        );
    }

    array_init(&payload);
    add_assoc_string(&payload, "object", "list");
    array_init(&data);

    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(models), index, key, entry) {
        zval model_object;

        king_inference_openai_model_object(&model_object, entry, key, index, options);
        add_next_index_zval(&data, &model_object);
    } ZEND_HASH_FOREACH_END();

    add_assoc_zval(&payload, "data", &data);
    result = king_inference_openai_json_encode(&payload, &json);
    zval_ptr_dtor(&payload);
    if (result != SUCCESS) {
        return FAILURE;
    }

    king_inference_openai_http_array_response(return_value, 200, "application/json", json, false);
    zend_string_release(json);
    return SUCCESS;
}

static zval *king_inference_openai_select_first_model(zval *models)
{
    zval *entry;

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(models), entry) {
        return king_inference_openai_is_model(entry) ? entry : NULL;
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static bool king_inference_openai_model_name_matches(zval *model_value, zend_string *name)
{
    king_inference_model_object *model = php_king_inference_model_obj_from_zend(Z_OBJ_P(model_value));

    return model->name != NULL && zend_string_equals(model->name, name);
}

static bool king_inference_openai_model_listed_id_matches(
    zval *model_value,
    zend_string *key,
    zend_ulong index,
    zend_string *requested)
{
    zend_string *listed_id;
    bool matches;

    listed_id = king_inference_openai_model_entry_id(model_value, key, index);
    matches = zend_string_equals(listed_id, requested);
    zend_string_release(listed_id);
    return matches;
}

static zval *king_inference_openai_find_model(zval *models, zval *payload)
{
    zval *requested = king_inference_array_find(payload, "model");
    zval *entry;
    zend_string *key;
    zend_ulong index;

    if (models == NULL || Z_TYPE_P(models) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(models)) == 0) {
        return NULL;
    }

    if (requested == NULL || Z_TYPE_P(requested) != IS_STRING || Z_STRLEN_P(requested) == 0) {
        return zend_hash_num_elements(Z_ARRVAL_P(models)) == 1
            ? king_inference_openai_select_first_model(models)
            : NULL;
    }

    entry = zend_hash_find(Z_ARRVAL_P(models), Z_STR_P(requested));
    if (king_inference_openai_is_model(entry)) {
        return entry;
    }

    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(models), index, key, entry) {
        if (king_inference_openai_is_model(entry)
            && (king_inference_openai_model_name_matches(entry, Z_STR_P(requested))
                || king_inference_openai_model_listed_id_matches(entry, key, index, Z_STR_P(requested)))) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zval *king_inference_openai_find_model_by_id(zval *models, zend_string *requested)
{
    zval *entry;
    zend_string *key;
    zend_ulong index;

    if (requested == NULL || ZSTR_LEN(requested) == 0) {
        return NULL;
    }

    entry = zend_hash_find(Z_ARRVAL_P(models), requested);
    if (king_inference_openai_is_model(entry)) {
        return entry;
    }

    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(models), index, key, entry) {
        if (king_inference_openai_is_model(entry)
            && (king_inference_openai_model_name_matches(entry, requested)
                || king_inference_openai_model_listed_id_matches(entry, key, index, requested))) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_openai_require_model(
    zval *models,
    zval *decoded,
    const char *endpoint_name,
    zval *return_value,
    zval **model_out)
{
    zval *requested_model;
    const char *model_error = NULL;

    *model_out = NULL;
    if (!king_inference_openai_models_valid(models)) {
        return king_inference_openai_error_response(
            return_value,
            500,
            "OpenAI-compatible inference router requires a non-empty model registry containing only loaded models with unique listed ids.",
            "server_error"
        );
    }

    requested_model = king_inference_array_find(decoded, "model");
    if (!king_inference_openai_model_field_valid(decoded, &model_error)) {
        return king_inference_openai_error_response(
            return_value,
            400,
            model_error != NULL ? model_error : endpoint_name,
            "invalid_request_error"
        );
    }
    if (requested_model == NULL && zend_hash_num_elements(Z_ARRVAL_P(models)) > 1) {
        return king_inference_openai_error_response(
            return_value,
            400,
            "Request must include model when multiple King models are registered.",
            "invalid_request_error"
        );
    }

    *model_out = king_inference_openai_find_model(models, decoded);
    if (*model_out == NULL) {
        return king_inference_openai_error_response(
            return_value,
            404,
            "Requested model is not registered in the King inference router.",
            "invalid_request_error"
        );
    }

    return SUCCESS;
}

static zend_result king_inference_openai_retrieve_model_response(
    zval *models,
    zend_string *model_id,
    zval *options,
    zval *return_value)
{
    zval *entry;
    zend_string *json = NULL;
    zend_result result;
    zval model_object;
    zend_string *key;
    zend_ulong index;
    const char *options_error = NULL;

    if (!king_inference_openai_models_valid(models)) {
        return king_inference_openai_error_response(
            return_value,
            500,
            "OpenAI-compatible inference router requires a non-empty model registry containing only loaded models with unique listed ids.",
            "server_error"
        );
    }
    if (!king_inference_openai_model_listing_options_valid(options, &options_error)) {
        return king_inference_openai_error_response(
            return_value,
            500,
            options_error != NULL ? options_error : "OpenAI model listing options are invalid.",
            "server_error"
        );
    }

    entry = king_inference_openai_find_model_by_id(models, model_id);
    if (entry == NULL) {
        return king_inference_openai_error_response(
            return_value,
            404,
            "Requested model is not registered in the King inference router.",
            "invalid_request_error"
        );
    }

    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(models), index, key, entry) {
        if (king_inference_openai_is_model(entry)
            && (zend_hash_find(Z_ARRVAL_P(models), model_id) == entry
                || king_inference_openai_model_name_matches(entry, model_id)
                || king_inference_openai_model_listed_id_matches(entry, key, index, model_id))) {
            king_inference_openai_model_object(&model_object, entry, key, index, options);
            result = king_inference_openai_json_encode(&model_object, &json);
            zval_ptr_dtor(&model_object);
            if (result != SUCCESS) {
                return FAILURE;
            }
            king_inference_openai_http_array_response(return_value, 200, "application/json", json, false);
            zend_string_release(json);
            return SUCCESS;
        }
    } ZEND_HASH_FOREACH_END();

    return king_inference_openai_error_response(
        return_value,
        404,
        "Requested model is not registered in the King inference router.",
        "invalid_request_error"
    );
}
