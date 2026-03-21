/*
 * =========================================================================
 * FILENAME:   include/config/quic_transport/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for QUIC transport.
 *
 * ARCHITECTURE:
 * This struct stores congestion-control, recovery, flow-control, and
 * datagram settings for QUIC.
 * =========================================================================
 */
#ifndef KING_CONFIG_QUIC_TRANSPORT_BASE_H
#define KING_CONFIG_QUIC_TRANSPORT_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_quic_transport_config_t {
    /* --- Congestion Control & Pacing --- */
    char *cc_algorithm;
    zend_long cc_initial_cwnd_packets;
    zend_long cc_min_cwnd_packets;
    bool cc_enable_hystart_plus_plus;
    bool pacing_enable;
    zend_long pacing_max_burst_packets;

    /* --- Loss Recovery, ACK Management & Timers --- */
    zend_long max_ack_delay_ms;
    zend_long ack_delay_exponent;
    zend_long pto_timeout_ms_initial;
    zend_long pto_timeout_ms_max;
    zend_long max_pto_probes;
    zend_long ping_interval_ms;

    /* --- Flow Control & Stream Limits --- */
    zend_long initial_max_data;
    zend_long initial_max_stream_data_bidi_local;
    zend_long initial_max_stream_data_bidi_remote;
    zend_long initial_max_stream_data_uni;
    zend_long initial_max_streams_bidi;
    zend_long initial_max_streams_uni;

    /* --- Protocol Features & Datagrams --- */
    zend_long active_connection_id_limit;
    bool stateless_retry_enable;
    bool grease_enable;
    bool datagrams_enable;
    zend_long dgram_recv_queue_len;
    zend_long dgram_send_queue_len;

} kg_quic_transport_config_t;

/* Module-global configuration instance. */
extern kg_quic_transport_config_t king_quic_transport_config;

#endif /* KING_CONFIG_QUIC_TRANSPORT_BASE_H */
