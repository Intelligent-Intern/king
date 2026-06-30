/*
 * CUDA device KV-cache storage for native King GPU inference.
 */

typedef int (*king_inference_cuda_device_kv_cache_memcpy_dtod_fn)(
    king_inference_cuda_device_ptr dst,
    king_inference_cuda_device_ptr src,
    size_t bytes
);
typedef int (*king_inference_cuda_device_kv_cache_memcpy_dtod_async_fn)(
    king_inference_cuda_device_ptr dst,
    king_inference_cuda_device_ptr src,
    size_t bytes,
    void *stream
);

static void king_inference_cuda_device_kv_cache_reset_fields(king_inference_model_object *model)
{
    model->cuda_device_kv_cache_keys = 0;
    model->cuda_device_kv_cache_values = 0;
    model->cuda_device_kv_cache_result = 0;
    model->cuda_device_kv_cache_error[0] = '\0';
    model->cuda_device_kv_cache_bytes = 0;
    model->cuda_device_kv_cache_key_bytes = 0;
    model->cuda_device_kv_cache_value_bytes = 0;
    model->cuda_device_kv_cache_reserve_call_count = 0;
    model->cuda_device_kv_cache_allocation_count = 0;
    model->cuda_device_kv_cache_write_count = 0;
    model->cuda_device_kv_cache_batch_write_count = 0;
    model->cuda_device_kv_cache_guard_check_count = 0;
    model->cuda_device_kv_cache_guard_failure_count = 0;
    model->cuda_device_kv_cache_layers = 0;
    model->cuda_device_kv_cache_kv_heads = 0;
    model->cuda_device_kv_cache_context_tokens = 0;
    model->cuda_device_kv_cache_key_length = 0;
    model->cuda_device_kv_cache_value_length = 0;
    model->cuda_device_kv_cache_last_layer = 0;
    model->cuda_device_kv_cache_last_head = 0;
    model->cuda_device_kv_cache_last_position = 0;
    model->cuda_device_kv_cache_guard_last_layer = 0;
    model->cuda_device_kv_cache_guard_last_head = 0;
    model->cuda_device_kv_cache_guard_last_position = 0;
    model->cuda_device_kv_cache_guard_last_count = 0;
    model->cuda_device_kv_cache_guard_last_page_start = 0;
    model->cuda_device_kv_cache_guard_last_page_end = 0;
    model->cuda_device_kv_cache_guard_last_operation[0] = '\0';
    model->cuda_device_kv_cache_guard_last_failure[0] = '\0';
    model->cuda_device_kv_cache_attempted = false;
    model->cuda_device_kv_cache_available = false;
    model->cuda_device_kv_cache_shape_ready = false;
    model->cuda_device_kv_cache_allocated = false;
    model->cuda_device_kv_cache_f32 = false;
    model->cuda_device_kv_cache_dtod_available = false;
    model->cuda_device_kv_cache_guard_fail_closed = true;
    model->cuda_device_kv_cache_guard_last_page_crossed = false;
}

