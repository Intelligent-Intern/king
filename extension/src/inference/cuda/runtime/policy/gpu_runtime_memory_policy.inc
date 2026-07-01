/*
 * GPU runtime memory and offload policy helpers.
 */

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

static bool king_inference_gpu_status_backend_supported(const char *backend)
{
    return !king_inference_gpu_status_non_empty(backend)
        || strcasecmp(backend, "auto") == 0
        || strcasecmp(backend, "cuda") == 0;
}

static zend_long king_inference_gpu_mb_to_bytes(zend_long mb)
{
    if (mb <= 0) {
        return 0;
    }
    if (mb > ZEND_LONG_MAX / 1024 / 1024) {
        return ZEND_LONG_MAX;
    }

    return mb * 1024 * 1024;
}

static bool king_inference_gpu_system_ram_available_bytes(zend_long *bytes_out)
{
    FILE *file;
    char buffer[160];

    *bytes_out = 0;
    file = fopen("/proc/meminfo", "r");
    if (file == NULL) {
        return false;
    }

    while (fgets(buffer, sizeof(buffer), file) != NULL) {
        unsigned long long kb;

        if (sscanf(buffer, "MemAvailable: %llu kB", &kb) == 1) {
            fclose(file);
            if (kb > (unsigned long long) ZEND_LONG_MAX / 1024ULL) {
                *bytes_out = ZEND_LONG_MAX;
            } else {
                *bytes_out = (zend_long) (kb * 1024ULL);
            }
            return true;
        }
    }

    fclose(file);
    return false;
}

static const char *king_inference_gpu_system_ram_offload_status(
    bool required,
    bool allowed,
    bool limit_configured,
    bool limit_met,
    bool system_ram_available,
    bool system_ram_floor_met,
    bool supported,
    bool admitted
) {
    if (!required) {
        return allowed ? "allowed_not_required" : "disabled_not_required";
    }
    if (!allowed) {
        return "required_but_disabled";
    }
    if (!limit_configured) {
        return "required_but_limit_missing";
    }
    if (!limit_met) {
        return "required_but_exceeds_limit";
    }
    if (!system_ram_available) {
        return "required_but_system_ram_unknown";
    }
    if (!system_ram_floor_met) {
        return "required_but_system_ram_floor_not_met";
    }
    if (!supported) {
        return "required_but_not_supported";
    }
    return admitted ? "admitted" : "required_but_not_admitted";
}

static const char *king_inference_gpu_system_ram_offload_error(
    bool required,
    bool allowed,
    bool limit_configured,
    bool limit_met,
    bool system_ram_available,
    bool system_ram_floor_met,
    bool supported,
    bool admitted
) {
    if (!required || admitted) {
        return "none";
    }
    if (!allowed) {
        return "gpu.system_ram_offload_required_but_disabled";
    }
    if (!limit_configured) {
        return "gpu.system_ram_offload_required_but_limit_missing";
    }
    if (!limit_met) {
        return "gpu.system_ram_offload_required_but_exceeds_limit";
    }
    if (!system_ram_available) {
        return "gpu.system_ram_offload_required_but_system_ram_unknown";
    }
    if (!system_ram_floor_met) {
        return "gpu.system_ram_offload_required_but_system_ram_floor_not_met";
    }
    if (!supported) {
        return "gpu.system_ram_offload_required_but_not_supported";
    }
    return "gpu.system_ram_offload_required_but_not_admitted";
}
