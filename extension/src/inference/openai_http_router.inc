static zval *king_inference_openai_request_field(zval *request, const char *name)
{
    return king_inference_array_find(request, name);
}

static bool king_inference_openai_request_path_is(zval *request, const char *path)
{
    zval *uri = king_inference_openai_request_field(request, "uri");
    size_t path_len = strlen(path);

    if (uri == NULL) {
        uri = king_inference_openai_request_field(request, "path");
    }
    if (uri == NULL || Z_TYPE_P(uri) != IS_STRING) {
        return false;
    }

    return Z_STRLEN_P(uri) == path_len
        ? memcmp(Z_STRVAL_P(uri), path, path_len) == 0
        : Z_STRLEN_P(uri) > path_len
            && memcmp(Z_STRVAL_P(uri), path, path_len) == 0
            && Z_STRVAL_P(uri)[path_len] == '?';
}

static zend_string *king_inference_openai_request_path_suffix(zval *request, const char *prefix)
{
    zval *uri = king_inference_openai_request_field(request, "uri");
    size_t prefix_len = strlen(prefix);
    const char *start;
    const char *end;

    if (uri == NULL) {
        uri = king_inference_openai_request_field(request, "path");
    }
    if (uri == NULL || Z_TYPE_P(uri) != IS_STRING || Z_STRLEN_P(uri) <= prefix_len) {
        return NULL;
    }
    if (memcmp(Z_STRVAL_P(uri), prefix, prefix_len) != 0) {
        return NULL;
    }

    start = Z_STRVAL_P(uri) + prefix_len;
    end = memchr(start, '?', Z_STRLEN_P(uri) - prefix_len);
    if (end == NULL) {
        end = Z_STRVAL_P(uri) + Z_STRLEN_P(uri);
    }
    if (end <= start) {
        return NULL;
    }

    return zend_string_init(start, (size_t) (end - start), 0);
}

static bool king_inference_openai_request_method_is(zval *request, const char *method)
{
    zval *actual = king_inference_openai_request_field(request, "method");

    return actual != NULL && Z_TYPE_P(actual) == IS_STRING && strcasecmp(Z_STRVAL_P(actual), method) == 0;
}

static bool king_inference_openai_is_model(zval *value)
{
    return value != NULL
        && Z_TYPE_P(value) == IS_OBJECT
        && instanceof_function(Z_OBJCE_P(value), king_ce_inference_model);
}

