/*
 * =========================================================================
 * FILENAME:   src/config/native_object_store/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the native object-store
 * config family. This file exposes the system-level storage enablement,
 * redundancy, erasure-coding, replication/chunk sizing, metadata/discovery,
 * cache, and direct-storage directives and keeps
 * `king_native_object_store_config` aligned with validated updates.
 * =========================================================================
 */

#include "include/config/native_object_store/ini.h"
#include "include/config/native_object_store/base_layer.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>
#include <zend_ini.h>
#include <strings.h>

/* INI strings live in persistent module storage, so replace them manually. */
static void native_object_store_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(ZSTR_VAL(value), 1);
}

static ZEND_INI_MH(OnUpdateObjectStorePositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (val <= 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for an object store directive. A positive integer is required."
        );
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.storage_default_replication_factor")) {
        king_native_object_store_config.default_replication_factor = val;
    } else if (zend_string_equals_literal(entry->name, "king.storage_default_chunk_size_mb")) {
        king_native_object_store_config.default_chunk_size_mb = val;
    } else if (zend_string_equals_literal(entry->name, "king.storage_metadata_cache_ttl_sec")) {
        king_native_object_store_config.metadata_cache_ttl_sec = val;
    } else if (zend_string_equals_literal(entry->name, "king.storage_metadata_cache_max_entries")) {
        king_native_object_store_config.metadata_cache_max_entries = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateRedundancyMode)
{
    const char *allowed[] = {"erasure_coding", "replication", NULL};
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
            "Invalid value for redundancy mode. Must be 'erasure_coding' or 'replication'."
        );
        return FAILURE;
    }
    native_object_store_replace_string(&king_native_object_store_config.default_redundancy_mode, new_value);
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateDiscoveryMode)
{
    const char *allowed[] = {"static", "mcp_heartbeat", NULL};
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
            "Invalid value for node discovery mode. Must be 'static' or 'mcp_heartbeat'."
        );
        return FAILURE;
    }
    native_object_store_replace_string(&king_native_object_store_config.node_discovery_mode, new_value);
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateErasureCodingShards)
{
    int data_shards, parity_shards;
    char d, p;

    /* Accept the compact `8d4p` layout that the config docs expose. */
    if (sscanf(ZSTR_VAL(new_value), "%d%c%d%c", &data_shards, &d, &parity_shards, &p) != 4 ||
        d != 'd' || p != 'p' || data_shards <= 0 || parity_shards <= 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid format for erasure coding shards. Expected format like '8d4p' with positive integers."
        );
        return FAILURE;
    }
    native_object_store_replace_string(&king_native_object_store_config.erasure_coding_shards, new_value);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.storage_enable", "0", PHP_INI_SYSTEM, OnUpdateBool, enable, kg_native_object_store_config_t, king_native_object_store_config)
    STD_PHP_INI_ENTRY("king.storage_s3_api_compat_enable", "0", PHP_INI_SYSTEM, OnUpdateBool, s3_api_compat_enable, kg_native_object_store_config_t, king_native_object_store_config)
    STD_PHP_INI_ENTRY("king.storage_versioning_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, versioning_enable, kg_native_object_store_config_t, king_native_object_store_config)
    STD_PHP_INI_ENTRY("king.storage_allow_anonymous_access", "0", PHP_INI_SYSTEM, OnUpdateBool, allow_anonymous_access, kg_native_object_store_config_t, king_native_object_store_config)
    ZEND_INI_ENTRY("king.storage_default_redundancy_mode", "erasure_coding", PHP_INI_SYSTEM, OnUpdateRedundancyMode)
    ZEND_INI_ENTRY("king.storage_erasure_coding_shards", "8d4p", PHP_INI_SYSTEM, OnUpdateErasureCodingShards)
    ZEND_INI_ENTRY("king.storage_default_replication_factor", "3", PHP_INI_SYSTEM, OnUpdateObjectStorePositiveLong)
    ZEND_INI_ENTRY("king.storage_default_chunk_size_mb", "64", PHP_INI_SYSTEM, OnUpdateObjectStorePositiveLong)
    STD_PHP_INI_ENTRY("king.storage_metadata_agent_uri", "127.0.0.1:9701", PHP_INI_SYSTEM, OnUpdateString, metadata_agent_uri, kg_native_object_store_config_t, king_native_object_store_config)
    ZEND_INI_ENTRY("king.storage_node_discovery_mode", "static", PHP_INI_SYSTEM, OnUpdateDiscoveryMode)
    STD_PHP_INI_ENTRY("king.storage_node_static_list", "127.0.0.1:9711,127.0.0.1:9712", PHP_INI_SYSTEM, OnUpdateString, node_static_list, kg_native_object_store_config_t, king_native_object_store_config)
    STD_PHP_INI_ENTRY("king.storage_metadata_cache_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, metadata_cache_enable, kg_native_object_store_config_t, king_native_object_store_config)
    ZEND_INI_ENTRY("king.storage_metadata_cache_ttl_sec", "60", PHP_INI_SYSTEM, OnUpdateObjectStorePositiveLong)
    ZEND_INI_ENTRY("king.storage_metadata_cache_max_entries", "4096", PHP_INI_SYSTEM, OnUpdateObjectStorePositiveLong)
    STD_PHP_INI_ENTRY("king.storage_enable_directstorage", "0", PHP_INI_SYSTEM, OnUpdateBool, enable_directstorage, kg_native_object_store_config_t, king_native_object_store_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_native_object_store_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_native_object_store_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
