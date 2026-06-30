/*
 * Host-to-device weight upload for native King GPU inference.
 */

struct _king_inference_cuda_weight_upload {
    zend_string *name;
    king_inference_cuda_device_ptr device_ptr;
    size_t bytes;
    zend_ulong layer;
    char role[48];
    bool has_layer;
    king_inference_cuda_weight_upload *next;
};

typedef int (*king_inference_cuda_upload_cu_memcpy_htod_fn)(
    king_inference_cuda_device_ptr dst,
    const void *src,
    size_t bytes
);

static void king_inference_cuda_weight_upload_reset_fields(king_inference_model_object *model)
{
    model->cuda_weight_uploads = NULL;
    model->cuda_weight_upload_result = 0;
    model->cuda_weight_upload_error[0] = '\0';
    model->cuda_weight_required_count = 0;
    model->cuda_weight_resolved_count = 0;
    model->cuda_weight_uploaded_count = 0;
    model->cuda_weight_duplicate_count = 0;
    model->cuda_weight_failed_count = 0;
    model->cuda_weight_uploaded_bytes = 0;
    model->cuda_weight_cache_hits = 0;
    model->cuda_weight_cache_misses = 0;
    model->cuda_weight_cache_stores = 0;
    model->cuda_weight_upload_attempted = false;
    model->cuda_weight_upload_complete = false;
    model->cuda_weight_cache_initialized = false;
    model->cuda_weight_cache_ready = false;
}

static void king_inference_cuda_weight_upload_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_weight_upload_error";
    }

    model->cuda_weight_upload_result = result;
    snprintf(model->cuda_weight_upload_error, sizeof(model->cuda_weight_upload_error), "%s", message);
}

static void king_inference_cuda_weight_upload_release(king_inference_model_object *model)
{
    king_inference_cuda_weight_upload *upload = model->cuda_weight_uploads;
    king_inference_cuda_weight_upload *next;

    if (model->cuda_weight_cache_initialized) {
        zend_hash_destroy(&model->cuda_weight_cache);
        model->cuda_weight_cache_initialized = false;
    }

    while (upload != NULL) {
        next = upload->next;
        if (upload->name != NULL) {
            zend_string_release(upload->name);
        }
        efree(upload);
        upload = next;
    }

    king_inference_cuda_weight_upload_reset_fields(model);
}

static void king_inference_cuda_weight_cache_ensure(king_inference_model_object *model)
{
    if (model->cuda_weight_cache_initialized) {
        return;
    }

    zend_hash_init(&model->cuda_weight_cache, 128, NULL, NULL, 0);
    model->cuda_weight_cache_initialized = true;
}

static king_inference_cuda_weight_upload *king_inference_cuda_weight_cache_find(
    king_inference_model_object *model,
    zend_string *name,
    bool count_access
) {
    zval *entry;

    if (!model->cuda_weight_cache_initialized) {
        if (count_access) {
            model->cuda_weight_cache_misses++;
        }
        return NULL;
    }

    entry = zend_hash_find(&model->cuda_weight_cache, name);
    if (entry == NULL || Z_TYPE_P(entry) != IS_PTR) {
        if (count_access) {
            model->cuda_weight_cache_misses++;
        }
        return NULL;
    }

    if (count_access) {
        model->cuda_weight_cache_hits++;
    }
    return (king_inference_cuda_weight_upload *) Z_PTR_P(entry);
}

static bool king_inference_cuda_weight_cache_store(
    king_inference_model_object *model,
    king_inference_cuda_weight_upload *upload
) {
    zval entry;

    if (upload == NULL || upload->name == NULL) {
        king_inference_cuda_weight_upload_set_error(model, -1, "cuda_weight_cache_entry_invalid");
        return false;
    }

    king_inference_cuda_weight_cache_ensure(model);
    ZVAL_PTR(&entry, upload);
    if (zend_hash_update(&model->cuda_weight_cache, upload->name, &entry) == NULL) {
        king_inference_cuda_weight_upload_set_error(model, -1, "cuda_weight_cache_store_failed");
        return false;
    }

    model->cuda_weight_cache_stores++;
    return true;
}

static bool king_inference_cuda_weight_upload_symbol(
    king_inference_model_object *model,
    const char *primary_name,
    const char *fallback_name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_weight_upload_set_error(model, -1, "cuda_driver_handle_unavailable");
        return false;
    }

    if (primary_name != NULL) {
        *symbol = dlsym(model->cuda_driver_handle, primary_name);
    }
    if (*symbol == NULL && fallback_name != NULL) {
        *symbol = dlsym(model->cuda_driver_handle, fallback_name);
    }
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_weight_upload_set_error(
        model,
        -1,
        primary_name != NULL ? primary_name : fallback_name
    );
    return false;
}

