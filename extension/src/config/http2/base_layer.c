/*
 * =========================================================================
 * FILENAME:   src/config/http2/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the HTTP/2 config family.
 * Enablement, initial window size, concurrent-stream limits, header-list
 * limits, server-push policy, and max-frame-size settings all land in the
 * single `king_http2_config` snapshot.
 * =========================================================================
 */

#include "include/config/http2/base_layer.h"

kg_http2_config_t king_http2_config;
