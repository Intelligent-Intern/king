#include "include/config/native_object_store/config.h"
#include "include/config/native_object_store/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"
#include "include/validation/config_param/validate_generic_string.h"
#include "include/validation/config_param/validate_erasure_coding_shards_string.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

int kg_config_native_object_store_apply_userland_config_to(
    kg_native_object_store_config_t *target,
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
        } else if (zend_string_equals_literal(key, "s3_api_compat_enable")) {
            if (kg_validate_bool(value, "s3_api_compat_enable") != SUCCESS) {
                return FAILURE;
            }
            target->s3_api_compat_enable = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "versioning_enable")) {
            if (kg_validate_bool(value, "versioning_enable") != SUCCESS) {
                return FAILURE;
            }
            target->versioning_enable = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "allow_anonymous_access")) {
            if (kg_validate_bool(value, "allow_anonymous_access") != SUCCESS) {
                return FAILURE;
            }
            target->allow_anonymous_access = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "default_redundancy_mode")) {
            const char *allowed[] = {"erasure_coding", "replication", NULL};
            if (kg_validate_string_from_allowlist(value, allowed, &target->default_redundancy_mode) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "erasure_coding_shards")) {
            /* Keep the compact `8d4p` shard notation stable across config layers. */
            if (kg_validate_erasure_coding_shards_string(value, &target->erasure_coding_shards) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "default_replication_factor")) {
            if (kg_validate_positive_long(value, &target->default_replication_factor) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "default_chunk_size_mb")) {
            if (kg_validate_positive_long(value, &target->default_chunk_size_mb) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "metadata_agent_uri")) {
            if (kg_validate_generic_string(value, &target->metadata_agent_uri) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "node_discovery_mode")) {
            const char *allowed[] = {"static", "mcp_heartbeat", NULL};
            if (kg_validate_string_from_allowlist(value, allowed, &target->node_discovery_mode) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "node_static_list")) {
            if (kg_validate_generic_string(value, &target->node_static_list) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "metadata_cache_enable")) {
            if (kg_validate_bool(value, "metadata_cache_enable") != SUCCESS) {
                return FAILURE;
            }
            target->metadata_cache_enable = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "metadata_cache_ttl_sec")) {
            if (kg_validate_positive_long(value, &target->metadata_cache_ttl_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "metadata_cache_max_entries")) {
            if (kg_validate_positive_long(value, &target->metadata_cache_max_entries) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "enable_directstorage")) {
            if (kg_validate_bool(value, "enable_directstorage") != SUCCESS) {
                return FAILURE;
            }
            target->enable_directstorage = zend_is_true(value);
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_native_object_store_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Configuration override from userland is disabled by system administrator."
        );
        return FAILURE;
    }

    return kg_config_native_object_store_apply_userland_config_to(
        &king_native_object_store_config,
        config_arr
    );
}