static bool king_inference_cuda_weight_already_uploaded(
    king_inference_model_object *model,
    zend_string *name
) {
    return king_inference_cuda_weight_cache_find(model, name, true) != NULL;
}

static bool king_inference_cuda_weight_tensor_source(
    king_inference_model_object *model,
    zend_string *name,
    const void **source,
    size_t *bytes
) {
    king_inference_tensor_type_info type_info;
    zend_ulong type;
    zend_ulong elements;
    zend_ulong relative_offset;
    zend_ulong blocks;
    zend_ulong byte_length;
    zend_ulong absolute_offset;
    zend_ulong byte_end;
    zval *descriptor = king_inference_tensor_index_descriptor(model, name);

    *source = NULL;
    *bytes = 0;
    if (descriptor == NULL
        || !model->native_map_loaded
        || model->native_map == NULL
        || model->native_map_size == 0) {
        king_inference_cuda_weight_upload_set_error(model, -1, "native_tensor_source_unavailable");
        return false;
    }
    if (!king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)
        || !king_inference_tensor_descriptor_ulong(descriptor, "elements", sizeof("elements") - 1, &elements)
        || !king_inference_tensor_descriptor_ulong(
            descriptor,
            "relative_offset",
            sizeof("relative_offset") - 1,
            &relative_offset
        )
        || !king_inference_tensor_type_lookup(type, &type_info)
        || type_info.block_size == 0) {
        king_inference_cuda_weight_upload_set_error(model, -1, "native_tensor_descriptor_invalid");
        return false;
    }

    blocks = elements == 0 ? 0 : 1 + ((elements - 1) / type_info.block_size);
    if (blocks == 0
        || !king_inference_tensor_mul_checked(blocks, type_info.type_size, &byte_length)
        || !king_inference_tensor_add_checked(model->gguf.tensor_data_offset, relative_offset, &absolute_offset)
        || !king_inference_tensor_add_checked(absolute_offset, byte_length, &byte_end)
        || byte_end > model->gguf.file_size
        || byte_end > (zend_ulong) model->native_map_size) {
        king_inference_cuda_weight_upload_set_error(model, -1, "native_tensor_bounds_invalid");
        return false;
    }

    *source = ((const unsigned char *) model->native_map) + absolute_offset;
    *bytes = (size_t) byte_length;
    return true;
}

static bool king_inference_cuda_weight_upload_tensor(
    king_inference_model_object *model,
    const char *role,
    bool has_layer,
    zend_ulong layer,
    zend_string *name
) {
    king_inference_cuda_upload_cu_memcpy_htod_fn cu_memcpy_htod;
    king_inference_cuda_weight_upload *upload;
    king_inference_cuda_device_ptr device_ptr = 0;
    const void *source;
    size_t bytes;
    int result;

    if (king_inference_cuda_weight_already_uploaded(model, name)) {
        model->cuda_weight_duplicate_count++;
        return true;
    }
    if (!king_inference_cuda_weight_tensor_source(model, name, &source, &bytes)) {
        model->cuda_weight_failed_count++;
        return false;
    }
    if (model->cuda_weight_uploaded_bytes > ((size_t) -1) - bytes) {
        king_inference_cuda_weight_upload_set_error(model, -1, "cuda_weight_upload_counter_overflow");
        model->cuda_weight_failed_count++;
        return false;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_weight_upload_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        model->cuda_weight_failed_count++;
        return false;
    }
    if (!king_inference_cuda_weight_upload_symbol(
        model,
        "cuMemcpyHtoD_v2",
        "cuMemcpyHtoD",
        (void **) &cu_memcpy_htod
    )) {
        model->cuda_weight_failed_count++;
        return false;
    }
    if (king_inference_cuda_device_allocator_alloc(model, bytes, &device_ptr) != SUCCESS) {
        king_inference_cuda_weight_upload_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_device_allocation_failed"
        );
        model->cuda_weight_failed_count++;
        return false;
    }

    result = cu_memcpy_htod(device_ptr, source, bytes);
    if (result != 0) {
        (void) king_inference_cuda_device_allocator_free(model, device_ptr);
        king_inference_cuda_weight_upload_set_error(model, result, "cuMemcpyHtoD_failed");
        model->cuda_weight_failed_count++;
        return false;
    }

    upload = emalloc(sizeof(*upload));
    upload->name = zend_string_copy(name);
    upload->device_ptr = device_ptr;
    upload->bytes = bytes;
    upload->layer = layer;
    upload->has_layer = has_layer;
    snprintf(upload->role, sizeof(upload->role), "%s", role != NULL ? role : "weight");
    if (!king_inference_cuda_weight_cache_store(model, upload)) {
        (void) king_inference_cuda_device_allocator_free(model, device_ptr);
        zend_string_release(upload->name);
        efree(upload);
        model->cuda_weight_failed_count++;
        return false;
    }
    upload->next = model->cuda_weight_uploads;
    model->cuda_weight_uploads = upload;
    model->cuda_weight_uploaded_count++;
    model->cuda_weight_uploaded_bytes += bytes;
    if (model->cuda_weight_failed_count == 0) {
        model->cuda_weight_upload_result = 0;
        model->cuda_weight_upload_error[0] = '\0';
    }
    return true;
}

