#ifndef KING_LSQUIC_GLOBAL_SERVER
#define KING_LSQUIC_GLOBAL_SERVER 2
#endif

static const char *king_server_http3_lsquic_map_candidate_error(const char *path)
{
    return path != NULL && path[0] != '\0' ? path : "unknown";
}

static zend_result king_server_http3_lsquic_load_symbol(void **target, const char *name)
{
    *target = dlsym(king_server_http3_lsquic.handle, name);
    if (*target == NULL) {
        king_server_http3_lsquic.load_error_kind = KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_SYMBOL;
        snprintf(
            king_server_http3_lsquic.load_error,
            sizeof(king_server_http3_lsquic.load_error),
            "Failed to load liblsquic server symbol '%s'.",
            name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_server_http3_lsquic_close_runtime_handle(void)
{
    if (king_server_http3_lsquic.global_initialized
        && king_server_http3_lsquic.lsquic_global_cleanup_fn != NULL) {
        king_server_http3_lsquic.lsquic_global_cleanup_fn();
        king_server_http3_lsquic.global_initialized = false;
    }

    if (king_server_http3_lsquic.handle != NULL) {
        dlclose(king_server_http3_lsquic.handle);
        king_server_http3_lsquic.handle = NULL;
    }

    king_server_http3_lsquic.ready = false;
}

static zend_result king_server_http3_lsquic_build_module_relative_candidate(
    char *buffer,
    size_t buffer_size,
    const char *relative_path)
{
    Dl_info info;
    const char *last_sep;
    size_t dir_len;
    size_t relative_len;

    if (dladdr((void *) king_server_http3_lsquic_build_module_relative_candidate, &info) == 0
        || info.dli_fname == NULL
        || info.dli_fname[0] == '\0') {
        return FAILURE;
    }

    last_sep = strrchr(info.dli_fname, '/');
    if (last_sep == NULL) {
        return FAILURE;
    }

    dir_len = (size_t) (last_sep - info.dli_fname);
    relative_len = strlen(relative_path);

    if (dir_len + 1 + relative_len + 1 > buffer_size) {
        return FAILURE;
    }

    memcpy(buffer, info.dli_fname, dir_len);
    buffer[dir_len] = '/';
    memcpy(buffer + dir_len + 1, relative_path, relative_len + 1);

    return SUCCESS;
}

static zend_result king_server_http3_ensure_lsquic_ready(void)
{
    const char *env_path = getenv("KING_LSQUIC_LIBRARY");
    char module_runtime_candidate[PATH_MAX];
    char module_local_candidate[PATH_MAX];
    const char *candidates[] = {
#if defined(__APPLE__)
        "liblsquic.dylib",
#endif
        "liblsquic.so.4",
        "liblsquic.so",
#if !defined(__APPLE__)
        "liblsquic.dylib",
#endif
        NULL
    };
    size_t i;
    int init_result;

    if (king_server_http3_lsquic.ready) {
        return SUCCESS;
    }

    if (king_server_http3_lsquic.load_attempted) {
        return FAILURE;
    }

    king_server_http3_lsquic.load_attempted = true;

    if (env_path != NULL && env_path[0] != '\0') {
        king_server_http3_lsquic.handle = dlopen(env_path, RTLD_LAZY | RTLD_LOCAL);
    }

    if (king_server_http3_lsquic.handle == NULL
        && king_server_http3_lsquic_build_module_relative_candidate(
            module_runtime_candidate,
            sizeof(module_runtime_candidate),
#if defined(__APPLE__)
            "king/runtime/liblsquic.dylib"
#else
            "king/runtime/liblsquic.so"
#endif
        ) == SUCCESS) {
        king_server_http3_lsquic.handle = dlopen(module_runtime_candidate, RTLD_LAZY | RTLD_LOCAL);
    }

    if (king_server_http3_lsquic.handle == NULL
        && king_server_http3_lsquic_build_module_relative_candidate(
            module_local_candidate,
            sizeof(module_local_candidate),
#if defined(__APPLE__)
            "liblsquic.dylib"
#else
            "liblsquic.so"
#endif
        ) == SUCCESS) {
        king_server_http3_lsquic.handle = dlopen(module_local_candidate, RTLD_LAZY | RTLD_LOCAL);
    }

    for (i = 0; king_server_http3_lsquic.handle == NULL && candidates[i] != NULL; ++i) {
        king_server_http3_lsquic.handle = dlopen(candidates[i], RTLD_LAZY | RTLD_LOCAL);
    }

    if (king_server_http3_lsquic.handle == NULL) {
        king_server_http3_lsquic.load_error_kind = KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_LIBRARY;
        snprintf(
            king_server_http3_lsquic.load_error,
            sizeof(king_server_http3_lsquic.load_error),
            "Failed to load liblsquic for the active HTTP/3 server runtime (checked '%s' first).",
            king_server_http3_lsquic_map_candidate_error(env_path)
        );
        return FAILURE;
    }

    if (king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_global_init_fn, "lsquic_global_init") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_global_cleanup_fn, "lsquic_global_cleanup") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_init_settings_fn, "lsquic_engine_init_settings") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_check_settings_fn, "lsquic_engine_check_settings") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_new_fn, "lsquic_engine_new") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_destroy_fn, "lsquic_engine_destroy") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_packet_in_fn, "lsquic_engine_packet_in") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_process_conns_fn, "lsquic_engine_process_conns") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_has_unsent_packets_fn, "lsquic_engine_has_unsent_packets") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_send_unsent_packets_fn, "lsquic_engine_send_unsent_packets") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_earliest_adv_tick_fn, "lsquic_engine_earliest_adv_tick") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_get_conns_count_fn, "lsquic_engine_get_conns_count") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_engine_count_attq_fn, "lsquic_engine_count_attq") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_conn_status_fn, "lsquic_conn_status") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_conn_close_fn, "lsquic_conn_close") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_conn_get_ctx_fn, "lsquic_conn_get_ctx") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_conn_set_ctx_fn, "lsquic_conn_set_ctx") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_send_headers_fn, "lsquic_stream_send_headers") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_get_hset_fn, "lsquic_stream_get_hset") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_write_fn, "lsquic_stream_write") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_read_fn, "lsquic_stream_read") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_flush_fn, "lsquic_stream_flush") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_shutdown_fn, "lsquic_stream_shutdown") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_close_fn, "lsquic_stream_close") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_wantread_fn, "lsquic_stream_wantread") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_wantwrite_fn, "lsquic_stream_wantwrite") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_get_ctx_fn, "lsquic_stream_get_ctx") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_set_ctx_fn, "lsquic_stream_set_ctx") != SUCCESS
        || king_server_http3_lsquic_load_symbol((void **) &king_server_http3_lsquic.lsquic_stream_id_fn, "lsquic_stream_id") != SUCCESS) {
        king_server_http3_lsquic_close_runtime_handle();
        return FAILURE;
    }

    init_result = king_server_http3_lsquic.lsquic_global_init_fn(KING_LSQUIC_GLOBAL_SERVER);
    if (init_result != 0) {
        king_server_http3_lsquic.load_error_kind = KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_GLOBAL_INIT;
        snprintf(
            king_server_http3_lsquic.load_error,
            sizeof(king_server_http3_lsquic.load_error),
            "Failed to initialize liblsquic server globals: lsquic_global_init returned %d.",
            init_result
        );
        king_server_http3_lsquic_close_runtime_handle();
        return FAILURE;
    }

    king_server_http3_lsquic.global_initialized = true;
    king_server_http3_lsquic.ready = true;
    king_server_http3_lsquic.load_error_kind = KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_NONE;
    king_server_http3_lsquic.load_error[0] = '\0';
    return SUCCESS;
}