static void king_inference_cuda_device_kv_cache_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_device_kv_cache_error";
    }

    model->cuda_device_kv_cache_result = result;
    snprintf(
        model->cuda_device_kv_cache_error,
        sizeof(model->cuda_device_kv_cache_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_device_kv_cache_symbol(
    king_inference_model_object *model,
    const char *primary_name,
    const char *fallback_name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_device_kv_cache_set_error(model, -1, "cuda_driver_handle_unavailable");
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

    king_inference_cuda_device_kv_cache_set_error(
        model,
        -1,
        primary_name != NULL ? primary_name : fallback_name
    );
    return false;
}

static bool king_inference_cuda_device_kv_cache_mul_size(size_t a, size_t b, size_t *out)
{
    if (a != 0 && b > ((size_t) -1) / a) {
        *out = 0;
        return false;
    }

    *out = a * b;
    return true;
}

static bool king_inference_cuda_device_kv_cache_add_size(size_t a, size_t b, size_t *out)
{
    if (b > ((size_t) -1) - a) {
        *out = 0;
        return false;
    }

    *out = a + b;
    return true;
}

static zend_ulong king_inference_cuda_device_kv_cache_plan_long(
    king_inference_model_object *model,
    const char *key,
    zend_ulong fallback
) {
    zval *value;

    if (Z_ISUNDEF(model->paged_kv_cache_plan) || Z_TYPE(model->paged_kv_cache_plan) != IS_ARRAY) {
        return fallback;
    }

    value = zend_hash_str_find(Z_ARRVAL(model->paged_kv_cache_plan), key, strlen(key));
    return value != NULL && Z_TYPE_P(value) == IS_LONG && Z_LVAL_P(value) > 0
        ? (zend_ulong) Z_LVAL_P(value)
        : fallback;
}

#include "cuda_device_kv_cache_guard.inc"

static bool king_inference_cuda_device_kv_cache_shape(
    king_inference_model_object *model,
    zend_ulong *layers,
    zend_ulong *kv_heads,
    zend_ulong *context_tokens,
    zend_ulong *key_length,
    zend_ulong *value_length,
    size_t *key_bytes,
    size_t *value_bytes,
    size_t *total_bytes
) {
    size_t slots;
    size_t key_units;
    size_t value_units;
    zend_ulong embedding = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong heads = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT];
    zend_ulong runtime_heads = heads;

    *layers = king_inference_cuda_device_kv_cache_plan_long(
        model,
        "layers",
        model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT]
    );
    *kv_heads = king_inference_cuda_device_kv_cache_plan_long(
        model,
        "kv_heads",
        model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV] > 0
            ? model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV]
            : heads
    );
    *context_tokens = king_inference_cuda_device_kv_cache_plan_long(
        model,
        "max_context_tokens",
        model->gguf.architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH]
    );
    *key_length = king_inference_cuda_device_kv_cache_plan_long(
        model,
        "key_dim",
        model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH]
    );
    *value_length = king_inference_cuda_device_kv_cache_plan_long(
        model,
        "value_dim",
        model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH]
    );

    if (*key_length == 0 && embedding > 0 && heads > 0 && embedding % heads == 0) {
        *key_length = embedding / heads;
    }
    if (*value_length == 0) {
        *value_length = *key_length;
    }
    king_inference_attention_refine_runtime_cache_shape_from_tensors(
        model,
        *layers,
        &runtime_heads,
        kv_heads,
        key_length,
        value_length
    );
    if (*layers == 0 || *kv_heads == 0 || *context_tokens == 0
        || *key_length == 0 || *value_length == 0) {
        return false;
    }

    if (!king_inference_cuda_device_kv_cache_mul_size((size_t) *layers, (size_t) *kv_heads, &slots)
        || !king_inference_cuda_device_kv_cache_mul_size(slots, (size_t) *context_tokens, &slots)
        || !king_inference_cuda_device_kv_cache_mul_size(slots, (size_t) *key_length, &key_units)
        || !king_inference_cuda_device_kv_cache_mul_size(slots, (size_t) *value_length, &value_units)
        || !king_inference_cuda_device_kv_cache_mul_size(key_units, sizeof(float), key_bytes)
        || !king_inference_cuda_device_kv_cache_mul_size(value_units, sizeof(float), value_bytes)
        || !king_inference_cuda_device_kv_cache_add_size(*key_bytes, *value_bytes, total_bytes)) {
        return false;
    }

    return *total_bytes > 0;
}

static void king_inference_cuda_device_kv_cache_release(king_inference_model_object *model)
{
    king_inference_cuda_device_ptr keys = model->cuda_device_kv_cache_keys;
    king_inference_cuda_device_ptr values = model->cuda_device_kv_cache_values;

    if (keys != 0) {
        (void) king_inference_cuda_device_allocator_free(model, keys);
    }
    if (values != 0) {
        (void) king_inference_cuda_device_allocator_free(model, values);
    }

    king_inference_cuda_device_kv_cache_reset_fields(model);
}

