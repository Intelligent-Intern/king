/*
 * =========================================================================
 * FILENAME:   src/config/tcp_transport/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the TCP transport config
 * family. Core enablement, connection and backlog limits, REUSEPORT /
 * NODELAY / CORK toggles, keepalive tuning, and the minimum TLS version
 * plus TLS 1.2 cipher policy all land in the single
 * `king_tcp_transport_config` snapshot.
 * =========================================================================
 */

#include "include/config/tcp_transport/base_layer.h"

kg_tcp_transport_config_t king_tcp_transport_config;
