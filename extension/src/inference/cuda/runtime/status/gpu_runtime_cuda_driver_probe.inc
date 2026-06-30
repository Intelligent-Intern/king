/*
 * CUDA driver probing for GPU runtime admission.
 */

typedef int (*king_inference_cuda_cu_init_fn)(unsigned int flags);
typedef int (*king_inference_cuda_cu_driver_get_version_fn)(int *driver_version);
typedef int (*king_inference_cuda_cu_device_get_count_fn)(int *count);
typedef int (*king_inference_cuda_cu_device_get_fn)(int *device, int ordinal);
typedef int (*king_inference_cuda_cu_device_get_name_fn)(char *name, int len, int device);
typedef int (*king_inference_cuda_cu_device_total_mem_fn)(size_t *bytes, int device);
typedef int (*king_inference_cuda_cu_device_primary_ctx_retain_fn)(void **ctx, int device);
typedef int (*king_inference_cuda_cu_device_primary_ctx_release_fn)(int device);
typedef int (*king_inference_cuda_cu_ctx_set_current_fn)(void *ctx);
typedef int (*king_inference_cuda_cu_mem_get_info_fn)(size_t *free_bytes, size_t *total_bytes);

typedef struct _king_inference_cuda_driver_status {
    bool loader_available;
    bool symbols_available;
    bool initialized;
    bool memory_info_available;
    int init_result;
    int driver_version;
    int device_count;
    char first_device_name[256];
    size_t first_device_total_memory_bytes;
    size_t first_device_free_memory_bytes;
    size_t first_device_runtime_total_memory_bytes;
    char error[160];
} king_inference_cuda_driver_status;

static void king_inference_cuda_driver_status_init(king_inference_cuda_driver_status *status)
{
    memset(status, 0, sizeof(*status));
    status->init_result = -1;
    status->driver_version = 0;
    status->device_count = 0;
    status->first_device_name[0] = '\0';
    status->first_device_free_memory_bytes = 0;
    status->first_device_runtime_total_memory_bytes = 0;
    status->error[0] = '\0';
}

static void king_inference_cuda_driver_status_set_error(
    king_inference_cuda_driver_status *status,
    const char *message)
{
    if (message == NULL) {
        message = "unknown_cuda_driver_error";
    }

    snprintf(status->error, sizeof(status->error), "%s", message);
}

static bool king_inference_cuda_driver_symbol(
    void *handle,
    const char *name,
    void **symbol,
    king_inference_cuda_driver_status *status)
{
    *symbol = dlsym(handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_driver_status_set_error(status, name);
    return false;
}

static void king_inference_cuda_driver_probe(king_inference_cuda_driver_status *status)
{
    void *handle;
    king_inference_cuda_cu_init_fn cu_init;
    king_inference_cuda_cu_driver_get_version_fn cu_driver_get_version;
    king_inference_cuda_cu_device_get_count_fn cu_device_get_count;
    king_inference_cuda_cu_device_get_fn cu_device_get;
    king_inference_cuda_cu_device_get_name_fn cu_device_get_name;
    king_inference_cuda_cu_device_total_mem_fn cu_device_total_mem;
    king_inference_cuda_cu_device_primary_ctx_retain_fn cu_device_primary_ctx_retain;
    king_inference_cuda_cu_device_primary_ctx_release_fn cu_device_primary_ctx_release;
    king_inference_cuda_cu_ctx_set_current_fn cu_ctx_set_current;
    king_inference_cuda_cu_mem_get_info_fn cu_mem_get_info;
    void *ctx = NULL;
    int device = 0;

    king_inference_cuda_driver_status_init(status);

    handle = dlopen("libcuda.so.1", RTLD_LAZY | RTLD_LOCAL);
    if (handle == NULL) {
        king_inference_cuda_driver_status_set_error(status, dlerror());
        return;
    }
    status->loader_available = true;

    if (!king_inference_cuda_driver_symbol(handle, "cuInit", (void **) &cu_init, status)
        || !king_inference_cuda_driver_symbol(
            handle,
            "cuDriverGetVersion",
            (void **) &cu_driver_get_version,
            status
        )
        || !king_inference_cuda_driver_symbol(
            handle,
            "cuDeviceGetCount",
            (void **) &cu_device_get_count,
            status
        )
        || !king_inference_cuda_driver_symbol(handle, "cuDeviceGet", (void **) &cu_device_get, status)
        || !king_inference_cuda_driver_symbol(
            handle,
            "cuDeviceGetName",
            (void **) &cu_device_get_name,
            status
        )) {
        dlclose(handle);
        return;
    }

    cu_device_total_mem = NULL;
    if (dlsym(handle, "cuDeviceTotalMem_v2") != NULL) {
        cu_device_total_mem = (king_inference_cuda_cu_device_total_mem_fn)
            dlsym(handle, "cuDeviceTotalMem_v2");
    } else if (dlsym(handle, "cuDeviceTotalMem") != NULL) {
        cu_device_total_mem = (king_inference_cuda_cu_device_total_mem_fn)
            dlsym(handle, "cuDeviceTotalMem");
    }
    cu_device_primary_ctx_retain = (king_inference_cuda_cu_device_primary_ctx_retain_fn)
        dlsym(handle, "cuDevicePrimaryCtxRetain");
    cu_device_primary_ctx_release = (king_inference_cuda_cu_device_primary_ctx_release_fn)
        dlsym(handle, "cuDevicePrimaryCtxRelease");
    cu_ctx_set_current = (king_inference_cuda_cu_ctx_set_current_fn)
        dlsym(handle, "cuCtxSetCurrent");
    cu_mem_get_info = NULL;
    if (dlsym(handle, "cuMemGetInfo_v2") != NULL) {
        cu_mem_get_info = (king_inference_cuda_cu_mem_get_info_fn)
            dlsym(handle, "cuMemGetInfo_v2");
    } else if (dlsym(handle, "cuMemGetInfo") != NULL) {
        cu_mem_get_info = (king_inference_cuda_cu_mem_get_info_fn)
            dlsym(handle, "cuMemGetInfo");
    }

    status->symbols_available = true;
    status->init_result = cu_init(0);
    if (status->init_result != 0) {
        king_inference_cuda_driver_status_set_error(status, "cuInit_failed");
        dlclose(handle);
        return;
    }
    status->initialized = true;

    if (cu_driver_get_version(&status->driver_version) != 0) {
        status->driver_version = 0;
    }
    if (cu_device_get_count(&status->device_count) != 0 || status->device_count < 0) {
        status->device_count = 0;
    }
    if (status->device_count > 0 && cu_device_get(&device, 0) == 0) {
        if (cu_device_get_name(status->first_device_name, sizeof(status->first_device_name), device) != 0) {
            status->first_device_name[0] = '\0';
        }
        if (cu_device_total_mem != NULL
            && cu_device_total_mem(&status->first_device_total_memory_bytes, device) != 0) {
            status->first_device_total_memory_bytes = 0;
        }
        if (cu_device_primary_ctx_retain != NULL
            && cu_device_primary_ctx_release != NULL
            && cu_ctx_set_current != NULL
            && cu_mem_get_info != NULL
            && cu_device_primary_ctx_retain(&ctx, device) == 0
            && ctx != NULL) {
            if (cu_ctx_set_current(ctx) == 0
                && cu_mem_get_info(
                    &status->first_device_free_memory_bytes,
                    &status->first_device_runtime_total_memory_bytes
                ) == 0) {
                status->memory_info_available = true;
            }
            (void) cu_device_primary_ctx_release(device);
        }
    }

    dlclose(handle);
}
