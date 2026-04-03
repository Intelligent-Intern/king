static const char *king_server_http3_map_candidate_error(const char *path)
{
    return path != NULL && path[0] != '\0' ? path : "unknown";
}

static zend_result king_server_http3_load_symbol(void **target, const char *name)
{
    *target = dlsym(king_server_http3_quiche.handle, name);
    if (*target == NULL) {
        snprintf(
            king_server_http3_quiche.load_error,
            sizeof(king_server_http3_quiche.load_error),
            "Failed to load libquiche symbol '%s'.",
            name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_server_http3_close_runtime_handle(void)
{
    if (king_server_http3_quiche.handle != NULL) {
        dlclose(king_server_http3_quiche.handle);
        king_server_http3_quiche.handle = NULL;
    }
}

static zend_result king_server_http3_build_module_relative_candidate(
    char *buffer,
    size_t buffer_size,
    const char *relative_path)
{
    Dl_info info;
    const char *last_sep;
    size_t dir_len;
    size_t relative_len;

    if (dladdr((void *) king_server_http3_build_module_relative_candidate, &info) == 0
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

static zend_result king_server_http3_ensure_quiche_ready(void)
{
    const char *env_path = getenv("KING_QUICHE_LIBRARY");
    char module_runtime_candidate[PATH_MAX];
    char module_local_candidate[PATH_MAX];
    const char *candidates[] = {
        "../quiche/target/release/libquiche.so",
        "../quiche/target/debug/libquiche.so",
        "../../quiche/target/release/libquiche.so",
        "../../quiche/target/debug/libquiche.so",
        "libquiche.so",
        NULL
    };
    size_t i;

    if (king_server_http3_quiche.ready) {
        return SUCCESS;
    }

    if (king_server_http3_quiche.load_attempted) {
        return FAILURE;
    }

    king_server_http3_quiche.load_attempted = true;

    if (env_path != NULL && env_path[0] != '\0') {
        king_server_http3_quiche.handle = dlopen(env_path, RTLD_LAZY | RTLD_LOCAL);
    }

    /* Optional module-relative candidates must not suppress the fixed fallbacks. */
    if (king_server_http3_quiche.handle == NULL
        && king_server_http3_build_module_relative_candidate(
            module_runtime_candidate,
            sizeof(module_runtime_candidate),
            "king/runtime/libquiche.so"
        ) == SUCCESS) {
        king_server_http3_quiche.handle = dlopen(module_runtime_candidate, RTLD_LAZY | RTLD_LOCAL);
    }

    if (king_server_http3_quiche.handle == NULL
        && king_server_http3_build_module_relative_candidate(
            module_local_candidate,
            sizeof(module_local_candidate),
            "libquiche.so"
        ) == SUCCESS) {
        king_server_http3_quiche.handle = dlopen(module_local_candidate, RTLD_LAZY | RTLD_LOCAL);
    }

    for (i = 0; king_server_http3_quiche.handle == NULL && candidates[i] != NULL; ++i) {
        king_server_http3_quiche.handle = dlopen(candidates[i], RTLD_LAZY | RTLD_LOCAL);
    }

    if (king_server_http3_quiche.handle == NULL) {
        snprintf(
            king_server_http3_quiche.load_error,
            sizeof(king_server_http3_quiche.load_error),
            "Failed to load libquiche for the active HTTP/3 server runtime (checked '%s' first).",
            king_server_http3_map_candidate_error(env_path)
        );
        return FAILURE;
    }

    if (king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_new_fn, "quiche_config_new") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_load_cert_chain_from_pem_file_fn, "quiche_config_load_cert_chain_from_pem_file") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_load_priv_key_from_pem_file_fn, "quiche_config_load_priv_key_from_pem_file") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_grease_fn, "quiche_config_grease") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_enable_hystart_fn, "quiche_config_enable_hystart") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_enable_pacing_fn, "quiche_config_enable_pacing") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_application_protos_fn, "quiche_config_set_application_protos") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_max_idle_timeout_fn, "quiche_config_set_max_idle_timeout") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_max_recv_udp_payload_size_fn, "quiche_config_set_max_recv_udp_payload_size") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_max_send_udp_payload_size_fn, "quiche_config_set_max_send_udp_payload_size") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_data_fn, "quiche_config_set_initial_max_data") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_local_fn, "quiche_config_set_initial_max_stream_data_bidi_local") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_stream_data_bidi_remote_fn, "quiche_config_set_initial_max_stream_data_bidi_remote") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_stream_data_uni_fn, "quiche_config_set_initial_max_stream_data_uni") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_streams_bidi_fn, "quiche_config_set_initial_max_streams_bidi") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_max_streams_uni_fn, "quiche_config_set_initial_max_streams_uni") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_ack_delay_exponent_fn, "quiche_config_set_ack_delay_exponent") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_max_ack_delay_fn, "quiche_config_set_max_ack_delay") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_disable_active_migration_fn, "quiche_config_set_disable_active_migration") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_cc_algorithm_name_fn, "quiche_config_set_cc_algorithm_name") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_initial_congestion_window_packets_fn, "quiche_config_set_initial_congestion_window_packets") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_set_active_connection_id_limit_fn, "quiche_config_set_active_connection_id_limit") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_enable_dgram_fn, "quiche_config_enable_dgram") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_config_free_fn, "quiche_config_free") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_header_info_fn, "quiche_header_info") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_version_is_supported_fn, "quiche_version_is_supported") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_negotiate_version_fn, "quiche_negotiate_version") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_accept_fn, "quiche_accept") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_recv_fn, "quiche_conn_recv") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_send_fn, "quiche_conn_send") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_timeout_as_millis_fn, "quiche_conn_timeout_as_millis") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_on_timeout_fn, "quiche_conn_on_timeout") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_is_established_fn, "quiche_conn_is_established") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_is_closed_fn, "quiche_conn_is_closed") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_close_fn, "quiche_conn_close") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_conn_free_fn, "quiche_conn_free") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_config_new_fn, "quiche_h3_config_new") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_config_free_fn, "quiche_h3_config_free") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_conn_new_with_transport_fn, "quiche_h3_conn_new_with_transport") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_conn_poll_fn, "quiche_h3_conn_poll") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_event_type_fn, "quiche_h3_event_type") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_event_for_each_header_fn, "quiche_h3_event_for_each_header") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_event_headers_has_more_frames_fn, "quiche_h3_event_headers_has_more_frames") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_event_free_fn, "quiche_h3_event_free") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_send_response_fn, "quiche_h3_send_response") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_send_body_fn, "quiche_h3_send_body") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_recv_body_fn, "quiche_h3_recv_body") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_send_goaway_fn, "quiche_h3_send_goaway") != SUCCESS
        || king_server_http3_load_symbol((void **) &king_server_http3_quiche.quiche_h3_conn_free_fn, "quiche_h3_conn_free") != SUCCESS) {
        king_server_http3_close_runtime_handle();
        return FAILURE;
    }

    king_server_http3_quiche.ready = true;
    return SUCCESS;
}
