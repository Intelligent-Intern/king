/*
 * GPU runtime status reporting for King inference.
 */

static bool king_inference_gpu_status_non_empty(const char *value)
{
    return value != NULL && value[0] != '\0';
}

static bool king_inference_gpu_status_path_readable(const char *path)
{
    return king_inference_gpu_status_non_empty(path) && access(path, R_OK) == 0;
}

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

static bool king_inference_gpu_status_artifact_size(const char *path, size_t *bytes_out)
{
    struct stat st;

    *bytes_out = 0;
    if (!king_inference_gpu_status_non_empty(path)) {
        return false;
    }
    if (stat(path, &st) != 0 || !S_ISREG(st.st_mode) || st.st_size <= 0) {
        return false;
    }

    *bytes_out = (size_t) st.st_size;
    return true;
}

static bool king_inference_gpu_status_driver_visible(void)
{
    return access("/proc/driver/nvidia/version", R_OK) == 0
        || access("/dev/nvidiactl", R_OK) == 0
        || access("/dev/dri", R_OK | X_OK) == 0;
}

static bool king_inference_gpu_status_read_temperature_path(
    const char *path,
    double *temperature_c
) {
    FILE *file;
    char buffer[64];
    char *endptr;
    double value;

    if (!king_inference_gpu_status_non_empty(path)) {
        return false;
    }

    file = fopen(path, "r");
    if (file == NULL) {
        return false;
    }

    if (fgets(buffer, sizeof(buffer), file) == NULL) {
        fclose(file);
        return false;
    }
    fclose(file);

    errno = 0;
    value = strtod(buffer, &endptr);
    if (errno != 0 || endptr == buffer) {
        return false;
    }

    *temperature_c = value > 1000.0 ? value / 1000.0 : value;
    return true;
}

static bool king_inference_gpu_status_read_temperature_command(
    const char *command,
    double *temperature_c
) {
    FILE *pipe;
    char buffer[64];
    char *endptr;
    double value;
    int status;

    if (!king_inference_gpu_status_non_empty(command)) {
        return false;
    }

    pipe = popen(command, "r");
    if (pipe == NULL) {
        return false;
    }

    if (fgets(buffer, sizeof(buffer), pipe) == NULL) {
        pclose(pipe);
        return false;
    }

    status = pclose(pipe);
    if (status == -1) {
        return false;
    }

    errno = 0;
    value = strtod(buffer, &endptr);
    if (errno != 0 || endptr == buffer) {
        return false;
    }

    *temperature_c = value > 1000.0 ? value / 1000.0 : value;
    return true;
}

static bool king_inference_gpu_status_backend_supported(const char *backend)
{
    return !king_inference_gpu_status_non_empty(backend)
        || strcasecmp(backend, "auto") == 0
        || strcasecmp(backend, "cuda") == 0;
}

static const char *king_inference_gpu_status_reason(
    bool gpu_enabled,
    bool process_bindings_enabled,
    bool config_bindings_enabled,
    bool backend_supported,
    bool artifact_configured,
    bool artifact_readable,
    zend_long max_gpu_layers,
    bool thermal_monitored,
    bool allow_unmonitored,
    bool temperature_available,
    bool temperature_ok,
    bool driver_visible,
    bool vram_admission_checked,
    bool model_vram_admitted
) {
    if (!gpu_enabled) {
        return "gpu_disabled";
    }
    if (!process_bindings_enabled) {
        return "process_gpu_bindings_disabled";
    }
    if (!config_bindings_enabled) {
        return "config_gpu_bindings_disabled";
    }
    if (!backend_supported) {
        return "unsupported_gpu_backend";
    }
    if (!artifact_configured) {
        return "gpu_model_artifact_missing";
    }
    if (!artifact_readable) {
        return "gpu_model_artifact_not_readable";
    }
    if (max_gpu_layers <= 0) {
        return "gpu_layers_disabled";
    }
    if (!vram_admission_checked) {
        return "gpu_vram_status_unavailable";
    }
    if (!model_vram_admitted) {
        return "gpu_model_artifact_exceeds_free_vram";
    }
    if (!driver_visible && !temperature_available) {
        return "gpu_driver_not_visible";
    }
    if (!thermal_monitored && !allow_unmonitored) {
        return "gpu_thermal_monitor_missing";
    }
    if (thermal_monitored && !temperature_available) {
        return "gpu_temperature_unavailable";
    }
    if (temperature_available && !temperature_ok) {
        return "gpu_temperature_limit_reached";
    }

    return "gpu_decoder_kernel_not_implemented";
}

