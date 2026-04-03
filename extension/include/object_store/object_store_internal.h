/*
 * =========================================================================
 * FILENAME:   include/object_store/object_store_internal.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Internal runtime state and helper contracts for object-store payload,
 * metadata, snapshot/restore, and resumable-upload flows.
 * =========================================================================
 */

#ifndef KING_OBJECT_STORE_INTERNAL_H
#define KING_OBJECT_STORE_INTERNAL_H

#include <stdbool.h>
#include "object_store/object_store.h"
#include <zend_hash.h>
#include "main/php_streams.h"

/* CDN cache registry state (defined in prelude/object_store_runtime.inc via types.inc) */
extern HashTable king_cdn_cache_registry;
extern bool king_cdn_cache_registry_initialized;
void king_cdn_sweep_expired(void);

typedef enum _king_object_store_result_code {
    KING_OBJECT_STORE_RESULT_MISS = 1,
    KING_OBJECT_STORE_RESULT_CONFLICT = 2,
    KING_OBJECT_STORE_RESULT_UNAVAILABLE = 3,
    KING_OBJECT_STORE_RESULT_VALIDATION = 4
} king_object_store_result_code;

typedef struct _king_object_store_runtime_state {
    zend_bool initialized;
    king_object_store_config_t config;

    /* Live primary-inventory stats tracked natively */
    uint64_t current_object_count;
    uint64_t current_stored_bytes;
    time_t latest_object_at;

    /* Adapter health/error telemetry */
    char primary_adapter_contract[16];
    char primary_adapter_status[24];
    char primary_adapter_error[512];
    char backup_adapter_contract[16];
    char backup_adapter_status[24];
    char backup_adapter_error[512];

    /* Persisted coordinator state and recovery telemetry for the distributed contract */
    zend_bool distributed_coordinator_state_present;
    zend_bool distributed_coordinator_state_recovered;
    uint64_t distributed_coordinator_state_version;
    uint64_t distributed_coordinator_generation;
    time_t distributed_coordinator_created_at;
    time_t distributed_coordinator_last_loaded_at;
    char distributed_coordinator_state_status[24];
    char distributed_coordinator_state_path[512];
    char distributed_coordinator_state_error[512];

} king_object_store_runtime_state;

extern king_object_store_runtime_state king_object_store_runtime;

int king_object_store_local_fs_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata);
int king_object_store_local_fs_write_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata
);
int king_object_store_local_fs_read(const char *object_id, void **data, size_t *data_size, king_object_metadata_t *metadata);
int king_object_store_local_fs_read_range(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
);
int king_object_store_local_fs_read_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata
);
int king_object_store_local_fs_remove(const char *object_id);
int king_object_store_local_fs_list(zval *return_array);
int king_object_store_list_object(zval *return_array);
int king_object_store_backend_read_metadata(const char *object_id, king_object_metadata_t *metadata);
const char *king_object_store_object_id_validate(const char *object_id);
const char *king_object_store_public_object_id_validate_zstr(const zend_string *object_id);
void king_object_store_config_clear(king_object_store_config_t *config);
void king_object_store_compute_sha256_hex(const void *data, size_t data_size, char output[65]);
int king_object_store_cdn_origin_http_read(
    const char *object_id,
    void **data,
    size_t *data_size,
    char *error,
    size_t error_size
);
int king_object_store_cdn_origin_http_read_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    char *error,
    size_t error_size
);
zend_bool king_object_store_runtime_capacity_is_enabled(void);
const char *king_object_store_runtime_capacity_mode_to_string(void);
uint64_t king_object_store_runtime_capacity_available_bytes(void);
int king_object_store_runtime_capacity_check_object_size(
    const char *object_id,
    uint64_t new_size,
    char *error,
    size_t error_size
);
int king_object_store_runtime_capacity_check_rewrite(
    uint64_t new_size,
    zend_bool had_existing_object,
    uint64_t old_size,
    char *error,
    size_t error_size
);

/* Durable metadata sidecars plus the process-local metadata cache */
void king_object_store_build_path(char *dest, size_t dest_len, const char *object_id);
void king_object_store_build_meta_path(char *dest, size_t dest_len, const char *object_id);
int king_object_store_meta_write(const char *object_id, const king_object_metadata_t *metadata);
int king_object_store_meta_read(const char *object_id, king_object_metadata_t *metadata);
void king_object_store_meta_remove(const char *object_id);
int king_object_store_runtime_metadata_cache_read(const char *object_id, king_object_metadata_t *metadata);
void king_object_store_runtime_metadata_cache_collect_stats(
    zend_long *entry_count,
    zend_long *eviction_count
);
int king_object_store_http_header_value_validate(
    const char *field_name,
    const char *value,
    char *error,
    size_t error_size
);

/* Rehydrate runtime stats from disk on init */
void king_object_store_rehydrate_stats(void);

/* Snapshot/restore, migration, and resumable-upload runtime hooks */
int king_object_store_backup_object(const char *object_id, const char *destination_path);
int king_object_store_restore_object(const char *object_id, const char *source_path);
int king_object_store_backup_all_objects(
    const char *destination_directory,
    zend_bool incremental,
    const char *base_snapshot_path
);
int king_object_store_restore_all_objects(const char *source_directory);
void king_object_store_initialize_adapter_statuses(void);
void king_object_store_set_runtime_adapter_status(
    const char *scope,
    const char *status,
    const char *contract,
    const char *error
);
const char *king_object_store_backend_contract_to_string(king_storage_backend_t backend);
int king_object_store_begin_upload_session(
    const char *object_id,
    const king_object_metadata_t *metadata,
    const char *expected_integrity_sha256,
    int adopted_lock_fd,
    char upload_id[65],
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
);
int king_object_store_append_upload_session_chunk(
    const char *upload_id,
    const char *source_path,
    uint64_t chunk_size,
    zend_bool is_final_chunk,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
);
int king_object_store_complete_upload_session(
    const char *upload_id,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
);
int king_object_store_abort_upload_session(
    const char *upload_id,
    char *error,
    size_t error_size
);
int king_object_store_get_upload_session_status(
    const char *upload_id,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
);
int king_object_store_acquire_object_lock(
    const char *object_id,
    int *lock_fd_out,
    char *error,
    size_t error_size
);
void king_object_store_release_object_lock(int *lock_fd);
void king_object_store_request_shutdown(void);

#endif /* KING_OBJECT_STORE_INTERNAL_H */
