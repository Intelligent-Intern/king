/* lsquic loader - runtime dynamic loading via dlopen */

static void king_http3_reset_lsquic_runtime_state(void)
{
    memset(&king_http3_lsquic, 0, sizeof(king_http3_lsquic));
}

static void king_http3_close_lsquic_runtime_handle(void)
{
    if (king_http3_lsquic.handle) {
        dlclose(king_http3_lsquic.handle);
        king_http3_lsquic.handle = NULL;
    }
}

static zend_result king_http3_load_lsquic_symbol(void **target, const char *name)
{
    *target = dlsym(king_http3_lsquic.handle, name);
    if (!*target) {
        snprintf(king_http3_lsquic.load_error,
                 sizeof(king_http3_lsquic.load_error),
                 "Failed to load lsquic symbol '%s' for HTTP/3 client.", name);
        return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_http3_ensure_lsquic_ready(void)
{
    const char *candidates[] = {"liblsquic.so.4", "liblsquic.so", NULL};
    size_t i;

    if (king_http3_lsquic.ready) {
        return SUCCESS;
    }
    if (king_http3_lsquic.load_attempted) {
        return FAILURE;
    }
    king_http3_lsquic.load_attempted = 1;

    for (i = 0; candidates[i] != NULL; ++i) {
        king_http3_lsquic.handle = dlopen(candidates[i], RTLD_LAZY | RTLD_LOCAL);
        if (king_http3_lsquic.handle) {
            break;
        }
    }
    if (!king_http3_lsquic.handle) {
        snprintf(king_http3_lsquic.load_error,
                 sizeof(king_http3_lsquic.load_error),
                 "Failed to load liblsquic.so.4 or liblsquic.so for HTTP/3 client.");
        return FAILURE;
    }

    if (king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_new_fn, "lsquic_config_new") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_load_cert_chain_from_pem_file_fn, "lsquic_config_load_cert_chain_from_pem_file") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_load_priv_key_from_pem_file_fn, "lsquic_config_load_priv_key_from_pem_file") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_load_verify_locations_from_file_fn, "lsquic_config_load_verify_locations_from_file") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_verify_peer_fn, "lsquic_config_verify_peer") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_grease_fn, "lsquic_config_grease") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_enable_early_data_fn, "lsquic_config_enable_early_data") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_enable_hystart_fn, "lsquic_config_enable_hystart") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_enable_pacing_fn, "lsquic_config_enable_pacing") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_application_protos_fn, "lsquic_config_set_application_protos") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_max_idle_timeout_fn, "lsquic_config_set_max_idle_timeout") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_max_recv_udp_payload_size_fn, "lsquic_config_set_max_recv_udp_payload_size") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_max_send_udp_payload_size_fn, "lsquic_config_set_max_send_udp_payload_size") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_initial_max_data_fn, "lsquic_config_set_initial_max_data") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_initial_max_stream_data_bidi_local_fn, "lsquic_config_set_initial_max_stream_data_bidi_local") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_initial_max_stream_data_bidi_remote_fn, "lsquic_config_set_initial_max_stream_data_bidi_remote") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_initial_max_stream_data_uni_fn, "lsquic_config_set_initial_max_stream_data_uni") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_initial_max_streams_bidi_fn, "lsquic_config_set_initial_max_streams_bidi") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_initial_max_streams_uni_fn, "lsquic_config_set_initial_max_streams_uni") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_ack_delay_exponent_fn, "lsquic_config_set_ack_delay_exponent") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_max_ack_delay_fn, "lsquic_config_set_max_ack_delay") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_disable_active_migration_fn, "lsquic_config_set_disable_active_migration") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_cc_algorithm_name_fn, "lsquic_config_set_cc_algorithm_name") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_initial_congestion_window_packets_fn, "lsquic_config_set_initial_congestion_window_packets") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_set_active_connection_id_limit_fn, "lsquic_config_set_active_connection_id_limit") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_enable_dgram_fn, "lsquic_config_enable_dgram") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_config_free_fn, "lsquic_config_free") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_header_info_fn, "lsquic_header_info") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_version_is_supported_fn, "lsquic_version_is_supported") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_negotiate_version_fn, "lsquic_negotiate_version") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_connect_fn, "lsquic_connect") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_recv_fn, "lsquic_conn_recv") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_send_fn, "lsquic_conn_send") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_timeout_as_millis_fn, "lsquic_conn_timeout_as_millis") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_on_timeout_fn, "lsquic_conn_on_timeout") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_is_established_fn, "lsquic_conn_is_established") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_is_resumed_fn, "lsquic_conn_is_resumed") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_is_in_early_data_fn, "lsquic_conn_is_in_early_data") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_stats_fn, "lsquic_conn_stats") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_is_closed_fn, "lsquic_conn_is_closed") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_is_timed_out_fn, "lsquic_conn_is_timed_out") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_close_fn, "lsquic_conn_close") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_set_session_fn, "lsquic_conn_set_session") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_session_fn, "lsquic_conn_session") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_peer_error_fn, "lsquic_conn_peer_error") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_local_error_fn, "lsquic_conn_local_error") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_conn_free_fn, "lsquic_conn_free") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_config_new_fn, "lsquic_h3_config_new") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_config_free_fn, "lsquic_h3_config_free") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_conn_new_with_transport_fn, "lsquic_h3_conn_new_with_transport") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_conn_poll_fn, "lsquic_h3_conn_poll") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_event_type_fn, "lsquic_h3_event_type") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_event_reset_error_fn, "lsquic_h3_event_reset_error") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_event_for_each_header_fn, "lsquic_h3_event_for_each_header") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_event_headers_has_more_frames_fn, "lsquic_h3_event_headers_has_more_frames") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_event_free_fn, "lsquic_h3_event_free") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_send_response_fn, "lsquic_h3_send_response") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_send_body_fn, "lsquic_h3_send_body") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_recv_body_fn, "lsquic_h3_recv_body") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_send_goaway_fn, "lsquic_h3_send_goaway") != SUCCESS ||
        king_http3_load_lsquic_symbol((void **)&king_http3_lsquic.lsquic_h3_conn_free_fn, "lsquic_h3_conn_free") != SUCCESS) {
        king_http3_close_lsquic_runtime_handle();
        return FAILURE;
    }

    king_http3_lsquic.ready = 1;
    return SUCCESS;
}