static void king_inference_gpu_runtime_status_array(
    zval *return_value,
    bool gpu_enabled,
    bool process_bindings_enabled,
    bool config_bindings_enabled,
    const char *backend,
    const char *artifact_path,
    zend_long max_gpu_layers,
    const char *thermal_sensor_path,
    const char *thermal_sensor_command,
    double max_temperature_c,
    bool allow_unmonitored
) {
    zval thermal;
    zval cuda_driver;
    king_inference_cuda_driver_status cuda_status;
    bool backend_supported = king_inference_gpu_status_backend_supported(backend);
    bool artifact_configured = king_inference_gpu_status_non_empty(artifact_path);
    bool artifact_readable = king_inference_gpu_status_path_readable(artifact_path);
    bool thermal_path_configured = king_inference_gpu_status_non_empty(thermal_sensor_path);
    bool thermal_command_configured = king_inference_gpu_status_non_empty(thermal_sensor_command);
    bool thermal_monitored = thermal_path_configured || thermal_command_configured;
    size_t artifact_bytes = 0;
    bool temperature_available = false;
    bool temperature_ok = false;
    bool artifact_size_available;
    bool vram_admission_checked;
    bool model_vram_admitted;
    bool driver_visible;
    bool config_ready;
    double temperature_c = 0.0;
    const char *reason;

    king_inference_cuda_driver_probe(&cuda_status);
    driver_visible = (cuda_status.initialized && cuda_status.device_count > 0)
        || king_inference_gpu_status_driver_visible();
    artifact_size_available = king_inference_gpu_status_artifact_size(artifact_path, &artifact_bytes);
    vram_admission_checked = artifact_size_available && cuda_status.memory_info_available;
    model_vram_admitted = vram_admission_checked
        && artifact_bytes <= cuda_status.first_device_free_memory_bytes;

    if (thermal_path_configured) {
        temperature_available = king_inference_gpu_status_read_temperature_path(
            thermal_sensor_path,
            &temperature_c
        );
    } else if (thermal_command_configured) {
        temperature_available = king_inference_gpu_status_read_temperature_command(
            thermal_sensor_command,
            &temperature_c
        );
    }

    if (temperature_available) {
        driver_visible = true;
        temperature_ok = temperature_c < max_temperature_c;
    }

    config_ready = gpu_enabled
        && process_bindings_enabled
        && config_bindings_enabled
        && backend_supported
        && artifact_configured
        && artifact_readable
        && max_gpu_layers > 0
        && vram_admission_checked
        && model_vram_admitted
        && driver_visible
        && ((thermal_monitored && temperature_available && temperature_ok)
            || (!thermal_monitored && allow_unmonitored));

    reason = king_inference_gpu_status_reason(
        gpu_enabled,
        process_bindings_enabled,
        config_bindings_enabled,
        backend_supported,
        artifact_configured,
        artifact_readable,
        max_gpu_layers,
        thermal_monitored,
        allow_unmonitored,
        temperature_available,
        temperature_ok,
        driver_visible,
        vram_admission_checked,
        model_vram_admitted
    );

    array_init(return_value);
    add_assoc_bool(return_value, "gpu_enabled", gpu_enabled);
    add_assoc_bool(return_value, "process_gpu_bindings_enable", process_bindings_enabled);
    add_assoc_bool(return_value, "config_gpu_bindings_enable", config_bindings_enabled);
    add_assoc_string(
        return_value,
        "backend",
        king_inference_gpu_status_non_empty(backend) ? backend : "auto"
    );
    add_assoc_bool(return_value, "backend_supported", backend_supported);
    add_assoc_string(
        return_value,
        "artifact_path",
        artifact_configured ? artifact_path : ""
    );
    add_assoc_bool(return_value, "artifact_configured", artifact_configured);
    add_assoc_bool(return_value, "artifact_readable", artifact_readable);
    add_assoc_bool(return_value, "artifact_size_available", artifact_size_available);
    add_assoc_long(return_value, "artifact_bytes", (zend_long) artifact_bytes);
    add_assoc_long(return_value, "model_vram_required_bytes", (zend_long) artifact_bytes);
    add_assoc_bool(return_value, "kv_cache_estimate_available", false);
    add_assoc_long(return_value, "kv_cache_estimated_bytes", 0);
    add_assoc_long(return_value, "kv_cache_context_tokens", 0);
    add_assoc_long(return_value, "kv_cache_layers", 0);
    add_assoc_long(return_value, "kv_cache_page_tokens", 0);
    add_assoc_long(return_value, "kv_cache_element_bytes", 0);
    add_assoc_string(return_value, "kv_cache_estimate_source", "unavailable");
    add_assoc_bool(return_value, "vram_admission_checked", vram_admission_checked);
    add_assoc_bool(return_value, "model_vram_admitted", model_vram_admitted);
    add_assoc_long(return_value, "max_gpu_layers", max_gpu_layers);
    add_assoc_bool(return_value, "driver_visible", driver_visible);
    add_assoc_bool(return_value, "config_ready", config_ready);
    add_assoc_bool(return_value, "decoder_kernel_ready", false);
    add_assoc_bool(return_value, "generation_ready", false);
    add_assoc_string(return_value, "reason", reason);

    array_init(&cuda_driver);
    add_assoc_bool(&cuda_driver, "loader_available", cuda_status.loader_available);
    add_assoc_bool(&cuda_driver, "symbols_available", cuda_status.symbols_available);
    add_assoc_bool(&cuda_driver, "initialized", cuda_status.initialized);
    add_assoc_long(&cuda_driver, "init_result", cuda_status.init_result);
    add_assoc_long(&cuda_driver, "driver_version", cuda_status.driver_version);
    add_assoc_long(&cuda_driver, "device_count", cuda_status.device_count);
    add_assoc_string(&cuda_driver, "first_device_name", cuda_status.first_device_name);
    add_assoc_long(
        &cuda_driver,
        "first_device_total_memory_bytes",
        (zend_long) cuda_status.first_device_total_memory_bytes
    );
    add_assoc_bool(&cuda_driver, "memory_info_available", cuda_status.memory_info_available);
    add_assoc_long(
        &cuda_driver,
        "first_device_free_memory_bytes",
        (zend_long) cuda_status.first_device_free_memory_bytes
    );
    add_assoc_long(
        &cuda_driver,
        "first_device_runtime_total_memory_bytes",
        (zend_long) cuda_status.first_device_runtime_total_memory_bytes
    );
    add_assoc_string(&cuda_driver, "error", cuda_status.error);
    add_assoc_zval(return_value, "cuda_driver", &cuda_driver);

    array_init(&thermal);
    add_assoc_string(
        &thermal,
        "sensor_path",
        thermal_path_configured ? thermal_sensor_path : ""
    );
    add_assoc_string(
        &thermal,
        "sensor_command",
        thermal_command_configured ? thermal_sensor_command : ""
    );
    add_assoc_bool(&thermal, "monitored", thermal_monitored);
    add_assoc_bool(&thermal, "allow_unmonitored_gpu", allow_unmonitored);
    add_assoc_double(&thermal, "max_temperature_c", max_temperature_c);
    add_assoc_bool(&thermal, "temperature_available", temperature_available);
    if (temperature_available) {
        add_assoc_double(&thermal, "temperature_c", temperature_c);
    } else {
        add_assoc_null(&thermal, "temperature_c");
    }
    add_assoc_bool(&thermal, "temperature_ok", temperature_available && temperature_ok);
    add_assoc_zval(return_value, "thermal", &thermal);
}