static bool king_inference_openai_models_valid(zval *models)
{
    zval *entry;

    if (models == NULL || Z_TYPE_P(models) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(models)) == 0) {
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(models), entry) {
        if (!king_inference_openai_is_model(entry)) {
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
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

static void king_inference_openai_invalid_backend_capabilities(zval *return_value)
{
    array_init(return_value);
    add_assoc_bool(return_value, "implemented", false);
    add_assoc_bool(return_value, "local", false);
    add_assoc_bool(return_value, "streaming", false);
    add_assoc_bool(return_value, "openai_chat_completions_stream", false);
    add_assoc_bool(return_value, "cancellable", false);
    add_assoc_bool(return_value, "model_registration", true);
    add_assoc_bool(return_value, "process_runner", false);
    add_assoc_bool(return_value, "native_runtime", false);
    add_assoc_bool(return_value, "native_model_loader", false);
    add_assoc_bool(return_value, "native_tensor_directory", false);
    add_assoc_bool(return_value, "native_tokenization", false);
    add_assoc_bool(return_value, "paged_kv_cache", false);
    add_assoc_bool(return_value, "native_graph_streaming", false);
    add_assoc_bool(return_value, "native_token_selection", false);
    add_assoc_bool(return_value, "token_generation", false);
    add_assoc_bool(return_value, "gpu", false);
    add_assoc_bool(return_value, "gpu_backend", false);
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

    array_init(return_value);
    add_assoc_str(return_value, "id", id);
    add_assoc_string(return_value, "object", "model");
    if (created != NULL && Z_TYPE_P(created) == IS_LONG) {
        add_assoc_long(return_value, "created", Z_LVAL_P(created));
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
    add_assoc_string(
        &king_meta,
        "backend",
        backend_config_valid ? king_inference_backend_kind_name(kind) : "invalid"
    );
    add_assoc_bool(&king_meta, "backend_config_valid", backend_config_valid);
    add_assoc_bool(&king_meta, "openai_generation", backend_config_valid && kind == KING_INFERENCE_BACKEND_LOCAL);
    add_assoc_bool(
        &king_meta,
        "native_graph_streaming",
        backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
    );
    add_assoc_bool(&king_meta, "embeddings", backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU);
    add_assoc_bool(&king_meta, "gpu_enabled", backend_config_valid && king_inference_gpu_enabled(&model->config));
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

    if (!king_inference_openai_models_valid(models)) {
        return king_inference_openai_error_response(
            return_value,
            500,
            "OpenAI-compatible inference router requires a non-empty model registry containing only loaded models.",
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
        (void) index;
        (void) key;
        if (king_inference_openai_is_model(entry)
            && king_inference_openai_model_name_matches(entry, Z_STR_P(requested))) {
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
        (void) index;
        (void) key;
        if (king_inference_openai_is_model(entry)
            && king_inference_openai_model_name_matches(entry, requested)) {
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
            "OpenAI-compatible inference router requires a non-empty model registry containing only loaded models.",
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

    if (!king_inference_openai_models_valid(models)) {
        return king_inference_openai_error_response(
            return_value,
            500,
            "OpenAI-compatible inference router requires a non-empty model registry containing only loaded models.",
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
                || king_inference_openai_model_name_matches(entry, model_id))) {
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

static zend_result king_inference_openai_router_chat_response(
    zval *models,
    zval *request,
    zval *options,
    zval *return_value)
{
    zval *body;
    zval decoded;
    zval *model;
    zend_result result;

    if (!king_inference_openai_request_method_is(request, "POST")) {
        return king_inference_openai_error_response(
            return_value,
            405,
            "Chat completions endpoint requires POST.",
            "invalid_request_error"
        );
    }

    body = king_inference_array_find(request, "body");
    if (body == NULL || Z_TYPE_P(body) != IS_STRING) {
        return king_inference_openai_error_response(
            return_value,
            400,
            "Chat completions HTTP request requires a JSON string body.",
            "invalid_request_error"
        );
    }

    if (king_inference_openai_json_decode(Z_STR_P(body), &decoded) != SUCCESS) {
        return king_inference_openai_error_response(return_value, 400, king_get_error(), "invalid_request_error");
    }

    result = king_inference_openai_require_model(
        models,
        &decoded,
        "Chat completions request model must be a non-empty string when provided.",
        return_value,
        &model
    );
    if (result != SUCCESS || Z_TYPE_P(return_value) == IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        return result;
    }

    result = king_inference_openai_payload_response(return_value, model, &decoded, options);
    zval_ptr_dtor(&decoded);
    return result;
}

static zend_result king_inference_openai_router_responses_response(
    zval *models,
    zval *request,
    zval *options,
    zval *return_value)
{
    zval *body;
    zval decoded;
    zval *model;
    zend_result result;

    if (!king_inference_openai_request_method_is(request, "POST")) {
        return king_inference_openai_error_response(
            return_value,
            405,
            "Responses endpoint requires POST.",
            "invalid_request_error"
        );
    }

    body = king_inference_array_find(request, "body");
    if (body == NULL || Z_TYPE_P(body) != IS_STRING) {
        return king_inference_openai_error_response(
            return_value,
            400,
            "Responses HTTP request requires a JSON string body.",
            "invalid_request_error"
        );
    }

    if (king_inference_openai_json_decode(Z_STR_P(body), &decoded) != SUCCESS) {
        return king_inference_openai_error_response(return_value, 400, king_get_error(), "invalid_request_error");
    }

    result = king_inference_openai_require_model(
        models,
        &decoded,
        "Responses request model must be a non-empty string when provided.",
        return_value,
        &model
    );
    if (result != SUCCESS || Z_TYPE_P(return_value) == IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        return result;
    }

    result = king_inference_openai_responses_payload_response(return_value, model, &decoded, options);
    zval_ptr_dtor(&decoded);
    return result;
}

static zend_result king_inference_openai_router_completions_response(
    zval *models,
    zval *request,
    zval *options,
    zval *return_value)
{
    zval *body;
    zval decoded;
    zval *model;
    zend_result result;

    if (!king_inference_openai_request_method_is(request, "POST")) {
        return king_inference_openai_error_response(
            return_value,
            405,
            "Completions endpoint requires POST.",
            "invalid_request_error"
        );
    }

    body = king_inference_array_find(request, "body");
    if (body == NULL || Z_TYPE_P(body) != IS_STRING) {
        return king_inference_openai_error_response(
            return_value,
            400,
            "Completions HTTP request requires a JSON string body.",
            "invalid_request_error"
        );
    }

    if (king_inference_openai_json_decode(Z_STR_P(body), &decoded) != SUCCESS) {
        return king_inference_openai_error_response(return_value, 400, king_get_error(), "invalid_request_error");
    }

    result = king_inference_openai_require_model(
        models,
        &decoded,
        "Completions request model must be a non-empty string when provided.",
        return_value,
        &model
    );
    if (result != SUCCESS || Z_TYPE_P(return_value) == IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        return result;
    }

    result = king_inference_openai_completions_payload_response(return_value, model, &decoded, options);
    zval_ptr_dtor(&decoded);
    return result;
}

static zend_result king_inference_openai_router_embeddings_response(
    zval *models,
    zval *request,
    zval *options,
    zval *return_value)
{
    zval *body;
    zval decoded;
    zval *model;
    zend_result result;

    if (!king_inference_openai_request_method_is(request, "POST")) {
        return king_inference_openai_error_response(
            return_value,
            405,
            "Embeddings endpoint requires POST.",
            "invalid_request_error"
        );
    }

    body = king_inference_array_find(request, "body");
    if (body == NULL || Z_TYPE_P(body) != IS_STRING) {
        return king_inference_openai_error_response(
            return_value,
            400,
            "Embeddings HTTP request requires a JSON string body.",
            "invalid_request_error"
        );
    }

    if (king_inference_openai_json_decode(Z_STR_P(body), &decoded) != SUCCESS) {
        return king_inference_openai_error_response(return_value, 400, king_get_error(), "invalid_request_error");
    }

    result = king_inference_openai_require_model(
        models,
        &decoded,
        "Embeddings request model must be a non-empty string when provided.",
        return_value,
        &model
    );
    if (result != SUCCESS || Z_TYPE_P(return_value) == IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        return result;
    }

    result = king_inference_openai_embeddings_payload_response(return_value, model, &decoded, options);
    zval_ptr_dtor(&decoded);
    return result;
}

PHP_FUNCTION(king_inference_openai_http_response)
{
    zval *models;
    zval *request;
    zval *options = NULL;
    zval empty_options;
    zval *effective_options;
    zend_result result;
    zend_string *model_id = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ARRAY(models)
        Z_PARAM_ARRAY(request)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (options == NULL) {
        array_init(&empty_options);
        effective_options = &empty_options;
    } else {
        effective_options = options;
    }

    model_id = king_inference_openai_request_path_suffix(request, "/v1/models/");

    if (king_inference_openai_request_path_is(request, "/v1/models")) {
        if (!king_inference_openai_request_method_is(request, "GET")) {
            result = king_inference_openai_error_response(
                return_value,
                405,
                "Models endpoint requires GET.",
                "invalid_request_error"
            );
        } else {
            result = king_inference_openai_models_response(models, effective_options, return_value);
        }
    } else if (model_id != NULL) {
        if (!king_inference_openai_request_method_is(request, "GET")) {
            result = king_inference_openai_error_response(
                return_value,
                405,
                "Model retrieve endpoint requires GET.",
                "invalid_request_error"
            );
        } else {
            result = king_inference_openai_retrieve_model_response(models, model_id, effective_options, return_value);
        }
    } else if (king_inference_openai_request_path_is(request, "/v1/chat/completions")) {
        result = king_inference_openai_router_chat_response(models, request, effective_options, return_value);
    } else if (king_inference_openai_request_path_is(request, "/v1/responses")) {
        result = king_inference_openai_router_responses_response(models, request, effective_options, return_value);
    } else if (king_inference_openai_request_path_is(request, "/v1/completions")) {
        result = king_inference_openai_router_completions_response(models, request, effective_options, return_value);
    } else if (king_inference_openai_request_path_is(request, "/v1/embeddings")) {
        result = king_inference_openai_router_embeddings_response(models, request, effective_options, return_value);
    } else {
        result = king_inference_openai_error_response(
            return_value,
            404,
            "OpenAI-compatible inference route not found.",
            "invalid_request_error"
        );
    }

    if (options == NULL) {
        zval_ptr_dtor(&empty_options);
    }
    if (model_id != NULL) {
        zend_string_release(model_id);
    }
    if (result != SUCCESS) {
        if (EG(exception)) {
            RETURN_THROWS();
        }
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            king_get_error()[0] != '\0' ? king_get_error() : "OpenAI-compatible inference router failed."
        );
        RETURN_THROWS();
    }
}
