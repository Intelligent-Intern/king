/*
 * CUDA device-memory allocation for native King inference.
 */

struct _king_inference_cuda_device_allocation {
    king_inference_cuda_device_ptr device_ptr;
    size_t bytes;
    king_inference_cuda_device_allocation *next;
};

typedef int (*king_inference_cuda_allocator_cu_mem_alloc_fn)(
    king_inference_cuda_device_ptr *device_ptr,
    size_t bytes
);
typedef int (*king_inference_cuda_allocator_cu_mem_free_fn)(
    king_inference_cuda_device_ptr device_ptr
);
typedef int (*king_inference_cuda_allocator_cu_ctx_set_current_fn)(void *ctx);

static void king_inference_cuda_device_allocator_reset_fields(king_inference_model_object *model)
{
    model->cuda_device_allocations = NULL;
    model->cuda_device_allocator_result = 0;
    model->cuda_device_allocator_error[0] = '\0';
    model->cuda_device_bytes_allocated = 0;
    model->cuda_device_peak_bytes_allocated = 0;
    model->cuda_device_allocation_count = 0;
    model->cuda_device_allocator_attempted = false;
    model->cuda_device_allocator_symbols_available = false;
    model->cuda_device_allocator_available = false;
}

static void king_inference_cuda_device_allocator_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_device_allocator_error";
    }

    model->cuda_device_allocator_result = result;
    snprintf(
        model->cuda_device_allocator_error,
        sizeof(model->cuda_device_allocator_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_device_allocator_symbol(
    king_inference_model_object *model,
    const char *primary_name,
    const char *fallback_name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_device_allocator_set_error(model, -1, "cuda_driver_handle_unavailable");
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

    king_inference_cuda_device_allocator_set_error(
        model,
        -1,
        primary_name != NULL ? primary_name : fallback_name
    );
    return false;
}

static bool king_inference_cuda_device_allocator_set_current(king_inference_model_object *model)
{
    king_inference_cuda_allocator_cu_ctx_set_current_fn cu_ctx_set_current;
    int result;

    if (model->cuda_context == NULL) {
        king_inference_cuda_device_allocator_set_error(model, -1, "cuda_context_unavailable");
        return false;
    }

    if (!king_inference_cuda_device_allocator_symbol(
        model,
        "cuCtxSetCurrent",
        NULL,
        (void **) &cu_ctx_set_current
    )) {
        return false;
    }

    result = cu_ctx_set_current(model->cuda_context);
    if (result != 0) {
        king_inference_cuda_device_allocator_set_error(model, result, "cuCtxSetCurrent_failed");
        return false;
    }

    return true;
}

static void king_inference_cuda_device_allocator_release(king_inference_model_object *model)
{
    king_inference_cuda_allocator_cu_mem_free_fn cu_mem_free = NULL;
    king_inference_cuda_device_allocation *allocation = model->cuda_device_allocations;
    king_inference_cuda_device_allocation *next;
    bool can_free_device_memory = allocation == NULL;

    if (allocation != NULL
        && model->cuda_driver_handle != NULL
        && model->cuda_context != NULL
        && king_inference_cuda_device_allocator_set_current(model)
        && king_inference_cuda_device_allocator_symbol(
            model,
            "cuMemFree_v2",
            "cuMemFree",
            (void **) &cu_mem_free
        )) {
        can_free_device_memory = true;
    }

    while (allocation != NULL) {
        next = allocation->next;
        if (can_free_device_memory && cu_mem_free != NULL && allocation->device_ptr != 0) {
            (void) cu_mem_free(allocation->device_ptr);
        }
        efree(allocation);
        allocation = next;
    }

    king_inference_cuda_device_allocator_reset_fields(model);
}

static void king_inference_cuda_device_allocator_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_allocator_cu_mem_alloc_fn cu_mem_alloc;
    king_inference_cuda_allocator_cu_mem_free_fn cu_mem_free;

    king_inference_cuda_device_allocator_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_device_allocator_attempted = true;
    if (!model->cuda_context_available || model->cuda_driver_handle == NULL) {
        king_inference_cuda_device_allocator_set_error(model, -1, "cuda_context_unavailable");
        return;
    }

    if (!king_inference_cuda_device_allocator_symbol(
        model,
        "cuMemAlloc_v2",
        "cuMemAlloc",
        (void **) &cu_mem_alloc
    )
        || !king_inference_cuda_device_allocator_symbol(
            model,
            "cuMemFree_v2",
            "cuMemFree",
            (void **) &cu_mem_free
        )
        || !king_inference_cuda_device_allocator_set_current(model)) {
        return;
    }

    model->cuda_device_allocator_symbols_available = true;
    model->cuda_device_allocator_available = true;
    model->cuda_device_allocator_result = 0;
    model->cuda_device_allocator_error[0] = '\0';
}

static zend_result king_inference_cuda_device_allocator_alloc(
    king_inference_model_object *model,
    size_t bytes,
    king_inference_cuda_device_ptr *device_ptr
) {
    king_inference_cuda_allocator_cu_mem_alloc_fn cu_mem_alloc;
    king_inference_cuda_device_allocation *allocation;
    king_inference_cuda_device_ptr allocated = 0;
    int result;

    *device_ptr = 0;
    if (bytes == 0) {
        king_inference_cuda_device_allocator_set_error(model, -1, "cuda_allocation_size_empty");
        return FAILURE;
    }
    if (!model->cuda_device_allocator_available) {
        king_inference_cuda_device_allocator_set_error(model, -1, "cuda_device_allocator_unavailable");
        return FAILURE;
    }
    if (model->cuda_device_bytes_allocated > ((size_t) -1) - bytes) {
        king_inference_cuda_device_allocator_set_error(model, -1, "cuda_allocation_counter_overflow");
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)
        || !king_inference_cuda_device_allocator_symbol(
            model,
            "cuMemAlloc_v2",
            "cuMemAlloc",
            (void **) &cu_mem_alloc
        )) {
        return FAILURE;
    }

    allocation = emalloc(sizeof(*allocation));

    result = cu_mem_alloc(&allocated, bytes);
    if (result != 0 || allocated == 0) {
        efree(allocation);
        king_inference_cuda_device_allocator_set_error(
            model,
            result,
            result != 0 ? "cuMemAlloc_failed" : "cuda_allocation_empty_pointer"
        );
        return FAILURE;
    }

    allocation->device_ptr = allocated;
    allocation->bytes = bytes;
    allocation->next = model->cuda_device_allocations;
    model->cuda_device_allocations = allocation;
    model->cuda_device_bytes_allocated += bytes;
    model->cuda_device_allocation_count++;
    if (model->cuda_device_bytes_allocated > model->cuda_device_peak_bytes_allocated) {
        model->cuda_device_peak_bytes_allocated = model->cuda_device_bytes_allocated;
    }

    *device_ptr = allocated;
    model->cuda_device_allocator_result = 0;
    model->cuda_device_allocator_error[0] = '\0';
    return SUCCESS;
}

