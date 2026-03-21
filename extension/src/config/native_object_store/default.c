#include "include/config/native_object_store/default.h"
#include "include/config/native_object_store/base_layer.h"

void kg_config_native_object_store_defaults_load(void)
{
    king_native_object_store_config.enable = false;
    king_native_object_store_config.s3_api_compat_enable = false;
    king_native_object_store_config.versioning_enable = true;
    king_native_object_store_config.allow_anonymous_access = false;

    king_native_object_store_config.default_redundancy_mode = pestrdup("erasure_coding", 1);
    /* Compact data/parity shard notation, e.g. 8 data shards + 4 parity shards. */
    king_native_object_store_config.erasure_coding_shards = pestrdup("8d4p", 1);
    king_native_object_store_config.default_replication_factor = 3;
    king_native_object_store_config.default_chunk_size_mb = 64;

    king_native_object_store_config.metadata_agent_uri = pestrdup("127.0.0.1:9701", 1);
    king_native_object_store_config.node_discovery_mode = pestrdup("static", 1);
    king_native_object_store_config.node_static_list = pestrdup("127.0.0.1:9711,127.0.0.1:9712", 1);

    king_native_object_store_config.metadata_cache_enable = true;
    king_native_object_store_config.metadata_cache_ttl_sec = 60;
    king_native_object_store_config.enable_directstorage = false;
}
