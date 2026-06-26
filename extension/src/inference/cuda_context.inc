/*
 * CUDA context ownership for native King inference.
 */

typedef int (*king_inference_cuda_context_cu_init_fn)(unsigned int flags);
typedef int (*king_inference_cuda_context_cu_device_get_count_fn)(int *count);
typedef int (*king_inference_cuda_context_cu_device_get_fn)(int *device, int ordinal);
typedef int (*king_inference_cuda_context_cu_device_primary_ctx_retain_fn)(void **ctx, int device);
typedef int (*king_inference_cuda_context_cu_device_primary_ctx_release_fn)(int device);
typedef int (*king_inference_cuda_context_cu_ctx_set_current_fn)(void *ctx);

static void king_inference_cuda_context_reset_fields(king_inference_model_object *model)
{
    model->cuda_driver_handle = NULL;
    model->cuda_context = NULL;
    model->cuda_device = -1;
    model->cuda_context_result = 0;
    model->cuda_context_error[0] = '\0';
    model->cuda_context_attempted = false;
    model->cuda_context_available = false;
    model->cuda_context_owned = false;
}

static void king_inference_cuda_context_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_context_error";
    }

    model->cuda_context_result = result;
    snprintf(model->cuda_context_error, sizeof(model->cuda_context_error), "%s", message);
}

static bool king_inference_cuda_context_symbol(
    king_inference_model_object *model,
    void *handle,
    const char *name,
    void **symbol
) {
    *symbol = dlsym(handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_context_set_error(model, -1, name);
    return false;
}

static void king_inference_cuda_context_release(king_inference_model_object *model)
{
    king_inference_cuda_context_cu_device_primary_ctx_release_fn cu_device_primary_ctx_release;

    if (model->cuda_driver_handle != NULL && model->cuda_context_owned && model->cuda_device >= 0) {
        cu_device_primary_ctx_release = (king_inference_cuda_context_cu_device_primary_ctx_release_fn)
            dlsym(model->cuda_driver_handle, "cuDevicePrimaryCtxRelease");
        if (cu_device_primary_ctx_release != NULL) {
            (void) cu_device_primary_ctx_release(model->cuda_device);
        }
    }

    if (model->cuda_driver_handle != NULL) {
        dlclose(model->cuda_driver_handle);
    }

    king_inference_cuda_context_reset_fields(model);
}

static void king_inference_cuda_context_open(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_context_cu_init_fn cu_init;
    king_inference_cuda_context_cu_device_get_count_fn cu_device_get_count;
    king_inference_cuda_context_cu_device_get_fn cu_device_get;
    king_inference_cuda_context_cu_device_primary_ctx_retain_fn cu_device_primary_ctx_retain;
    king_inference_cuda_context_cu_ctx_set_current_fn cu_ctx_set_current;
    void *handle;
    void *ctx = NULL;
    int device_count = 0;
    int device = 0;
    int result;

    king_inference_cuda_context_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_context_attempted = true;

    handle = dlopen("libcuda.so.1", RTLD_LAZY | RTLD_LOCAL);
    if (handle == NULL) {
        king_inference_cuda_context_set_error(model, -1, dlerror());
        return;
    }

    if (!king_inference_cuda_context_symbol(model, handle, "cuInit", (void **) &cu_init)
        || !king_inference_cuda_context_symbol(
            model,
            handle,
            "cuDeviceGetCount",
            (void **) &cu_device_get_count
        )
        || !king_inference_cuda_context_symbol(model, handle, "cuDeviceGet", (void **) &cu_device_get)
        || !king_inference_cuda_context_symbol(
            model,
            handle,
            "cuDevicePrimaryCtxRetain",
            (void **) &cu_device_primary_ctx_retain
        )
        || !king_inference_cuda_context_symbol(
            model,
            handle,
            "cuCtxSetCurrent",
            (void **) &cu_ctx_set_current
        )) {
        dlclose(handle);
        return;
    }

    result = cu_init(0);
    if (result != 0) {
        dlclose(handle);
        king_inference_cuda_context_set_error(model, result, "cuInit_failed");
        return;
    }

    result = cu_device_get_count(&device_count);
    if (result != 0 || device_count <= 0) {
        dlclose(handle);
        king_inference_cuda_context_set_error(
            model,
            result,
            result != 0 ? "cuDeviceGetCount_failed" : "cuda_device_unavailable"
        );
        return;
    }

    result = cu_device_get(&device, 0);
    if (result != 0) {
        dlclose(handle);
        king_inference_cuda_context_set_error(model, result, "cuDeviceGet_failed");
        return;
    }

    result = cu_device_primary_ctx_retain(&ctx, device);
    if (result != 0 || ctx == NULL) {
        dlclose(handle);
        king_inference_cuda_context_set_error(
            model,
            result,
            result != 0 ? "cuDevicePrimaryCtxRetain_failed" : "cuda_primary_context_unavailable"
        );
        return;
    }

    result = cu_ctx_set_current(ctx);
    if (result != 0) {
        king_inference_cuda_context_cu_device_primary_ctx_release_fn release_fn;

        release_fn = (king_inference_cuda_context_cu_device_primary_ctx_release_fn)
            dlsym(handle, "cuDevicePrimaryCtxRelease");
        if (release_fn != NULL) {
            (void) release_fn(device);
        }
        dlclose(handle);
        king_inference_cuda_context_set_error(model, result, "cuCtxSetCurrent_failed");
        return;
    }

    model->cuda_driver_handle = handle;
    model->cuda_context = ctx;
    model->cuda_device = device;
    model->cuda_context_result = 0;
    model->cuda_context_error[0] = '\0';
    model->cuda_context_available = true;
    model->cuda_context_owned = true;
}

static void king_inference_cuda_context_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval cuda_context;

    array_init(&cuda_context);
    add_assoc_bool(&cuda_context, "attempted", model->cuda_context_attempted);
    add_assoc_bool(&cuda_context, "available", model->cuda_context_available);
    add_assoc_bool(&cuda_context, "owned", model->cuda_context_owned);
    add_assoc_long(&cuda_context, "device", model->cuda_device);
    add_assoc_long(&cuda_context, "result", model->cuda_context_result);
    add_assoc_string(&cuda_context, "error", model->cuda_context_error);
    add_assoc_zval(return_value, "cuda_context", &cuda_context);
}

static void king_inference_cuda_context_prepend_return_reason(
    zval *return_value,
    const char *reason
) {
    zval fresh;
    zval *reasons;
    zval *entry;

    array_init(&fresh);
    king_inference_gpu_status_append_reason(&fresh, reason);

    reasons = zend_hash_str_find(
        Z_ARRVAL_P(return_value),
        "refusal_reasons",
        sizeof("refusal_reasons") - 1
    );
    if (reasons != NULL && Z_TYPE_P(reasons) == IS_ARRAY) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(reasons), entry) {
            if (Z_TYPE_P(entry) == IS_STRING) {
                king_inference_gpu_status_append_reason(&fresh, Z_STRVAL_P(entry));
            }
        } ZEND_HASH_FOREACH_END();
    }

    add_assoc_zval(return_value, "refusal_reasons", &fresh);
}

static void king_inference_cuda_context_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_context;

    king_inference_cuda_context_add_status(model, return_value);
    if (!model->cuda_context_attempted || model->cuda_context_available) {
        return;
    }

    add_assoc_bool(return_value, "config_ready", false);

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_context = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_context) {
        king_inference_cuda_context_prepend_return_reason(return_value, "gpu_cuda_context_unavailable");
        add_assoc_string(return_value, "reason", "gpu_cuda_context_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_cuda_context_unavailable");
    }
}
