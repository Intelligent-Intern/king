/*
 * =========================================================================
 * FILENAME:   src/config/app_http3_websockets_webtransport/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the app-protocol config
 * family. The active HTTP/3, Early Hints, WebSocket, and WebTransport
 * defaults and INI/userland overrides all land in this single
 * `king_app_protocols_config` snapshot.
 * =========================================================================
 */

#include "include/config/app_http3_websockets_webtransport/base_layer.h"

kg_app_protocols_config_t king_app_protocols_config = {0};
