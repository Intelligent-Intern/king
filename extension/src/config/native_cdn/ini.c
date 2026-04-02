/*
 * =========================================================================
 * FILENAME:   src/config/native_cdn/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the native CDN config
 * family. This file exposes the system-level enablement, cache, origin,
 * stale-on-error, response-header, and allowed-method directives and keeps
 * `king_native_cdn_config` aligned with validated updates.
 * =========================================================================
 */

#include "include/config/native_cdn/ini.h"
#include "include/config/native_cdn/base_layer.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>
#include <zend_ini.h>
#include <strings.h>

/* INI strings live in persistent module storage, so replace them manually. */
static void native_cdn_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(ZSTR_VAL(value), 1);
}

static ZEND_INI_MH(OnUpdateCdnPositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (val <= 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for a CDN directive. A positive integer is required."
        );
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.cdn_cache_memory_limit_mb")) {
        king_native_cdn_config.cache_memory_limit_mb = val;
    } else if (zend_string_equals_literal(entry->name, "king.cdn_cache_default_ttl_sec")) {
        king_native_cdn_config.cache_default_ttl_sec = val;
    } else if (zend_string_equals_literal(entry->name, "king.cdn_cache_max_object_size_mb")) {
        king_native_cdn_config.cache_max_object_size_mb = val;
    } else if (zend_string_equals_literal(entry->name, "king.cdn_origin_request_timeout_ms")) {
        king_native_cdn_config.origin_request_timeout_ms = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateCacheMode)
{
    const char *allowed[] = {"memory", "disk", "hybrid", NULL};
    bool is_allowed = false;
    for (int i = 0; allowed[i] != NULL; i++) {
        if (strcasecmp(ZSTR_VAL(new_value), allowed[i]) == 0) {
            is_allowed = true;
            break;
        }
    }
    if (!is_allowed) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for CDN cache mode. Must be 'memory', 'disk', or 'hybrid'."
        );
        return FAILURE;
    }
    native_cdn_replace_string(&king_native_cdn_config.cache_mode, new_value);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.cdn_enable", "0", PHP_INI_SYSTEM, OnUpdateBool, enable, kg_native_cdn_config_t, king_native_cdn_config)
    ZEND_INI_ENTRY("king.cdn_cache_mode", "disk", PHP_INI_SYSTEM, OnUpdateCacheMode)
    ZEND_INI_ENTRY("king.cdn_cache_memory_limit_mb", "512", PHP_INI_SYSTEM, OnUpdateCdnPositiveLong)
    STD_PHP_INI_ENTRY("king.cdn_cache_disk_path", "/var/cache/king_cdn", PHP_INI_SYSTEM, OnUpdateString, cache_disk_path, kg_native_cdn_config_t, king_native_cdn_config)
    ZEND_INI_ENTRY("king.cdn_cache_default_ttl_sec", "86400", PHP_INI_SYSTEM, OnUpdateCdnPositiveLong)
    ZEND_INI_ENTRY("king.cdn_cache_max_object_size_mb", "1024", PHP_INI_SYSTEM, OnUpdateCdnPositiveLong)
    STD_PHP_INI_ENTRY("king.cdn_cache_respect_origin_headers", "1", PHP_INI_SYSTEM, OnUpdateBool, cache_respect_origin_headers, kg_native_cdn_config_t, king_native_cdn_config)
    STD_PHP_INI_ENTRY("king.cdn_cache_vary_on_headers", "Accept-Encoding", PHP_INI_SYSTEM, OnUpdateString, cache_vary_on_headers, kg_native_cdn_config_t, king_native_cdn_config)
    STD_PHP_INI_ENTRY("king.cdn_origin_mcp_endpoint", "", PHP_INI_SYSTEM, OnUpdateString, origin_mcp_endpoint, kg_native_cdn_config_t, king_native_cdn_config)
    STD_PHP_INI_ENTRY("king.cdn_origin_http_endpoint", "", PHP_INI_SYSTEM, OnUpdateString, origin_http_endpoint, kg_native_cdn_config_t, king_native_cdn_config)
    ZEND_INI_ENTRY("king.cdn_origin_request_timeout_ms", "15000", PHP_INI_SYSTEM, OnUpdateCdnPositiveLong)
    STD_PHP_INI_ENTRY("king.cdn_serve_stale_on_error", "1", PHP_INI_SYSTEM, OnUpdateBool, serve_stale_on_error, kg_native_cdn_config_t, king_native_cdn_config)
    STD_PHP_INI_ENTRY("king.cdn_response_headers_to_add", "X-Cache-Status: HIT", PHP_INI_SYSTEM, OnUpdateString, response_headers_to_add, kg_native_cdn_config_t, king_native_cdn_config)
    STD_PHP_INI_ENTRY("king.cdn_allowed_http_methods", "GET,HEAD", PHP_INI_SYSTEM, OnUpdateString, allowed_http_methods, kg_native_cdn_config_t, king_native_cdn_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_native_cdn_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_native_cdn_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
