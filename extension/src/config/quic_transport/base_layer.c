/*
 * =========================================================================
 * FILENAME:   src/config/quic_transport/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the QUIC transport config
 * family. Congestion control, pacing, ACK/PTO timing, flow-control windows,
 * stream limits, connection ID policy, retry/GREASE toggles, and datagram
 * queue sizing all land in the single `king_quic_transport_config`
 * snapshot.
 * =========================================================================
 */

#include "include/config/quic_transport/base_layer.h"

kg_quic_transport_config_t king_quic_transport_config;
