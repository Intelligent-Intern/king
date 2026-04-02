/*
 * include/object_store/object_store.h - Public object-store/CDN surface
 * =========================================================================
 *
 * Shared object-store/CDN types plus the exported PHP and native C entry
 * points used by the extension. The active runtime covers object payload and
 * metadata storage, bounded stream ingress/egress, real-cloud resumable
 * uploads, CDN cache hooks, and committed snapshot backup/restore flows.
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

typedef enum {
    KING_OBJECT_STORE_UPLOAD_PROTOCOL_NONE = 0,
    KING_OBJECT_STORE_UPLOAD_PROTOCOL_S3_MULTIPART,
    KING_OBJECT_STORE_UPLOAD_PROTOCOL_GCS_RESUMABLE,
    KING_OBJECT_STORE_UPLOAD_PROTOCOL_AZURE_BLOCKS
} king_object_store_upload_protocol_t;

typedef struct _king_object_metadata_t {
    char object_id[128];
    char content_type[64];
    char content_encoding[32];
    char etag[129];
    char integrity_sha256[65];
    uint64_t content_length;
    uint64_t version;
    time_t created_at;
    time_t modified_at;
    time_t expires_at;
    king_object_type_t object_type;
    king_cache_policy_t cache_policy;
    uint32_t cache_ttl_seconds;
    uint8_t local_fs_present;
    uint8_t distributed_present;
    uint8_t cloud_s3_present;
    uint8_t cloud_gcs_present;
    uint8_t cloud_azure_present;
    /* Cloud-native HA state */
    uint8_t is_backed_up;
    uint8_t replication_status; /* 0: none, 1: pending, 2: completed, 3: failed */
    /* CDN distribution state */
    uint8_t is_distributed;
    uint32_t distribution_peer_count;
} king_object_metadata_t;

typedef struct _king_object_store_upload_status_t {
    char upload_id[65];
    char object_id[128];
    king_storage_backend_t backend;
    king_object_store_upload_protocol_t protocol;
    uint64_t uploaded_bytes;
    uint64_t next_offset;
    uint64_t chunk_size_bytes;
    uint32_t next_part_number;
    uint32_t uploaded_part_count;
    time_t created_at;
    time_t updated_at;
    uint8_t sequential_chunks_required;
    uint8_t final_chunk_may_be_shorter;
    uint8_t final_chunk_received;
    uint8_t remote_completed;
    uint8_t recovered_after_restart;
    uint8_t completed;
    uint8_t aborted;
} king_object_store_upload_status_t; /* Stable PHP-visible upload-session snapshot. */

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

/* Initializes the local object-store/CDN runtime from a PHP config array. */
PHP_FUNCTION(king_object_store_init);

/* Stores an object. */
PHP_FUNCTION(king_object_store_put);

/* Stores an object from a readable stream without whole-payload materialization. */
PHP_FUNCTION(king_object_store_put_from_stream);

/* Starts a real-cloud resumable upload session on the active primary backend. */
PHP_FUNCTION(king_object_store_begin_resumable_upload);

/* Appends one chunk to a provider-native resumable upload session. */
PHP_FUNCTION(king_object_store_append_resumable_upload_chunk);

/* Completes a provider-native resumable upload session. */
PHP_FUNCTION(king_object_store_complete_resumable_upload);

/* Aborts a provider-native resumable upload session. */
PHP_FUNCTION(king_object_store_abort_resumable_upload);

/* Returns the current resumable-upload session snapshot. */
PHP_FUNCTION(king_object_store_get_resumable_upload_status);

/* Retrieves an object. */
PHP_FUNCTION(king_object_store_get);

/* Writes an object into a writable stream without whole-payload materialization. */
PHP_FUNCTION(king_object_store_get_to_stream);

/* Deletes an object. */
PHP_FUNCTION(king_object_store_delete);

/* Returns the current object inventory snapshot. */
PHP_FUNCTION(king_object_store_list);

/* Caches an object in CDN edge nodes. */
PHP_FUNCTION(king_cdn_cache_object);

/* Invalidates cached objects across CDN edge nodes. */
PHP_FUNCTION(king_cdn_invalidate_cache);

/* Returns CDN edge-node information. */
PHP_FUNCTION(king_cdn_get_edge_nodes);

/* Returns object-store statistics. */
PHP_FUNCTION(king_object_store_get_stats);

/* Exports one object payload and metadata sidecar to a directory. */
PHP_FUNCTION(king_object_store_backup_object);

/* Restores exactly one archived object from a backup directory. */
PHP_FUNCTION(king_object_store_restore_object);

/* Exports a committed full or incremental snapshot of the current store. */
PHP_FUNCTION(king_object_store_backup_all_objects);

/* Restores a committed full snapshot or incremental patch replay. */
PHP_FUNCTION(king_object_store_restore_all_objects);

/* Runs object-store maintenance tasks. */
PHP_FUNCTION(king_object_store_optimize);

/* Removes already expired objects from the active runtime. */
PHP_FUNCTION(king_object_store_cleanup_expired_objects);

/* Returns one stored metadata snapshot, or false on miss. */
PHP_FUNCTION(king_object_store_get_metadata);

/* --- Internal C API --- */

int king_object_store_init_system(king_object_store_config_t *config);
void king_object_store_shutdown_system(void);
int king_object_store_write_object(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata);
int king_object_store_write_object_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata
);
int king_object_store_read_object(const char *object_id, void **data, size_t *data_size, king_object_metadata_t *metadata);
int king_object_store_read_object_range(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
);
int king_object_store_read_object_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata
);
int king_object_store_remove_object(const char *object_id);
int king_object_store_replicate_object(const char *object_id, uint32_t replication_factor);
int king_cdn_distribute_object(const char *object_id, const king_storage_node_t *edge_nodes, uint32_t node_count);
int king_cdn_find_optimal_edge_node(const char *client_ip, king_storage_node_t **optimal_node);
zend_bool king_object_store_metadata_is_expired_at(const king_object_metadata_t *metadata, time_t now);
zend_bool king_object_store_metadata_is_expired_now(const king_object_metadata_t *metadata);
int king_object_store_cleanup_expired_objects(
    uint64_t *scanned_out,
    uint64_t *removed_out,
    uint64_t *bytes_reclaimed_out,
    uint64_t *failures_out
);
const char* king_storage_backend_to_string(king_storage_backend_t backend);
const char* king_object_type_to_string(king_object_type_t type);
const char* king_cache_policy_to_string(king_cache_policy_t policy);
const char* king_object_store_upload_protocol_to_string(king_object_store_upload_protocol_t protocol);

#endif /* KING_OBJECT_STORE_H */