static void king_inference_gpu_runtime_add_kv_cache_estimate(
    zval *return_value,
    king_inference_model_object *model
) {
    zval *plan;
    zval *max_sequence_bytes;
    zval *max_context_tokens;
    zval *layers;
    zval *page_tokens;
    zval *element_bytes;
    bool estimate_available = false;
    zend_long estimated_bytes = 0;

    if (Z_ISUNDEF(model->paged_kv_cache_plan) || Z_TYPE(model->paged_kv_cache_plan) != IS_ARRAY) {
        return;
    }

    plan = &model->paged_kv_cache_plan;
    max_sequence_bytes = zend_hash_str_find(
        Z_ARRVAL_P(plan),
        "max_sequence_bytes",
        sizeof("max_sequence_bytes") - 1
    );
    max_context_tokens = zend_hash_str_find(
        Z_ARRVAL_P(plan),
        "max_context_tokens",
        sizeof("max_context_tokens") - 1
    );
    layers = zend_hash_str_find(Z_ARRVAL_P(plan), "layers", sizeof("layers") - 1);
    page_tokens = zend_hash_str_find(Z_ARRVAL_P(plan), "page_tokens", sizeof("page_tokens") - 1);
    element_bytes = zend_hash_str_find(Z_ARRVAL_P(plan), "element_bytes", sizeof("element_bytes") - 1);

    if (max_sequence_bytes != NULL && Z_TYPE_P(max_sequence_bytes) == IS_LONG && Z_LVAL_P(max_sequence_bytes) > 0) {
        estimate_available = true;
        estimated_bytes = Z_LVAL_P(max_sequence_bytes);
    }

    add_assoc_bool(return_value, "kv_cache_estimate_available", estimate_available);
    add_assoc_long(return_value, "kv_cache_estimated_bytes", estimate_available ? estimated_bytes : 0);
    add_assoc_long(
        return_value,
        "kv_cache_context_tokens",
        max_context_tokens != NULL && Z_TYPE_P(max_context_tokens) == IS_LONG ? Z_LVAL_P(max_context_tokens) : 0
    );
    add_assoc_long(
        return_value,
        "kv_cache_layers",
        layers != NULL && Z_TYPE_P(layers) == IS_LONG ? Z_LVAL_P(layers) : 0
    );
    add_assoc_long(
        return_value,
        "kv_cache_page_tokens",
        page_tokens != NULL && Z_TYPE_P(page_tokens) == IS_LONG ? Z_LVAL_P(page_tokens) : 0
    );
    add_assoc_long(
        return_value,
        "kv_cache_element_bytes",
        element_bytes != NULL && Z_TYPE_P(element_bytes) == IS_LONG ? Z_LVAL_P(element_bytes) : 0
    );
    add_assoc_string(
        return_value,
        "kv_cache_estimate_source",
        estimate_available ? "paged_kv_cache_plan" : "paged_kv_cache_plan_incomplete"
    );
}