static void king_inference_cuda_weight_upload_resolved(
    king_inference_model_object *model,
    const char *role,
    bool has_layer,
    zend_ulong layer,
    zend_result resolved,
    zend_string *name,
    const char *status
) {
    model->cuda_weight_required_count++;
    if (resolved != SUCCESS || name == NULL) {
        model->cuda_weight_failed_count++;
        king_inference_cuda_weight_upload_set_error(
            model,
            -1,
            status != NULL ? status : "required_weight_not_resolved"
        );
        return;
    }

    model->cuda_weight_resolved_count++;
    (void) king_inference_cuda_weight_upload_tensor(model, role, has_layer, layer, name);
}

static void king_inference_cuda_weight_upload_optional_layer_tensor(
    king_inference_model_object *model,
    const char *role,
    zend_ulong layer,
    const char *suffix
) {
    char name_buffer[96];
    zend_string *name;
    zval *descriptor;

    if (snprintf(name_buffer, sizeof(name_buffer), "blk.%lu.%s", (unsigned long) layer, suffix) >= (int) sizeof(name_buffer)) {
        return;
    }

    name = zend_string_init(name_buffer, strlen(name_buffer), 0);
    descriptor = king_inference_tensor_index_descriptor(model, name);
    if (descriptor != NULL) {
        model->cuda_weight_required_count++;
        model->cuda_weight_resolved_count++;
        (void) king_inference_cuda_weight_upload_tensor(model, role, true, layer, name);
    }
    zend_string_release(name);
}

static void king_inference_cuda_weight_upload_layer(
    king_inference_model_object *model,
    zend_ulong layer
) {
    zend_string *name = NULL;
    const char *status = NULL;
    zend_result resolved;

    resolved = king_inference_resolve_rms_norm_layer_name(
        model,
        KING_INFERENCE_RMS_NORM_ATTENTION,
        layer,
        &name,
        NULL,
        &status
    );
    king_inference_cuda_weight_upload_resolved(model, "rms_norm.attention", true, layer, resolved, name, status);
    if (name != NULL) zend_string_release(name);
    king_inference_cuda_weight_upload_optional_layer_tensor(model, "rms_norm.attention_query", layer, "attn_q_norm.weight");
    king_inference_cuda_weight_upload_optional_layer_tensor(model, "rms_norm.attention_key", layer, "attn_k_norm.weight");
    king_inference_cuda_weight_upload_optional_layer_tensor(model, "rms_norm.post_attention", layer, "post_attention_norm.weight");

    for (int role = KING_INFERENCE_ATTENTION_QUERY; role <= KING_INFERENCE_ATTENTION_OUTPUT; role++) {
        const char *upload_role = king_inference_attention_role_keys[role];

        name = NULL;
        status = NULL;
        resolved = king_inference_resolve_attention_tensor_name(
            model,
            (king_inference_attention_role) role,
            layer,
            &name,
            NULL,
            &status
        );
        king_inference_cuda_weight_upload_resolved(model, upload_role, true, layer, resolved, name, status);
        if (name != NULL) zend_string_release(name);
    }

    name = NULL;
    status = NULL;
    resolved = king_inference_resolve_rms_norm_layer_name(
        model,
        KING_INFERENCE_RMS_NORM_FEED_FORWARD,
        layer,
        &name,
        NULL,
        &status
    );
    king_inference_cuda_weight_upload_resolved(model, "rms_norm.feed_forward", true, layer, resolved, name, status);
    if (name != NULL) zend_string_release(name);
    king_inference_cuda_weight_upload_optional_layer_tensor(model, "rms_norm.post_feed_forward", layer, "post_ffw_norm.weight");

    for (int role = KING_INFERENCE_FFN_GATE; role <= KING_INFERENCE_FFN_DOWN; role++) {
        char upload_role[32];

        name = NULL;
        status = NULL;
        resolved = king_inference_resolve_ffn_tensor_name(
            model,
            (king_inference_ffn_role) role,
            layer,
            &name,
            NULL,
            &status
        );
        snprintf(upload_role, sizeof(upload_role), "ffn.%s", king_inference_ffn_role_keys[role]);
        king_inference_cuda_weight_upload_resolved(model, upload_role, true, layer, resolved, name, status);
        if (name != NULL) zend_string_release(name);
    }
}