static void king_inference_cuda_device_kv_cache_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    void *memcpy_dtod = NULL;

    king_inference_cuda_device_kv_cache_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_device_kv_cache_attempted = true;
    if (!model->cuda_device_allocator_available) {
        king_inference_cuda_device_kv_cache_set_error(model, -1, "cuda_device_allocator_unavailable");
        return;
    }
    if (!king_inference_cuda_device_kv_cache_symbol(
        model,
        "cuMemcpyDtoD_v2",
        "cuMemcpyDtoD",
        &memcpy_dtod
    )) {
        return;
    }
    model->cuda_device_kv_cache_dtod_available = true;

    model->cuda_device_kv_cache_shape_ready = king_inference_cuda_device_kv_cache_shape(
        model,
        &model->cuda_device_kv_cache_layers,
        &model->cuda_device_kv_cache_kv_heads,
        &model->cuda_device_kv_cache_context_tokens,
        &model->cuda_device_kv_cache_key_length,
        &model->cuda_device_kv_cache_value_length,
        &model->cuda_device_kv_cache_key_bytes,
        &model->cuda_device_kv_cache_value_bytes,
        &model->cuda_device_kv_cache_bytes
    );
    if (!model->cuda_device_kv_cache_shape_ready) {
        king_inference_cuda_device_kv_cache_set_error(model, -1, "cuda_device_kv_cache_shape_unavailable");
        return;
    }

    model->cuda_device_kv_cache_available = true;
    model->cuda_device_kv_cache_f32 = true;
    model->cuda_device_kv_cache_result = 0;
    model->cuda_device_kv_cache_error[0] = '\0';
}

static zend_result king_inference_cuda_device_kv_cache_reserve(king_inference_model_object *model)
{
    king_inference_cuda_device_ptr keys = 0;
    king_inference_cuda_device_ptr values = 0;

    model->cuda_device_kv_cache_reserve_call_count++;
    if (model->cuda_device_kv_cache_allocated) {
        return SUCCESS;
    }
    if (!model->cuda_device_kv_cache_available || !model->cuda_device_kv_cache_shape_ready) {
        king_inference_cuda_device_kv_cache_set_error(model, -1, "cuda_device_kv_cache_unavailable");
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(model, model->cuda_device_kv_cache_key_bytes, &keys) != SUCCESS) {
        king_inference_cuda_device_kv_cache_set_error(model, -1, "cuda_device_kv_cache_key_alloc_failed");
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(model, model->cuda_device_kv_cache_value_bytes, &values) != SUCCESS) {
        (void) king_inference_cuda_device_allocator_free(model, keys);
        king_inference_cuda_device_kv_cache_set_error(model, -1, "cuda_device_kv_cache_value_alloc_failed");
        return FAILURE;
    }

    model->cuda_device_kv_cache_keys = keys;
    model->cuda_device_kv_cache_values = values;
    model->cuda_device_kv_cache_allocated = true;
    model->cuda_device_kv_cache_allocation_count++;
    model->cuda_device_kv_cache_result = 0;
    model->cuda_device_kv_cache_error[0] = '\0';
    return SUCCESS;
}

