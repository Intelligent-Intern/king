/*
 * =========================================================================
 * FILENAME:   src/config/quic_transport/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the QUIC transport config family. This slice
 * seeds the baseline congestion-control algorithm, pacing burst, ACK/PTO
 * timers, flow-control windows, stream counts, retry/GREASE behavior, and
 * datagram queue lengths before INI and any allowed userland overrides
 * refine the live transport snapshot.
 * =========================================================================
 */

#include "include/config/quic_transport/default.h"
#include "include/config/quic_transport/base_layer.h"

void kg_config_quic_transport_defaults_load(void)
{
    king_quic_transport_config.cc_algorithm = pestrdup("cubic", 1);
    king_quic_transport_config.cc_initial_cwnd_packets = 32;
    king_quic_transport_config.cc_min_cwnd_packets = 4;
    king_quic_transport_config.cc_enable_hystart_plus_plus = true;
    king_quic_transport_config.pacing_enable = true;
    king_quic_transport_config.pacing_max_burst_packets = 10;

    king_quic_transport_config.max_ack_delay_ms = 25;
    king_quic_transport_config.ack_delay_exponent = 3;
    king_quic_transport_config.pto_timeout_ms_initial = 1000;
    king_quic_transport_config.pto_timeout_ms_max = 60000;
    king_quic_transport_config.max_pto_probes = 5;
    king_quic_transport_config.ping_interval_ms = 15000;

    king_quic_transport_config.initial_max_data = 10485760;
    king_quic_transport_config.initial_max_stream_data_bidi_local = 1048576;
    king_quic_transport_config.initial_max_stream_data_bidi_remote = 1048576;
    king_quic_transport_config.initial_max_stream_data_uni = 1048576;
    king_quic_transport_config.initial_max_streams_bidi = 100;
    king_quic_transport_config.initial_max_streams_uni = 100;

    king_quic_transport_config.active_connection_id_limit = 8;
    king_quic_transport_config.stateless_retry_enable = false;
    king_quic_transport_config.grease_enable = true;
    king_quic_transport_config.datagrams_enable = true;
    king_quic_transport_config.dgram_recv_queue_len = 1024;
    king_quic_transport_config.dgram_send_queue_len = 1024;
}
