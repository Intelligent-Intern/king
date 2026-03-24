/*
 * include/object_store/object_store.h - Public C API for object storage/CDN
 * =========================================================================
 *
 * This header exposes the native object store and CDN entry points used by
 * the extension.
 */

#ifndef KING_OBJECT_STORE_H
#define KING_OBJECT_STORE_H

#include <php.h>
#include <stdint.h>
#include <time.h>

/* --- Object Store Types --- */

typedef enum {
    KING_STORAGE_BACKEND_LOCAL_FS,
    KING_STORAGE_BACKEND_DISTRIBUTED,
    KING_STORAGE_BACKEND_CLOUD_S3,
    KING_STORAGE_BACKEND_CLOUD_GCS,
    KING_STORAGE_BACKEND_CLOUD_AZURE,
    KING_STORAGE_BACKEND_MEMORY_CACHE
} king_storage_backend_t;

typedef enum {
    KING_OBJECT_TYPE_STATIC_ASSET,
    KING_OBJECT_TYPE_DYNAMIC_CONTENT,
    KING_OBJECT_TYPE_MEDIA_FILE,
    KING_OBJECT_TYPE_DOCUMENT,
    KING_OBJECT_TYPE_CACHE_ENTRY,
    KING_OBJECT_TYPE_BINARY_DATA
} king_object_type_t;

typedef enum {
    KING_CACHE_POLICY_NO_CACHE,
    KING_CACHE_POLICY_CACHE_CONTROL,
    KING_CACHE_POLICY_ETAG,
    KING_CACHE_POLICY_LAST_MODIFIED,
    KING_CACHE_POLICY_AGGRESSIVE,
    KING_CACHE_POLICY_SMART_CDN
} king_cache_policy_t;

typedef struct _king_object_metadata_t {
    char object_id[128];
    char content_type[64];
    char content_encoding[32];
    char etag[64];
    uint64_t content_length;
    time_t created_at;
    time_t modified_at;
    time_t expires_at;
    king_object_type_t object_type;
    king_cache_policy_t cache_policy;
    uint32_t cache_ttl_seconds;
    /* Cloud-native HA state */
    uint8_t is_backed_up;
    uint8_t replication_status; /* 0: none, 1: pending, 2: completed, 3: failed */
    /* CDN distribution state */
    uint8_t is_distributed;
    uint32_t distribution_peer_count;
} king_object_metadata_t;

typedef struct _king_storage_node_t {
    char node_id[64];
    char hostname[256];
    uint16_t port;
    king_storage_backend_t backend_type;
    uint64_t total_capacity_bytes;
    uint64_t used_capacity_bytes;
    uint32_t current_load_percent;
    double performance_score;
    time_t last_health_check;
    zend_bool is_healthy;
    zend_bool is_cdn_edge;
} king_storage_node_t;

typedef struct _king_cdn_config_t {
    zend_bool enabled;
    uint32_t edge_node_count;
    king_storage_node_t *edge_nodes;
    uint32_t cache_size_mb;
    uint32_t max_object_size_mb;
    uint32_t default_ttl_seconds;
    zend_bool enable_compression;
    zend_bool enable_image_optimization;
    zend_bool enable_smart_routing;
    char origin_server[256];
} king_cdn_config_t;

typedef struct _king_object_store_config_t {
    king_storage_backend_t primary_backend;
    king_storage_backend_t backup_backend;
    char storage_root_path[512];
    uint64_t max_storage_size_bytes;
    uint32_t replication_factor;
    uint32_t chunk_size_kb;
    zend_bool enable_deduplication;
    zend_bool enable_encryption;
    zend_bool enable_compression;
    king_cdn_config_t cdn_config;
    zval cloud_credentials; /* PHP array */
} king_object_store_config_t;

/* --- PHP Function Prototypes --- */

/* Initializes the object store from a PHP config array. */
PHP_FUNCTION(king_object_store_init);

/* Stores an object. */
PHP_FUNCTION(king_object_store_put);

/* Retrieves an object. */
PHP_FUNCTION(king_object_store_get);

/* Deletes an object. */
PHP_FUNCTION(king_object_store_delete);

/* Returns the object inventory for the active build. */
PHP_FUNCTION(king_object_store_list);

/* Caches an object in CDN edge nodes. */
PHP_FUNCTION(king_cdn_cache_object);

/* Invalidates cached objects across CDN edge nodes. */
PHP_FUNCTION(king_cdn_invalidate_cache);

/* Returns CDN edge-node information. */
PHP_FUNCTION(king_cdn_get_edge_nodes);

/* Returns object-store statistics. */
PHP_FUNCTION(king_object_store_get_stats);

/* Runs object-store maintenance tasks. */
PHP_FUNCTION(king_object_store_optimize);

/* --- Internal C API --- */

int king_object_store_init_system(king_object_store_config_t *config);
void king_object_store_shutdown_system(void);
int king_object_store_write_object(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata);
int king_object_store_read_object(const char *object_id, void **data, size_t *data_size, king_object_metadata_t *metadata);
int king_object_store_remove_object(const char *object_id);
int king_object_store_replicate_object(const char *object_id, uint32_t replication_factor);
int king_cdn_distribute_object(const char *object_id, const king_storage_node_t *edge_nodes, uint32_t node_count);
int king_cdn_find_optimal_edge_node(const char *client_ip, king_storage_node_t **optimal_node);
void king_object_store_cleanup_expired_objects(void);
const char* king_storage_backend_to_string(king_storage_backend_t backend);
const char* king_object_type_to_string(king_object_type_t type);
const char* king_cache_policy_to_string(king_cache_policy_t policy);

#endif /* KING_OBJECT_STORE_H */
