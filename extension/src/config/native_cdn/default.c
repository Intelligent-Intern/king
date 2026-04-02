/*
 * =========================================================================
 * FILENAME:   src/config/native_cdn/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the native CDN config family. This slice seeds
 * the baseline cache mode, memory/disk limits, origin timeout, stale-on-
 * error, response-header, and allowed-method defaults before INI and any
 * allowed userland overrides refine the live CDN snapshot.
 * =========================================================================
 */

#include "include/config/native_cdn/default.h"
#include "include/config/native_cdn/base_layer.h"

void kg_config_native_cdn_defaults_load(void)
{
    king_native_cdn_config.enable = false;
    king_native_cdn_config.cache_mode = NULL;
    king_native_cdn_config.cache_memory_limit_mb = 512;
    king_native_cdn_config.cache_disk_path = NULL;
    king_native_cdn_config.cache_default_ttl_sec = 86400;
    king_native_cdn_config.cache_max_object_size_mb = 1024;
    king_native_cdn_config.cache_respect_origin_headers = true;
    king_native_cdn_config.cache_vary_on_headers = NULL;
    king_native_cdn_config.origin_mcp_endpoint = NULL;
    king_native_cdn_config.origin_http_endpoint = NULL;
    king_native_cdn_config.origin_request_timeout_ms = 15000;
    king_native_cdn_config.serve_stale_on_error = true;
    king_native_cdn_config.response_headers_to_add = NULL;
    king_native_cdn_config.allowed_http_methods = NULL;
}
