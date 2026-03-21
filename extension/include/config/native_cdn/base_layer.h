/*
 * =========================================================================
 * FILENAME:   include/config/native_cdn/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for the native CDN module.
 *
 * ARCHITECTURE:
 * This struct stores cache, origin, and client-facing CDN settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_NATIVE_CDN_BASE_H
#define KING_CONFIG_NATIVE_CDN_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_native_cdn_config_t {
    /* --- General --- */
    bool enable;

    /* --- Caching Policy & Behavior --- */
    char *cache_mode;
    zend_long cache_memory_limit_mb;
    char *cache_disk_path;
    zend_long cache_default_ttl_sec;
    zend_long cache_max_object_size_mb;
    bool cache_respect_origin_headers;
    char *cache_vary_on_headers;

    /* --- Origin (Backend) Configuration --- */
    char *origin_mcp_endpoint;
    char *origin_http_endpoint;
    zend_long origin_request_timeout_ms;

    /* --- Client-Facing Behavior --- */
    bool serve_stale_on_error;
    char *response_headers_to_add;
    char *allowed_http_methods;

} kg_native_cdn_config_t;

/* Module-global configuration instance. */
extern kg_native_cdn_config_t king_native_cdn_config;

#endif /* KING_CONFIG_NATIVE_CDN_BASE_H */