static zend_result king_inference_cuda_device_kv_cache_slot_ptrs(
    king_inference_model_object *model,
    zend_ulong layer,
    zend_ulong head,
    zend_ulong position,
    king_inference_cuda_device_ptr *key_ptr,
    king_inference_cuda_device_ptr *value_ptr
) {
    size_t slot;
    size_t offset;

    *key_ptr = 0;
    *value_ptr = 0;
    if (king_inference_cuda_device_kv_cache_guard_slot(
            model,
            "slot_ptr",
            layer,
            head,
            position,
            true
    ) != SUCCESS) {
        return FAILURE;
    }

    if (!king_inference_cuda_device_kv_cache_mul_size((size_t) layer, (size_t) model->cuda_device_kv_cache_kv_heads, &slot)
        || !king_inference_cuda_device_kv_cache_add_size(slot, (size_t) head, &slot)
        || !king_inference_cuda_device_kv_cache_mul_size(slot, (size_t) model->cuda_device_kv_cache_context_tokens, &slot)
        || !king_inference_cuda_device_kv_cache_add_size(slot, (size_t) position, &slot)
        || !king_inference_cuda_device_kv_cache_mul_size(slot, (size_t) model->cuda_device_kv_cache_key_length, &offset)
        || !king_inference_cuda_device_kv_cache_mul_size(offset, sizeof(float), &offset)) {
        king_inference_cuda_device_kv_cache_set_error(model, -1, "cuda_device_kv_cache_key_offset_overflow");
        return FAILURE;
    }
    *key_ptr = model->cuda_device_kv_cache_keys + (king_inference_cuda_device_ptr) offset;

    if (!king_inference_cuda_device_kv_cache_mul_size(slot, (size_t) model->cuda_device_kv_cache_value_length, &offset)
        || !king_inference_cuda_device_kv_cache_mul_size(offset, sizeof(float), &offset)) {
        king_inference_cuda_device_kv_cache_set_error(model, -1, "cuda_device_kv_cache_value_offset_overflow");
        return FAILURE;
    }
    *value_ptr = model->cuda_device_kv_cache_values + (king_inference_cuda_device_ptr) offset;
    return SUCCESS;
}

static zend_result king_inference_cuda_device_kv_cache_head(
    king_inference_model_object *model,
    bool value_cache,
    zend_ulong layer,
    zend_ulong head,
    king_inference_cuda_device_ptr *cache_ptr,
    zend_ulong *stride
) {
    king_inference_cuda_device_ptr key_ptr;
    king_inference_cuda_device_ptr value_ptr;

    *cache_ptr = 0;
    *stride = 0;
    if (king_inference_cuda_device_kv_cache_guard_slot(
            model,
            value_cache ? "read_value_head" : "read_key_head",
            layer,
            head,
            0,
            false
    ) != SUCCESS
        || king_inference_cuda_device_kv_cache_reserve(model) != SUCCESS
        || king_inference_cuda_device_kv_cache_slot_ptrs(model, layer, head, 0, &key_ptr, &value_ptr) != SUCCESS) {
        return FAILURE;
    }

    *cache_ptr = value_cache ? value_ptr : key_ptr;
    *stride = value_cache ? model->cuda_device_kv_cache_value_length : model->cuda_device_kv_cache_key_length;
    return SUCCESS;
}

static zend_result king_inference_cuda_device_kv_cache_write(
    king_inference_model_object *model,
    zend_ulong layer,
    zend_ulong head,
    zend_ulong position,
    king_inference_cuda_device_ptr key_vector,
    zend_ulong key_length,
    king_inference_cuda_device_ptr value_vector,
    zend_ulong value_length
) {
    king_inference_cuda_device_kv_cache_memcpy_dtod_fn cu_memcpy_dtod;
    king_inference_cuda_device_kv_cache_memcpy_dtod_async_fn cu_memcpy_dtod_async = NULL;
    king_inference_cuda_device_ptr key_slot;
    king_inference_cuda_device_ptr value_slot;
    int result;

    if (king_inference_cuda_device_kv_cache_guard_vectors(
            model,
            "single_write_vectors",
            key_vector,
            key_length,
            value_vector,
            value_length
    ) != SUCCESS
        || king_inference_cuda_device_kv_cache_guard_slot(
            model,
            "single_write_slot",
            layer,
            head,
            position,
            false
    ) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_device_kv_cache_reserve(model) != SUCCESS
        || king_inference_cuda_device_kv_cache_slot_ptrs(
            model,
            layer,
            head,
            position,
            &key_slot,
            &value_slot
        ) != SUCCESS) {
        return FAILURE;
    }
    if (!king_inference_cuda_device_kv_cache_symbol(
            model,
            "cuMemcpyDtoDAsync_v2",
            "cuMemcpyDtoDAsync",
            (void **) &cu_memcpy_dtod_async
        )) {
        if (!king_inference_cuda_device_kv_cache_symbol(
                model,
                "cuMemcpyDtoD_v2",
                "cuMemcpyDtoD",
                (void **) &cu_memcpy_dtod
        )) {
            return FAILURE;
        }
    }

    if (cu_memcpy_dtod_async != NULL) {
        result = cu_memcpy_dtod_async(key_slot, key_vector, (size_t) key_length * sizeof(float), NULL);
        if (result != 0) {
            king_inference_cuda_device_kv_cache_set_error(model, result, "cuMemcpyDtoDAsync_key_failed");
            return FAILURE;
        }
        result = cu_memcpy_dtod_async(value_slot, value_vector, (size_t) value_length * sizeof(float), NULL);
        if (result != 0) {
            king_inference_cuda_device_kv_cache_set_error(model, result, "cuMemcpyDtoDAsync_value_failed");
            return FAILURE;
        }
    } else {
        result = cu_memcpy_dtod(key_slot, key_vector, (size_t) key_length * sizeof(float));
        if (result != 0) {
            king_inference_cuda_device_kv_cache_set_error(model, result, "cuMemcpyDtoD_key_failed");
            return FAILURE;
        }
        result = cu_memcpy_dtod(value_slot, value_vector, (size_t) value_length * sizeof(float));
        if (result != 0) {
            king_inference_cuda_device_kv_cache_set_error(model, result, "cuMemcpyDtoD_value_failed");
            return FAILURE;
        }
    }

    model->cuda_device_kv_cache_write_count++;
    model->cuda_device_kv_cache_last_layer = layer;
    model->cuda_device_kv_cache_last_head = head;
    model->cuda_device_kv_cache_last_position = position;
    model->cuda_device_kv_cache_result = 0;
    model->cuda_device_kv_cache_error[0] = '\0';
    return SUCCESS;
}

