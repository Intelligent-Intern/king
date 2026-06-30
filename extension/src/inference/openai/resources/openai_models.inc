#include "openai_route_reuse_profile.inc"
#include "openai_runtime_surface.inc"
#include "openai_invalid_backend_capabilities.inc"

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

static void king_inference_openai_client_capabilities_array(
    zval *return_value,
    bool backend_config_valid,
    king_inference_backend_kind kind,
    bool gpu_enabled,
    bool gpu_generation_ready)
{
    bool local_backend = kind == KING_INFERENCE_BACKEND_LOCAL;
    bool native_cpu_backend = kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    bool native_gpu_backend = kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
    bool openai_text_generation = backend_config_valid
        && ((local_backend && !gpu_enabled)
            || native_cpu_backend
            || (native_gpu_backend && gpu_generation_ready));
    bool native_stream_contract = backend_config_valid
        && (native_cpu_backend || native_gpu_backend);

    array_init(return_value);
    add_assoc_long(return_value, "version", 1);
    add_assoc_bool(return_value, "model_selectable", backend_config_valid);
    add_assoc_bool(return_value, "generation_ready", openai_text_generation);
    add_assoc_bool(return_value, "plain_text_chat", openai_text_generation);
    add_assoc_bool(return_value, "openai_chat_completions", openai_text_generation);
    add_assoc_bool(return_value, "openai_chat_completions_stream", openai_text_generation);
    add_assoc_bool(return_value, "openai_responses", openai_text_generation);
    add_assoc_bool(return_value, "openai_completions", openai_text_generation);
    add_assoc_bool(return_value, "openai_embeddings", backend_config_valid && native_cpu_backend);
    add_assoc_bool(return_value, "prompt_to_logits_generation", openai_text_generation);
    add_assoc_bool(return_value, "native_graph_streaming", backend_config_valid && native_cpu_backend);
    add_assoc_bool(return_value, "native_stream_contract", native_stream_contract);
    add_assoc_bool(return_value, "synthetic_token_vector_graph", native_stream_contract);
    add_assoc_string(
        return_value,
        "synthetic_token_vector_score_semantics",
        native_stream_contract ? "direct_token_id_uses_probability_1_logit_0_rank_0" : "unavailable"
    );
    add_assoc_bool(return_value, "requires_gpu", gpu_enabled || native_gpu_backend);
    add_assoc_bool(return_value, "gpu_runtime_required", gpu_enabled || native_gpu_backend);
    add_assoc_bool(return_value, "gpu_generation_ready", backend_config_valid && native_gpu_backend && gpu_generation_ready);
    add_assoc_bool(return_value, "openai_tools", false);
    add_assoc_bool(return_value, "openai_tool_calls", false);
    add_assoc_bool(return_value, "openai_function_calling", false);
    add_assoc_bool(return_value, "openai_parallel_tool_calls", false);
    add_assoc_bool(return_value, "openai_vision", false);
    add_assoc_bool(return_value, "openai_audio", false);
    add_assoc_string(return_value, "tool_call_status", "unsupported");
}

static const char *king_inference_openai_route_hot_path(
    king_inference_model_object *model,
    king_inference_backend_kind kind,
    bool backend_config_valid,
    bool openai_generation_ready
) {
    if (!backend_config_valid || !openai_generation_ready) {
        return "fallback_unavailable";
    }

    switch (kind) {
        case KING_INFERENCE_BACKEND_KING_NATIVE_GPU:
            if (model->cuda_decoder_prompt_loop_last_used_batch_prefill) {
                return "batch_prefill_decode";
            }
            if (model->cuda_decoder_prompt_loop_last_prompt_tokens > 0) {
                return "sequential_prefill_decode";
            }
            if (king_inference_cuda_decoder_prompt_loop_batch_prefill_admitted(model)) {
                return "batch_prefill_decode";
            }
            if (model->cuda_decoder_prompt_loop_available) {
                return "sequential_prefill_decode";
            }
            return "fallback_unavailable";

        case KING_INFERENCE_BACKEND_KING_NATIVE_CPU:
            return "cached_decode";

        case KING_INFERENCE_BACKEND_LOCAL:
            return "fallback";
    }

    return "fallback_unavailable";
}