static void king_inference_cuda_weight_upload_required(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    zend_ulong block_count = model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT];
    zend_string *name = NULL;
    const char *status = NULL;
    zend_result resolved;
    bool tied_embedding = false;
    zend_ulong layer;

    king_inference_cuda_weight_upload_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_weight_upload_attempted = true;
    if (!model->cuda_device_allocator_available) {
        king_inference_cuda_weight_upload_set_error(model, -1, "cuda_device_allocator_unavailable");
        return;
    }

    resolved = king_inference_resolve_token_embedding_tensor_name(model, NULL, &name, NULL, &status);
    king_inference_cuda_weight_upload_resolved(model, "token_embedding", false, 0, resolved, name, status);
    if (name != NULL) zend_string_release(name);

    name = NULL;
    status = NULL;
    resolved = king_inference_resolve_output_projection_tensor_name(
        model,
        NULL,
        &name,
        NULL,
        &status,
        &tied_embedding
    );
    king_inference_cuda_weight_upload_resolved(model, "output_projection", false, 0, resolved, name, status);
    if (name != NULL) zend_string_release(name);

    name = NULL;
    status = NULL;
    resolved = king_inference_resolve_rms_norm_final_name(model, &name, NULL, &status);
    king_inference_cuda_weight_upload_resolved(model, "rms_norm.final", false, 0, resolved, name, status);
    if (name != NULL) zend_string_release(name);

    if (block_count == 0) {
        model->cuda_weight_failed_count++;
        king_inference_cuda_weight_upload_set_error(model, -1, "decoder_block_count_unavailable");
    }
    for (layer = 0; layer < block_count; layer++) {
        king_inference_cuda_weight_upload_layer(model, layer);
    }

    model->cuda_weight_upload_complete = model->cuda_weight_required_count > 0
        && model->cuda_weight_failed_count == 0
        && model->cuda_weight_required_count
            == model->cuda_weight_uploaded_count + model->cuda_weight_duplicate_count;
    model->cuda_weight_cache_ready = model->cuda_weight_upload_complete
        && model->cuda_weight_cache_initialized
        && model->cuda_weight_cache_stores == model->cuda_weight_uploaded_count;
}

static void king_inference_cuda_weight_upload_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval upload;

    array_init(&upload);
    add_assoc_bool(&upload, "attempted", model->cuda_weight_upload_attempted);
    add_assoc_bool(&upload, "complete", model->cuda_weight_upload_complete);
    add_assoc_long(&upload, "required_tensors", (zend_long) model->cuda_weight_required_count);
    add_assoc_long(&upload, "resolved_tensors", (zend_long) model->cuda_weight_resolved_count);
    add_assoc_long(&upload, "uploaded_tensors", (zend_long) model->cuda_weight_uploaded_count);
    add_assoc_long(&upload, "duplicate_tensors", (zend_long) model->cuda_weight_duplicate_count);
    add_assoc_long(&upload, "failed_tensors", (zend_long) model->cuda_weight_failed_count);
    add_assoc_long(&upload, "uploaded_bytes", (zend_long) model->cuda_weight_uploaded_bytes);
    add_assoc_bool(&upload, "cache_ready", model->cuda_weight_cache_ready);
    add_assoc_long(&upload, "cache_entries", (zend_long) model->cuda_weight_cache_stores);
    add_assoc_long(&upload, "cache_hits", (zend_long) model->cuda_weight_cache_hits);
    add_assoc_long(&upload, "cache_misses", (zend_long) model->cuda_weight_cache_misses);
    add_assoc_long(&upload, "result", model->cuda_weight_upload_result);
    add_assoc_string(&upload, "error", model->cuda_weight_upload_error);
    add_assoc_zval(return_value, "required_weight_upload", &upload);
}

static void king_inference_cuda_weight_upload_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_upload;

    king_inference_cuda_weight_upload_add_status(model, return_value);
    if (!model->cuda_weight_upload_attempted
        || model->cuda_weight_upload_complete
        || !model->cuda_device_allocator_available) {
        return;
    }

    add_assoc_bool(return_value, "config_ready", false);

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_upload = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_upload) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_required_weight_upload_incomplete"
        );
        add_assoc_string(return_value, "reason", "gpu_required_weight_upload_incomplete");
    } else {
        king_inference_gpu_status_append_return_reason(
            return_value,
            "gpu_required_weight_upload_incomplete"
        );
    }
}
