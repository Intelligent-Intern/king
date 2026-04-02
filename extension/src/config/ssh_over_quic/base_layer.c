/*
 * =========================================================================
 * FILENAME:   src/config/ssh_over_quic/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the SSH-over-QUIC config
 * family. Gateway enablement, bind/target endpoints, connect and idle
 * timers, auth and target-mapping modes, MCP/user-profile agent URIs, and
 * session-activity logging all land in the single
 * `king_ssh_over_quic_config` snapshot.
 * =========================================================================
 */

#include "include/config/ssh_over_quic/base_layer.h"

kg_ssh_over_quic_config_t king_ssh_over_quic_config;