static zend_result king_inference_cuda_device_kv_cache_write_batch_strided(
    king_inference_model_object *model,
    zend_ulong layer,
    zend_ulong head,
    zend_ulong position_start,
    zend_ulong count,
    king_inference_cuda_device_ptr key_matrix,
    zend_ulong key_row_stride,
    zend_ulong key_offset,
    zend_ulong key_length,
    king_inference_cuda_device_ptr value_matrix,
    zend_ulong value_row_stride,
    zend_ulong value_offset,
    zend_ulong value_length
) {
    king_inference_cuda_device_kv_cache_memcpy_dtod_fn cu_memcpy_dtod;
    king_inference_cuda_device_kv_cache_memcpy_dtod_async_fn cu_memcpy_dtod_async = NULL;
    zend_ulong index;

    if (key_matrix == 0 || value_matrix == 0) {
        king_inference_cuda_device_kv_cache_guard_record(
            model,
            "batch_write_vectors",
            "cuda_device_kv_cache_guard_batch_matrix_invalid",
            layer,
            head,
            position_start,
            count
        );
        return FAILURE;
    }
    if (king_inference_cuda_device_kv_cache_guard_batch_source(
            model,
            "batch_write_source",
            count,
            key_row_stride,
            key_offset,
            key_length,
            value_row_stride,
            value_offset,
            value_length
    ) != SUCCESS
        || king_inference_cuda_device_kv_cache_guard_range(
            model,
            "batch_write_range",
            layer,
            head,
            position_start,
            count,
            false
    ) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_device_kv_cache_reserve(model) != SUCCESS) {
        return FAILURE;
    }
    if (!king_inference_cuda_device_kv_cache_symbol(
            model,
            "cuMemcpyDtoDAsync_v2",
            "cuMemcpyDtoDAsync",
            (void **) &cu_memcpy_dtod_async
        )) {
        if (!king_inference_cuda_device_kv_cache_symbol(
                model,
                "cuMemcpyDtoD_v2",
                "cuMemcpyDtoD",
                (void **) &cu_memcpy_dtod
        )) {
            return FAILURE;
        }
    }

    for (index = 0; index < count; index++) {
        king_inference_cuda_device_ptr key_slot;
        king_inference_cuda_device_ptr value_slot;
        king_inference_cuda_device_ptr key_source;
        king_inference_cuda_device_ptr value_source;
        size_t key_units;
        size_t value_units;
        size_t key_bytes;
        size_t value_bytes;
        int result;

        if (king_inference_cuda_device_kv_cache_slot_ptrs(
                model,
                layer,
                head,
                position_start + index,
                &key_slot,
                &value_slot
        ) != SUCCESS
            || !king_inference_cuda_device_kv_cache_mul_size((size_t) index, (size_t) key_row_stride, &key_units)
            || !king_inference_cuda_device_kv_cache_add_size(key_units, (size_t) key_offset, &key_units)
            || !king_inference_cuda_device_kv_cache_mul_size(key_units, sizeof(float), &key_bytes)
            || !king_inference_cuda_device_kv_cache_mul_size((size_t) index, (size_t) value_row_stride, &value_units)
            || !king_inference_cuda_device_kv_cache_add_size(value_units, (size_t) value_offset, &value_units)
            || !king_inference_cuda_device_kv_cache_mul_size(value_units, sizeof(float), &value_bytes)) {
            king_inference_cuda_device_kv_cache_set_error(model, -1, "cuda_device_kv_cache_batch_offset_overflow");
            return FAILURE;
        }
        key_source = key_matrix + (king_inference_cuda_device_ptr) key_bytes;
        value_source = value_matrix + (king_inference_cuda_device_ptr) value_bytes;
        if (cu_memcpy_dtod_async != NULL) {
            result = cu_memcpy_dtod_async(key_slot, key_source, (size_t) key_length * sizeof(float), NULL);
            if (result != 0) {
                king_inference_cuda_device_kv_cache_set_error(model, result, "cuMemcpyDtoDAsync_batch_key_failed");
                return FAILURE;
            }
            result = cu_memcpy_dtod_async(value_slot, value_source, (size_t) value_length * sizeof(float), NULL);
            if (result != 0) {
                king_inference_cuda_device_kv_cache_set_error(model, result, "cuMemcpyDtoDAsync_batch_value_failed");
                return FAILURE;
            }
        } else {
            result = cu_memcpy_dtod(key_slot, key_source, (size_t) key_length * sizeof(float));
            if (result != 0) {
                king_inference_cuda_device_kv_cache_set_error(model, result, "cuMemcpyDtoD_batch_key_failed");
                return FAILURE;
            }
            result = cu_memcpy_dtod(value_slot, value_source, (size_t) value_length * sizeof(float));
            if (result != 0) {
                king_inference_cuda_device_kv_cache_set_error(model, result, "cuMemcpyDtoD_batch_value_failed");
                return FAILURE;
            }
        }
    }

    model->cuda_device_kv_cache_write_count += count;
    model->cuda_device_kv_cache_batch_write_count++;
    model->cuda_device_kv_cache_last_layer = layer;
    model->cuda_device_kv_cache_last_head = head;
    model->cuda_device_kv_cache_last_position = position_start + count - 1;
    model->cuda_device_kv_cache_result = 0;
    model->cuda_device_kv_cache_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_device_kv_cache_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval cache;

    array_init(&cache);
    add_assoc_bool(&cache, "attempted", model->cuda_device_kv_cache_attempted);
    add_assoc_bool(&cache, "available", model->cuda_device_kv_cache_available);
    add_assoc_bool(&cache, "shape_ready", model->cuda_device_kv_cache_shape_ready);
    add_assoc_bool(&cache, "allocated", model->cuda_device_kv_cache_allocated);
    add_assoc_bool(&cache, "lazy_allocation", true);
    add_assoc_bool(&cache, "f32", model->cuda_device_kv_cache_f32);
    add_assoc_bool(&cache, "device_to_device_copy_available", model->cuda_device_kv_cache_dtod_available);
    add_assoc_bool(&cache, "read_write_bounds_checked", true);
    add_assoc_bool(&cache, "context_overflow_fails_closed", true);
    add_assoc_bool(&cache, "page_boundary_tracked", true);
    add_assoc_bool(&cache, "reset_clears_device_pointers", true);
    add_assoc_bool(&cache, "reset_clears_guard_state", true);
    add_assoc_long(&cache, "bytes", (zend_long) model->cuda_device_kv_cache_bytes);
    add_assoc_long(&cache, "key_bytes", (zend_long) model->cuda_device_kv_cache_key_bytes);
    add_assoc_long(&cache, "value_bytes", (zend_long) model->cuda_device_kv_cache_value_bytes);
    add_assoc_long(&cache, "reserve_calls", (zend_long) model->cuda_device_kv_cache_reserve_call_count);
    add_assoc_long(&cache, "logical_allocations", (zend_long) model->cuda_device_kv_cache_allocation_count);
    add_assoc_long(&cache, "layers", (zend_long) model->cuda_device_kv_cache_layers);
    add_assoc_long(&cache, "kv_heads", (zend_long) model->cuda_device_kv_cache_kv_heads);
    add_assoc_long(&cache, "context_tokens", (zend_long) model->cuda_device_kv_cache_context_tokens);
    add_assoc_long(&cache, "key_length", (zend_long) model->cuda_device_kv_cache_key_length);
    add_assoc_long(&cache, "value_length", (zend_long) model->cuda_device_kv_cache_value_length);
    add_assoc_long(&cache, "writes", (zend_long) model->cuda_device_kv_cache_write_count);
    add_assoc_long(&cache, "batch_writes", (zend_long) model->cuda_device_kv_cache_batch_write_count);
    add_assoc_bool(&cache, "guard_fail_closed", model->cuda_device_kv_cache_guard_fail_closed);
    add_assoc_long(&cache, "guard_checks", (zend_long) model->cuda_device_kv_cache_guard_check_count);
    add_assoc_long(&cache, "guard_failures", (zend_long) model->cuda_device_kv_cache_guard_failure_count);
    add_assoc_string(&cache, "guard_last_operation", model->cuda_device_kv_cache_guard_last_operation);
    add_assoc_string(&cache, "guard_last_failure", model->cuda_device_kv_cache_guard_last_failure);
    add_assoc_long(&cache, "guard_last_layer", (zend_long) model->cuda_device_kv_cache_guard_last_layer);
    add_assoc_long(&cache, "guard_last_head", (zend_long) model->cuda_device_kv_cache_guard_last_head);
    add_assoc_long(&cache, "guard_last_position", (zend_long) model->cuda_device_kv_cache_guard_last_position);
    add_assoc_long(&cache, "guard_last_count", (zend_long) model->cuda_device_kv_cache_guard_last_count);
    add_assoc_long(&cache, "guard_last_page_start", (zend_long) model->cuda_device_kv_cache_guard_last_page_start);
    add_assoc_long(&cache, "guard_last_page_end", (zend_long) model->cuda_device_kv_cache_guard_last_page_end);
    add_assoc_bool(&cache, "guard_last_page_crossed", model->cuda_device_kv_cache_guard_last_page_crossed);
    add_assoc_long(&cache, "last_layer", (zend_long) model->cuda_device_kv_cache_last_layer);
    add_assoc_long(&cache, "last_head", (zend_long) model->cuda_device_kv_cache_last_head);
    add_assoc_long(&cache, "last_position", (zend_long) model->cuda_device_kv_cache_last_position);
    add_assoc_long(&cache, "result", model->cuda_device_kv_cache_result);
    add_assoc_string(&cache, "error", model->cuda_device_kv_cache_error);
    add_assoc_zval(return_value, "device_kv_cache", &cache);
    add_assoc_bool(return_value, "gpu_device_kv_cache_ready", model->cuda_device_kv_cache_available);
}

static void king_inference_cuda_device_kv_cache_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_kv_cache;

    king_inference_cuda_device_kv_cache_add_status(model, return_value);
    if (!model->cuda_device_kv_cache_attempted
        || model->cuda_device_kv_cache_available
        || !model->cuda_device_allocator_available) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_kv_cache = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_kv_cache) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_device_kv_cache_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_device_kv_cache_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_device_kv_cache_unavailable");
    }
}