static void king_inference_gpu_runtime_status_from_compute(
    const kg_high_perf_compute_ai_config_t *compute,
    zval *return_value
) {
    bool process_gpu_bindings_enabled = king_high_perf_compute_ai_config.gpu_bindings_enable;
    bool config_gpu_bindings_enabled = compute->gpu_bindings_enable;
    bool gpu_enabled = process_gpu_bindings_enabled
        && config_gpu_bindings_enabled
        && compute->inference_gpu_max_gpu_layers > 0;

    king_inference_gpu_runtime_status_array(
        return_value,
        gpu_enabled,
        process_gpu_bindings_enabled,
        config_gpu_bindings_enabled,
        compute->gpu_default_backend,
        compute->inference_gpu_model_artifact,
        compute->inference_gpu_max_gpu_layers,
        compute->inference_gpu_thermal_sensor_path,
        compute->inference_gpu_thermal_sensor_command,
        compute->inference_gpu_thermal_max_temperature_c,
        compute->inference_gpu_allow_unmonitored
    );
}

static void king_inference_gpu_runtime_status_from_model(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *gpu = king_inference_array_find(&model->config, "gpu");
    zval *thermal = gpu != NULL && Z_TYPE_P(gpu) == IS_ARRAY
        ? king_inference_array_find(gpu, "thermal")
        : NULL;
    zval *sensor_path = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_array_find(thermal, "sensor_path")
        : NULL;
    zval *sensor_command = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_array_find(thermal, "sensor_command")
        : NULL;
    const char *thermal_sensor_path = sensor_path != NULL && Z_TYPE_P(sensor_path) == IS_STRING
        ? Z_STRVAL_P(sensor_path)
        : "";
    const char *thermal_sensor_command = sensor_command != NULL && Z_TYPE_P(sensor_command) == IS_STRING
        ? Z_STRVAL_P(sensor_command)
        : "";
    double max_temperature_c = thermal != NULL && Z_TYPE_P(thermal) == IS_ARRAY
        ? king_inference_double_from_array(thermal, "max_temperature_c", 80.0)
        : 80.0;
    bool allow_unmonitored = thermal != NULL
        && Z_TYPE_P(thermal) == IS_ARRAY
        && king_inference_bool_from_array(thermal, "allow_unmonitored_gpu", false);

    king_inference_gpu_runtime_status_array(
        return_value,
        king_inference_gpu_enabled(&model->config),
        king_high_perf_compute_ai_config.gpu_bindings_enable,
        true,
        king_high_perf_compute_ai_config.gpu_default_backend,
        model->artifact_path != NULL ? ZSTR_VAL(model->artifact_path) : "",
        king_inference_max_gpu_layers(&model->config),
        thermal_sensor_path,
        thermal_sensor_command,
        max_temperature_c,
        allow_unmonitored
    );
    king_inference_gpu_runtime_add_kv_cache_estimate(return_value, model);
}
