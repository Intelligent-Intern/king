static uint64_t king_telemetry_saturated_add_u64(uint64_t left, uint64_t right)
{
    if (UINT64_MAX - left < right) {
        return UINT64_MAX;
    }

    return left + right;
}

static void king_telemetry_reset_libcurl_runtime_state(void)
{
    memset(&king_telemetry_libcurl, 0, sizeof(king_telemetry_libcurl));
}

static void king_telemetry_close_libcurl_runtime_handle(void)
{
    if (king_telemetry_libcurl.handle != NULL) {
        dlclose(king_telemetry_libcurl.handle);
        king_telemetry_libcurl.handle = NULL;
    }
}

static zend_result king_telemetry_load_libcurl_symbol(void **target, const char *name)
{
    *target = dlsym(king_telemetry_libcurl.handle, name);
    if (*target == NULL) {
        snprintf(
            king_telemetry_libcurl.load_error,
            sizeof(king_telemetry_libcurl.load_error),
            "Failed to load libcurl symbol '%s' for telemetry exports.",
            name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_telemetry_ensure_libcurl_ready(void)
{
    const char *const candidates[] = {"libcurl.so.4", "libcurl.so", NULL};
    size_t index;

    if (king_telemetry_libcurl.ready) {
        return SUCCESS;
    }

    if (king_telemetry_libcurl.load_attempted) {
        return FAILURE;
    }

    king_telemetry_libcurl.load_attempted = 1;

    for (index = 0; candidates[index] != NULL; index++) {
        king_telemetry_libcurl.handle = dlopen(candidates[index], RTLD_LAZY | RTLD_LOCAL);
        if (king_telemetry_libcurl.handle != NULL) {
            break;
        }
    }

    if (king_telemetry_libcurl.handle == NULL) {
        snprintf(
            king_telemetry_libcurl.load_error,
            sizeof(king_telemetry_libcurl.load_error),
            "Failed to load libcurl.so.4 or libcurl.so for telemetry exports."
        );
        return FAILURE;
    }

    if (king_telemetry_load_libcurl_symbol(
            (void **) &king_telemetry_libcurl.curl_global_init_fn,
            "curl_global_init"
        ) != SUCCESS
        || king_telemetry_load_libcurl_symbol(
            (void **) &king_telemetry_libcurl.curl_global_cleanup_fn,
            "curl_global_cleanup"
        ) != SUCCESS
        || king_telemetry_load_libcurl_symbol(
            (void **) &king_telemetry_libcurl.curl_easy_init_fn,
            "curl_easy_init"
        ) != SUCCESS
        || king_telemetry_load_libcurl_symbol(
            (void **) &king_telemetry_libcurl.curl_easy_cleanup_fn,
            "curl_easy_cleanup"
        ) != SUCCESS
        || king_telemetry_load_libcurl_symbol(
            (void **) &king_telemetry_libcurl.curl_easy_setopt_fn,
            "curl_easy_setopt"
        ) != SUCCESS
        || king_telemetry_load_libcurl_symbol(
            (void **) &king_telemetry_libcurl.curl_easy_perform_fn,
            "curl_easy_perform"
        ) != SUCCESS
        || king_telemetry_load_libcurl_symbol(
            (void **) &king_telemetry_libcurl.curl_easy_getinfo_fn,
            "curl_easy_getinfo"
        ) != SUCCESS
        || king_telemetry_load_libcurl_symbol(
            (void **) &king_telemetry_libcurl.curl_easy_strerror_fn,
            "curl_easy_strerror"
        ) != SUCCESS
        || king_telemetry_load_libcurl_symbol(
            (void **) &king_telemetry_libcurl.curl_slist_append_fn,
            "curl_slist_append"
        ) != SUCCESS
        || king_telemetry_load_libcurl_symbol(
            (void **) &king_telemetry_libcurl.curl_slist_free_all_fn,
            "curl_slist_free_all"
        ) != SUCCESS) {
        king_telemetry_close_libcurl_runtime_handle();
        return FAILURE;
    }

    /* Keep libcurl global state alive for the telemetry system lifetime. */
    if (king_telemetry_libcurl.curl_global_init_fn(CURL_GLOBAL_DEFAULT) != CURLE_OK) {
        snprintf(
            king_telemetry_libcurl.load_error,
            sizeof(king_telemetry_libcurl.load_error),
            "curl_global_init() failed for telemetry exports."
        );
        king_telemetry_close_libcurl_runtime_handle();
        return FAILURE;
    }

    king_telemetry_libcurl.ready = 1;
    return SUCCESS;
}

static void king_telemetry_shutdown_libcurl_runtime(void)
{
    /* libcurl teardown is process-scoped and must never happen per export call. */
    if (king_telemetry_libcurl.ready && king_telemetry_libcurl.curl_global_cleanup_fn != NULL) {
        king_telemetry_libcurl.curl_global_cleanup_fn();
    }

    king_telemetry_close_libcurl_runtime_handle();
    king_telemetry_reset_libcurl_runtime_state();
}

static uint32_t king_telemetry_default_max_queue_size(void)
{
    if (king_open_telemetry_config.batch_processor_max_queue_size > 0
        && king_open_telemetry_config.batch_processor_max_queue_size <= UINT32_MAX) {
        return (uint32_t) king_open_telemetry_config.batch_processor_max_queue_size;
    }

    return 2048;
}

static uint32_t king_telemetry_default_export_chunk_size(void)
{
    if (king_open_telemetry_config.logs_exporter_batch_size > 0
        && king_open_telemetry_config.logs_exporter_batch_size <= UINT32_MAX) {
        return (uint32_t) king_open_telemetry_config.logs_exporter_batch_size;
    }

    return 512;
}

static uint32_t king_telemetry_runtime_max_queue_size(void)
{
    if (king_telemetry_runtime_config.max_queue_size > 0) {
        return king_telemetry_runtime_config.max_queue_size;
    }

    return king_telemetry_default_max_queue_size();
}

static uint32_t king_telemetry_runtime_export_chunk_size(void)
{
    if (king_telemetry_runtime_config.max_export_batch_size > 0) {
        return king_telemetry_runtime_config.max_export_batch_size;
    }

    return king_telemetry_default_export_chunk_size();
}

uint32_t king_telemetry_get_export_chunk_size(void)
{
    return king_telemetry_runtime_export_chunk_size();
}

static uint64_t king_telemetry_runtime_memory_byte_limit_internal(void)
{
    uint64_t queue_slots = (uint64_t) king_telemetry_runtime_max_queue_size();

    if (queue_slots == 0) {
        queue_slots = (uint64_t) king_telemetry_default_max_queue_size();
    }

    if (queue_slots > UINT64_MAX / KING_TELEMETRY_BYTES_PER_QUEUE_SLOT) {
        return UINT64_MAX;
    }

    return queue_slots * KING_TELEMETRY_BYTES_PER_QUEUE_SLOT;
}
