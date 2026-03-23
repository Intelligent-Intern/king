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

#include "include/object_store/object_store.h"

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

#endif /* KING_OBJECT_STORE_INTERNAL_H */
