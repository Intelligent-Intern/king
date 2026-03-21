static zend_bool king_config_is_known_quic_flat_key(zend_string *key)
{
    static const char *keys[] = {
        "cc_algorithm",
        "cc_initial_cwnd_packets",
        "cc_min_cwnd_packets",
        "cc_enable_hystart_plus_plus",
        "pacing_enable",
        "pacing_max_burst_packets",
        "max_ack_delay_ms",
        "ack_delay_exponent",
        "pto_timeout_ms_initial",
        "pto_timeout_ms_max",
        "max_pto_probes",
        "ping_interval_ms",
        "initial_max_data",
        "initial_max_stream_data_bidi_local",
        "initial_max_stream_data_bidi_remote",
        "initial_max_stream_data_uni",
        "initial_max_streams_bidi",
        "initial_max_streams_uni",
        "active_connection_id_limit",
        "stateless_retry_enable",
        "grease_enable",
        "datagrams_enable",
        "dgram_recv_queue_len",
        "dgram_send_queue_len",
        NULL
    };
    int i;

    for (i = 0; keys[i] != NULL; i++) {
        if (zend_string_equals_cstr(key, keys[i], strlen(keys[i]))) {
            return 1;
        }
    }

    return 0;
}

static zend_string *king_config_normalize_suffix_key(
    zend_string *key,
    size_t offset,
    const char *prefix)
{
    size_t prefix_len = strlen(prefix);
    size_t suffix_len = ZSTR_LEN(key) - offset;
    zend_string *normalized = zend_string_alloc(prefix_len + suffix_len, 0);
    size_t i;

    memcpy(ZSTR_VAL(normalized), prefix, prefix_len);

    for (i = 0; i < suffix_len; i++) {
        char ch = ZSTR_VAL(key)[offset + i];
        ZSTR_VAL(normalized)[prefix_len + i] = (ch == '.') ? '_' : ch;
    }

    ZSTR_VAL(normalized)[prefix_len + suffix_len] = '\0';
    return normalized;
}

static king_config_override_module_t king_config_route_override_key(
    zend_string *key,
    zend_string **normalized_key)
{
    if (zend_string_starts_with_literal(key, "tls.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("tls.") - 1, "tls_");
        return KING_CONFIG_OVERRIDE_TLS;
    }

    if (zend_string_starts_with_literal(key, "quic.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("quic.") - 1, "");
        return KING_CONFIG_OVERRIDE_QUIC;
    }

    if (zend_string_starts_with_literal(key, "http2.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("http2.") - 1, "");
        return KING_CONFIG_OVERRIDE_HTTP2;
    }

    if (zend_string_starts_with_literal(key, "tcp.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("tcp.") - 1, "tcp_");
        return KING_CONFIG_OVERRIDE_TCP;
    }

    if (zend_string_starts_with_literal(key, "autoscale.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("autoscale.") - 1, "");
        return KING_CONFIG_OVERRIDE_AUTOSCALE;
    }

    if (zend_string_starts_with_literal(key, "mcp.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("mcp.") - 1, "mcp_");
        return KING_CONFIG_OVERRIDE_MCP_ORCHESTRATOR;
    }

    if (zend_string_starts_with_literal(key, "orchestrator.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("orchestrator.") - 1, "orchestrator_");
        return KING_CONFIG_OVERRIDE_MCP_ORCHESTRATOR;
    }

    if (zend_string_starts_with_literal(key, "geometry.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("geometry.") - 1, "");
        return KING_CONFIG_OVERRIDE_GEOMETRY;
    }

    if (zend_string_starts_with_literal(key, "smartcontract.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("smartcontract.") - 1, "");
        return KING_CONFIG_OVERRIDE_SMART_CONTRACT;
    }

    if (zend_string_starts_with_literal(key, "ssh.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("ssh.") - 1, "ssh_");
        return KING_CONFIG_OVERRIDE_SSH;
    }

    if (zend_string_starts_with_literal(key, "tls_")) {
        *normalized_key = zend_string_copy(key);
        return KING_CONFIG_OVERRIDE_TLS;
    }

    if (zend_string_starts_with_literal(key, "tcp_")) {
        *normalized_key = zend_string_copy(key);
        return KING_CONFIG_OVERRIDE_TCP;
    }

    if (zend_string_starts_with_literal(key, "storage.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("storage.") - 1, "");
        return KING_CONFIG_OVERRIDE_STORAGE;
    }

    if (zend_string_starts_with_literal(key, "cdn.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("cdn.") - 1, "");
        return KING_CONFIG_OVERRIDE_CDN;
    }

    if (zend_string_starts_with_literal(key, "dns.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("dns.") - 1, "dns_");
        return KING_CONFIG_OVERRIDE_DNS;
    }

    if (zend_string_starts_with_literal(key, "otel.")) {
        *normalized_key = king_config_normalize_suffix_key(key, sizeof("otel.") - 1, "");
        return KING_CONFIG_OVERRIDE_OTEL;
    }

    if (zend_string_starts_with_literal(key, "storage_")) {
        *normalized_key = zend_string_copy(key);
        return KING_CONFIG_OVERRIDE_STORAGE;
    }

    if (zend_string_starts_with_literal(key, "cdn_")) {
        *normalized_key = zend_string_copy(key);
        return KING_CONFIG_OVERRIDE_CDN;
    }

    if (zend_string_starts_with_literal(key, "dns_")) {
        *normalized_key = zend_string_copy(key);
        return KING_CONFIG_OVERRIDE_DNS;
    }

    if (zend_string_starts_with_literal(key, "otel_")) {
        *normalized_key = zend_string_copy(key);
        return KING_CONFIG_OVERRIDE_OTEL;
    }

    if (king_config_is_known_quic_flat_key(key)) {
        *normalized_key = zend_string_copy(key);
        return KING_CONFIG_OVERRIDE_QUIC;
    }

    return KING_CONFIG_OVERRIDE_NONE;
}