static const char *king_inference_openai_route_last_hot_path(
    king_inference_model_object *model,
    king_inference_backend_kind kind
) {
    if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        && model->cuda_decoder_prompt_loop_last_prompt_tokens > 0) {
        return model->cuda_decoder_prompt_loop_last_used_batch_prefill
            ? "batch_prefill_decode"
            : "sequential_prefill_decode";
    }

    if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
        && model->runtime_last_generated_tokens > 0) {
        return "cached_decode";
    }

    if (kind == KING_INFERENCE_BACKEND_LOCAL
        && model->runtime_last_generated_tokens > 0) {
        return "fallback";
    }

    return "not_observed";
}

static void king_inference_openai_route_status_array(
    zval *return_value,
    king_inference_model_object *model,
    king_inference_backend_kind kind,
    bool backend_config_valid,
    bool openai_generation_ready,
    zval *native_engine_readiness
) {
    bool native_backend = kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
        || kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
    bool gpu_backend = kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU;
    bool lazy_gpu_token_stream = gpu_backend && model->cuda_decoder_prompt_loop_available;
    bool native_plain_text_admitted = king_inference_native_readiness_bool(
        native_engine_readiness,
        "plain_text_generation_admitted"
    );
    bool native_graph_payload_admitted = king_inference_native_readiness_bool(
        native_engine_readiness,
        "native_graph_payload_admitted"
    );

    array_init(return_value);
    add_assoc_long(return_value, "schema_version", 1);
    add_assoc_bool(return_value, "openai_generation_ready", openai_generation_ready);
    add_assoc_string(return_value, "native_engine_admission_source", "x_king.native_engine_readiness");
    add_assoc_bool(return_value, "native_engine_plain_text_generation_admitted", native_plain_text_admitted);
    add_assoc_bool(return_value, "native_engine_graph_payload_admitted", native_graph_payload_admitted);
    add_assoc_string(return_value, "prompt_formatting_owner", "openai_router");
    add_assoc_string(return_value, "tool_fields_owner", "openai_router");
    add_assoc_string(return_value, "error_response_owner", "openai_router");
    add_assoc_bool(return_value, "stream_transport", true);
    add_assoc_string(return_value, "stream_transport_mode", "sse_body_stream");
    add_assoc_bool(return_value, "immediate_token_streaming", lazy_gpu_token_stream);
    add_assoc_bool(return_value, "buffered_full_response_drain", false);
    add_assoc_bool(return_value, "buffered_native_event_drain", native_backend && !lazy_gpu_token_stream);
    add_assoc_string(
        return_value,
        "stream_content_delivery",
        lazy_gpu_token_stream
            ? "lazy_native_token_generation"
            : (native_backend ? "buffered_native_event_drain" : "external_runner_stream")
    );
    add_assoc_string(
        return_value,
        "stream_construction_mode",
        lazy_gpu_token_stream ? "setup_only_generation_on_next" : "backend_start_generates_events"
    );
    add_assoc_string(return_value, "non_stream_response_mode", "buffered_full_response_drain");
    add_assoc_string(return_value, "compat_http_stream_response_mode", "buffered_full_response_drain");
    add_assoc_string(
        return_value,
        "active_hot_path",
        king_inference_openai_route_hot_path(model, kind, backend_config_valid, openai_generation_ready)
    );
    add_assoc_string(return_value, "last_observed_hot_path", king_inference_openai_route_last_hot_path(model, kind));
    add_assoc_bool(return_value, "sequential_prefill_available", gpu_backend && model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(return_value, "batch_prefill_compiled", gpu_backend);
    add_assoc_bool(return_value, "batch_prefill_available", gpu_backend && model->cuda_decoder_prompt_loop_batch_prefill_available);
    add_assoc_bool(return_value, "batch_prefill_ready", gpu_backend && king_inference_cuda_decoder_prompt_loop_batch_prefill_ready(model));
    add_assoc_bool(return_value, "batch_prefill_experimental_enabled", gpu_backend && king_inference_cuda_decoder_prompt_loop_batch_prefill_experimental_enabled(model));
    add_assoc_bool(return_value, "batch_prefill_admitted", gpu_backend && king_inference_cuda_decoder_prompt_loop_batch_prefill_admitted(model));
    add_assoc_bool(return_value, "batch_prefill_request_control_allowed", false);
    add_assoc_string(
        return_value,
        "batch_prefill_status",
        gpu_backend
            ? king_inference_cuda_decoder_prompt_loop_batch_prefill_status(model)
            : "non_gpu_backend"
    );
    add_assoc_string(
        return_value,
        "batch_prefill_admission_reason",
        gpu_backend
            ? king_inference_cuda_decoder_prompt_loop_batch_prefill_admission_reason(model)
            : "non_gpu_backend"
    );
    add_assoc_string(
        return_value,
        "batch_prefill_use_condition",
        "prompt_tokens >= batch_prefill_min_prompt_tokens && batch_prefill_admitted"
    );
    add_assoc_bool(return_value, "batch_prefill_last_used", gpu_backend && model->cuda_decoder_prompt_loop_last_used_batch_prefill);
    add_assoc_long(
        return_value,
        "batch_prefill_min_prompt_tokens",
        gpu_backend ? (zend_long) KING_INFERENCE_CUDA_PROMPT_BATCH_PREFILL_MIN_TOKENS : 0
    );
    add_assoc_long(
        return_value,
        "last_prompt_tokens",
        gpu_backend ? (zend_long) model->cuda_decoder_prompt_loop_last_prompt_tokens : 0
    );
    add_assoc_long(
        return_value,
        "last_prefill_batches",
        gpu_backend ? (zend_long) model->cuda_decoder_prompt_loop_last_prefill_batches : 0
    );
    add_assoc_long(
        return_value,
        "last_prefill_tokens",
        gpu_backend ? (zend_long) model->cuda_decoder_prompt_loop_last_prefill_tokens : 0
    );
    add_assoc_long(
        return_value,
        "last_prefill_template_reuses",
        gpu_backend ? (zend_long) model->cuda_decoder_prompt_loop_last_prefill_template_reuses : 0
    );
    if (gpu_backend) {
        king_inference_cuda_decoder_prompt_profile_add_status(return_value, model);
        king_inference_openai_route_reuse_profile_add_status(return_value, model, gpu_backend);
    }
    add_assoc_bool(return_value, "cached_decode_available", kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU);
    add_assoc_bool(return_value, "fused_decode_available", false);
    add_assoc_bool(return_value, "fallback_available", kind == KING_INFERENCE_BACKEND_LOCAL);
    add_assoc_bool(return_value, "silent_cpu_fallback", false);
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
    zval client_capabilities;
    zval gpu_runtime;
    zval runtime_truth;
    zval runtime_readiness;
    zval runtime_surface;
    zval native_engine_readiness;
    zval error_taxonomy;
    zval route_status;
    bool backend_config_valid = true;
    bool created_config_valid = created != NULL && Z_TYPE_P(created) == IS_LONG && Z_LVAL_P(created) >= 0;
    bool gpu_enabled = false;
    bool gpu_openai_generation_ready = false;
    bool openai_generation_ready = false;
    bool native_graph_streaming_ready = false;
    bool native_stream_contract_ready = false;
    bool embeddings_ready = false;

    ZVAL_UNDEF(&gpu_runtime);

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
    king_inference_model_native_engine_readiness_array(model, &native_engine_readiness);
    gpu_enabled = backend_config_valid && king_inference_gpu_enabled(&model->config);
    if (gpu_enabled) {
        king_inference_gpu_runtime_status_from_model(model, &gpu_runtime);
        gpu_openai_generation_ready = king_inference_native_readiness_bool(
            &native_engine_readiness,
            "plain_text_generation_admitted"
        );
    }
    openai_generation_ready = backend_config_valid
        && ((kind == KING_INFERENCE_BACKEND_LOCAL && !gpu_enabled)
            || kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
            || (kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU
                && gpu_openai_generation_ready));
    native_graph_streaming_ready = king_inference_native_readiness_bool(
        &native_engine_readiness,
        "native_graph_payload_admitted"
    ) && kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    native_stream_contract_ready = king_inference_native_readiness_bool(
        &native_engine_readiness,
        "native_stream_contract_admitted"
    );
    embeddings_ready = backend_config_valid && kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU;

    add_assoc_string(
        &king_meta,
        "backend",
        backend_config_valid ? king_inference_backend_kind_name(kind) : "invalid"
    );
    add_assoc_bool(&king_meta, "backend_config_valid", backend_config_valid);
    add_assoc_bool(&king_meta, "openai_generation", openai_generation_ready);
    add_assoc_bool(&king_meta, "openai_chat_completions_stream", openai_generation_ready);
    add_assoc_bool(&king_meta, "native_graph_streaming", native_graph_streaming_ready);
    add_assoc_bool(&king_meta, "native_stream_contract", native_stream_contract_ready);
    add_assoc_bool(&king_meta, "embeddings", embeddings_ready);
    add_assoc_bool(&king_meta, "gpu_enabled", gpu_enabled);
    {
        zval native_engine_copy;
        ZVAL_COPY(&native_engine_copy, &native_engine_readiness);
        add_assoc_zval(&king_meta, "native_engine_readiness", &native_engine_copy);
    }
    king_inference_openai_runtime_surface_array(
        &runtime_surface,
        model,
        kind,
        backend_config_valid,
        gpu_enabled,
        gpu_openai_generation_ready,
        openai_generation_ready,
        native_graph_streaming_ready,
        native_stream_contract_ready,
        embeddings_ready,
        gpu_enabled ? &gpu_runtime : NULL
    );
    add_assoc_zval(&king_meta, "runtime_surface", &runtime_surface);
    king_inference_error_taxonomy_catalog_array(&error_taxonomy);
    add_assoc_zval(&king_meta, "error_taxonomy", &error_taxonomy);
    if (gpu_enabled) {
        add_assoc_zval(&king_meta, "gpu_runtime", &gpu_runtime);
    }
    king_inference_model_runtime_truth_array(model, &runtime_truth);
    king_inference_model_add_runtime_surface_fields(model, &king_meta, &runtime_truth);
    add_assoc_zval(&king_meta, "runtime_truth", &runtime_truth);
    add_assoc_bool(&king_meta, "created_config_valid", created_config_valid);
    add_assoc_long(&king_meta, "capability_schema_version", 2);
    add_assoc_string(&king_meta, "capabilities_scope", "implemented_capability_contract");
    add_assoc_string(&king_meta, "readiness_scope", "active_runtime_state");
    king_inference_openai_client_capabilities_array(
        &client_capabilities,
        backend_config_valid,
        kind,
        gpu_enabled,
        gpu_openai_generation_ready
    );
    add_assoc_zval(&king_meta, "client_capabilities", &client_capabilities);
    if (backend_config_valid) {
        king_inference_backend_capabilities_array(kind, &capabilities);
    } else {
        king_inference_openai_invalid_backend_capabilities(&capabilities);
    }
    add_assoc_zval(&king_meta, "capabilities", &capabilities);
    king_inference_model_readiness_truth_array(model, &runtime_readiness);
    add_assoc_zval(&king_meta, "runtime_readiness", &runtime_readiness);
    king_inference_openai_route_status_array(
        &route_status,
        model,
        kind,
        backend_config_valid,
        openai_generation_ready,
        &native_engine_readiness
    );
    add_assoc_zval(&king_meta, "openai_route", &route_status);
    zval_ptr_dtor(&native_engine_readiness);
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
