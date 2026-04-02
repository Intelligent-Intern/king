/*
 * =========================================================================
 * FILENAME:   src/config/native_object_store/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the native object-store config family. This slice
 * seeds the baseline storage enablement, redundancy, replication/chunk
 * sizing, metadata/discovery, cache, and direct-storage defaults before INI
 * and any allowed userland overrides refine the live storage snapshot.
 * =========================================================================
 */

#include "include/config/native_object_store/default.h"
#include "include/config/native_object_store/base_layer.h"

void kg_config_native_object_store_defaults_load(void)
{
    king_native_object_store_config.enable = false;
    king_native_object_store_config.s3_api_compat_enable = false;
    king_native_object_store_config.versioning_enable = true;
    king_native_object_store_config.allow_anonymous_access = false;

    king_native_object_store_config.default_redundancy_mode = NULL;
    /* Compact data/parity shard notation, e.g. 8 data shards + 4 parity shards. */
    king_native_object_store_config.erasure_coding_shards = NULL;
    king_native_object_store_config.default_replication_factor = 3;
    king_native_object_store_config.default_chunk_size_mb = 64;

    king_native_object_store_config.metadata_agent_uri = NULL;
    king_native_object_store_config.node_discovery_mode = NULL;
    king_native_object_store_config.node_static_list = NULL;

    king_native_object_store_config.metadata_cache_enable = true;
    king_native_object_store_config.metadata_cache_ttl_sec = 60;
    king_native_object_store_config.metadata_cache_max_entries = 4096;
    king_native_object_store_config.enable_directstorage = false;
}
