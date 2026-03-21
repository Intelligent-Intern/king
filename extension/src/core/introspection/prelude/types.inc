#include "php.h"
#include "php_king.h"
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <zend_exceptions.h>
#include <zend_hash.h>
#include <zend_smart_str.h>

#include "include/king_globals.h"
#include "include/config/cloud_autoscale/base_layer.h"
#include "include/config/iibin/base_layer.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/config/native_cdn/base_layer.h"
#include "include/config/native_object_store/base_layer.h"
#include "include/config/open_telemetry/base_layer.h"
#include "include/config/smart_dns/base_layer.h"
#include "include/iibin/iibin_internal.h"

static HashTable king_semantic_dns_service_registry;
static HashTable king_semantic_dns_mother_node_registry;
static HashTable king_object_store_registry;
static HashTable king_cdn_cache_registry;
static HashTable king_telemetry_metric_registry;
static bool king_semantic_dns_registry_initialized = false;
static bool king_object_store_registry_initialized = false;
static bool king_cdn_cache_registry_initialized = false;
static bool king_telemetry_registry_initialized = false;

typedef struct _king_semantic_dns_service {
    zend_string *service_id;
    zend_string *service_name;
    zend_string *service_type;
    zend_string *hostname;
    zend_string *status;
    HashTable attributes;
    zend_long port;
    zend_long current_load_percent;
    zend_long active_connections;
    zend_long total_requests;
    time_t registered_at;
} king_semantic_dns_service;

typedef struct _king_semantic_dns_mother_node {
    zend_string *node_id;
    zend_string *hostname;
    zend_string *status;
    zend_long port;
    zend_long managed_services_count;
    double trust_score;
    time_t registered_at;
} king_semantic_dns_mother_node;

typedef struct _king_object_store_entry {
    zend_string *object_id;
    zend_string *payload;
    time_t stored_at;
} king_object_store_entry;

typedef struct _king_cdn_cache_entry {
    zend_string *object_id;
    zend_long payload_size;
    time_t cached_at;
    time_t expires_at;
} king_cdn_cache_entry;

typedef struct _king_object_store_runtime_state {
    bool initialized;
    char primary_backend[32];
    char storage_root_path[512];
    zend_long max_storage_size_bytes;
    zend_long replication_factor;
    zend_long chunk_size_kb;
    bool cdn_enabled;
    zend_long cdn_cache_size_mb;
    zend_long cdn_default_ttl_sec;
} king_object_store_runtime_state;

typedef struct _king_telemetry_metric_record {
    zend_string *metric_name;
    zend_string *metric_type;
    HashTable labels;
    double value;
    time_t recorded_at;
} king_telemetry_metric_record;

static const char * const king_proto_primitive_types[] = {
    "double",
    "float",
    "int64",
    "uint64",
    "int32",
    "uint32",
    "sint32",
    "sint64",
    "fixed64",
    "sfixed64",
    "fixed32",
    "sfixed32",
    "bool",
    "string",
    "bytes"
};

static king_object_store_runtime_state king_object_store_runtime;

static zend_always_inline const char *king_safe_string(const char *value)
{
    return value != NULL ? value : "";
}

static void king_object_store_runtime_assign_defaults(
    king_object_store_runtime_state *state
)
{
    memset(state, 0, sizeof(*state));

    strncpy(state->primary_backend, "local_fs", sizeof(state->primary_backend) - 1);
    strncpy(state->storage_root_path, "/tmp/king_object_store", sizeof(state->storage_root_path) - 1);
    state->max_storage_size_bytes = 10LL * 1024 * 1024 * 1024;
    state->replication_factor =
        king_native_object_store_config.default_replication_factor > 0
        ? king_native_object_store_config.default_replication_factor
        : 1;
    state->chunk_size_kb =
        king_native_object_store_config.default_chunk_size_mb > 0
        ? king_native_object_store_config.default_chunk_size_mb * 1024
        : 1024;
    state->cdn_enabled = king_native_cdn_config.enable ? true : false;
    state->cdn_cache_size_mb = king_native_cdn_config.cache_memory_limit_mb;
    state->cdn_default_ttl_sec = king_native_cdn_config.cache_default_ttl_sec;
}

static void king_object_store_runtime_reset_defaults(void)
{
    king_object_store_runtime_assign_defaults(&king_object_store_runtime);
    king_object_store_runtime.initialized = false;
}

static bool king_object_store_runtime_backend_supported(zend_string *backend)
{
    static const char * const supported_backends[] = {
        "local_fs",
        "distributed",
        "cloud_s3",
        "cloud_gcs",
        "cloud_azure",
        "memory_cache"
    };
    size_t i;

    for (i = 0; i < sizeof(supported_backends) / sizeof(supported_backends[0]); i++) {
        if (strcmp(ZSTR_VAL(backend), supported_backends[i]) == 0) {
            return true;
        }
    }

    return false;
}

static uint32_t king_proto_float_to_bits(float value)
{
    uint32_t bits;

    memcpy(&bits, &value, sizeof(bits));
    return bits;
}

static float king_proto_bits_to_float(uint32_t bits)
{
    float value;

    memcpy(&value, &bits, sizeof(value));
    return value;
}

static uint64_t king_proto_double_to_bits(double value)
{
    uint64_t bits;

    memcpy(&bits, &value, sizeof(bits));
    return bits;
}

static double king_proto_bits_to_double(uint64_t bits)
{
    double value;

    memcpy(&value, &bits, sizeof(value));
    return value;
}
