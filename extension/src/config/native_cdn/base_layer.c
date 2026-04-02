/*
 * =========================================================================
 * FILENAME:   src/config/native_cdn/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the native CDN config family.
 * Enablement, cache mode and limits, disk path, vary/response headers,
 * origin endpoints/timeouts, stale-on-error policy, and allowed method
 * settings all land in the single `king_native_cdn_config` snapshot.
 * =========================================================================
 */

#include "include/config/native_cdn/base_layer.h"

kg_native_cdn_config_t king_native_cdn_config;
