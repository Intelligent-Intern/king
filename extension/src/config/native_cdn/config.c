#include "include/config/native_cdn/config.h"
#include "include/config/native_cdn/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"
#include "include/validation/config_param/validate_generic_string.h"
#include "include/validation/config_param/validate_comma_separated_string_from_allowlist.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

int kg_config_native_cdn_apply_userland_config_to(
    kg_native_cdn_config_t *target,
    zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (target == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Configuration must be provided as an array."
        );
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "enable")) {
            if (kg_validate_bool(value, "enable") != SUCCESS) {
                return FAILURE;
            }
            target->enable = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "cache_mode")) {
            const char *allowed[] = {"memory", "disk", "hybrid", NULL};
            if (kg_validate_string_from_allowlist(value, allowed, &target->cache_mode) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cache_memory_limit_mb")) {
            if (kg_validate_positive_long(value, &target->cache_memory_limit_mb) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cache_disk_path")) {
            if (kg_validate_generic_string(value, &target->cache_disk_path) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cache_default_ttl_sec")) {
            if (kg_validate_positive_long(value, &target->cache_default_ttl_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cache_max_object_size_mb")) {
            if (kg_validate_positive_long(value, &target->cache_max_object_size_mb) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cache_respect_origin_headers")) {
            if (kg_validate_bool(value, "cache_respect_origin_headers") != SUCCESS) {
                return FAILURE;
            }
            target->cache_respect_origin_headers = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "cache_vary_on_headers")) {
            if (kg_validate_generic_string(value, &target->cache_vary_on_headers) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "origin_mcp_endpoint")) {
            if (kg_validate_generic_string(value, &target->origin_mcp_endpoint) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "origin_http_endpoint")) {
            if (kg_validate_generic_string(value, &target->origin_http_endpoint) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "origin_request_timeout_ms")) {
            if (kg_validate_positive_long(value, &target->origin_request_timeout_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "serve_stale_on_error")) {
            if (kg_validate_bool(value, "serve_stale_on_error") != SUCCESS) {
                return FAILURE;
            }
            target->serve_stale_on_error = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "response_headers_to_add")) {
            if (kg_validate_generic_string(value, &target->response_headers_to_add) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "allowed_http_methods")) {
            const char *allowed[] = {"GET", "HEAD", "POST", "PUT", "DELETE", "OPTIONS", "PATCH", NULL};
            if (kg_validate_comma_separated_string_from_allowlist(value, allowed, &target->allowed_http_methods) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_native_cdn_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Configuration override from userland is disabled by system administrator."
        );
        return FAILURE;
    }

    return kg_config_native_cdn_apply_userland_config_to(
        &king_native_cdn_config,
        config_arr
    );
}
