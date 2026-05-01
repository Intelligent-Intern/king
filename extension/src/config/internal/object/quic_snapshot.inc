static void king_config_object_add_quic_snapshot(
    zval *target,
    const kg_quic_transport_config_t *quic)
{
    add_assoc_string(target, "quic.cc_algorithm", quic->cc_algorithm != NULL ? quic->cc_algorithm : "");
    add_assoc_long(target, "quic.cc_initial_cwnd_packets", quic->cc_initial_cwnd_packets);
    add_assoc_long(target, "quic.cc_min_cwnd_packets", quic->cc_min_cwnd_packets);
    add_assoc_bool(target, "quic.cc_enable_hystart_plus_plus", quic->cc_enable_hystart_plus_plus);
    add_assoc_bool(target, "quic.pacing_enable", quic->pacing_enable);
    add_assoc_long(target, "quic.pacing_max_burst_packets", quic->pacing_max_burst_packets);
    add_assoc_long(target, "quic.max_ack_delay_ms", quic->max_ack_delay_ms);
    add_assoc_long(target, "quic.ack_delay_exponent", quic->ack_delay_exponent);
    add_assoc_long(target, "quic.pto_timeout_ms_initial", quic->pto_timeout_ms_initial);
    add_assoc_long(target, "quic.pto_timeout_ms_max", quic->pto_timeout_ms_max);
    add_assoc_long(target, "quic.max_pto_probes", quic->max_pto_probes);
    add_assoc_long(target, "quic.ping_interval_ms", quic->ping_interval_ms);
    add_assoc_long(target, "quic.initial_max_data", quic->initial_max_data);
    add_assoc_long(target, "quic.initial_max_stream_data_bidi_local", quic->initial_max_stream_data_bidi_local);
    add_assoc_long(target, "quic.initial_max_stream_data_bidi_remote", quic->initial_max_stream_data_bidi_remote);
    add_assoc_long(target, "quic.initial_max_stream_data_uni", quic->initial_max_stream_data_uni);
    add_assoc_long(target, "quic.initial_max_streams_bidi", quic->initial_max_streams_bidi);
    add_assoc_long(target, "quic.initial_max_streams_uni", quic->initial_max_streams_uni);
    add_assoc_long(target, "quic.active_connection_id_limit", quic->active_connection_id_limit);
    add_assoc_bool(target, "quic.stateless_retry_enable", quic->stateless_retry_enable);
    add_assoc_bool(target, "quic.grease_enable", quic->grease_enable);
    add_assoc_bool(target, "quic.datagrams_enable", quic->datagrams_enable);
    add_assoc_long(target, "quic.dgram_recv_queue_len", quic->dgram_recv_queue_len);
    add_assoc_long(target, "quic.dgram_send_queue_len", quic->dgram_send_queue_len);
}

static zend_result king_config_object_read_quic_snapshot(
    const kg_quic_transport_config_t *quic,
    zend_string *canonical_key,
    zval *return_value)
{
    if (zend_string_equals_literal(canonical_key, "quic.cc_algorithm")) {
        ZVAL_STRING(return_value, quic->cc_algorithm != NULL ? quic->cc_algorithm : "");
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.cc_initial_cwnd_packets")) {
        ZVAL_LONG(return_value, quic->cc_initial_cwnd_packets);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.cc_min_cwnd_packets")) {
        ZVAL_LONG(return_value, quic->cc_min_cwnd_packets);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.cc_enable_hystart_plus_plus")) {
        ZVAL_BOOL(return_value, quic->cc_enable_hystart_plus_plus);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.pacing_enable")) {
        ZVAL_BOOL(return_value, quic->pacing_enable);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.pacing_max_burst_packets")) {
        ZVAL_LONG(return_value, quic->pacing_max_burst_packets);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.max_ack_delay_ms")) {
        ZVAL_LONG(return_value, quic->max_ack_delay_ms);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.ack_delay_exponent")) {
        ZVAL_LONG(return_value, quic->ack_delay_exponent);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.pto_timeout_ms_initial")) {
        ZVAL_LONG(return_value, quic->pto_timeout_ms_initial);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.pto_timeout_ms_max")) {
        ZVAL_LONG(return_value, quic->pto_timeout_ms_max);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.max_pto_probes")) {
        ZVAL_LONG(return_value, quic->max_pto_probes);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.ping_interval_ms")) {
        ZVAL_LONG(return_value, quic->ping_interval_ms);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.initial_max_data")) {
        ZVAL_LONG(return_value, quic->initial_max_data);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.initial_max_stream_data_bidi_local")) {
        ZVAL_LONG(return_value, quic->initial_max_stream_data_bidi_local);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.initial_max_stream_data_bidi_remote")) {
        ZVAL_LONG(return_value, quic->initial_max_stream_data_bidi_remote);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.initial_max_stream_data_uni")) {
        ZVAL_LONG(return_value, quic->initial_max_stream_data_uni);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.initial_max_streams_bidi")) {
        ZVAL_LONG(return_value, quic->initial_max_streams_bidi);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.initial_max_streams_uni")) {
        ZVAL_LONG(return_value, quic->initial_max_streams_uni);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.active_connection_id_limit")) {
        ZVAL_LONG(return_value, quic->active_connection_id_limit);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.stateless_retry_enable")) {
        ZVAL_BOOL(return_value, quic->stateless_retry_enable);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.grease_enable")) {
        ZVAL_BOOL(return_value, quic->grease_enable);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.datagrams_enable")) {
        ZVAL_BOOL(return_value, quic->datagrams_enable);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.dgram_recv_queue_len")) {
        ZVAL_LONG(return_value, quic->dgram_recv_queue_len);
        return SUCCESS;
    }
    if (zend_string_equals_literal(canonical_key, "quic.dgram_send_queue_len")) {
        ZVAL_LONG(return_value, quic->dgram_send_queue_len);
        return SUCCESS;
    }

    return FAILURE;
}
