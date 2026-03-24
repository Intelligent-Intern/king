/*
 * =========================================================================
 * FILENAME:   src/object_store/object_store_internal.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Internal structures and state for the native object store backend.
 * =========================================================================
 */

#ifndef KING_OBJECT_STORE_INTERNAL_H
#define KING_OBJECT_STORE_INTERNAL_H

#include <stdbool.h>
#include "include/object_store/object_store.h"
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

} king_object_store_runtime_state;

extern king_object_store_runtime_state king_object_store_runtime;

int king_object_store_local_fs_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata);
int king_object_store_local_fs_read(const char *object_id, void **data, size_t *data_size, king_object_metadata_t *metadata);
int king_object_store_local_fs_remove(const char *object_id);
int king_object_store_local_fs_list(zval *return_array);

/* Durable metadata sidecar */
void king_object_store_build_meta_path(char *dest, size_t dest_len, const char *object_id);
int king_object_store_meta_write(const char *object_id, const king_object_metadata_t *metadata);
int king_object_store_meta_read(const char *object_id, king_object_metadata_t *metadata);
void king_object_store_meta_remove(const char *object_id);

/* Rehydrate runtime stats from disk on init */
void king_object_store_rehydrate_stats(void);

#endif /* KING_OBJECT_STORE_INTERNAL_H */
