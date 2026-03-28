/*
 * =========================================================================
 * FILENAME:   include/object_store/object_store_internal.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Internal structures and state for the native object store backend.
 * =========================================================================
 */

#ifndef KING_OBJECT_STORE_INTERNAL_H
#define KING_OBJECT_STORE_INTERNAL_H

#include <stdbool.h>
#include "object_store/object_store.h"
#include <zend_hash.h>

/* CDN cache registry state (defined in prelude/object_store_runtime.inc via types.inc) */
extern HashTable king_cdn_cache_registry;
extern bool king_cdn_cache_registry_initialized;
void king_cdn_sweep_expired(void);

typedef struct _king_object_store_runtime_state {
    zend_bool initialized;
    king_object_store_config_t config;

    /* Live stats tracked natively */
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

} king_object_store_runtime_state;

extern king_object_store_runtime_state king_object_store_runtime;

int king_object_store_local_fs_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata);
int king_object_store_local_fs_read(const char *object_id, void **data, size_t *data_size, king_object_metadata_t *metadata);
int king_object_store_local_fs_remove(const char *object_id);
int king_object_store_local_fs_list(zval *return_array);
int king_object_store_list_object(zval *return_array);
int king_object_store_backend_read_metadata(const char *object_id, king_object_metadata_t *metadata);
const char *king_object_store_object_id_validate(const char *object_id);

/* Durable metadata sidecar */
void king_object_store_build_path(char *dest, size_t dest_len, const char *object_id);
void king_object_store_build_meta_path(char *dest, size_t dest_len, const char *object_id);
int king_object_store_meta_write(const char *object_id, const king_object_metadata_t *metadata);
int king_object_store_meta_read(const char *object_id, king_object_metadata_t *metadata);
void king_object_store_meta_remove(const char *object_id);

/* Rehydrate runtime stats from disk on init */
void king_object_store_rehydrate_stats(void);

/* Cloud-native HA hooks */
/* File-backed persistence backup/restore and recovery paths */
int king_object_store_backup_object(const char *object_id, const char *destination_path);
int king_object_store_restore_object(const char *object_id, const char *source_path);
int king_object_store_backup_all_objects(const char *destination_directory);
int king_object_store_restore_all_objects(const char *source_directory);
void king_object_store_initialize_adapter_statuses(void);
void king_object_store_set_runtime_adapter_status(
    const char *scope,
    const char *status,
    const char *contract,
    const char *error
);
const char *king_object_store_backend_contract_to_string(king_storage_backend_t backend);

#endif /* KING_OBJECT_STORE_INTERNAL_H */