static zend_result king_inference_cuda_device_allocator_free(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr device_ptr
) {
    king_inference_cuda_allocator_cu_mem_free_fn cu_mem_free;
    king_inference_cuda_device_allocation **cursor = &model->cuda_device_allocations;
    king_inference_cuda_device_allocation *allocation;
    int result;

    if (device_ptr == 0) {
        return SUCCESS;
    }
    if (!model->cuda_device_allocator_available) {
        king_inference_cuda_device_allocator_set_error(model, -1, "cuda_device_allocator_unavailable");
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)
        || !king_inference_cuda_device_allocator_symbol(
            model,
            "cuMemFree_v2",
            "cuMemFree",
            (void **) &cu_mem_free
        )) {
        return FAILURE;
    }

    while (*cursor != NULL && (*cursor)->device_ptr != device_ptr) {
        cursor = &(*cursor)->next;
    }
    if (*cursor == NULL) {
        king_inference_cuda_device_allocator_set_error(model, -1, "cuda_allocation_not_tracked");
        return FAILURE;
    }

    allocation = *cursor;
    result = cu_mem_free(device_ptr);
    if (result != 0) {
        king_inference_cuda_device_allocator_set_error(model, result, "cuMemFree_failed");
        return FAILURE;
    }

    *cursor = allocation->next;
    if (model->cuda_device_bytes_allocated >= allocation->bytes) {
        model->cuda_device_bytes_allocated -= allocation->bytes;
    } else {
        model->cuda_device_bytes_allocated = 0;
    }
    if (model->cuda_device_allocation_count > 0) {
        model->cuda_device_allocation_count--;
    }
    efree(allocation);

    model->cuda_device_allocator_result = 0;
    model->cuda_device_allocator_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_device_allocator_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval allocator;

    array_init(&allocator);
    add_assoc_bool(&allocator, "attempted", model->cuda_device_allocator_attempted);
    add_assoc_bool(&allocator, "symbols_available", model->cuda_device_allocator_symbols_available);
    add_assoc_bool(&allocator, "available", model->cuda_device_allocator_available);
    add_assoc_long(&allocator, "active_allocation_count", (zend_long) model->cuda_device_allocation_count);
    add_assoc_long(&allocator, "active_bytes", (zend_long) model->cuda_device_bytes_allocated);
    add_assoc_long(&allocator, "peak_bytes", (zend_long) model->cuda_device_peak_bytes_allocated);
    add_assoc_long(&allocator, "result", model->cuda_device_allocator_result);
    add_assoc_string(&allocator, "error", model->cuda_device_allocator_error);
    add_assoc_zval(return_value, "device_memory_allocator", &allocator);
}

static void king_inference_cuda_device_allocator_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_allocator;

    king_inference_cuda_device_allocator_add_status(model, return_value);
    if (!model->cuda_device_allocator_attempted
        || model->cuda_device_allocator_available
        || !model->cuda_context_available) {
        return;
    }

    add_assoc_bool(return_value, "config_ready", false);

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_allocator = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_allocator) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_device_memory_allocator_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_device_memory_allocator_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(
            return_value,
            "gpu_device_memory_allocator_unavailable"
        );
    }
}
