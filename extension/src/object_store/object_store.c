/*
 * =========================================================================
 * FILENAME:   src/object_store/object_store.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Native object store backend core. Implements local_fs persistence,
 * backend-routing dispatch, capacity enforcement accounting, and stubs
 * for distributed/cloud paths.
 * =========================================================================
 */

#include "php_king.h"
#include "object_store/object_store_internal.h"
#include "Zend/zend_smart_str.h"
#include "main/php_streams.h"
#include "ext/standard/base64.h"
#include "ext/hash/php_hash.h"
#include "ext/hash/php_hash_sha.h"
#include <curl/curl.h>
#include <inttypes.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <errno.h>
#include <dlfcn.h>
#include <ctype.h>
#include <fcntl.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <unistd.h>
#include <dirent.h>
#include <limits.h>

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

king_object_store_runtime_state king_object_store_runtime;
static HashTable king_object_store_runtime_metadata_cache;
static bool king_object_store_runtime_metadata_cache_initialized = false;
static HashTable king_object_store_upload_sessions;
static bool king_object_store_upload_sessions_initialized = false;

typedef struct _king_object_store_upload_session_t {
    char upload_id[65];
    char object_id[128];
    char provider_token[1024];
    char expected_integrity_sha256[65];
    char assembly_path[PATH_MAX];
    char state_path[PATH_MAX];
    char metadata_path[PATH_MAX];
    char existing_metadata_path[PATH_MAX];
    king_storage_backend_t backend;
    king_object_store_upload_protocol_t protocol;
    king_object_metadata_t metadata;
    king_object_metadata_t existing_metadata;
    PHP_SHA256_CTX sha256_ctx;
    uint64_t uploaded_bytes;
    uint64_t next_offset;
    uint64_t old_size;
    uint64_t chunk_size_bytes;
    int lock_fd;
    uint32_t next_part_number;
    time_t created_at;
    time_t updated_at;
    zend_bool existed_before;
    zend_bool sequential_chunks_required;
    zend_bool final_chunk_may_be_shorter;
    zend_bool final_chunk_received;
    zend_bool remote_completed;
    zend_bool recovered_after_restart;
    zend_bool completed;
    zend_bool aborted;
    HashTable provider_parts;
} king_object_store_upload_session_t;

static const char *king_object_store_adapter_status_ok = "ok";
static const char *king_object_store_adapter_status_simulated = "simulated";
static const char *king_object_store_adapter_status_failed = "failed";
static const char *king_object_store_adapter_status_unimplemented = "unimplemented";
static const char *king_object_store_adapter_status_unknown = "unknown";
static const char *king_object_store_adapter_contract_local = "local";
static const char *king_object_store_adapter_contract_distributed = "distributed";
static const char *king_object_store_adapter_contract_cloud = "cloud";
static const char *king_object_store_adapter_contract_simulated = "simulated";
static const char *king_object_store_adapter_contract_unconfigured = "unconfigured";
static const char *king_object_store_distributed_state_status_inactive = "inactive";
static const char *king_object_store_distributed_state_status_initialized = "initialized";
static const char *king_object_store_distributed_state_status_recovered = "recovered";
static const char *king_object_store_distributed_state_status_failed = "failed";

static int king_object_store_mkdir_parents(const char *path);
static int king_object_store_ensure_directory_recursive(const char *path);
static int king_object_store_read_file_contents(const char *source_path, void **data, size_t *data_size);
static int king_object_store_atomic_write_file(const char *target_path, const void *data, size_t data_size);
static int king_object_store_copy_file_to_path(const char *source_path, const char *target_path);
static int king_object_store_build_path_in_directory(
    char *dest,
    size_t dest_len,
    const char *directory,
    const char *object_id,
    const char *suffix
);
static void king_object_store_build_distributed_objects_dir_path(char *dest, size_t dest_len);
static int king_object_store_build_distributed_path(char *dest, size_t dest_len, const char *object_id);
static int king_object_store_distributed_write_internal(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    zend_bool update_counters,
    char *error,
    size_t error_size
);
static int king_object_store_distributed_write_from_file_internal(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    zend_bool update_counters,
    char *error,
    size_t error_size
);
static int king_object_store_distributed_remove_internal(
    const char *object_id,
    zend_bool update_counters,
    zend_bool remove_metadata,
    char *error,
    size_t error_size
);
static int king_object_store_meta_write_to_path(const char *path, const king_object_metadata_t *metadata);
static int king_object_store_meta_read_from_path(const char *path, king_object_metadata_t *metadata);
static int king_object_store_backup_object_to_backend(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    king_storage_backend_t backup_backend
);
static int king_object_store_remove_backup_object_from_backend(
    const char *object_id,
    king_storage_backend_t backup_backend
);
static int king_object_store_directory_is_within_storage_root(const char *directory_path, int allow_missing_path);
static int king_object_store_s3_rehydrate_stats(char *error, size_t error_size);
static int king_object_store_s3_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters
);
static int king_object_store_s3_write_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters
);
static int king_object_store_s3_read(const char *object_id, void **data, size_t *data_size);
static int king_object_store_s3_read_range(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
);
static int king_object_store_s3_read_to_path_with_error(
    const char *object_id,
    const char *destination_path,
    size_t offset,
    size_t length,
    zend_bool has_length,
    char *error,
    size_t error_size
);
static int king_object_store_s3_remove(const char *object_id);
static int king_object_store_s3_delete_backup_copy(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool *removed_out
);
static int king_object_store_s3_list(zval *return_array);
static int king_object_store_gcs_rehydrate_stats(char *error, size_t error_size);
static int king_object_store_gcs_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters
);
static int king_object_store_gcs_write_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters
);
static int king_object_store_gcs_read(const char *object_id, void **data, size_t *data_size);
static int king_object_store_gcs_read_range(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
);
static int king_object_store_gcs_read_to_path_with_error(
    const char *object_id,
    const char *destination_path,
    size_t offset,
    size_t length,
    zend_bool has_length,
    char *error,
    size_t error_size
);
static int king_object_store_gcs_remove(const char *object_id);
static int king_object_store_gcs_delete_backup_copy(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool *removed_out
);
static int king_object_store_gcs_list(zval *return_array);
static zend_result king_object_store_gcs_head_object(
    const char *object_id,
    zend_bool *exists_out,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size
);
static zend_result king_object_store_azure_head_object(
    const char *object_id,
    zend_bool *exists_out,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size
);
static int king_object_store_azure_rehydrate_stats(char *error, size_t error_size);
static int king_object_store_azure_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters
);
static int king_object_store_azure_write_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size,
    zend_bool update_counters
);
static int king_object_store_azure_read(const char *object_id, void **data, size_t *data_size);
static int king_object_store_azure_read_range(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
);
static int king_object_store_azure_read_to_path_with_error(
    const char *object_id,
    const char *destination_path,
    size_t offset,
    size_t length,
    zend_bool has_length,
    char *error,
    size_t error_size
);

static void king_object_store_distributed_state_reset_runtime(void)
{
    king_object_store_runtime.distributed_coordinator_state_present = 0;
    king_object_store_runtime.distributed_coordinator_state_recovered = 0;
    king_object_store_runtime.distributed_coordinator_state_version = 0;
    king_object_store_runtime.distributed_coordinator_generation = 0;
    king_object_store_runtime.distributed_coordinator_created_at = 0;
    king_object_store_runtime.distributed_coordinator_last_loaded_at = 0;
    king_object_store_runtime.distributed_coordinator_state_path[0] = '\0';
    king_object_store_runtime.distributed_coordinator_state_error[0] = '\0';
    snprintf(
        king_object_store_runtime.distributed_coordinator_state_status,
        sizeof(king_object_store_runtime.distributed_coordinator_state_status),
        "%s",
        king_object_store_distributed_state_status_inactive
    );
}

static void king_object_store_build_distributed_coordinator_dir_path(char *dest, size_t dest_len)
{
    if (dest == NULL || dest_len == 0) {
        return;
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        dest[0] = '\0';
        return;
    }

    snprintf(
        dest,
        dest_len,
        "%s/.king-distributed",
        king_object_store_runtime.config.storage_root_path
    );
}

static void king_object_store_build_distributed_objects_dir_path(char *dest, size_t dest_len)
{
    char coordinator_dir[PATH_MAX];

    if (dest == NULL || dest_len == 0) {
        return;
    }

    king_object_store_build_distributed_coordinator_dir_path(
        coordinator_dir,
        sizeof(coordinator_dir)
    );
    snprintf(dest, dest_len, "%s/objects", coordinator_dir);
}

static int king_object_store_build_distributed_path(
    char *dest,
    size_t dest_len,
    const char *object_id
)
{
    char objects_dir[PATH_MAX];

    king_object_store_build_distributed_objects_dir_path(
        objects_dir,
        sizeof(objects_dir)
    );

    return king_object_store_build_path_in_directory(
        dest,
        dest_len,
        objects_dir,
        object_id,
        NULL
    );
}

static void king_object_store_build_distributed_coordinator_state_path(char *dest, size_t dest_len)
{
    char directory_path[PATH_MAX];

    if (dest == NULL || dest_len == 0) {
        return;
    }

    king_object_store_build_distributed_coordinator_dir_path(
        directory_path,
        sizeof(directory_path)
    );
    if (directory_path[0] == '\0') {
        dest[0] = '\0';
        return;
    }

    snprintf(dest, dest_len, "%s/coordinator.state", directory_path);
}

static int king_object_store_distributed_backend_is_configured(void)
{
    return king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED
        || king_object_store_runtime.config.backup_backend == KING_STORAGE_BACKEND_DISTRIBUTED;
}

static int king_object_store_distributed_coordinator_write_state(
    const char *state_path,
    uint64_t version,
    uint64_t generation,
    time_t created_at
)
{
    char payload[512];
    int payload_len;
    time_t now = time(NULL);

    if (state_path == NULL || state_path[0] == '\0') {
        return FAILURE;
    }

    payload_len = snprintf(
        payload,
        sizeof(payload),
        "version=%" PRIu64 "\n"
        "generation=%" PRIu64 "\n"
        "created_at=%" PRIu64 "\n"
        "updated_at=%" PRIu64 "\n",
        version,
        generation,
        (uint64_t) created_at,
        (uint64_t) now
    );
    if (payload_len < 0 || (size_t) payload_len >= sizeof(payload)) {
        return FAILURE;
    }

    return king_object_store_atomic_write_file(
        state_path,
        payload,
        (size_t) payload_len
    );
}

static int king_object_store_distributed_coordinator_load_state(
    const char *state_path,
    uint64_t *version_out,
    uint64_t *generation_out,
    time_t *created_at_out
)
{
    char *cursor;
    char *line;
    char *saveptr = NULL;
    void *state_data = NULL;
    size_t state_size = 0;
    uint64_t version = 0;
    uint64_t generation = 0;
    uint64_t created_at = 0;
    int has_version = 0;
    int has_generation = 0;
    int has_created_at = 0;

    if (state_path == NULL
        || version_out == NULL
        || generation_out == NULL
        || created_at_out == NULL) {
        return FAILURE;
    }

    if (king_object_store_read_file_contents(state_path, &state_data, &state_size) != SUCCESS) {
        return FAILURE;
    }

    cursor = (char *) state_data;
    for (line = strtok_r(cursor, "\n", &saveptr);
         line != NULL;
         line = strtok_r(NULL, "\n", &saveptr)) {
        char *eq = strchr(line, '=');
        uint64_t value;

        if (eq == NULL) {
            continue;
        }

        *eq = '\0';
        value = strtoull(eq + 1, NULL, 10);

        if (strcmp(line, "version") == 0) {
            version = value;
            has_version = 1;
        } else if (strcmp(line, "generation") == 0) {
            generation = value;
            has_generation = 1;
        } else if (strcmp(line, "created_at") == 0) {
            created_at = value;
            has_created_at = 1;
        }
    }

    pefree(state_data, 1);

    if (!has_version || !has_generation || !has_created_at || version == 0 || generation == 0) {
        return FAILURE;
    }

    *version_out = version;
    *generation_out = generation;
    *created_at_out = (time_t) created_at;
    return SUCCESS;
}

static int king_object_store_initialize_distributed_coordinator_state(
    char *error,
    size_t error_size
)
{
    char directory_path[PATH_MAX];
    char state_path[PATH_MAX];
    struct stat state_stat;
    uint64_t version;
    uint64_t generation;
    time_t created_at;

    king_object_store_distributed_state_reset_runtime();

    if (!king_object_store_distributed_backend_is_configured()) {
        return SUCCESS;
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        snprintf(
            king_object_store_runtime.distributed_coordinator_state_status,
            sizeof(king_object_store_runtime.distributed_coordinator_state_status),
            "%s",
            king_object_store_distributed_state_status_failed
        );
        snprintf(
            king_object_store_runtime.distributed_coordinator_state_error,
            sizeof(king_object_store_runtime.distributed_coordinator_state_error),
            "%s",
            "Distributed coordinator state requires a non-empty storage_root_path."
        );
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "%s", king_object_store_runtime.distributed_coordinator_state_error);
        }
        return FAILURE;
    }

    king_object_store_build_distributed_coordinator_dir_path(
        directory_path,
        sizeof(directory_path)
    );
    king_object_store_build_distributed_coordinator_state_path(
        state_path,
        sizeof(state_path)
    );
    snprintf(
        king_object_store_runtime.distributed_coordinator_state_path,
        sizeof(king_object_store_runtime.distributed_coordinator_state_path),
        "%s",
        state_path
    );

    if (king_object_store_ensure_directory_recursive(directory_path) != SUCCESS) {
        snprintf(
            king_object_store_runtime.distributed_coordinator_state_status,
            sizeof(king_object_store_runtime.distributed_coordinator_state_status),
            "%s",
            king_object_store_distributed_state_status_failed
        );
        snprintf(
            king_object_store_runtime.distributed_coordinator_state_error,
            sizeof(king_object_store_runtime.distributed_coordinator_state_error),
            "Could not create distributed coordinator directory '%s'.",
            directory_path
        );
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "%s", king_object_store_runtime.distributed_coordinator_state_error);
        }
        return FAILURE;
    }

    if (stat(state_path, &state_stat) != 0) {
        time_t now = time(NULL);
        version = 1;
        generation = (uint64_t) now;
        created_at = now;

        if (king_object_store_distributed_coordinator_write_state(
                state_path,
                version,
                generation,
                created_at
            ) != SUCCESS) {
            snprintf(
                king_object_store_runtime.distributed_coordinator_state_status,
                sizeof(king_object_store_runtime.distributed_coordinator_state_status),
                "%s",
                king_object_store_distributed_state_status_failed
            );
            snprintf(
                king_object_store_runtime.distributed_coordinator_state_error,
                sizeof(king_object_store_runtime.distributed_coordinator_state_error),
                "Could not persist distributed coordinator state at '%s'.",
                state_path
            );
            if (error != NULL && error_size > 0) {
                snprintf(error, error_size, "%s", king_object_store_runtime.distributed_coordinator_state_error);
            }
            return FAILURE;
        }

        king_object_store_runtime.distributed_coordinator_state_present = 1;
        king_object_store_runtime.distributed_coordinator_state_recovered = 0;
        king_object_store_runtime.distributed_coordinator_state_version = version;
        king_object_store_runtime.distributed_coordinator_generation = generation;
        king_object_store_runtime.distributed_coordinator_created_at = created_at;
        king_object_store_runtime.distributed_coordinator_last_loaded_at = now;
        snprintf(
            king_object_store_runtime.distributed_coordinator_state_status,
            sizeof(king_object_store_runtime.distributed_coordinator_state_status),
            "%s",
            king_object_store_distributed_state_status_initialized
        );
        return SUCCESS;
    }

    if (king_object_store_distributed_coordinator_load_state(
            state_path,
            &version,
            &generation,
            &created_at
        ) != SUCCESS) {
        snprintf(
            king_object_store_runtime.distributed_coordinator_state_status,
            sizeof(king_object_store_runtime.distributed_coordinator_state_status),
            "%s",
            king_object_store_distributed_state_status_failed
        );
        snprintf(
            king_object_store_runtime.distributed_coordinator_state_error,
            sizeof(king_object_store_runtime.distributed_coordinator_state_error),
            "Distributed coordinator state at '%s' is unreadable or corrupted.",
            state_path
        );
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "%s", king_object_store_runtime.distributed_coordinator_state_error);
        }
        return FAILURE;
    }

    king_object_store_runtime.distributed_coordinator_state_present = 1;
    king_object_store_runtime.distributed_coordinator_state_recovered = 1;
    king_object_store_runtime.distributed_coordinator_state_version = version;
    king_object_store_runtime.distributed_coordinator_generation = generation;
    king_object_store_runtime.distributed_coordinator_created_at = created_at;
    king_object_store_runtime.distributed_coordinator_last_loaded_at = time(NULL);
    snprintf(
        king_object_store_runtime.distributed_coordinator_state_status,
        sizeof(king_object_store_runtime.distributed_coordinator_state_status),
        "%s",
        king_object_store_distributed_state_status_recovered
    );
    return SUCCESS;
}
static int king_object_store_azure_remove(const char *object_id);
static int king_object_store_azure_delete_backup_copy(
    const char *object_id,
    char *error,
    size_t error_size,
    zend_bool *removed_out
);
static int king_object_store_azure_list(zval *return_array);
static void king_object_store_shutdown_libcurl_runtime(void);
static int king_object_store_backend_is_real(king_storage_backend_t backend);
static king_storage_backend_t king_object_store_normalize_backend(king_storage_backend_t backend);
static void king_object_store_update_replication_status(const char *object_id, uint8_t replication_status);
static void king_object_store_invalidate_cdn_cache_entry(const char *object_id);
static uint32_t king_object_store_count_achieved_real_copies(const king_object_metadata_t *metadata);
static void king_object_store_reconcile_replication_status(king_object_metadata_t *metadata);
static void king_object_store_metadata_mark_backend_present(
    king_object_metadata_t *metadata,
    king_storage_backend_t backend,
    uint8_t present
);
static void king_object_store_set_backend_runtime_result(
    const char *scope,
    king_storage_backend_t backend,
    int status,
    const char *error_message
);
static int king_object_store_require_honest_backend(
    const char *scope,
    king_storage_backend_t backend,
    const char *operation_name
);
static int king_object_store_local_fs_remove_with_real_backup_semantics(const char *object_id);
static struct curl_slist *king_object_store_append_metadata_headers(
    struct curl_slist *headers,
    const char *prefix,
    const king_object_metadata_t *metadata
);
static void king_object_store_list_entry_from_metadata(
    zval *entry,
    const char *object_id,
    const king_object_metadata_t *metadata,
    uint64_t fallback_size,
    time_t fallback_timestamp
);
static void king_object_store_finalize_metadata_for_write(
    const char *object_id,
    uint64_t data_size,
    const char *integrity_sha256,
    king_object_metadata_t *metadata,
    const king_object_metadata_t *existing_metadata
);
static size_t king_object_store_streaming_chunk_bytes(void);
static int king_object_store_copy_path_to_stream(
    const char *source_path,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length
);
static int king_object_store_compute_sha256_hex_for_path(
    const char *source_path,
    char output[65],
    uint64_t *size_out
);
static int king_object_store_seed_sha256_ctx_from_path(
    const char *source_path,
    PHP_SHA256_CTX *ctx,
    uint64_t *size_out
);
static int king_object_store_create_temp_file_path(char *destination, size_t destination_size);
static int king_object_store_build_upload_session_directory(
    char *destination,
    size_t destination_size
);
static int king_object_store_build_upload_session_path(
    const char *upload_id,
    const char *suffix,
    char *destination,
    size_t destination_size
);
static int king_object_store_upload_session_assign_paths(king_object_store_upload_session_t *session);
static int king_object_store_upload_session_persist(
    const king_object_store_upload_session_t *session,
    char *error,
    size_t error_size
);
static void king_object_store_upload_session_remove_persisted_state(
    const king_object_store_upload_session_t *session
);
static int king_object_store_rehydrate_upload_sessions(char *error, size_t error_size);
static int king_object_store_build_lock_path(
    const char *object_id,
    char *destination,
    size_t destination_size
);
static int king_object_store_backup_object_from_file_to_backend(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    king_storage_backend_t backup_backend
);
static int king_object_store_local_fs_restore_payload_from_path(
    const char *object_id,
    const char *source_path
);
static int king_object_store_runtime_capacity_resolve_existing_size(
    const char *object_id,
    uint64_t *old_size_out,
    zend_bool *had_existing_object_out
);
static zend_bool king_object_store_runtime_capacity_allows_rewrite(
    uint64_t new_size,
    zend_bool had_existing_object,
    uint64_t old_size,
    uint64_t *projected_total_out
);
static int king_object_store_apply_local_fs_counters_for_rewrite(
    const char *object_id,
    uint64_t new_size,
    int had_existing_object,
    uint64_t old_size
);
static int king_object_store_local_fs_read_fallback_from_cloud_backup_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata
);
static zend_result king_object_store_fill_random_bytes(uint8_t *target, size_t target_len);
static int king_object_store_upload_sessions_ensure(void);
static void king_object_store_upload_sessions_reset(void);
static void king_object_store_upload_session_export_status(
    const king_object_store_upload_session_t *session,
    king_object_store_upload_status_t *status_out
);
static int king_object_store_upload_session_store(const king_object_store_upload_session_t *session);
static king_object_store_upload_session_t *king_object_store_upload_session_find(const char *upload_id);
static void king_object_store_upload_session_destroy_ptr(king_object_store_upload_session_t *session);
static int king_object_store_append_path_to_file_and_hash(
    const char *source_path,
    const char *destination_path,
    PHP_SHA256_CTX *sha_ctx,
    uint64_t *bytes_appended_out
);
static int king_object_store_finalize_completed_upload_session(
    king_object_store_upload_session_t *session,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
);
static int king_object_store_s3_begin_upload_session(
    const char *object_id,
    const king_object_metadata_t *metadata,
    char *provider_token,
    size_t provider_token_size,
    char *error,
    size_t error_size
);
static int king_object_store_s3_append_upload_chunk(
    const char *object_id,
    const char *provider_token,
    uint32_t part_number,
    const char *source_path,
    uint64_t chunk_size,
    char *part_token,
    size_t part_token_size,
    char *error,
    size_t error_size
);
static int king_object_store_s3_complete_upload_session(
    const char *object_id,
    const char *provider_token,
    const HashTable *provider_parts,
    char *error,
    size_t error_size
);
static int king_object_store_s3_abort_upload_session(
    const char *object_id,
    const char *provider_token,
    char *error,
    size_t error_size
);
static int king_object_store_gcs_begin_upload_session(
    const char *object_id,
    const king_object_metadata_t *metadata,
    char *provider_token,
    size_t provider_token_size,
    char *error,
    size_t error_size
);
static int king_object_store_gcs_append_upload_chunk(
    const char *provider_token,
    const char *source_path,
    uint64_t chunk_size,
    uint64_t offset,
    zend_bool is_final_chunk,
    uint64_t final_size,
    zend_bool *remote_completed_out,
    char *error,
    size_t error_size
);
static int king_object_store_gcs_abort_upload_session(
    const char *provider_token,
    char *error,
    size_t error_size
);
static int king_object_store_azure_begin_upload_session(
    const char *object_id,
    const king_object_metadata_t *metadata,
    char *provider_token,
    size_t provider_token_size,
    char *error,
    size_t error_size
);
static int king_object_store_azure_append_upload_chunk(
    const char *object_id,
    uint32_t part_number,
    const char *source_path,
    uint64_t chunk_size,
    char *part_token,
    size_t part_token_size,
    char *error,
    size_t error_size
);
static int king_object_store_azure_complete_upload_session(
    const char *object_id,
    const HashTable *provider_parts,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size
);

void king_object_store_compute_sha256_hex(const void *data, size_t data_size, char output[65])
{
    PHP_SHA256_CTX ctx;
    unsigned char digest[32];
    static const char hex[] = "0123456789abcdef";
    size_t i;

    if (output == NULL) {
        return;
    }

    PHP_SHA256Init(&ctx);
    if (data_size > 0 && data != NULL) {
        PHP_SHA256Update(&ctx, (const unsigned char *) data, data_size);
    }
    PHP_SHA256Final(digest, &ctx);

    for (i = 0; i < sizeof(digest); ++i) {
        output[(i * 2)] = hex[(digest[i] >> 4) & 0x0f];
        output[(i * 2) + 1] = hex[digest[i] & 0x0f];
    }
    output[64] = '\0';
}

static void king_object_store_prepare_metadata_for_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    king_object_metadata_t *metadata,
    const king_object_metadata_t *existing_metadata
)
{
    char computed_sha256[65];

    king_object_store_compute_sha256_hex(data, data_size, computed_sha256);
    king_object_store_finalize_metadata_for_write(
        object_id,
        (uint64_t) data_size,
        computed_sha256,
        metadata,
        existing_metadata
    );
}

static void king_object_store_finalize_metadata_for_write(
    const char *object_id,
    uint64_t data_size,
    const char *integrity_sha256,
    king_object_metadata_t *metadata,
    const king_object_metadata_t *existing_metadata
)
{
    time_t now;

    if (metadata == NULL || object_id == NULL) {
        return;
    }

    now = time(NULL);

    if (metadata->object_id[0] == '\0') {
        strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
    }
    if (metadata->content_length == 0) {
        metadata->content_length = data_size;
    }
    if (metadata->created_at == 0) {
        metadata->created_at = existing_metadata != NULL && existing_metadata->created_at > 0
            ? existing_metadata->created_at
            : now;
    }
    metadata->modified_at = now;
    if (metadata->version == 0) {
        if (existing_metadata != NULL) {
            metadata->version = existing_metadata->version > 0
                ? existing_metadata->version + 1
                : 2;
        } else {
            metadata->version = 1;
        }
    }
    if (metadata->integrity_sha256[0] == '\0' && integrity_sha256 != NULL && integrity_sha256[0] != '\0') {
        strncpy(metadata->integrity_sha256, integrity_sha256, sizeof(metadata->integrity_sha256) - 1);
    }
    if (metadata->etag[0] == '\0') {
        strncpy(metadata->etag, metadata->integrity_sha256, sizeof(metadata->etag) - 1);
    }
    if (metadata->content_type[0] == '\0') {
        strncpy(metadata->content_type, "application/octet-stream", sizeof(metadata->content_type) - 1);
    }
}

static size_t king_object_store_streaming_chunk_bytes(void)
{
    size_t chunk_bytes = (size_t) king_object_store_runtime.config.chunk_size_kb * 1024U;

    if (chunk_bytes == 0) {
        chunk_bytes = 64 * 1024;
    }

    return chunk_bytes;
}

static int king_object_store_copy_path_to_stream(
    const char *source_path,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length
)
{
    FILE *source_fp;
    unsigned char *buffer;
    size_t chunk_bytes;
    uint64_t remaining;

    if (source_path == NULL || destination_stream == NULL) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        return FAILURE;
    }
    if (fseeko(source_fp, (off_t) offset, SEEK_SET) != 0) {
        fclose(source_fp);
        return FAILURE;
    }

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);
    remaining = has_length ? (uint64_t) length : UINT64_MAX;

    while (remaining > 0) {
        size_t want = chunk_bytes;
        size_t bytes_read;
        size_t bytes_written;

        if (has_length && remaining < (uint64_t) want) {
            want = (size_t) remaining;
        }

        bytes_read = fread(buffer, 1, want, source_fp);
        if (bytes_read == 0) {
            if (feof(source_fp)) {
                break;
            }
            efree(buffer);
            fclose(source_fp);
            return FAILURE;
        }

        bytes_written = php_stream_write(destination_stream, (char *) buffer, bytes_read);
        if (bytes_written != bytes_read) {
            efree(buffer);
            fclose(source_fp);
            return FAILURE;
        }

        if (has_length) {
            remaining -= (uint64_t) bytes_read;
        }
    }

    efree(buffer);
    fclose(source_fp);
    return SUCCESS;
}

static int king_object_store_backup_object_from_file_to_backend(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    king_storage_backend_t backup_backend
)
{
    int rc = FAILURE;
    king_object_metadata_t metadata_snapshot;
    char backup_error[512] = {0};

    if (object_id == NULL || object_id[0] == '\0' || source_path == NULL) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Missing object payload for backup operation."
        );
        return FAILURE;
    }

    if (!king_object_store_runtime.initialized) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Object store is not initialized."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "backup",
            backup_backend,
            "object backup") == FAILURE) {
        return FAILURE;
    }

    switch (backup_backend) {
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_write_from_file_internal(
                object_id,
                source_path,
                metadata,
                0,
                backup_error,
                sizeof(backup_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_write_from_file(
                object_id,
                source_path,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_write_from_file(
                object_id,
                source_path,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_write_from_file(
                object_id,
                source_path,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Local backup backends are not implemented for a non-local primary object-store backend."
            );
            return FAILURE;
        default:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Unsupported backup object-store backend."
            );
            return FAILURE;
    }

    if (rc != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            backup_error[0] == '\0' ? "Backup object-store backend write failed." : backup_error
        );
        return FAILURE;
    }

    if (king_object_store_backend_read_metadata(object_id, &metadata_snapshot) == SUCCESS) {
        if (metadata_snapshot.is_backed_up == 0) {
            metadata_snapshot.is_backed_up = 1;
        }
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            king_object_store_runtime.config.primary_backend,
            1
        );
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            backup_backend,
            1
        );
        king_object_store_meta_write(object_id, &metadata_snapshot);
    }

    king_object_store_set_backend_runtime_result(
        "backup",
        backup_backend,
        SUCCESS,
        NULL
    );
    return SUCCESS;
}

static int king_object_store_compute_sha256_hex_for_path(
    const char *source_path,
    char output[65],
    uint64_t *size_out
)
{
    FILE *source_fp;
    unsigned char *buffer;
    unsigned char digest[32];
    PHP_SHA256_CTX ctx;
    uint64_t total = 0;
    size_t chunk_bytes;
    static const char hex[] = "0123456789abcdef";
    size_t i;

    if (source_path == NULL || output == NULL) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        return FAILURE;
    }

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);
    PHP_SHA256Init(&ctx);

    while (1) {
        size_t bytes_read = fread(buffer, 1, chunk_bytes, source_fp);

        if (bytes_read == 0) {
            if (feof(source_fp)) {
                break;
            }
            efree(buffer);
            fclose(source_fp);
            return FAILURE;
        }

        PHP_SHA256Update(&ctx, buffer, bytes_read);
        total += (uint64_t) bytes_read;
    }

    PHP_SHA256Final(digest, &ctx);
    efree(buffer);
    fclose(source_fp);

    for (i = 0; i < sizeof(digest); ++i) {
        output[i * 2] = hex[(digest[i] >> 4) & 0x0f];
        output[(i * 2) + 1] = hex[digest[i] & 0x0f];
    }
    output[64] = '\0';

    if (size_out != NULL) {
        *size_out = total;
    }
    return SUCCESS;
}

static int king_object_store_seed_sha256_ctx_from_path(
    const char *source_path,
    PHP_SHA256_CTX *ctx,
    uint64_t *size_out
)
{
    FILE *source_fp;
    unsigned char *buffer;
    uint64_t total = 0;
    size_t chunk_bytes;

    if (source_path == NULL || ctx == NULL) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        return FAILURE;
    }

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);
    PHP_SHA256Init(ctx);

    while (1) {
        size_t bytes_read = fread(buffer, 1, chunk_bytes, source_fp);

        if (bytes_read == 0) {
            if (feof(source_fp)) {
                break;
            }
            efree(buffer);
            fclose(source_fp);
            return FAILURE;
        }

        PHP_SHA256Update(ctx, buffer, bytes_read);
        total += (uint64_t) bytes_read;
    }

    efree(buffer);
    fclose(source_fp);

    if (size_out != NULL) {
        *size_out = total;
    }
    return SUCCESS;
}

static int king_object_store_build_upload_session_directory(
    char *destination,
    size_t destination_size
)
{
    const char *base_directory;

    if (destination == NULL || destination_size == 0) {
        return FAILURE;
    }

    base_directory = king_object_store_runtime.config.storage_root_path[0] != '\0'
        ? king_object_store_runtime.config.storage_root_path
        : "/tmp";
    if (snprintf(destination, destination_size, "%s/.king_upload_sessions", base_directory) >= (int) destination_size) {
        return FAILURE;
    }
    return SUCCESS;
}

static int king_object_store_build_upload_session_path(
    const char *upload_id,
    const char *suffix,
    char *destination,
    size_t destination_size
)
{
    char directory[PATH_MAX];

    if (upload_id == NULL || upload_id[0] == '\0' || suffix == NULL
        || destination == NULL || destination_size == 0) {
        return FAILURE;
    }
    if (king_object_store_build_upload_session_directory(directory, sizeof(directory)) != SUCCESS) {
        return FAILURE;
    }
    if (snprintf(destination, destination_size, "%s/%s%s", directory, upload_id, suffix) >= (int) destination_size) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_upload_session_assign_paths(king_object_store_upload_session_t *session)
{
    char directory[PATH_MAX];

    if (session == NULL || session->upload_id[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_build_upload_session_directory(directory, sizeof(directory)) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_ensure_directory_recursive(directory) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_build_upload_session_path(
            session->upload_id,
            ".assembly",
            session->assembly_path,
            sizeof(session->assembly_path)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_build_upload_session_path(
            session->upload_id,
            ".state",
            session->state_path,
            sizeof(session->state_path)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_build_upload_session_path(
            session->upload_id,
            ".metadata",
            session->metadata_path,
            sizeof(session->metadata_path)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_build_upload_session_path(
            session->upload_id,
            ".existing",
            session->existing_metadata_path,
            sizeof(session->existing_metadata_path)
        ) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_create_temp_file_path(char *destination, size_t destination_size)
{
    const char *base_directory;
    int temp_fd;

    if (destination == NULL || destination_size == 0) {
        return FAILURE;
    }

    base_directory = king_object_store_runtime.config.storage_root_path[0] != '\0'
        ? king_object_store_runtime.config.storage_root_path
        : "/tmp";
    if (snprintf(destination, destination_size, "%s/.king_object_tmp_XXXXXX", base_directory) >= (int) destination_size) {
        return FAILURE;
    }

    temp_fd = mkstemp(destination);
    if (temp_fd < 0) {
        destination[0] = '\0';
        return FAILURE;
    }
    close(temp_fd);
    return SUCCESS;
}

static int king_object_store_build_lock_path(
    const char *object_id,
    char *destination,
    size_t destination_size
)
{
    char lock_directory[1024];

    if (object_id == NULL || destination == NULL || destination_size == 0) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    if (snprintf(
            lock_directory,
            sizeof(lock_directory),
            "%s/.king_object_locks",
            king_object_store_runtime.config.storage_root_path[0] != '\0'
                ? king_object_store_runtime.config.storage_root_path
                : "/tmp"
        ) >= (int) sizeof(lock_directory)) {
        return FAILURE;
    }
    if (king_object_store_ensure_directory_recursive(lock_directory) != SUCCESS) {
        return FAILURE;
    }
    if (snprintf(
            destination,
            destination_size,
            "%s/%s.lock",
            lock_directory,
            object_id
        ) >= (int) destination_size) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_bool king_object_store_verify_integrity_buffer(
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata
)
{
    char computed[65];

    if (metadata == NULL || metadata->integrity_sha256[0] == '\0') {
        return 1;
    }

    king_object_store_compute_sha256_hex(data, data_size, computed);
    return strcmp(computed, metadata->integrity_sha256) == 0;
}

static void king_object_store_runtime_metadata_cache_entry_free(king_object_metadata_t *metadata)
{
    if (metadata == NULL) {
        return;
    }

    pefree(metadata, 1);
}

static void king_object_store_runtime_metadata_cache_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_object_store_runtime_metadata_cache_entry_free((king_object_metadata_t *) Z_PTR_P(zv));
    }
}

static int king_object_store_runtime_metadata_cache_ensure(void)
{
    if (king_object_store_runtime_metadata_cache_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_object_store_runtime_metadata_cache,
        8,
        NULL,
        king_object_store_runtime_metadata_cache_zval_dtor,
        1
    );
    king_object_store_runtime_metadata_cache_initialized = true;
    return SUCCESS;
}

static void king_object_store_runtime_metadata_cache_reset(void)
{
    if (!king_object_store_runtime_metadata_cache_initialized) {
        return;
    }

    zend_hash_destroy(&king_object_store_runtime_metadata_cache);
    king_object_store_runtime_metadata_cache_initialized = false;
}

static void king_object_store_runtime_metadata_cache_clear(void)
{
    if (!king_object_store_runtime_metadata_cache_initialized) {
        return;
    }

    zend_hash_clean(&king_object_store_runtime_metadata_cache);
}

static int king_object_store_runtime_metadata_cache_store(
    const char *object_id,
    const king_object_metadata_t *metadata
)
{
    zend_string *persistent_key;
    king_object_metadata_t *copy;
    zval stored_metadata;

    if (object_id == NULL || object_id[0] == '\0' || metadata == NULL) {
        return FAILURE;
    }

    if (king_object_store_runtime_metadata_cache_ensure() != SUCCESS) {
        return FAILURE;
    }

    copy = pemalloc(sizeof(*copy), 1);
    if (copy == NULL) {
        return FAILURE;
    }
    *copy = *metadata;

    ZVAL_PTR(&stored_metadata, copy);
    persistent_key = zend_string_init(object_id, strlen(object_id), 1);
    zend_hash_update(&king_object_store_runtime_metadata_cache, persistent_key, &stored_metadata);
    zend_string_release_ex(persistent_key, 1);

    return SUCCESS;
}

int king_object_store_runtime_metadata_cache_read(
    const char *object_id,
    king_object_metadata_t *metadata
)
{
    zval *cached_entry;

    if (object_id == NULL || metadata == NULL || !king_object_store_runtime_metadata_cache_initialized) {
        return FAILURE;
    }

    cached_entry = zend_hash_str_find(
        &king_object_store_runtime_metadata_cache,
        object_id,
        strlen(object_id)
    );
    if (cached_entry == NULL || Z_TYPE_P(cached_entry) != IS_PTR || Z_PTR_P(cached_entry) == NULL) {
        return FAILURE;
    }

    *metadata = *((king_object_metadata_t *) Z_PTR_P(cached_entry));
    return SUCCESS;
}

static void king_object_store_runtime_metadata_cache_remove(const char *object_id)
{
    if (object_id == NULL || !king_object_store_runtime_metadata_cache_initialized) {
        return;
    }

    zend_hash_str_del(
        &king_object_store_runtime_metadata_cache,
        object_id,
        strlen(object_id)
    );
}

static zend_result king_object_store_fill_random_bytes(uint8_t *target, size_t target_len)
{
    int fd;
    size_t total = 0;

    if (target == NULL || target_len == 0) {
        return FAILURE;
    }

    fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (fd < 0) {
        return FAILURE;
    }

    while (total < target_len) {
        ssize_t chunk = read(fd, target + total, target_len - total);

        if (chunk <= 0) {
            close(fd);
            return FAILURE;
        }

        total += (size_t) chunk;
    }

    close(fd);
    return SUCCESS;
}

int king_object_store_acquire_object_lock(
    const char *object_id,
    int *lock_fd_out,
    char *error,
    size_t error_size
)
{
    char lock_path[1024];
    int fd;

    if (lock_fd_out != NULL) {
        *lock_fd_out = -1;
    }
    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }

    if (!king_object_store_runtime.initialized || object_id == NULL || object_id[0] == '\0') {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Object-store runtime is unavailable for mutation locking.");
        }
        return FAILURE;
    }
    if (king_object_store_build_lock_path(object_id, lock_path, sizeof(lock_path)) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to prepare the object-store mutation lock for '%s'.", object_id);
        }
        return FAILURE;
    }

    fd = open(lock_path, O_RDWR | O_CREAT | O_CLOEXEC, 0666);
    if (fd < 0) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to open the object-store mutation lock for '%s'.", object_id);
        }
        return FAILURE;
    }

    if (flock(fd, LOCK_EX | LOCK_NB) != 0) {
        if (error != NULL && error_size > 0) {
            if (errno == EWOULDBLOCK || errno == EAGAIN) {
                snprintf(error, error_size, "Object-store object '%s' already has an active mutation.", object_id);
            } else {
                snprintf(error, error_size, "Failed to acquire the object-store mutation lock for '%s'.", object_id);
            }
        }
        close(fd);
        return FAILURE;
    }

    if (lock_fd_out != NULL) {
        *lock_fd_out = fd;
    } else {
        close(fd);
        return FAILURE;
    }

    return SUCCESS;
}

void king_object_store_release_object_lock(int *lock_fd)
{
    if (lock_fd == NULL || *lock_fd < 0) {
        return;
    }

    (void) flock(*lock_fd, LOCK_UN);
    close(*lock_fd);
    *lock_fd = -1;
}

static void king_object_store_upload_session_destroy_ptr(king_object_store_upload_session_t *session)
{
    if (session == NULL) {
        return;
    }

    if (session->lock_fd >= 0) {
        king_object_store_release_object_lock(&session->lock_fd);
    }

    if (session->completed || session->aborted) {
        king_object_store_upload_session_remove_persisted_state(session);
    }

    zend_hash_destroy(&session->provider_parts);
    efree(session);
}

static void king_object_store_upload_session_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_object_store_upload_session_destroy_ptr((king_object_store_upload_session_t *) Z_PTR_P(zv));
    }
}

static int king_object_store_upload_sessions_ensure(void)
{
    if (king_object_store_upload_sessions_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_object_store_upload_sessions,
        8,
        NULL,
        king_object_store_upload_session_zval_dtor,
        0
    );
    king_object_store_upload_sessions_initialized = true;
    return SUCCESS;
}

static void king_object_store_upload_sessions_reset(void)
{
    if (!king_object_store_upload_sessions_initialized) {
        return;
    }

    zend_hash_destroy(&king_object_store_upload_sessions);
    king_object_store_upload_sessions_initialized = false;
}

static int king_object_store_upload_session_store(const king_object_store_upload_session_t *session)
{
    zval stored_session;

    if (session == NULL || session->upload_id[0] == '\0') {
        return FAILURE;
    }

    if (king_object_store_upload_sessions_ensure() != SUCCESS) {
        return FAILURE;
    }

    ZVAL_PTR(&stored_session, (void *) session);
    zend_hash_str_update(
        &king_object_store_upload_sessions,
        session->upload_id,
        strlen(session->upload_id),
        &stored_session
    );
    return SUCCESS;
}

static king_object_store_upload_session_t *king_object_store_upload_session_find(const char *upload_id)
{
    zval *entry;

    if (upload_id == NULL || upload_id[0] == '\0' || !king_object_store_upload_sessions_initialized) {
        return NULL;
    }

    entry = zend_hash_str_find(
        &king_object_store_upload_sessions,
        upload_id,
        strlen(upload_id)
    );
    if (entry == NULL || Z_TYPE_P(entry) != IS_PTR || Z_PTR_P(entry) == NULL) {
        return NULL;
    }

    return (king_object_store_upload_session_t *) Z_PTR_P(entry);
}

static int king_object_store_upload_session_persist(
    const king_object_store_upload_session_t *session,
    char *error,
    size_t error_size
)
{
    FILE *fp = NULL;
    char temp_path[PATH_MAX];
    int temp_fd = -1;
    zval *part_value;
    zend_ulong part_number;

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    if (session == NULL || session->state_path[0] == '\0') {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to persist object-store upload session state.");
        }
        return FAILURE;
    }

    if (snprintf(temp_path, sizeof(temp_path), "%s.XXXXXX", session->state_path) >= (int) sizeof(temp_path)) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to prepare a temporary object-store upload state file.");
        }
        return FAILURE;
    }

    temp_fd = mkstemp(temp_path);
    if (temp_fd < 0) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to create a temporary object-store upload state file.");
        }
        return FAILURE;
    }

    fp = fdopen(temp_fd, "wb");
    if (fp == NULL) {
        close(temp_fd);
        unlink(temp_path);
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to open a temporary object-store upload state file.");
        }
        return FAILURE;
    }
    temp_fd = -1;

    fprintf(fp,
        "upload_id=%s\n"
        "object_id=%s\n"
        "provider_token=%s\n"
        "expected_integrity_sha256=%s\n"
        "backend=%d\n"
        "protocol=%d\n"
        "uploaded_bytes=%" PRIu64 "\n"
        "next_offset=%" PRIu64 "\n"
        "old_size=%" PRIu64 "\n"
        "chunk_size_bytes=%" PRIu64 "\n"
        "next_part_number=%u\n"
        "created_at=%" PRId64 "\n"
        "updated_at=%" PRId64 "\n"
        "existed_before=%d\n"
        "sequential_chunks_required=%d\n"
        "final_chunk_may_be_shorter=%d\n"
        "final_chunk_received=%d\n"
        "remote_completed=%d\n"
        "recovered_after_restart=%d\n"
        "completed=%d\n"
        "aborted=%d\n",
        session->upload_id,
        session->object_id,
        session->provider_token,
        session->expected_integrity_sha256,
        (int) session->backend,
        (int) session->protocol,
        (uint64_t) session->uploaded_bytes,
        (uint64_t) session->next_offset,
        (uint64_t) session->old_size,
        (uint64_t) session->chunk_size_bytes,
        (unsigned) session->next_part_number,
        (int64_t) session->created_at,
        (int64_t) session->updated_at,
        (int) session->existed_before,
        (int) session->sequential_chunks_required,
        (int) session->final_chunk_may_be_shorter,
        (int) session->final_chunk_received,
        (int) session->remote_completed,
        (int) session->recovered_after_restart,
        (int) session->completed,
        (int) session->aborted
    );

    ZEND_HASH_FOREACH_NUM_KEY_VAL(&session->provider_parts, part_number, part_value) {
        if (Z_TYPE_P(part_value) != IS_STRING) {
            continue;
        }
        fprintf(fp, "part.%lu=%s\n", (unsigned long) part_number, Z_STRVAL_P(part_value));
    } ZEND_HASH_FOREACH_END();

    if (fclose(fp) != 0) {
        unlink(temp_path);
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to flush the object-store upload state file.");
        }
        return FAILURE;
    }

    if (rename(temp_path, session->state_path) != 0) {
        unlink(temp_path);
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to commit the object-store upload state file.");
        }
        return FAILURE;
    }

    if (king_object_store_meta_write_to_path(session->metadata_path, &session->metadata) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to persist object-store upload metadata state.");
        }
        return FAILURE;
    }
    if (session->existed_before) {
        if (king_object_store_meta_write_to_path(
                session->existing_metadata_path,
                &session->existing_metadata
            ) != SUCCESS) {
            if (error != NULL && error_size > 0) {
                snprintf(error, error_size, "Failed to persist object-store upload existing-object metadata.");
            }
            return FAILURE;
        }
    } else {
        unlink(session->existing_metadata_path);
    }

    return SUCCESS;
}

static void king_object_store_upload_session_remove_persisted_state(
    const king_object_store_upload_session_t *session
)
{
    if (session == NULL) {
        return;
    }

    if (session->state_path[0] != '\0') {
        unlink(session->state_path);
    }
    if (session->metadata_path[0] != '\0') {
        unlink(session->metadata_path);
    }
    if (session->existing_metadata_path[0] != '\0') {
        unlink(session->existing_metadata_path);
    }
    if (session->assembly_path[0] != '\0') {
        unlink(session->assembly_path);
    }
}

static void king_object_store_upload_session_export_status(
    const king_object_store_upload_session_t *session,
    king_object_store_upload_status_t *status_out
)
{
    if (status_out == NULL) {
        return;
    }

    memset(status_out, 0, sizeof(*status_out));
    if (session == NULL) {
        return;
    }

    snprintf(status_out->upload_id, sizeof(status_out->upload_id), "%s", session->upload_id);
    snprintf(status_out->object_id, sizeof(status_out->object_id), "%s", session->object_id);
    status_out->backend = session->backend;
    status_out->protocol = session->protocol;
    status_out->uploaded_bytes = session->uploaded_bytes;
    status_out->next_offset = session->next_offset;
    status_out->chunk_size_bytes = session->chunk_size_bytes;
    status_out->next_part_number = session->next_part_number;
    status_out->uploaded_part_count = session->next_part_number > 0 ? session->next_part_number - 1 : 0;
    status_out->created_at = session->created_at;
    status_out->updated_at = session->updated_at;
    status_out->sequential_chunks_required = session->sequential_chunks_required;
    status_out->final_chunk_may_be_shorter = session->final_chunk_may_be_shorter;
    status_out->final_chunk_received = session->final_chunk_received;
    status_out->remote_completed = session->remote_completed;
    status_out->recovered_after_restart = session->recovered_after_restart;
    status_out->completed = session->completed;
    status_out->aborted = session->aborted;
}

static int king_object_store_rehydrate_upload_sessions(char *error, size_t error_size)
{
    DIR *dir;
    struct dirent *ent;
    char session_directory[PATH_MAX];

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }

    if (king_object_store_upload_sessions_ensure() != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to initialize the object-store upload-session registry.");
        }
        return FAILURE;
    }
    if (king_object_store_build_upload_session_directory(
            session_directory,
            sizeof(session_directory)
        ) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to prepare the object-store upload-session directory.");
        }
        return FAILURE;
    }

    dir = opendir(session_directory);
    if (dir == NULL) {
        return SUCCESS;
    }

    while ((ent = readdir(dir)) != NULL) {
        FILE *fp = NULL;
        size_t name_len;
        char state_path[PATH_MAX];
        char line[2048];
        king_object_store_upload_session_t *session = NULL;
        int session_lock_fd = -1;
        char lock_error[512];
        uint64_t assembly_size = 0;

        if (ent->d_name[0] == '.') {
            continue;
        }

        name_len = strlen(ent->d_name);
        if (name_len <= 6 || strcmp(ent->d_name + name_len - 6, ".state") != 0) {
            continue;
        }
        if (snprintf(state_path, sizeof(state_path), "%s/%s", session_directory, ent->d_name) >= (int) sizeof(state_path)) {
            continue;
        }

        fp = fopen(state_path, "r");
        if (fp == NULL) {
            continue;
        }

        session = ecalloc(1, sizeof(*session));
        session->lock_fd = -1;
        zend_hash_init(&session->provider_parts, 8, NULL, ZVAL_PTR_DTOR, 0);

        while (fgets(line, sizeof(line), fp) != NULL) {
            char *eq = strchr(line, '=');
            char *key;
            char *val;
            size_t vlen;

            if (eq == NULL) {
                continue;
            }

            *eq = '\0';
            key = line;
            val = eq + 1;
            vlen = strlen(val);
            if (vlen > 0 && val[vlen - 1] == '\n') {
                val[vlen - 1] = '\0';
            }

            if (strcmp(key, "upload_id") == 0) {
                snprintf(session->upload_id, sizeof(session->upload_id), "%s", val);
            } else if (strcmp(key, "object_id") == 0) {
                snprintf(session->object_id, sizeof(session->object_id), "%s", val);
            } else if (strcmp(key, "provider_token") == 0) {
                snprintf(session->provider_token, sizeof(session->provider_token), "%s", val);
            } else if (strcmp(key, "expected_integrity_sha256") == 0) {
                snprintf(session->expected_integrity_sha256, sizeof(session->expected_integrity_sha256), "%s", val);
            } else if (strcmp(key, "backend") == 0) {
                session->backend = (king_storage_backend_t) atoi(val);
            } else if (strcmp(key, "protocol") == 0) {
                session->protocol = (king_object_store_upload_protocol_t) atoi(val);
            } else if (strcmp(key, "uploaded_bytes") == 0) {
                session->uploaded_bytes = (uint64_t) strtoull(val, NULL, 10);
            } else if (strcmp(key, "next_offset") == 0) {
                session->next_offset = (uint64_t) strtoull(val, NULL, 10);
            } else if (strcmp(key, "old_size") == 0) {
                session->old_size = (uint64_t) strtoull(val, NULL, 10);
            } else if (strcmp(key, "chunk_size_bytes") == 0) {
                session->chunk_size_bytes = (uint64_t) strtoull(val, NULL, 10);
            } else if (strcmp(key, "next_part_number") == 0) {
                session->next_part_number = (uint32_t) strtoul(val, NULL, 10);
            } else if (strcmp(key, "created_at") == 0) {
                session->created_at = (time_t) strtoll(val, NULL, 10);
            } else if (strcmp(key, "updated_at") == 0) {
                session->updated_at = (time_t) strtoll(val, NULL, 10);
            } else if (strcmp(key, "existed_before") == 0) {
                session->existed_before = atoi(val) != 0;
            } else if (strcmp(key, "sequential_chunks_required") == 0) {
                session->sequential_chunks_required = atoi(val) != 0;
            } else if (strcmp(key, "final_chunk_may_be_shorter") == 0) {
                session->final_chunk_may_be_shorter = atoi(val) != 0;
            } else if (strcmp(key, "final_chunk_received") == 0) {
                session->final_chunk_received = atoi(val) != 0;
            } else if (strcmp(key, "remote_completed") == 0) {
                session->remote_completed = atoi(val) != 0;
            } else if (strcmp(key, "recovered_after_restart") == 0) {
                session->recovered_after_restart = atoi(val) != 0;
            } else if (strcmp(key, "completed") == 0) {
                session->completed = atoi(val) != 0;
            } else if (strcmp(key, "aborted") == 0) {
                session->aborted = atoi(val) != 0;
            } else if (strncmp(key, "part.", sizeof("part.") - 1) == 0) {
                zend_ulong part_number = (zend_ulong) strtoul(key + (sizeof("part.") - 1), NULL, 10);
                zval stored_part;

                ZVAL_STRING(&stored_part, val);
                zend_hash_index_update(&session->provider_parts, part_number, &stored_part);
            }
        }
        fclose(fp);
        fp = NULL;

        if (session->upload_id[0] == '\0' || session->object_id[0] == '\0'
            || king_object_store_upload_session_assign_paths(session) != SUCCESS) {
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }

        if (king_object_store_meta_read_from_path(session->metadata_path, &session->metadata) != SUCCESS) {
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }
        if (session->existed_before
            && king_object_store_meta_read_from_path(
                session->existing_metadata_path,
                &session->existing_metadata
            ) != SUCCESS) {
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }

        if (session->completed || session->aborted) {
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }

        if (king_object_store_seed_sha256_ctx_from_path(
                session->assembly_path,
                &session->sha256_ctx,
                &assembly_size
            ) != SUCCESS
            || assembly_size != session->uploaded_bytes) {
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }

        memset(lock_error, 0, sizeof(lock_error));
        if (king_object_store_acquire_object_lock(
                session->object_id,
                &session_lock_fd,
                lock_error,
                sizeof(lock_error)
            ) != SUCCESS) {
            if (error != NULL && error_size > 0 && error[0] == '\0') {
                snprintf(
                    error,
                    error_size,
                    "Failed to rehydrate object-store upload session '%s': %s",
                    session->upload_id,
                    lock_error[0] != '\0' ? lock_error : "object mutation lock unavailable"
                );
            }
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }

        session->lock_fd = session_lock_fd;
        session->recovered_after_restart = 1;
        if (king_object_store_upload_session_store(session) != SUCCESS) {
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }
    }

    closedir(dir);
    return SUCCESS;
}

static int king_object_store_append_path_to_file_and_hash(
    const char *source_path,
    const char *destination_path,
    PHP_SHA256_CTX *sha_ctx,
    uint64_t *bytes_appended_out
)
{
    FILE *source_fp = NULL;
    FILE *destination_fp = NULL;
    unsigned char *buffer = NULL;
    size_t chunk_bytes;
    uint64_t appended = 0;
    int rc = FAILURE;

    if (source_path == NULL || destination_path == NULL || sha_ctx == NULL) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        return FAILURE;
    }

    destination_fp = fopen(destination_path, "ab");
    if (destination_fp == NULL) {
        fclose(source_fp);
        return FAILURE;
    }

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);

    while (1) {
        size_t bytes_read = fread(buffer, 1, chunk_bytes, source_fp);

        if (bytes_read == 0) {
            if (feof(source_fp)) {
                rc = SUCCESS;
                break;
            }
            goto cleanup;
        }

        if (fwrite(buffer, 1, bytes_read, destination_fp) != bytes_read) {
            goto cleanup;
        }

        PHP_SHA256Update(sha_ctx, buffer, bytes_read);
        appended += (uint64_t) bytes_read;
    }

cleanup:
    if (buffer != NULL) {
        efree(buffer);
    }
    if (source_fp != NULL) {
        fclose(source_fp);
    }
    if (destination_fp != NULL) {
        fclose(destination_fp);
    }
    if (bytes_appended_out != NULL) {
        *bytes_appended_out = appended;
    }

    return rc;
}

static int king_object_store_finalize_completed_upload_session(
    king_object_store_upload_session_t *session,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    PHP_SHA256_CTX final_ctx;
    unsigned char digest[32];
    static const char hex[] = "0123456789abcdef";
    char computed_sha256[65];
    king_object_metadata_t final_metadata;
    size_t i;

    if (session == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (session->completed) {
        king_object_store_upload_session_export_status(session, status_out);
        return SUCCESS;
    }

    final_ctx = session->sha256_ctx;
    PHP_SHA256Final(digest, &final_ctx);
    for (i = 0; i < sizeof(digest); ++i) {
        computed_sha256[i * 2] = hex[(digest[i] >> 4) & 0x0f];
        computed_sha256[(i * 2) + 1] = hex[digest[i] & 0x0f];
    }
    computed_sha256[64] = '\0';

    if (session->expected_integrity_sha256[0] != '\0'
        && strcmp(session->expected_integrity_sha256, computed_sha256) != 0) {
        snprintf(
            error,
            error_size,
            "Resumable upload integrity validation failed for '%s'.",
            session->object_id
        );
        return FAILURE;
    }
    if (king_object_store_runtime_capacity_check_rewrite(
            session->uploaded_bytes,
            session->existed_before,
            session->old_size,
            error,
            error_size
        ) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "Resumable upload for '%s' would exceed the configured object-store runtime capacity.",
            session->object_id
        );
        return FAILURE;
    }

    final_metadata = session->metadata;
    king_object_store_metadata_mark_backend_present(&final_metadata, session->backend, 1);
    king_object_store_finalize_metadata_for_write(
        session->object_id,
        session->uploaded_bytes,
        computed_sha256,
        &final_metadata,
        session->existed_before ? &session->existing_metadata : NULL
    );

    if (king_object_store_meta_write(session->object_id, &final_metadata) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "Resumable upload for '%s' committed remotely but local metadata sidecar write failed.",
            session->object_id
        );
        return FAILURE;
    }

    king_object_store_apply_local_fs_counters_for_rewrite(
        session->object_id,
        session->uploaded_bytes,
        session->existed_before,
        session->old_size
    );

    if (king_object_store_runtime.config.backup_backend != session->backend) {
        if (king_object_store_backup_object_from_file_to_backend(
                session->object_id,
                session->assembly_path,
                &final_metadata,
                king_object_store_runtime.config.backup_backend
            ) != SUCCESS) {
            if (king_object_store_runtime.config.replication_factor > 0) {
                king_object_store_update_replication_status(session->object_id, 3);
            }
            snprintf(
                error,
                error_size,
                "Resumable upload for '%s' committed remotely but backup operation failed.",
                session->object_id
            );
            return FAILURE;
        }
    }

    if (king_object_store_runtime.config.replication_factor > 0) {
        if (king_object_store_replicate_object(
                session->object_id,
                king_object_store_runtime.config.replication_factor
            ) != SUCCESS) {
            snprintf(
                error,
                error_size,
                "Resumable upload for '%s' committed remotely but replication target was not reached.",
                session->object_id
            );
            return FAILURE;
        }
    }

    session->metadata = final_metadata;
    session->completed = 1;
    session->remote_completed = 1;
    session->updated_at = time(NULL);
    king_object_store_upload_session_export_status(session, status_out);
    return SUCCESS;
}

static void king_object_store_release_read_buffer(void **data, size_t *data_size)
{
    if (data == NULL || *data == NULL) {
        return;
    }

    pefree(*data, 1);
    *data = NULL;
    if (data_size != NULL) {
        *data_size = 0;
    }
}

static void king_object_store_metadata_mark_backend_present(
    king_object_metadata_t *metadata,
    king_storage_backend_t backend,
    uint8_t present
)
{
    if (metadata == NULL) {
        return;
    }

    backend = king_object_store_normalize_backend(backend);
    if (backend == KING_STORAGE_BACKEND_LOCAL_FS) {
        metadata->local_fs_present = present;
    } else if (backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        metadata->distributed_present = present;
    } else if (backend == KING_STORAGE_BACKEND_CLOUD_S3) {
        metadata->cloud_s3_present = present;
    } else if (backend == KING_STORAGE_BACKEND_CLOUD_GCS) {
        metadata->cloud_gcs_present = present;
    } else if (backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
        metadata->cloud_azure_present = present;
    }
}

zend_bool king_object_store_metadata_is_expired_at(
    const king_object_metadata_t *metadata,
    time_t now
)
{
    if (metadata == NULL || metadata->expires_at <= 0) {
        return 0;
    }

    return now >= metadata->expires_at ? 1 : 0;
}

zend_bool king_object_store_metadata_is_expired_now(
    const king_object_metadata_t *metadata
)
{
    return king_object_store_metadata_is_expired_at(metadata, time(NULL));
}

static int king_object_store_read_visibility_metadata(
    const char *object_id,
    king_object_metadata_t *metadata
)
{
    king_storage_backend_t primary_backend;
    struct stat root_stat;

    if (object_id == NULL || metadata == NULL) {
        return FAILURE;
    }

    primary_backend = king_object_store_normalize_backend(
        king_object_store_runtime.config.primary_backend
    );
    if (primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
        || primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
            return FAILURE;
        }
        if (stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0
            || !S_ISDIR(root_stat.st_mode)) {
            if (king_object_store_runtime_metadata_cache_read(object_id, metadata) == SUCCESS) {
                return SUCCESS;
            }
            return FAILURE;
        }
    }

    if (king_object_store_backend_read_metadata(object_id, metadata) == SUCCESS) {
        return SUCCESS;
    }

    if ((primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
            || primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE)
        && king_object_store_runtime_metadata_cache_read(object_id, metadata) == SUCCESS) {
        return SUCCESS;
    }

    return FAILURE;
}

static int king_object_store_fail_expired_visibility(const char *operation)
{
    (void) operation;
    return KING_OBJECT_STORE_RESULT_MISS;
}

static void king_object_store_set_local_failure_error(
    const char *operation,
    const char *object_id,
    const char *detail
)
{
    if (operation == NULL || operation[0] == '\0') {
        operation = "operation";
    }

    if (object_id != NULL && object_id[0] != '\0') {
        snprintf(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "local_fs %s for '%s' failed: %s",
            operation,
            object_id,
            detail != NULL && detail[0] != '\0' ? detail : "unknown local filesystem failure."
        );
        return;
    }

    snprintf(
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error),
        "local_fs %s failed: %s",
        operation,
        detail != NULL && detail[0] != '\0' ? detail : "unknown local filesystem failure."
    );
}

static void king_object_store_set_backend_filesystem_error(
    char *destination,
    size_t destination_size,
    const char *backend_name,
    const char *operation,
    const char *object_id,
    const char *detail
)
{
    const char *safe_backend = backend_name != NULL && backend_name[0] != '\0'
        ? backend_name
        : "backend";
    const char *safe_operation = operation != NULL && operation[0] != '\0'
        ? operation
        : "operation";
    const char *safe_detail = detail != NULL && detail[0] != '\0'
        ? detail
        : "unknown filesystem failure.";

    if (destination == NULL || destination_size == 0) {
        return;
    }

    if (object_id != NULL && object_id[0] != '\0') {
        snprintf(
            destination,
            destination_size,
            "%s %s for '%s' failed: %s",
            safe_backend,
            safe_operation,
            object_id,
            safe_detail
        );
        return;
    }

    snprintf(
        destination,
        destination_size,
        "%s %s failed: %s",
        safe_backend,
        safe_operation,
        safe_detail
    );
}

static int king_object_store_local_fs_root_available(const char *operation)
{
    struct stat root_stat;

    if (operation == NULL || operation[0] == '\0') {
        operation = "operation";
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        king_object_store_set_local_failure_error(operation, NULL, "the configured storage_root_path is empty.");
        return 0;
    }

    if (stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0) {
        king_object_store_set_local_failure_error(operation, NULL, strerror(errno));
        return 0;
    }

    if (!S_ISDIR(root_stat.st_mode)) {
        king_object_store_set_local_failure_error(operation, NULL, "the configured storage_root_path is not a directory.");
        return 0;
    }

    return 1;
}

static int king_object_store_local_fs_payload_exists(const char *object_id)
{
    char file_path[1024];
    struct stat st;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        return 0;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (stat(file_path, &st) != 0) {
        return 0;
    }

    return S_ISREG(st.st_mode) ? 1 : 0;
}

static int king_object_store_distributed_payload_exists(const char *object_id)
{
    char file_path[PATH_MAX];
    struct stat st;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        return 0;
    }

    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        return 0;
    }
    if (stat(file_path, &st) != 0) {
        return 0;
    }

    return S_ISREG(st.st_mode) ? 1 : 0;
}

static int king_object_store_metadata_matches_backend(
    const char *object_id,
    const king_object_metadata_t *metadata,
    king_storage_backend_t backend
)
{
    if (metadata == NULL) {
        return 0;
    }

    backend = king_object_store_normalize_backend(backend);

    if (backend == KING_STORAGE_BACKEND_LOCAL_FS) {
        if (metadata->local_fs_present != 0) {
            return 1;
        }

        /* Legacy sidecars without explicit route markers stay readable when
         * the local payload still exists or when the object is known to have
         * a backed-up failover copy for the local_fs primary contract.
         */
        return king_object_store_local_fs_payload_exists(object_id)
            || metadata->is_backed_up != 0;
    }

    if (backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        return metadata->distributed_present != 0;
    }

    if (backend == KING_STORAGE_BACKEND_CLOUD_S3) {
        if (metadata->cloud_s3_present != 0) {
            return 1;
        }

        /* Legacy local_fs sidecars that were also backed up can still prove a
         * cloud-visible copy. Pure legacy cloud_s3 sidecars will miss here and
         * be rehydrated from a fresh HEAD request instead of leaking local
         * route state into the cloud path.
         */
        return metadata->is_backed_up != 0;
    }

    if (backend == KING_STORAGE_BACKEND_CLOUD_GCS) {
        return metadata->cloud_gcs_present != 0;
    }

    if (backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
        return metadata->cloud_azure_present != 0;
    }

    return 0;
}

static int king_object_store_metadata_counts_toward_primary_inventory(
    const char *object_id,
    const king_object_metadata_t *metadata,
    king_storage_backend_t backend
)
{
    backend = king_object_store_normalize_backend(backend);

    if (!king_object_store_metadata_matches_backend(object_id, metadata, backend)) {
        return 0;
    }

    if (backend == KING_STORAGE_BACKEND_LOCAL_FS) {
        return king_object_store_local_fs_payload_exists(object_id);
    }
    if (backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        return king_object_store_distributed_payload_exists(object_id);
    }

    return 1;
}

static king_storage_backend_t king_object_store_normalize_backend(king_storage_backend_t backend)
{
    if (backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        return KING_STORAGE_BACKEND_LOCAL_FS;
    }

    return backend;
}

static void king_object_store_update_replication_status(const char *object_id, uint8_t replication_status)
{
    king_object_metadata_t metadata;

    if (object_id == NULL || object_id[0] == '\0') {
        return;
    }

    if (king_object_store_meta_read(object_id, &metadata) != SUCCESS) {
        return;
    }

    metadata.replication_status = replication_status;
    (void) king_object_store_meta_write(object_id, &metadata);
}

static void king_object_store_invalidate_cdn_cache_entry(const char *object_id)
{
    zend_string *zobj_id;

    if (object_id == NULL || object_id[0] == '\0' || !king_cdn_cache_registry_initialized) {
        return;
    }

    zobj_id = zend_string_init(object_id, strlen(object_id), 0);
    zend_hash_del(&king_cdn_cache_registry, zobj_id);
    zend_string_release(zobj_id);
}

static uint32_t king_object_store_count_achieved_real_copies(const king_object_metadata_t *metadata)
{
    king_storage_backend_t primary_backend;
    king_storage_backend_t backup_backend;
    uint32_t achieved_real_copies = 0;

    if (metadata == NULL) {
        return 0;
    }

    primary_backend = king_object_store_normalize_backend(
        king_object_store_runtime.config.primary_backend
    );
    backup_backend = king_object_store_normalize_backend(
        king_object_store_runtime.config.backup_backend
    );

    if (primary_backend == KING_STORAGE_BACKEND_LOCAL_FS && metadata->local_fs_present != 0) {
        achieved_real_copies++;
    } else if (primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED && metadata->distributed_present != 0) {
        achieved_real_copies++;
    } else if (primary_backend == KING_STORAGE_BACKEND_CLOUD_S3 && metadata->cloud_s3_present != 0) {
        achieved_real_copies++;
    } else if (primary_backend == KING_STORAGE_BACKEND_CLOUD_GCS && metadata->cloud_gcs_present != 0) {
        achieved_real_copies++;
    } else if (primary_backend == KING_STORAGE_BACKEND_CLOUD_AZURE && metadata->cloud_azure_present != 0) {
        achieved_real_copies++;
    } else if (king_object_store_backend_is_real(primary_backend)) {
        achieved_real_copies++;
    }

    if (backup_backend != primary_backend && king_object_store_backend_is_real(backup_backend)) {
        if (backup_backend == KING_STORAGE_BACKEND_LOCAL_FS && metadata->local_fs_present != 0) {
            achieved_real_copies++;
        } else if (backup_backend == KING_STORAGE_BACKEND_DISTRIBUTED && metadata->distributed_present != 0) {
            achieved_real_copies++;
        } else if (backup_backend == KING_STORAGE_BACKEND_CLOUD_S3
            && (metadata->cloud_s3_present != 0 || metadata->is_backed_up != 0)) {
            achieved_real_copies++;
        } else if (backup_backend == KING_STORAGE_BACKEND_CLOUD_GCS
            && metadata->cloud_gcs_present != 0) {
            achieved_real_copies++;
        } else if (backup_backend == KING_STORAGE_BACKEND_CLOUD_AZURE
            && metadata->cloud_azure_present != 0) {
            achieved_real_copies++;
        }
    }

    return achieved_real_copies;
}

static void king_object_store_reconcile_replication_status(king_object_metadata_t *metadata)
{
    if (metadata == NULL) {
        return;
    }

    if (king_object_store_runtime.config.replication_factor == 0) {
        metadata->replication_status = 0;
        return;
    }

    metadata->replication_status =
        king_object_store_count_achieved_real_copies(metadata)
            >= king_object_store_runtime.config.replication_factor
        ? 2
        : 3;
}

static int king_object_store_path_is_within_root(const char *candidate_path, const char *root_path)
{
    size_t root_len;

    if (candidate_path == NULL || root_path == NULL) {
        return 0;
    }

    root_len = strlen(root_path);
    if (root_len == 0) {
        return 0;
    }

    if (strncmp(candidate_path, root_path, root_len) != 0) {
        return 0;
    }

    return candidate_path[root_len] == '\0' || candidate_path[root_len] == '/';
}

static int king_object_store_expand_to_absolute_path(
    const char *input_path,
    char *output_path,
    size_t output_path_size
)
{
    char cwd[PATH_MAX];
    int written;

    if (input_path == NULL || input_path[0] == '\0' ||
        output_path == NULL || output_path_size == 0) {
        return FAILURE;
    }

    if (input_path[0] == '/') {
        written = snprintf(output_path, output_path_size, "%s", input_path);
        return (written >= 0 && (size_t) written < output_path_size) ? SUCCESS : FAILURE;
    }

    if (getcwd(cwd, sizeof(cwd)) == NULL) {
        return FAILURE;
    }

    written = snprintf(output_path, output_path_size, "%s/%s", cwd, input_path);
    return (written >= 0 && (size_t) written < output_path_size) ? SUCCESS : FAILURE;
}

static int king_object_store_find_existing_ancestor(
    const char *path,
    char *ancestor_path,
    size_t ancestor_path_size
)
{
    struct stat st;
    size_t len;

    if (path == NULL || path[0] == '\0' ||
        ancestor_path == NULL || ancestor_path_size == 0) {
        return FAILURE;
    }

    if (snprintf(ancestor_path, ancestor_path_size, "%s", path) >= (int) ancestor_path_size) {
        return FAILURE;
    }

    len = strlen(ancestor_path);
    while (len > 1 && ancestor_path[len - 1] == '/') {
        ancestor_path[len - 1] = '\0';
        len--;
    }

    while (1) {
        if (stat(ancestor_path, &st) == 0) {
            return S_ISDIR(st.st_mode) ? SUCCESS : FAILURE;
        }

        if (strcmp(ancestor_path, "/") == 0) {
            return FAILURE;
        }

        {
            char *slash = strrchr(ancestor_path, '/');
            if (slash == NULL) {
                return FAILURE;
            }
            if (slash == ancestor_path) {
                ancestor_path[1] = '\0';
            } else {
                *slash = '\0';
            }
        }
    }
}

static int king_object_store_directory_is_within_storage_root(
    const char *directory_path,
    int allow_missing_path
)
{
    char absolute_directory[PATH_MAX];
    char existing_ancestor[PATH_MAX];
    char resolved_directory[PATH_MAX];
    char resolved_root[PATH_MAX];
    struct stat st;

    if (directory_path == NULL || directory_path[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return FAILURE;
    }
    if (realpath(king_object_store_runtime.config.storage_root_path, resolved_root) == NULL) {
        return FAILURE;
    }
    if (stat(resolved_root, &st) != 0 || !S_ISDIR(st.st_mode)) {
        return FAILURE;
    }
    if (king_object_store_expand_to_absolute_path(
            directory_path,
            absolute_directory,
            sizeof(absolute_directory)
        ) != SUCCESS) {
        return FAILURE;
    }

    if (stat(absolute_directory, &st) == 0) {
        if (!S_ISDIR(st.st_mode)) {
            return FAILURE;
        }
        if (realpath(absolute_directory, resolved_directory) == NULL) {
            return FAILURE;
        }
    } else {
        if (!allow_missing_path) {
            return FAILURE;
        }
        if (king_object_store_find_existing_ancestor(
                absolute_directory,
                existing_ancestor,
                sizeof(existing_ancestor)
            ) != SUCCESS) {
            return FAILURE;
        }
        if (realpath(existing_ancestor, resolved_directory) == NULL) {
            return FAILURE;
        }
    }

    return king_object_store_path_is_within_root(resolved_directory, resolved_root)
        ? SUCCESS
        : FAILURE;
}

static void king_object_store_append_adapter_error(char *destination, size_t destination_size, const char *message)
{
    size_t used;
    if (message == NULL || message[0] == '\0' || destination == NULL || destination_size == 0) {
        return;
    }
    used = strnlen(destination, destination_size);
    if (used >= destination_size - 1) {
        return;
    }
    if (destination[0] == '\0') {
        snprintf(destination, destination_size, "%s", message);
        return;
    }
    strncat(destination, "; ", destination_size - used - 1);
    used = strnlen(destination, destination_size);
    if (used < destination_size - 1) {
        strncat(destination, message, destination_size - used - 1);
    }
}

const char *king_object_store_backend_contract_to_string(king_storage_backend_t backend)
{
    backend = king_object_store_normalize_backend(backend);

    switch (backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
            return king_object_store_adapter_contract_local;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            return king_object_store_adapter_contract_distributed;
        case KING_STORAGE_BACKEND_CLOUD_S3:
        case KING_STORAGE_BACKEND_CLOUD_GCS:
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            return king_object_store_adapter_contract_cloud;
        default:
            return king_object_store_adapter_contract_unconfigured;
    }
}

static const char *king_object_store_initial_backend_status(king_storage_backend_t backend)
{
    backend = king_object_store_normalize_backend(backend);

    switch (backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_DISTRIBUTED:
        case KING_STORAGE_BACKEND_CLOUD_S3:
        case KING_STORAGE_BACKEND_CLOUD_GCS:
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            return king_object_store_adapter_status_ok;
        default:
            return king_object_store_adapter_status_unimplemented;
    }
}

void king_object_store_initialize_adapter_statuses(void)
{
    strncpy(
        king_object_store_runtime.primary_adapter_status,
        king_object_store_initial_backend_status(king_object_store_runtime.config.primary_backend),
        sizeof(king_object_store_runtime.primary_adapter_status) - 1
    );
    strncpy(
        king_object_store_runtime.backup_adapter_status,
        king_object_store_initial_backend_status(king_object_store_runtime.config.backup_backend),
        sizeof(king_object_store_runtime.backup_adapter_status) - 1
    );
    strncpy(
        king_object_store_runtime.primary_adapter_contract,
        king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
        sizeof(king_object_store_runtime.primary_adapter_contract) - 1
    );
    strncpy(
        king_object_store_runtime.backup_adapter_contract,
        king_object_store_backend_contract_to_string(king_object_store_runtime.config.backup_backend),
        sizeof(king_object_store_runtime.backup_adapter_contract) - 1
    );
}

void king_object_store_set_runtime_adapter_status(
    const char *scope,
    const char *status,
    const char *contract,
    const char *error
)
{
    char *status_target = NULL;
    char *contract_target = NULL;
    char *error_target = NULL;
    const char *safe_status = status == NULL ? king_object_store_adapter_status_unknown : status;
    const char *safe_contract = contract == NULL ? king_object_store_adapter_contract_unconfigured : contract;

    if (scope == NULL || scope[0] == '\0') {
        return;
    }
    if (strcmp(scope, "primary") == 0) {
        status_target = king_object_store_runtime.primary_adapter_status;
        contract_target = king_object_store_runtime.primary_adapter_contract;
        error_target = king_object_store_runtime.primary_adapter_error;
    } else if (strcmp(scope, "backup") == 0) {
        status_target = king_object_store_runtime.backup_adapter_status;
        contract_target = king_object_store_runtime.backup_adapter_contract;
        error_target = king_object_store_runtime.backup_adapter_error;
    } else {
        return;
    }

    snprintf(status_target, 24, "%s", safe_status);
    snprintf(contract_target, 16, "%s", safe_contract);
    if (error == NULL || error[0] == '\0') {
        error_target[0] = '\0';
    } else {
        size_t error_target_size = 512;
        king_object_store_append_adapter_error(error_target, error_target_size, error);
    }
}

static void king_object_store_set_backend_runtime_result(
    const char *scope,
    king_storage_backend_t backend,
    int success,
    const char *error
)
{
    int operation_succeeded = (success == SUCCESS);
    const char *contract = king_object_store_backend_contract_to_string(backend);
    const char *status = operation_succeeded ? king_object_store_initial_backend_status(backend) : king_object_store_adapter_status_failed;
    if (!operation_succeeded && (error == NULL || error[0] == '\0')) {
        error = "Object-store backend operation failed.";
    }
    king_object_store_set_runtime_adapter_status(scope, status, contract, error);
}

static char *king_object_store_runtime_adapter_error_target(const char *scope)
{
    if (scope == NULL || scope[0] == '\0') {
        return NULL;
    }

    if (strcmp(scope, "primary") == 0) {
        return king_object_store_runtime.primary_adapter_error;
    }
    if (strcmp(scope, "backup") == 0) {
        return king_object_store_runtime.backup_adapter_error;
    }

    return NULL;
}

static void king_object_store_replace_runtime_adapter_status(
    const char *scope,
    const char *status,
    const char *contract,
    const char *error
)
{
    char *error_target = king_object_store_runtime_adapter_error_target(scope);

    if (error_target != NULL) {
        error_target[0] = '\0';
    }
    king_object_store_set_runtime_adapter_status(scope, status, contract, error);
}

static void king_object_store_finalize_backend_runtime_result(
    const char *scope,
    king_storage_backend_t backend,
    int result,
    const char *fallback_error
)
{
    const char *contract = king_object_store_backend_contract_to_string(backend);
    char *error_target = king_object_store_runtime_adapter_error_target(scope);
    const char *message = NULL;
    char error_copy[512];

    if (result == SUCCESS || result == KING_OBJECT_STORE_RESULT_MISS) {
        king_object_store_replace_runtime_adapter_status(
            scope,
            king_object_store_initial_backend_status(backend),
            contract,
            NULL
        );
        return;
    }

    if (result == KING_OBJECT_STORE_RESULT_UNAVAILABLE) {
        if (error_target == NULL || error_target[0] == '\0') {
            king_object_store_replace_runtime_adapter_status(
                scope,
                king_object_store_adapter_status_failed,
                contract,
                fallback_error != NULL ? fallback_error : "Object-store backend is unavailable."
            );
        }
        return;
    }

    message = (error_target != NULL && error_target[0] != '\0')
        ? error_target
        : fallback_error;
    if (message != NULL) {
        snprintf(error_copy, sizeof(error_copy), "%s", message);
        message = error_copy;
    }
    king_object_store_replace_runtime_adapter_status(
        scope,
        king_object_store_adapter_status_failed,
        contract,
        message != NULL ? message : "Object-store backend operation failed."
    );
}

static int king_object_store_backend_is_local(king_storage_backend_t backend)
{
    backend = king_object_store_normalize_backend(backend);
    return backend == KING_STORAGE_BACKEND_LOCAL_FS;
}

static int king_object_store_backend_is_real(king_storage_backend_t backend)
{
    backend = king_object_store_normalize_backend(backend);
    return backend == KING_STORAGE_BACKEND_LOCAL_FS
        || backend == KING_STORAGE_BACKEND_DISTRIBUTED
        || backend == KING_STORAGE_BACKEND_CLOUD_S3
        || backend == KING_STORAGE_BACKEND_CLOUD_GCS
        || backend == KING_STORAGE_BACKEND_CLOUD_AZURE;
}

static zend_bool king_object_store_backend_is_future_cloud(king_storage_backend_t backend)
{
    (void) backend;
    return 0;
}

static const char *king_object_store_missing_future_cloud_credentials_error(const char *scope)
{
    if (scope != NULL && strcmp(scope, "backup") == 0) {
        return "Cloud credentials are required to enable native cloud backup backends.";
    }

    return "Cloud credentials are required to enable native cloud backend operation.";
}

static int king_object_store_require_honest_backend(
    const char *scope,
    king_storage_backend_t backend,
    const char *operation
)
{
    const char *backend_name;
    const char *contract;
    char message[256];

    if (king_object_store_backend_is_real(backend) == 1) {
        return SUCCESS;
    }

    if (operation == NULL || operation[0] == '\0') {
        operation = "an operation";
    }

    backend_name = king_storage_backend_to_string(backend);
    contract = king_object_store_backend_contract_to_string(backend);

    if (
        king_object_store_backend_is_future_cloud(backend)
        && Z_TYPE(king_object_store_runtime.config.cloud_credentials) == IS_UNDEF
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s",
            king_object_store_missing_future_cloud_credentials_error(scope)
        );
    } else {
        snprintf(
            message,
            sizeof(message),
            "object-store backend '%s' is unavailable for %s.",
            backend_name,
            operation
        );
    }

    king_object_store_replace_runtime_adapter_status(
        scope,
        king_object_store_adapter_status_failed,
        contract,
        message
    );

    return FAILURE;
}

const char *king_object_store_object_id_validate(const char *object_id)
{
    if (object_id == NULL || object_id[0] == '\0') {
        return "Object ID must be between 1 and 127 bytes.";
    }

    if (strlen(object_id) > 127) {
        return "Object ID must be between 1 and 127 bytes.";
    }

    if (strstr(object_id, "..") != NULL) {
        return "Object ID must not contain traversal sequences.";
    }

    if (strchr(object_id, '/') != NULL || strchr(object_id, '\\') != NULL) {
        return "Object ID must not contain path separator characters.";
    }

    return NULL;
}

/* --- Config helpers --- */

static void king_object_store_config_clear(king_object_store_config_t *config)
{
    if (config == NULL) {
        return;
    }
    if (Z_TYPE(config->cloud_credentials) != IS_UNDEF) {
        zval_ptr_dtor(&config->cloud_credentials);
        ZVAL_UNDEF(&config->cloud_credentials);
    }
}

static void king_object_store_config_copy(
    king_object_store_config_t *target,
    const king_object_store_config_t *source
)
{
    memset(target, 0, sizeof(*target));
    ZVAL_UNDEF(&target->cloud_credentials);
    target->primary_backend       = king_object_store_normalize_backend(source->primary_backend);
    target->backup_backend        = king_object_store_normalize_backend(source->backup_backend);
    strncpy(target->storage_root_path, source->storage_root_path, sizeof(target->storage_root_path) - 1);
    target->max_storage_size_bytes = source->max_storage_size_bytes;
    target->replication_factor    = source->replication_factor;
    target->chunk_size_kb         = source->chunk_size_kb;
    target->enable_deduplication  = source->enable_deduplication;
    target->enable_encryption     = source->enable_encryption;
    target->enable_compression    = source->enable_compression;
    target->cdn_config            = source->cdn_config;
    if (Z_TYPE(source->cloud_credentials) != IS_UNDEF) {
        ZVAL_COPY(&target->cloud_credentials, (zval *) &source->cloud_credentials);
    }
}

/* --- String conversion helpers (satisfy include/object_store/object_store.h declarations) --- */

const char *king_storage_backend_to_string(king_storage_backend_t backend)
{
    backend = king_object_store_normalize_backend(backend);

    switch (backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:     return "local_fs";
        case KING_STORAGE_BACKEND_DISTRIBUTED:  return "distributed";
        case KING_STORAGE_BACKEND_CLOUD_S3:     return "cloud_s3";
        case KING_STORAGE_BACKEND_CLOUD_GCS:    return "cloud_gcs";
        case KING_STORAGE_BACKEND_CLOUD_AZURE:  return "cloud_azure";
        default:                                return "unknown";
    }
}

const char *king_object_type_to_string(king_object_type_t type)
{
    switch (type) {
        case KING_OBJECT_TYPE_STATIC_ASSET:    return "static_asset";
        case KING_OBJECT_TYPE_DYNAMIC_CONTENT: return "dynamic_content";
        case KING_OBJECT_TYPE_MEDIA_FILE:      return "media_file";
        case KING_OBJECT_TYPE_DOCUMENT:        return "document";
        case KING_OBJECT_TYPE_CACHE_ENTRY:     return "cache_entry";
        case KING_OBJECT_TYPE_BINARY_DATA:     return "binary_data";
        default:                               return "unknown";
    }
}

const char *king_cache_policy_to_string(king_cache_policy_t policy)
{
    switch (policy) {
        case KING_CACHE_POLICY_NO_CACHE:      return "no_cache";
        case KING_CACHE_POLICY_CACHE_CONTROL: return "cache_control";
        case KING_CACHE_POLICY_ETAG:          return "etag";
        case KING_CACHE_POLICY_LAST_MODIFIED: return "last_modified";
        case KING_CACHE_POLICY_AGGRESSIVE:    return "aggressive";
        case KING_CACHE_POLICY_SMART_CDN:     return "smart_cdn";
        default:                              return "unknown";
    }
}

const char *king_object_store_upload_protocol_to_string(king_object_store_upload_protocol_t protocol)
{
    switch (protocol) {
        case KING_OBJECT_STORE_UPLOAD_PROTOCOL_S3_MULTIPART:  return "s3_multipart";
        case KING_OBJECT_STORE_UPLOAD_PROTOCOL_GCS_RESUMABLE: return "gcs_resumable";
        case KING_OBJECT_STORE_UPLOAD_PROTOCOL_AZURE_BLOCKS:  return "azure_blocks";
        default:                                              return "unknown";
    }
}

/* --- System lifecycle --- */

int king_object_store_init_system(king_object_store_config_t *config)
{
    char error[256];

    if (config == NULL) {
        return FAILURE;
    }

    king_object_store_upload_sessions_reset();
    king_object_store_runtime_metadata_cache_reset();
    king_object_store_config_clear(&king_object_store_runtime.config);
    memset(&king_object_store_runtime, 0, sizeof(king_object_store_runtime));
    ZVAL_UNDEF(&king_object_store_runtime.config.cloud_credentials);

    king_object_store_config_copy(&king_object_store_runtime.config, config);
    king_object_store_runtime.initialized = true;
    king_object_store_initialize_adapter_statuses();
    king_object_store_distributed_state_reset_runtime();

    if (king_object_store_runtime.config.storage_root_path[0] != '\0') {
        mkdir(king_object_store_runtime.config.storage_root_path, 0755);
    }

    if (king_object_store_initialize_distributed_coordinator_state(error, sizeof(error)) != SUCCESS
        && error[0] == '\0'
        && king_object_store_runtime.distributed_coordinator_state_error[0] != '\0') {
        snprintf(error, sizeof(error), "%s", king_object_store_runtime.distributed_coordinator_state_error);
    }

    if (!king_object_store_backend_is_real(king_object_store_runtime.config.primary_backend)) {
        const char *message = "Primary backend is simulated-only and unavailable in this runtime.";
        if (king_object_store_backend_is_future_cloud(king_object_store_runtime.config.primary_backend) &&
            Z_TYPE(king_object_store_runtime.config.cloud_credentials) == IS_UNDEF) {
            message = king_object_store_missing_future_cloud_credentials_error("primary");
        }

        king_object_store_set_runtime_adapter_status(
            "primary",
            king_object_store_adapter_status_simulated,
            king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
            message
        );
    }

    if (!king_object_store_backend_is_real(king_object_store_runtime.config.backup_backend)) {
        const char *message = "Backup backend is simulated-only and unavailable in this runtime.";
        if (king_object_store_backend_is_future_cloud(king_object_store_runtime.config.backup_backend) &&
            Z_TYPE(king_object_store_runtime.config.cloud_credentials) == IS_UNDEF) {
            message = king_object_store_missing_future_cloud_credentials_error("backup");
        }

        king_object_store_set_runtime_adapter_status(
            "backup",
            king_object_store_adapter_status_simulated,
            king_object_store_backend_contract_to_string(king_object_store_runtime.config.backup_backend),
            message
        );
    }

    if (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_S3
        || king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_GCS
        || king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
        if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
            king_object_store_set_runtime_adapter_status(
                "primary",
                king_object_store_adapter_status_failed,
                king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
                "Missing required storage_root_path for cloud object-store metadata sidecars."
            );
        } else if (
            (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_S3
                && king_object_store_s3_rehydrate_stats(error, sizeof(error)) != SUCCESS)
            || (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_GCS
                && king_object_store_gcs_rehydrate_stats(error, sizeof(error)) != SUCCESS)
            || (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_AZURE
                && king_object_store_azure_rehydrate_stats(error, sizeof(error)) != SUCCESS)
        ) {
            king_object_store_set_runtime_adapter_status(
                "primary",
                king_object_store_adapter_status_failed,
                king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
                error
            );
        }
    }

    if (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_LOCAL_FS ||
        king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE ||
        king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        if (king_object_store_runtime.config.storage_root_path[0] != '\0') {
            /* Rehydrate live stats from any existing on-disk objects */
            king_object_store_rehydrate_stats();
        } else {
            king_object_store_set_runtime_adapter_status(
                "primary",
                king_object_store_adapter_status_unimplemented,
                king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
                king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED
                    ? "Missing required storage_root_path for the distributed object-store data plane."
                    : "Missing required storage_root_path for local file-backed adapters."
            );
        }
    }

    if (king_object_store_runtime.config.storage_root_path[0] != '\0') {
        DIR *dir;
        struct dirent *ent;
        char meta_path[1024];

        if (king_object_store_runtime_metadata_cache_ensure() == SUCCESS) {
            dir = opendir(king_object_store_runtime.config.storage_root_path);
            if (dir != NULL) {
                while ((ent = readdir(dir)) != NULL) {
                    king_object_metadata_t metadata;
                    size_t name_len;

                    if (ent->d_name[0] == '.') {
                        continue;
                    }

                    name_len = strlen(ent->d_name);
                    if (name_len <= 5 || strcmp(ent->d_name + name_len - 5, ".meta") != 0) {
                        continue;
                    }

                    snprintf(
                        meta_path,
                        sizeof(meta_path),
                        "%s/%s",
                        king_object_store_runtime.config.storage_root_path,
                        ent->d_name
                    );

                    if (king_object_store_meta_read_from_path(meta_path, &metadata) == SUCCESS) {
                        (void) king_object_store_runtime_metadata_cache_store(
                            metadata.object_id,
                            &metadata
                        );
                    }
                }

                closedir(dir);
            }
        }

        if (king_object_store_rehydrate_upload_sessions(error, sizeof(error)) != SUCCESS) {
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                error
            );
        }
    }

    return SUCCESS;
}

void king_object_store_shutdown_system(void)
{
    king_object_store_upload_sessions_reset();
    king_object_store_runtime_metadata_cache_reset();
    king_object_store_config_clear(&king_object_store_runtime.config);
    memset(&king_object_store_runtime, 0, sizeof(king_object_store_runtime));
    king_object_store_shutdown_libcurl_runtime();
}

void king_object_store_request_shutdown(void)
{
    king_object_store_upload_sessions_reset();
}

/* --- local_fs backend --- */

void king_object_store_build_path(char *dest, size_t dest_len, const char *object_id)
{
    snprintf(dest, dest_len, "%s/%s",
        king_object_store_runtime.config.storage_root_path, object_id);
}

static int king_object_store_build_path_in_directory(
    char *dest,
    size_t dest_len,
    const char *directory,
    const char *object_id,
    const char *suffix
)
{
    int written;

    if (dest == NULL || directory == NULL || object_id == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (suffix == NULL) {
        suffix = "";
    }

    written = snprintf(dest, dest_len, "%s/%s%s", directory, object_id, suffix);
    return (written >= 0 && (size_t) written < dest_len) ? SUCCESS : FAILURE;
}

static int king_object_store_is_directory(const char *path)
{
    struct stat st;
    if (path == NULL || path[0] == '\0') {
        return 0;
    }
    if (stat(path, &st) != 0) {
        return 0;
    }
    return S_ISDIR(st.st_mode) ? 1 : 0;
}

static int king_object_store_ensure_directory(const char *path)
{
    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }

    if (mkdir(path, 0755) == 0 || errno == EEXIST) {
        return king_object_store_is_directory(path) ? SUCCESS : FAILURE;
    }

    return FAILURE;
}

static int king_object_store_ensure_directory_recursive(const char *path)
{
    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }

    if (king_object_store_mkdir_parents(path) != SUCCESS) {
        return FAILURE;
    }

    return king_object_store_ensure_directory(path);
}

static int king_object_store_atomic_write_file(const char *target_path, const void *data, size_t data_size)
{
    FILE *fp;
    int temp_fd;
    char temp_path[1024];

    if (target_path == NULL || data == NULL) {
        return FAILURE;
    }

    if (snprintf(temp_path, sizeof(temp_path), "%s.XXXXXX", target_path) >= (int) sizeof(temp_path)) {
        return FAILURE;
    }

    temp_fd = mkstemp(temp_path);
    if (temp_fd < 0) {
        return FAILURE;
    }

    fp = fdopen(temp_fd, "wb");
    if (fp == NULL) {
        close(temp_fd);
        unlink(temp_path);
        return FAILURE;
    }

    if (data_size > 0) {
        if (fwrite(data, 1, data_size, fp) != data_size) {
            fclose(fp);
            unlink(temp_path);
            return FAILURE;
        }
    }

    if (fclose(fp) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    if (rename(temp_path, target_path) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_copy_file_to_path(
    const char *source_path,
    const char *target_path
)
{
    FILE *source_fp = NULL;
    FILE *destination_fp = NULL;
    unsigned char *buffer = NULL;
    size_t chunk_bytes;
    char temp_path[1024];
    int temp_fd = -1;
    int rc = FAILURE;

    if (source_path == NULL || target_path == NULL) {
        return FAILURE;
    }
    if (king_object_store_mkdir_parents(target_path) != SUCCESS) {
        return FAILURE;
    }
    if (snprintf(temp_path, sizeof(temp_path), "%s.XXXXXX", target_path) >= (int) sizeof(temp_path)) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        goto cleanup;
    }

    temp_fd = mkstemp(temp_path);
    if (temp_fd < 0) {
        goto cleanup;
    }

    destination_fp = fdopen(temp_fd, "wb");
    if (destination_fp == NULL) {
        close(temp_fd);
        temp_fd = -1;
        goto cleanup;
    }
    temp_fd = -1;

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);

    while (1) {
        size_t bytes_read = fread(buffer, 1, chunk_bytes, source_fp);

        if (bytes_read == 0) {
            if (feof(source_fp)) {
                break;
            }
            goto cleanup;
        }

        if (fwrite(buffer, 1, bytes_read, destination_fp) != bytes_read) {
            goto cleanup;
        }
    }

    if (fclose(destination_fp) != 0) {
        destination_fp = NULL;
        goto cleanup;
    }
    destination_fp = NULL;

    if (rename(temp_path, target_path) != 0) {
        goto cleanup;
    }

    rc = SUCCESS;

cleanup:
    if (buffer != NULL) {
        efree(buffer);
    }
    if (source_fp != NULL) {
        fclose(source_fp);
    }
    if (destination_fp != NULL) {
        fclose(destination_fp);
    } else if (temp_fd >= 0) {
        close(temp_fd);
    }
    if (rc != SUCCESS && temp_path[0] != '\0') {
        unlink(temp_path);
    }

    return rc;
}

static int king_object_store_mkdir_parents(const char *path)
{
    size_t len;
    size_t i;
    size_t slash_count = 0;
    char current[1024];

    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }
    if (strlen(path) >= sizeof(current)) {
        return FAILURE;
    }
    memcpy(current, path, strlen(path) + 1);

    len = strlen(current);
    if (len == 0) {
        return FAILURE;
    }
    if (current[len - 1] == '/' && len > 1) {
        current[len - 1] = '\0';
        len--;
    }
    if (len == 0) {
        return FAILURE;
    }

    for (i = 0; i < len; i++) {
        if (current[i] == '/') {
            slash_count++;
        }
    }
    if (slash_count == 0) {
        return SUCCESS;
    }

    for (i = 1; i < len; i++) {
        if (current[i] == '/') {
            char old = current[i];
            current[i] = '\0';
            if (mkdir(current, 0755) != 0 && errno != EEXIST) {
                if (!king_object_store_is_directory(current)) {
                    current[i] = old;
                    return FAILURE;
                }
            }
            current[i] = old;
        }
    }

    return SUCCESS;
}

static int king_object_store_read_file_contents(
    const char *source_path,
    void **data,
    size_t *data_size
)
{
    FILE *fp;
    struct stat st;

    if (source_path == NULL || data == NULL || data_size == NULL) {
        return FAILURE;
    }

    if (stat(source_path, &st) != 0 || !S_ISREG(st.st_mode)) {
        return FAILURE;
    }

    *data_size = (size_t) st.st_size;
    *data = pemalloc(*data_size + 1, 1);
    if (*data == NULL) {
        *data_size = 0;
        return FAILURE;
    }

    fp = fopen(source_path, "rb");
    if (fp == NULL) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        return FAILURE;
    }

    if (*data_size > 0 && fread(*data, 1, *data_size, fp) != *data_size) {
        fclose(fp);
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        return FAILURE;
    }

    ((char *) *data)[*data_size] = '\0';
    fclose(fp);
    return SUCCESS;
}

static int king_object_store_metadata_parse_stream(FILE *fp, king_object_metadata_t *metadata)
{
    char line[640];

    if (fp == NULL || metadata == NULL) {
        return FAILURE;
    }

    memset(metadata, 0, sizeof(*metadata));

    while (fgets(line, sizeof(line), fp) != NULL) {
        char *eq = strchr(line, '=');
        if (eq == NULL) {
            continue;
        }

        *eq = '\0';
        char *key = line;
        char *val = eq + 1;
        size_t vlen;

        vlen = strlen(val);
        if (vlen > 0 && val[vlen - 1] == '\n') {
            val[vlen - 1] = '\0';
        }

        if (strcmp(key, "object_id") == 0) {
            strncpy(metadata->object_id, val, sizeof(metadata->object_id) - 1);
        } else if (strcmp(key, "content_type") == 0) {
            strncpy(metadata->content_type, val, sizeof(metadata->content_type) - 1);
        } else if (strcmp(key, "content_encoding") == 0) {
            strncpy(metadata->content_encoding, val, sizeof(metadata->content_encoding) - 1);
        } else if (strcmp(key, "etag") == 0) {
            strncpy(metadata->etag, val, sizeof(metadata->etag) - 1);
        } else if (strcmp(key, "integrity_sha256") == 0) {
            strncpy(metadata->integrity_sha256, val, sizeof(metadata->integrity_sha256) - 1);
        } else if (strcmp(key, "content_length") == 0) {
            metadata->content_length = (uint64_t) strtoull(val, NULL, 10);
        } else if (strcmp(key, "version") == 0) {
            metadata->version = (uint64_t) strtoull(val, NULL, 10);
        } else if (strcmp(key, "created_at") == 0) {
            metadata->created_at = (time_t) strtoll(val, NULL, 10);
        } else if (strcmp(key, "modified_at") == 0) {
            metadata->modified_at = (time_t) strtoll(val, NULL, 10);
        } else if (strcmp(key, "expires_at") == 0) {
            metadata->expires_at = (time_t) strtoll(val, NULL, 10);
        } else if (strcmp(key, "object_type") == 0) {
            metadata->object_type = (king_object_type_t) atoi(val);
        } else if (strcmp(key, "cache_policy") == 0) {
            metadata->cache_policy = (king_cache_policy_t) atoi(val);
        } else if (strcmp(key, "cache_ttl_seconds") == 0) {
            metadata->cache_ttl_seconds = (uint32_t) strtoul(val, NULL, 10);
        } else if (strcmp(key, "local_fs_present") == 0) {
            metadata->local_fs_present = (uint8_t) atoi(val);
        } else if (strcmp(key, "distributed_present") == 0) {
            metadata->distributed_present = (uint8_t) atoi(val);
        } else if (strcmp(key, "cloud_s3_present") == 0) {
            metadata->cloud_s3_present = (uint8_t) atoi(val);
        } else if (strcmp(key, "cloud_gcs_present") == 0) {
            metadata->cloud_gcs_present = (uint8_t) atoi(val);
        } else if (strcmp(key, "cloud_azure_present") == 0) {
            metadata->cloud_azure_present = (uint8_t) atoi(val);
        } else if (strcmp(key, "is_backed_up") == 0) {
            metadata->is_backed_up = (uint8_t) atoi(val);
        } else if (strcmp(key, "replication_status") == 0) {
            metadata->replication_status = (uint8_t) atoi(val);
        } else if (strcmp(key, "is_distributed") == 0) {
            metadata->is_distributed = (uint8_t) atoi(val);
        } else if (strcmp(key, "distribution_peer_count") == 0) {
            metadata->distribution_peer_count = (uint32_t) strtoul(val, NULL, 10);
        }
    }

    return SUCCESS;
}

static int king_object_store_meta_write_to_path(const char *path, const king_object_metadata_t *metadata)
{
    FILE *fp = NULL;
    king_object_metadata_t scratch;
    char temp_path[1024];
    int temp_fd = -1;

    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_mkdir_parents(path) != SUCCESS) {
        return FAILURE;
    }
    if (snprintf(temp_path, sizeof(temp_path), "%s.XXXXXX", path) >= (int) sizeof(temp_path)) {
        return FAILURE;
    }

    temp_fd = mkstemp(temp_path);
    if (temp_fd < 0) {
        return FAILURE;
    }

    fp = fdopen(temp_fd, "wb");
    if (fp == NULL) {
        close(temp_fd);
        unlink(temp_path);
        return FAILURE;
    }
    temp_fd = -1;

    if (metadata == NULL) {
        memset(&scratch, 0, sizeof(scratch));
        scratch.created_at = time(NULL);
        metadata = &scratch;
    }

    fprintf(fp,
        "object_id=%s\n"
        "content_type=%s\n"
        "content_encoding=%s\n"
        "etag=%s\n"
        "integrity_sha256=%s\n"
        "content_length=%" PRIu64 "\n"
        "version=%" PRIu64 "\n"
        "created_at=%" PRId64 "\n"
        "modified_at=%" PRId64 "\n"
        "expires_at=%" PRId64 "\n"
        "object_type=%d\n"
        "cache_policy=%d\n"
        "cache_ttl_seconds=%u\n"
        "local_fs_present=%d\n"
        "distributed_present=%d\n"
        "cloud_s3_present=%d\n"
        "cloud_gcs_present=%d\n"
        "cloud_azure_present=%d\n"
        "is_backed_up=%d\n"
        "replication_status=%d\n"
        "is_distributed=%d\n"
        "distribution_peer_count=%u\n",
        metadata->object_id,
        metadata->content_type,
        metadata->content_encoding,
        metadata->etag,
        metadata->integrity_sha256,
        (uint64_t) metadata->content_length,
        (uint64_t) metadata->version,
        (int64_t)  metadata->created_at,
        (int64_t)  metadata->modified_at,
        (int64_t)  metadata->expires_at,
        (int)      metadata->object_type,
        (int)      metadata->cache_policy,
        (unsigned) metadata->cache_ttl_seconds,
        (int)      metadata->local_fs_present,
        (int)      metadata->distributed_present,
        (int)      metadata->cloud_s3_present,
        (int)      metadata->cloud_gcs_present,
        (int)      metadata->cloud_azure_present,
        (int)      metadata->is_backed_up,
        (int)      metadata->replication_status,
        (int)      metadata->is_distributed,
        (unsigned) metadata->distribution_peer_count
    );

    if (fclose(fp) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    if (rename(temp_path, path) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_meta_read_from_path(const char *path, king_object_metadata_t *metadata)
{
    FILE *fp;

    if (path == NULL || metadata == NULL) {
        return FAILURE;
    }

    fp = fopen(path, "r");
    if (fp == NULL) {
        return FAILURE;
    }

    if (king_object_store_metadata_parse_stream(fp, metadata) != SUCCESS) {
        fclose(fp);
        return FAILURE;
    }

    fclose(fp);
    return SUCCESS;
}

/* --- Durable metadata sidecar helpers --- */

void king_object_store_build_meta_path(char *dest, size_t dest_len, const char *object_id)
{
    snprintf(dest, dest_len, "%s/%s.meta",
        king_object_store_runtime.config.storage_root_path, object_id);
}

/*
 * Simple text key=value format, one entry per line:
 *   object_id=<str>\n
 *   content_type=<str>\n
 *   content_encoding=<str>\n
 *   etag=<str>\n
 *   integrity_sha256=<hex>\n
 *   content_length=<uint64>\n
 *   version=<uint64>\n
 *   created_at=<int64>\n
 *   modified_at=<int64>\n
 *   expires_at=<int64>\n
 *   object_type=<int>\n
 *   cache_policy=<int>\n
 *   cache_ttl_seconds=<uint32>\n
 *   is_backed_up=<bool/uint8>\n
 *   replication_status=<uint8>\n
 */
int king_object_store_meta_write(const char *object_id, const king_object_metadata_t *metadata)
{
    char meta_path[1024];
    king_object_metadata_t scratch;
    king_object_metadata_t final_metadata = {0};
    const king_object_metadata_t *metadata_source = NULL;

    if (metadata == NULL) {
        memset(&scratch, 0, sizeof(scratch));
        metadata_source = &scratch;
    } else {
        final_metadata = *metadata;
        metadata_source = &final_metadata;
    }
    if (metadata_source->object_id[0] == '\0' && object_id != NULL) {
        strncpy(final_metadata.object_id, object_id, sizeof(final_metadata.object_id) - 1);
        metadata_source = &final_metadata;
    }
    if (object_id == NULL) {
        return FAILURE;
    }

    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    king_object_store_build_meta_path(meta_path, sizeof(meta_path), object_id);
    if (king_object_store_meta_write_to_path(meta_path, metadata_source) != SUCCESS) {
        return FAILURE;
    }

    (void) king_object_store_runtime_metadata_cache_store(object_id, metadata_source);
    return SUCCESS;
}

int king_object_store_meta_read(const char *object_id, king_object_metadata_t *metadata)
{
    char meta_path[1024];

    if (king_object_store_object_id_validate(object_id) != NULL || metadata == NULL) {
        return FAILURE;
    }

    king_object_store_build_meta_path(meta_path, sizeof(meta_path), object_id);
    if (king_object_store_meta_read_from_path(meta_path, metadata) != SUCCESS) {
        return FAILURE;
    }

    (void) king_object_store_runtime_metadata_cache_store(object_id, metadata);
    return SUCCESS;
}

void king_object_store_meta_remove(const char *object_id)
{
    char meta_path[1024];
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return;
    }
    king_object_store_build_meta_path(meta_path, sizeof(meta_path), object_id);
    unlink(meta_path);
    king_object_store_runtime_metadata_cache_remove(object_id);
}

/*
 * Rehydrate live stats from disk by scanning the storage root.
 * Called after init_system so the runtime counters match the actual
 * on-disk state even across process restarts.
 */
void king_object_store_rehydrate_stats(void)
{
    DIR *dir;
    struct dirent *ent;
    struct stat st;
    char file_path[1024];
    HashTable seen_object_ids;
    king_object_metadata_t metadata;
    zend_bool seen_initialized = 0;

    king_object_store_runtime.current_object_count = 0;
    king_object_store_runtime.current_stored_bytes  = 0;
    king_object_store_runtime.latest_object_at      = 0;

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return;
    }

    dir = opendir(king_object_store_runtime.config.storage_root_path);
    if (dir == NULL) {
        return;
    }

    zend_hash_init(&seen_object_ids, 8, NULL, NULL, 0);
    seen_initialized = 1;

    while ((ent = readdir(dir)) != NULL) {
        size_t name_len;
        char object_id[128];
        time_t latest_timestamp;

        if (ent->d_name[0] == '.') {
            continue;
        }

        name_len = strlen(ent->d_name);
        if (name_len <= 5 || strcmp(ent->d_name + name_len - 5, ".meta") != 0) {
            continue;
        }

        snprintf(
            file_path,
            sizeof(file_path),
            "%s/%s",
            king_object_store_runtime.config.storage_root_path,
            ent->d_name
        );

        if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
            continue;
        }
        if (name_len - 5 >= sizeof(object_id)) {
            continue;
        }

        memcpy(object_id, ent->d_name, name_len - 5);
        object_id[name_len - 5] = '\0';

        if (king_object_store_meta_read_from_path(file_path, &metadata) != SUCCESS) {
            continue;
        }

        zend_hash_str_add_empty_element(&seen_object_ids, object_id, name_len - 5);
        if (king_object_store_metadata_is_expired_now(&metadata)) {
            continue;
        }
        if (!king_object_store_metadata_counts_toward_primary_inventory(
                object_id,
                &metadata,
                king_object_store_runtime.config.primary_backend
            )) {
            continue;
        }

        king_object_store_runtime.current_object_count++;
        king_object_store_runtime.current_stored_bytes += metadata.content_length;
        latest_timestamp = metadata.modified_at > 0 ? metadata.modified_at : st.st_mtime;
        if (latest_timestamp > king_object_store_runtime.latest_object_at) {
            king_object_store_runtime.latest_object_at = latest_timestamp;
        }
    }

    rewinddir(dir);
    while ((ent = readdir(dir)) != NULL) {
        size_t nlen;
        if (ent->d_name[0] == '.') {
            continue;
        }

        nlen = strlen(ent->d_name);
        if (nlen > 5 && strcmp(ent->d_name + nlen - 5, ".meta") == 0) {
            continue;
        }
        if (seen_initialized && zend_hash_str_exists(&seen_object_ids, ent->d_name, nlen)) {
            continue;
        }

        snprintf(file_path, sizeof(file_path), "%s/%s",
            king_object_store_runtime.config.storage_root_path, ent->d_name);

        if (stat(file_path, &st) == 0 && S_ISREG(st.st_mode)) {
            king_object_store_runtime.current_object_count++;
            king_object_store_runtime.current_stored_bytes += (uint64_t) st.st_size;
            if (st.st_mtime > king_object_store_runtime.latest_object_at) {
                king_object_store_runtime.latest_object_at = st.st_mtime;
            }
        }
    }

    if (seen_initialized) {
        zend_hash_destroy(&seen_object_ids);
    }
    closedir(dir);
}

int king_object_store_local_fs_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata)
{
    char file_path[1024];
    struct stat st_old;
    int is_overwrite = 0;
    zend_bool old_size_resolved = 0;
    uint64_t old_size = 0;
    king_object_metadata_t final_metadata;
    king_object_metadata_t existing_metadata;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL) {
        return FAILURE;
    }

    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (king_object_store_runtime_metadata_cache_read(object_id, &existing_metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &existing_metadata) == SUCCESS) {
        is_overwrite = 1;
        old_size = existing_metadata.content_length;
        old_size_resolved = 1;
    } else if (stat(file_path, &st_old) == 0 && S_ISREG(st_old.st_mode)) {
        is_overwrite = 1;
        old_size = (uint64_t) st_old.st_size;
        old_size_resolved = 1;
        strncpy(existing_metadata.object_id, object_id, sizeof(existing_metadata.object_id) - 1);
        existing_metadata.content_length = old_size;
        existing_metadata.created_at = st_old.st_mtime;
        existing_metadata.modified_at = st_old.st_mtime;
        existing_metadata.version = 1;
    }

    if (king_object_store_mkdir_parents(file_path) != SUCCESS
        || king_object_store_atomic_write_file(file_path, data, data_size) != SUCCESS) {
        return FAILURE;
    }

    if (is_overwrite) {
        if (old_size_resolved && king_object_store_runtime.current_stored_bytes >= old_size) {
            king_object_store_runtime.current_stored_bytes -= old_size;
        }
        /* Do NOT increment object count on overwrite */
    } else {
        king_object_store_runtime.current_object_count++;
    }
    king_object_store_runtime.current_stored_bytes += data_size;
    king_object_store_runtime.latest_object_at = time(NULL);

    /* Write durable metadata sidecar */
    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata != NULL) {
        final_metadata = *metadata;
    }
    if (final_metadata.object_id[0] == '\0') {
        strncpy(final_metadata.object_id, object_id, sizeof(final_metadata.object_id) - 1);
    }
    if (final_metadata.content_length == 0) {
        final_metadata.content_length = data_size;
    }
    king_object_store_prepare_metadata_for_write(
        object_id,
        data,
        data_size,
        &final_metadata,
        is_overwrite ? &existing_metadata : NULL
    );
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_LOCAL_FS,
        1
    );

    king_object_store_meta_write(object_id, &final_metadata);

    return SUCCESS;
}

int king_object_store_local_fs_write_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata
)
{
    char file_path[1024];
    struct stat source_st;
    struct stat st_old;
    int is_overwrite = 0;
    zend_bool old_size_resolved = 0;
    uint64_t old_size = 0;
    king_object_metadata_t final_metadata;
    king_object_metadata_t existing_metadata;
    char computed_sha256[65];
    uint64_t source_size = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL || source_path == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (stat(source_path, &source_st) != 0 || !S_ISREG(source_st.st_mode)) {
        return FAILURE;
    }
    if (king_object_store_compute_sha256_hex_for_path(source_path, computed_sha256, &source_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (king_object_store_runtime_metadata_cache_read(object_id, &existing_metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &existing_metadata) == SUCCESS) {
        is_overwrite = 1;
        old_size = existing_metadata.content_length;
        old_size_resolved = 1;
    } else if (stat(file_path, &st_old) == 0 && S_ISREG(st_old.st_mode)) {
        is_overwrite = 1;
        old_size = (uint64_t) st_old.st_size;
        old_size_resolved = 1;
        strncpy(existing_metadata.object_id, object_id, sizeof(existing_metadata.object_id) - 1);
        existing_metadata.content_length = old_size;
        existing_metadata.created_at = st_old.st_mtime;
        existing_metadata.modified_at = st_old.st_mtime;
        existing_metadata.version = 1;
    }

    if (king_object_store_copy_file_to_path(source_path, file_path) != SUCCESS) {
        return FAILURE;
    }

    if (is_overwrite) {
        if (old_size_resolved && king_object_store_runtime.current_stored_bytes >= old_size) {
            king_object_store_runtime.current_stored_bytes -= old_size;
        }
    } else {
        king_object_store_runtime.current_object_count++;
    }
    king_object_store_runtime.current_stored_bytes += source_size;
    king_object_store_runtime.latest_object_at = time(NULL);

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata != NULL) {
        final_metadata = *metadata;
    }
    king_object_store_finalize_metadata_for_write(
        object_id,
        source_size,
        computed_sha256,
        &final_metadata,
        is_overwrite ? &existing_metadata : NULL
    );
    king_object_store_metadata_mark_backend_present(&final_metadata, KING_STORAGE_BACKEND_LOCAL_FS, 1);

    if (king_object_store_meta_write(object_id, &final_metadata) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

int king_object_store_local_fs_read(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata)
{
    FILE *fp;
    char file_path[1024];
    struct stat st;

    if (!king_object_store_runtime.initialized || object_id == NULL ||
        data == NULL || data_size == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_local_fs_root_available("read")) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);

    if (stat(file_path, &st) != 0) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_local_failure_error("read", object_id, strerror(errno));
        return FAILURE;
    }

    if (metadata != NULL) {
        if (king_object_store_meta_read(object_id, metadata) != SUCCESS) {
            memset(metadata, 0, sizeof(*metadata));
            strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
            metadata->content_length = (uint64_t) st.st_size;
            metadata->created_at     = st.st_mtime;
            metadata->modified_at    = st.st_mtime;
            metadata->version        = 1;
        } else if (king_object_store_metadata_is_expired_now(metadata)) {
            return FAILURE;
        }
    }

    fp = fopen(file_path, "rb");
    if (fp == NULL) {
        king_object_store_set_local_failure_error("read", object_id, strerror(errno));
        return FAILURE;
    }

    *data_size = st.st_size;
    *data = pecalloc(1, *data_size + 1, 1); /* +1 for null-terminator safety */
    if (*data == NULL) {
        fclose(fp);
        king_object_store_set_local_failure_error("read", object_id, "could not allocate a read buffer.");
        return FAILURE;
    }

    if (fread(*data, 1, *data_size, fp) != *data_size) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        fclose(fp);
        king_object_store_set_local_failure_error("read", object_id, "could not read the committed payload bytes.");
        return FAILURE;
    }

    fclose(fp);
    if (metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Primary object-store backend integrity validation failed during read."
        );
        return FAILURE;
    }

    return SUCCESS;
}

int king_object_store_local_fs_read_range(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
)
{
    FILE *fp;
    char file_path[1024];
    struct stat st;
    size_t bytes_to_read;
    king_object_metadata_t resolved_metadata;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL || data_size == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_local_fs_root_available("range read")) {
        return FAILURE;
    }

    *data = NULL;
    *data_size = 0;

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_local_failure_error("range read", object_id, strerror(errno));
        return FAILURE;
    }

    if ((uint64_t) offset > (uint64_t) st.st_size) {
        king_object_store_set_local_failure_error(
            "range read",
            object_id,
            "requested range starts past the end of the object."
        );
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (metadata != NULL) {
        if (king_object_store_meta_read(object_id, metadata) != SUCCESS) {
            memset(metadata, 0, sizeof(*metadata));
            strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
            metadata->content_length = (uint64_t) st.st_size;
            metadata->created_at = st.st_mtime;
            metadata->modified_at = st.st_mtime;
            metadata->version = 1;
        } else if (king_object_store_metadata_is_expired_now(metadata)) {
            return FAILURE;
        }
    } else if (king_object_store_meta_read(object_id, &resolved_metadata) == SUCCESS
        && king_object_store_metadata_is_expired_now(&resolved_metadata)) {
        return FAILURE;
    }

    bytes_to_read = (size_t) ((uint64_t) st.st_size - (uint64_t) offset);
    if (has_length && length < bytes_to_read) {
        bytes_to_read = length;
    }

    fp = fopen(file_path, "rb");
    if (fp == NULL) {
        king_object_store_set_local_failure_error("range read", object_id, strerror(errno));
        return FAILURE;
    }
    if (fseeko(fp, (off_t) offset, SEEK_SET) != 0) {
        fclose(fp);
        king_object_store_set_local_failure_error("range read", object_id, "could not seek to the requested offset.");
        return FAILURE;
    }

    *data = pecalloc(1, bytes_to_read + 1, 1);
    if (*data == NULL) {
        fclose(fp);
        king_object_store_set_local_failure_error("range read", object_id, "could not allocate a range-read buffer.");
        return FAILURE;
    }
    if (bytes_to_read > 0 && fread(*data, 1, bytes_to_read, fp) != bytes_to_read) {
        pefree(*data, 1);
        *data = NULL;
        fclose(fp);
        king_object_store_set_local_failure_error("range read", object_id, "could not read the requested range bytes.");
        return FAILURE;
    }

    fclose(fp);
    *data_size = bytes_to_read;
    return SUCCESS;
}

int king_object_store_local_fs_read_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata
)
{
    char file_path[1024];
    struct stat st;
    king_object_metadata_t resolved_metadata;
    king_object_metadata_t *metadata_ptr = metadata;
    char computed_sha256[65];
    uint64_t source_size = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL || destination_stream == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_local_fs_root_available("read")) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_local_failure_error("read", object_id, strerror(errno));
        return FAILURE;
    }
    if ((uint64_t) offset > (uint64_t) st.st_size) {
        king_object_store_set_local_failure_error(
            "range read",
            object_id,
            "requested range starts past the end of the object."
        );
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (metadata_ptr == NULL) {
        metadata_ptr = &resolved_metadata;
    }
    if (king_object_store_meta_read(object_id, metadata_ptr) != SUCCESS) {
        memset(metadata_ptr, 0, sizeof(*metadata_ptr));
        strncpy(metadata_ptr->object_id, object_id, sizeof(metadata_ptr->object_id) - 1);
        metadata_ptr->content_length = (uint64_t) st.st_size;
        metadata_ptr->created_at = st.st_mtime;
        metadata_ptr->modified_at = st.st_mtime;
        metadata_ptr->version = 1;
    } else if (king_object_store_metadata_is_expired_now(metadata_ptr)) {
        return FAILURE;
    }

    if (!has_length && offset == 0 && metadata_ptr->integrity_sha256[0] != '\0') {
        if (king_object_store_compute_sha256_hex_for_path(file_path, computed_sha256, &source_size) != SUCCESS) {
            king_object_store_set_local_failure_error("read", object_id, "could not compute integrity_sha256 for the committed payload.");
            return FAILURE;
        }
        if (strcmp(computed_sha256, metadata_ptr->integrity_sha256) != 0 || source_size != metadata_ptr->content_length) {
            king_object_store_set_local_failure_error("read", object_id, "integrity validation failed.");
            return FAILURE;
        }
    }

    return king_object_store_copy_path_to_stream(
        file_path,
        destination_stream,
        offset,
        length,
        has_length
    );
}

static int king_object_store_local_fs_payload_is_missing(const char *object_id)
{
    char file_path[1024];
    struct stat st;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        return 0;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (stat(file_path, &st) == 0) {
        return 0;
    }

    return errno == ENOENT ? 1 : 0;
}

int king_object_store_local_fs_remove(const char *object_id)
{
    char file_path[1024];
    struct stat st;
    king_object_metadata_t metadata;
    uint64_t removed_size = 0;
    zend_bool size_resolved = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_local_fs_root_available("delete")) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);

    if (king_object_store_runtime_metadata_cache_read(object_id, &metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &metadata) == SUCCESS) {
        removed_size = metadata.content_length;
        size_resolved = 1;
    } else if (stat(file_path, &st) == 0) {
        removed_size = (uint64_t) st.st_size;
        size_resolved = 1;
    }

    if (unlink(file_path) != 0) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_local_failure_error("delete", object_id, strerror(errno));
        return FAILURE;
    }

    if (size_resolved && king_object_store_runtime.current_stored_bytes >= removed_size) {
        king_object_store_runtime.current_stored_bytes -= removed_size;
    }
    if (king_object_store_runtime.current_object_count > 0) {
        king_object_store_runtime.current_object_count--;
    }

    /* Remove durable metadata sidecar */
    king_object_store_meta_remove(object_id);

    /* Auto-invalidate matching CDN cache entry */
    king_object_store_invalidate_cdn_cache_entry(object_id);

    return SUCCESS;
}

int king_object_store_local_fs_list(zval *return_array)
{
    DIR *dir;
    struct dirent *ent;
    struct stat st;
    char file_path[1024];
    zval exported_entry;
    king_object_metadata_t metadata;
    king_object_metadata_t *metadata_ptr;

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }
    if (!king_object_store_local_fs_root_available("list")) {
        return FAILURE;
    }

    dir = opendir(king_object_store_runtime.config.storage_root_path);
    if (dir == NULL) {
        king_object_store_set_local_failure_error("list", NULL, strerror(errno));
        return FAILURE;
    }

    while ((ent = readdir(dir)) != NULL) {
        if (ent->d_name[0] == '.') {
            continue;
        }
        king_object_store_build_path(file_path, sizeof(file_path), ent->d_name);
        if (stat(file_path, &st) == 0 && S_ISREG(st.st_mode)) {
            /* Skip .meta sidecar files from the object listing */
            size_t nlen = strlen(ent->d_name);
            if (nlen > 5 && strcmp(ent->d_name + nlen - 5, ".meta") == 0) {
                continue;
            }
            metadata_ptr = NULL;
            if (king_object_store_meta_read(ent->d_name, &metadata) == SUCCESS) {
                if (king_object_store_metadata_is_expired_now(&metadata)) {
                    continue;
                }
                metadata_ptr = &metadata;
            }
            king_object_store_list_entry_from_metadata(
                &exported_entry,
                ent->d_name,
                metadata_ptr,
                (uint64_t) st.st_size,
                st.st_mtime
            );
            add_next_index_zval(return_array, &exported_entry);
        }
    }

    closedir(dir);
    return SUCCESS;
}

static int king_object_store_is_meta_filename(const char *name)
{
    size_t name_len;

    if (name == NULL || name[0] == '\0' || name[0] == '.') {
        return 1;
    }

    name_len = strlen(name);
    if (name_len > 5 && strcmp(name + name_len - 5, ".meta") == 0) {
        return 1;
    }

    return 0;
}

static void king_object_store_fill_fallback_metadata(
    king_object_metadata_t *metadata,
    const char *object_id,
    uint64_t content_length,
    time_t fallback_timestamp)
{
    if (metadata == NULL || object_id == NULL) {
        return;
    }

    memset(metadata, 0, sizeof(*metadata));
    strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
    metadata->content_length = content_length;
    metadata->created_at = fallback_timestamp;
    metadata->modified_at = fallback_timestamp;
}

zend_bool king_object_store_runtime_capacity_is_enabled(void)
{
    return king_object_store_runtime.initialized
        && king_object_store_runtime.config.max_storage_size_bytes > 0;
}

const char *king_object_store_runtime_capacity_mode_to_string(void)
{
    return king_object_store_runtime_capacity_is_enabled()
        ? "logical_hard_limit"
        : "disabled";
}

uint64_t king_object_store_runtime_capacity_available_bytes(void)
{
    uint64_t limit_bytes;

    if (!king_object_store_runtime_capacity_is_enabled()) {
        return 0;
    }

    limit_bytes = king_object_store_runtime.config.max_storage_size_bytes;
    if (king_object_store_runtime.current_stored_bytes >= limit_bytes) {
        return 0;
    }

    return limit_bytes - king_object_store_runtime.current_stored_bytes;
}

static int king_object_store_runtime_capacity_resolve_existing_size(
    const char *object_id,
    uint64_t *old_size_out,
    zend_bool *had_existing_object_out
)
{
    char file_path[1024];
    struct stat st_old;
    king_object_metadata_t existing_metadata;
    king_storage_backend_t primary_backend;

    if (old_size_out == NULL || had_existing_object_out == NULL) {
        return FAILURE;
    }

    *old_size_out = 0;
    *had_existing_object_out = 0;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_runtime_metadata_cache_read(object_id, &existing_metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &existing_metadata) == SUCCESS) {
        *old_size_out = existing_metadata.content_length;
        *had_existing_object_out = 1;
        return SUCCESS;
    }

    primary_backend = king_object_store_normalize_backend(
        king_object_store_runtime.config.primary_backend
    );
    if (primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
            return FAILURE;
        }
    } else {
        king_object_store_build_path(file_path, sizeof(file_path), object_id);
    }
    if (stat(file_path, &st_old) == 0 && S_ISREG(st_old.st_mode)) {
        *old_size_out = (uint64_t) st_old.st_size;
        *had_existing_object_out = 1;
    }

    return SUCCESS;
}

static zend_bool king_object_store_runtime_capacity_allows_rewrite(
    uint64_t new_size,
    zend_bool had_existing_object,
    uint64_t old_size,
    uint64_t *projected_total_out
)
{
    uint64_t projected_total;

    if (!king_object_store_runtime_capacity_is_enabled()) {
        if (projected_total_out != NULL) {
            *projected_total_out = king_object_store_runtime.current_stored_bytes;
        }
        return 1;
    }

    projected_total = king_object_store_runtime.current_stored_bytes;
    if (had_existing_object) {
        if (projected_total >= old_size) {
            projected_total -= old_size;
        } else {
            projected_total = 0;
        }
    }

    if (UINT64_MAX - projected_total < new_size) {
        if (projected_total_out != NULL) {
            *projected_total_out = UINT64_MAX;
        }
        return 0;
    }

    projected_total += new_size;
    if (projected_total_out != NULL) {
        *projected_total_out = projected_total;
    }

    return projected_total <= king_object_store_runtime.config.max_storage_size_bytes;
}

int king_object_store_runtime_capacity_check_rewrite(
    uint64_t new_size,
    zend_bool had_existing_object,
    uint64_t old_size,
    char *error,
    size_t error_size
)
{
    if (!king_object_store_runtime_capacity_allows_rewrite(
            new_size,
            had_existing_object,
            old_size,
            NULL
        )) {
        if (error != NULL && error_size > 0) {
            snprintf(
                error,
                error_size,
                "Object-store operation would exceed the configured runtime capacity."
            );
        }
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

int king_object_store_runtime_capacity_check_object_size(
    const char *object_id,
    uint64_t new_size,
    char *error,
    size_t error_size
)
{
    uint64_t old_size = 0;
    zend_bool had_existing_object = 0;

    if (king_object_store_runtime_capacity_resolve_existing_size(
            object_id,
            &old_size,
            &had_existing_object
        ) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(
                error,
                error_size,
                "Object-store operation could not resolve the current runtime capacity baseline."
            );
        }
        return FAILURE;
    }

    return king_object_store_runtime_capacity_check_rewrite(
        new_size,
        had_existing_object,
        old_size,
        error,
        error_size
    );
}

static int king_object_store_apply_local_fs_counters_for_rewrite(
    const char *object_id,
    uint64_t new_size,
    int had_old,
    uint64_t old_size
)
{
    if (had_old) {
        if (king_object_store_runtime.current_stored_bytes >= old_size) {
            king_object_store_runtime.current_stored_bytes -= old_size;
        }
    } else {
        king_object_store_runtime.current_object_count++;
    }

    king_object_store_runtime.current_stored_bytes += new_size;
    king_object_store_runtime.latest_object_at = time(NULL);

    return SUCCESS;
}

static int king_object_store_export_object(const char *object_id, const char *destination_directory)
{
    char destination_path[1024];
    char destination_meta_path[1024];
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL || destination_directory == NULL || destination_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "object export") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 1) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_ensure_directory_recursive(destination_directory) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 0) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_read_object(object_id, &data, &data_size, &metadata) != SUCCESS) {
        return FAILURE;
    }

    if (metadata.object_id[0] == '\0') {
        king_object_store_fill_fallback_metadata(&metadata, object_id, (uint64_t) data_size, time(NULL));
    }

    if (king_object_store_build_path_in_directory(destination_path, sizeof(destination_path), destination_directory, object_id, NULL) != SUCCESS
        || king_object_store_build_path_in_directory(
            destination_meta_path,
            sizeof(destination_meta_path),
            destination_directory,
            object_id,
            ".meta"
        ) != SUCCESS) {
        if (data != NULL) {
            pefree(data, 1);
        }
        return FAILURE;
    }

    if (king_object_store_mkdir_parents(destination_path) != SUCCESS
        || king_object_store_atomic_write_file(destination_path, data, data_size) != SUCCESS
        || king_object_store_meta_write_to_path(destination_meta_path, &metadata) != SUCCESS) {
        if (data != NULL) {
            pefree(data, 1);
        }
        unlink(destination_path);
        unlink(destination_meta_path);
        return FAILURE;
    }

    if (data != NULL) {
        pefree(data, 1);
    }

    return SUCCESS;
}

static int king_object_store_import_object(const char *object_id, const char *source_directory)
{
    char source_path[1024];
    char source_meta_path[1024];
    char capacity_error[256];
    struct stat source_stat;
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL || source_directory == NULL || source_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "object import") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(source_directory, 0) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_build_path_in_directory(source_path, sizeof(source_path), source_directory, object_id, NULL) != SUCCESS) {
        return FAILURE;
    }
    if (stat(source_path, &source_stat) != 0 || !S_ISREG(source_stat.st_mode)) {
        return FAILURE;
    }
    capacity_error[0] = '\0';
    if (king_object_store_runtime_capacity_check_object_size(
            object_id,
            (uint64_t) source_stat.st_size,
            capacity_error,
            sizeof(capacity_error)
        ) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            capacity_error
        );
        return FAILURE;
    }

    if (king_object_store_build_path_in_directory(
            source_meta_path,
            sizeof(source_meta_path),
            source_directory,
            object_id,
            ".meta"
        ) != SUCCESS
        || king_object_store_read_file_contents(source_path, &data, &data_size) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_meta_read_from_path(source_meta_path, &metadata) != SUCCESS) {
        king_object_store_fill_fallback_metadata(&metadata, object_id, (uint64_t) source_stat.st_size, source_stat.st_mtime);
    }

    if (metadata.object_id[0] == '\0') {
        strncpy(metadata.object_id, object_id, sizeof(metadata.object_id) - 1);
    }
    if (metadata.content_length == 0) {
        metadata.content_length = (uint64_t) source_stat.st_size;
    }

    if (king_object_store_write_object(object_id, data, data_size, &metadata) != SUCCESS) {
        if (data != NULL) {
            pefree(data, 1);
        }
        return FAILURE;
    }

    if (data != NULL) {
        pefree(data, 1);
    }

    return SUCCESS;
}

int king_object_store_backup_object(const char *object_id, const char *destination_path)
{
    return king_object_store_export_object(object_id, destination_path);
}

int king_object_store_restore_object(const char *object_id, const char *source_path)
{
    return king_object_store_import_object(object_id, source_path);
}

int king_object_store_backup_all_objects(const char *destination_directory)
{
    zval objects;
    zval *entry;

    if (!king_object_store_runtime.initialized || destination_directory == NULL || destination_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "batch object export") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 1) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_ensure_directory_recursive(destination_directory) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 0) != SUCCESS) {
        return FAILURE;
    }

    array_init(&objects);
    if (king_object_store_list_object(&objects) != SUCCESS) {
        zval_ptr_dtor(&objects);
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(objects), entry) {
        zval *object_id_zv;

        if (Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        object_id_zv = zend_hash_str_find(Z_ARRVAL_P(entry), "object_id", sizeof("object_id") - 1);
        if (object_id_zv == NULL || Z_TYPE_P(object_id_zv) != IS_STRING) {
            continue;
        }

        if (king_object_store_backup_object(Z_STRVAL_P(object_id_zv), destination_directory) != SUCCESS) {
            zval_ptr_dtor(&objects);
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&objects);
    return SUCCESS;
}

int king_object_store_restore_all_objects(const char *source_directory)
{
    DIR *dir;
    struct dirent *ent;
    char source_path[1024];
    struct stat st;

    if (!king_object_store_runtime.initialized || source_directory == NULL || source_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "batch object import") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(source_directory, 0) != SUCCESS) {
        return FAILURE;
    }

    dir = opendir(source_directory);
    if (dir == NULL) {
        return FAILURE;
    }

    while ((ent = readdir(dir)) != NULL) {
        if (ent->d_name[0] == '.') {
            continue;
        }
        if (king_object_store_is_meta_filename(ent->d_name)) {
            continue;
        }
        if (king_object_store_object_id_validate(ent->d_name) != NULL) {
            closedir(dir);
            return FAILURE;
        }
        if (king_object_store_build_path_in_directory(source_path, sizeof(source_path), source_directory, ent->d_name, NULL) != SUCCESS) {
            closedir(dir);
            return FAILURE;
        }
        if (stat(source_path, &st) != 0) {
            closedir(dir);
            return FAILURE;
        }
        if (!S_ISREG(st.st_mode)) {
            continue;
        }
        if (king_object_store_restore_object(ent->d_name, source_directory) != SUCCESS) {
            closedir(dir);
            return FAILURE;
        }
    }

    closedir(dir);
    return SUCCESS;
}

/* --- Replication (runtime: local_fs single file = replica 1) --- */

int king_object_store_replicate_object(const char *object_id, uint32_t replication_factor)
{
    king_object_metadata_t metadata;
    uint32_t achieved_real_copies;
    char replication_error[256];

    if (replication_factor == 0) {
        return SUCCESS;
    }

    if (object_id == NULL || object_id[0] == '\0') {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Object-store replication requires a valid object id."
        );
        return FAILURE;
    }

    if (king_object_store_meta_read(object_id, &metadata) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Object-store replication could not load metadata for the stored object."
        );
        return FAILURE;
    }

    achieved_real_copies = king_object_store_count_achieved_real_copies(&metadata);

    if (achieved_real_copies >= replication_factor) {
        metadata.replication_status = 2; /* 2: Completed */
        (void) king_object_store_meta_write(object_id, &metadata);
        return SUCCESS;
    }

    metadata.replication_status = 3; /* 3: Failed */
    (void) king_object_store_meta_write(object_id, &metadata);

    snprintf(
        replication_error,
        sizeof(replication_error),
        "Object-store replication requested replication_factor %u but runtime achieved only %u real copies.",
        replication_factor,
        achieved_real_copies
    );
    king_object_store_set_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        FAILURE,
        replication_error
    );
    return FAILURE;
}

static int king_object_store_backup_object_to_backend(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    king_storage_backend_t backup_backend)
{
    int rc = FAILURE;
    king_object_metadata_t metadata_snapshot;
    char backup_error[512] = {0};

    if (object_id == NULL || object_id[0] == '\0' || data == NULL) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Missing object payload for backup operation."
        );
        return FAILURE;
    }

    if (!king_object_store_runtime.initialized) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Object store is not initialized."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "backup",
            backup_backend,
            "object backup") == FAILURE) {
        return FAILURE;
    }

    switch (backup_backend) {
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_write_internal(
                object_id,
                data,
                data_size,
                metadata,
                0,
                backup_error,
                sizeof(backup_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_write(
                object_id,
                data,
                data_size,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_write(
                object_id,
                data,
                data_size,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_write(
                object_id,
                data,
                data_size,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Local backup backends are not implemented for a non-local primary object-store backend."
            );
            return FAILURE;
        default:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Unsupported backup object-store backend."
            );
            return FAILURE;
    }

    if (rc != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            backup_error[0] == '\0' ? "Backup object-store backend write failed." : backup_error
        );
        return FAILURE;
    }

    if (king_object_store_backend_read_metadata(object_id, &metadata_snapshot) == SUCCESS) {
        if (metadata_snapshot.is_backed_up == 0) {
            metadata_snapshot.is_backed_up = 1;
        }
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            king_object_store_runtime.config.primary_backend,
            1
        );
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            backup_backend,
            1
        );
        king_object_store_meta_write(object_id, &metadata_snapshot);
    }

    king_object_store_set_backend_runtime_result(
        "backup",
        backup_backend,
        SUCCESS,
        NULL
    );
    return SUCCESS;
}

static int king_object_store_remove_backup_object_from_backend(
    const char *object_id,
    king_storage_backend_t backup_backend)
{
    int rc = FAILURE;
    char backup_error[512] = {0};
    zend_bool removed = 0;

    if (object_id == NULL || object_id[0] == '\0') {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Missing object id for backup delete operation."
        );
        return FAILURE;
    }

    if (!king_object_store_runtime.initialized) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Object store is not initialized."
        );
        return FAILURE;
    }

    if (backup_backend == king_object_store_runtime.config.primary_backend) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            SUCCESS,
            NULL
        );
        return SUCCESS;
    }

    if (king_object_store_require_honest_backend(
            "backup",
            backup_backend,
            "object delete"
        ) == FAILURE) {
        return FAILURE;
    }

    switch (backup_backend) {
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_remove_internal(
                object_id,
                0,
                0,
                backup_error,
                sizeof(backup_error)
            );
            if (rc == KING_OBJECT_STORE_RESULT_MISS) {
                rc = SUCCESS;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_delete_backup_copy(
                object_id,
                backup_error,
                sizeof(backup_error),
                &removed
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_delete_backup_copy(
                object_id,
                backup_error,
                sizeof(backup_error),
                &removed
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_delete_backup_copy(
                object_id,
                backup_error,
                sizeof(backup_error),
                &removed
            );
            break;
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Local backup backends are not implemented for a non-local primary object-store backend."
            );
            return FAILURE;
        default:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Unsupported backup object-store backend."
            );
            return FAILURE;
    }

    if (rc != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            backup_error[0] == '\0' ? "Backup object-store backend delete failed." : backup_error
        );
        return FAILURE;
    }

    king_object_store_set_backend_runtime_result(
        "backup",
        backup_backend,
        SUCCESS,
        NULL
    );
    return SUCCESS;
}

static int king_object_store_local_fs_remove_with_real_backup_semantics(const char *object_id)
{
    king_object_metadata_t metadata_snapshot;
    zend_bool has_metadata = 0;
    int payload_exists;
    king_storage_backend_t backup_backend;
    int logical_exists;
    char primary_error[512];

    if (object_id == NULL || object_id[0] == '\0') {
        return FAILURE;
    }

    backup_backend = king_object_store_normalize_backend(
        king_object_store_runtime.config.backup_backend
    );
    if (backup_backend == king_object_store_normalize_backend(king_object_store_runtime.config.primary_backend)
        || !king_object_store_backend_is_real(backup_backend)) {
        return king_object_store_local_fs_remove(object_id);
    }
    if (!king_object_store_local_fs_root_available("delete")) {
        return FAILURE;
    }

    payload_exists = king_object_store_local_fs_payload_exists(object_id);
    if (king_object_store_meta_read(object_id, &metadata_snapshot) == SUCCESS
        || king_object_store_runtime_metadata_cache_read(object_id, &metadata_snapshot) == SUCCESS) {
        has_metadata = 1;
    }

    logical_exists = payload_exists || has_metadata;
    if (!logical_exists) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    if (king_object_store_remove_backup_object_from_backend(object_id, backup_backend) != SUCCESS) {
        snprintf(
            primary_error,
            sizeof(primary_error),
            "local_fs delete for '%s' could not complete because backup removal failed: %s",
            object_id,
            king_object_store_runtime.backup_adapter_error[0] != '\0'
                ? king_object_store_runtime.backup_adapter_error
                : "backup delete failed."
        );
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            primary_error
        );
        return FAILURE;
    }

    if (has_metadata) {
        if (backup_backend == KING_STORAGE_BACKEND_DISTRIBUTED
            || backup_backend == KING_STORAGE_BACKEND_CLOUD_S3
            || backup_backend == KING_STORAGE_BACKEND_CLOUD_GCS
            || backup_backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
            metadata_snapshot.is_backed_up = 0;
        }
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            backup_backend,
            0
        );
        king_object_store_reconcile_replication_status(&metadata_snapshot);
        if (king_object_store_meta_write(object_id, &metadata_snapshot) != SUCCESS) {
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Primary object-store delete removed the backup copy but failed to update local metadata."
            );
            return FAILURE;
        }
    }

    if (payload_exists) {
        return king_object_store_local_fs_remove(object_id);
    }

    king_object_store_meta_remove(object_id);
    king_object_store_invalidate_cdn_cache_entry(object_id);
    return SUCCESS;
}

static int king_object_store_distributed_root_available(
    const char *operation,
    char *error,
    size_t error_size
)
{
    struct stat root_stat;

    if (operation == NULL || operation[0] == '\0') {
        operation = "operation";
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            operation,
            NULL,
            "the configured storage_root_path is empty."
        );
        return 0;
    }

    if (stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            operation,
            NULL,
            strerror(errno)
        );
        return 0;
    }

    if (!S_ISDIR(root_stat.st_mode)) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            operation,
            NULL,
            "the configured storage_root_path is not a directory."
        );
        return 0;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return 1;
}

static int king_object_store_distributed_write_internal(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    zend_bool update_counters,
    char *error,
    size_t error_size
)
{
    char file_path[PATH_MAX];
    struct stat st_old;
    int is_overwrite = 0;
    zend_bool old_size_resolved = 0;
    uint64_t old_size = 0;
    king_object_metadata_t final_metadata;
    king_object_metadata_t existing_metadata;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("write", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_runtime_metadata_cache_read(object_id, &existing_metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &existing_metadata) == SUCCESS) {
        is_overwrite = 1;
        old_size = existing_metadata.content_length;
        old_size_resolved = 1;
    } else if (stat(file_path, &st_old) == 0 && S_ISREG(st_old.st_mode)) {
        is_overwrite = 1;
        old_size = (uint64_t) st_old.st_size;
        old_size_resolved = 1;
        strncpy(existing_metadata.object_id, object_id, sizeof(existing_metadata.object_id) - 1);
        existing_metadata.content_length = old_size;
        existing_metadata.created_at = st_old.st_mtime;
        existing_metadata.modified_at = st_old.st_mtime;
        existing_metadata.version = 1;
    }

    if (king_object_store_mkdir_parents(file_path) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "could not create the distributed payload directory."
        );
        return FAILURE;
    }
    if (king_object_store_atomic_write_file(file_path, data, data_size) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    if (update_counters) {
        if (is_overwrite) {
            if (old_size_resolved && king_object_store_runtime.current_stored_bytes >= old_size) {
                king_object_store_runtime.current_stored_bytes -= old_size;
            }
        } else {
            king_object_store_runtime.current_object_count++;
        }
        king_object_store_runtime.current_stored_bytes += data_size;
        king_object_store_runtime.latest_object_at = time(NULL);
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata != NULL) {
        final_metadata = *metadata;
    }
    if (final_metadata.object_id[0] == '\0') {
        strncpy(final_metadata.object_id, object_id, sizeof(final_metadata.object_id) - 1);
    }
    if (final_metadata.content_length == 0) {
        final_metadata.content_length = data_size;
    }
    king_object_store_prepare_metadata_for_write(
        object_id,
        data,
        data_size,
        &final_metadata,
        is_overwrite ? &existing_metadata : NULL
    );
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_DISTRIBUTED,
        1
    );

    if (king_object_store_meta_write(object_id, &final_metadata) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "could not persist the shared object metadata sidecar."
        );
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_write_from_file_internal(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    zend_bool update_counters,
    char *error,
    size_t error_size
)
{
    char file_path[PATH_MAX];
    struct stat source_st;
    struct stat st_old;
    int is_overwrite = 0;
    zend_bool old_size_resolved = 0;
    uint64_t old_size = 0;
    king_object_metadata_t final_metadata;
    king_object_metadata_t existing_metadata;
    char computed_sha256[65];
    uint64_t source_size = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL || source_path == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("write", error, error_size)) {
        return FAILURE;
    }
    if (stat(source_path, &source_st) != 0 || !S_ISREG(source_st.st_mode)) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "the upload source path is unavailable."
        );
        return FAILURE;
    }
    if (king_object_store_compute_sha256_hex_for_path(source_path, computed_sha256, &source_size) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "could not compute integrity_sha256 for the source payload."
        );
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_runtime_metadata_cache_read(object_id, &existing_metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &existing_metadata) == SUCCESS) {
        is_overwrite = 1;
        old_size = existing_metadata.content_length;
        old_size_resolved = 1;
    } else if (stat(file_path, &st_old) == 0 && S_ISREG(st_old.st_mode)) {
        is_overwrite = 1;
        old_size = (uint64_t) st_old.st_size;
        old_size_resolved = 1;
        strncpy(existing_metadata.object_id, object_id, sizeof(existing_metadata.object_id) - 1);
        existing_metadata.content_length = old_size;
        existing_metadata.created_at = st_old.st_mtime;
        existing_metadata.modified_at = st_old.st_mtime;
        existing_metadata.version = 1;
    }

    if (king_object_store_copy_file_to_path(source_path, file_path) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    if (update_counters) {
        if (is_overwrite) {
            if (old_size_resolved && king_object_store_runtime.current_stored_bytes >= old_size) {
                king_object_store_runtime.current_stored_bytes -= old_size;
            }
        } else {
            king_object_store_runtime.current_object_count++;
        }
        king_object_store_runtime.current_stored_bytes += source_size;
        king_object_store_runtime.latest_object_at = time(NULL);
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata != NULL) {
        final_metadata = *metadata;
    }
    king_object_store_finalize_metadata_for_write(
        object_id,
        source_size,
        computed_sha256,
        &final_metadata,
        is_overwrite ? &existing_metadata : NULL
    );
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_DISTRIBUTED,
        1
    );

    if (king_object_store_meta_write(object_id, &final_metadata) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "could not persist the shared object metadata sidecar."
        );
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_read_internal(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size
)
{
    FILE *fp;
    char file_path[PATH_MAX];
    struct stat st;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL || data_size == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("read", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    if (stat(file_path, &st) != 0) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    if (metadata != NULL) {
        if (king_object_store_meta_read(object_id, metadata) != SUCCESS) {
            memset(metadata, 0, sizeof(*metadata));
            strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
            metadata->content_length = (uint64_t) st.st_size;
            metadata->created_at = st.st_mtime;
            metadata->modified_at = st.st_mtime;
            metadata->version = 1;
            metadata->distributed_present = 1;
        } else if (king_object_store_metadata_is_expired_now(metadata)) {
            return FAILURE;
        }
    }

    fp = fopen(file_path, "rb");
    if (fp == NULL) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    *data_size = (size_t) st.st_size;
    *data = pecalloc(1, *data_size + 1, 1);
    if (*data == NULL) {
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not allocate a read buffer."
        );
        return FAILURE;
    }

    if (fread(*data, 1, *data_size, fp) != *data_size) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not read the committed payload bytes."
        );
        return FAILURE;
    }

    fclose(fp);
    if (metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        if (error != NULL && error_size > 0) {
            snprintf(
                error,
                error_size,
                "distributed read for '%s' failed: integrity validation failed.",
                object_id
            );
        }
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_read_range_internal(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size
)
{
    FILE *fp;
    char file_path[PATH_MAX];
    struct stat st;
    size_t bytes_to_read;
    king_object_metadata_t resolved_metadata;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL || data_size == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("range read", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    *data = NULL;
    *data_size = 0;

    if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    if ((uint64_t) offset > (uint64_t) st.st_size) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "requested range starts past the end of the object."
        );
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (metadata != NULL) {
        if (king_object_store_meta_read(object_id, metadata) != SUCCESS) {
            memset(metadata, 0, sizeof(*metadata));
            strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
            metadata->content_length = (uint64_t) st.st_size;
            metadata->created_at = st.st_mtime;
            metadata->modified_at = st.st_mtime;
            metadata->version = 1;
            metadata->distributed_present = 1;
        } else if (king_object_store_metadata_is_expired_now(metadata)) {
            return FAILURE;
        }
    } else if (king_object_store_meta_read(object_id, &resolved_metadata) == SUCCESS
        && king_object_store_metadata_is_expired_now(&resolved_metadata)) {
        return FAILURE;
    }

    bytes_to_read = (size_t) ((uint64_t) st.st_size - (uint64_t) offset);
    if (has_length && length < bytes_to_read) {
        bytes_to_read = length;
    }

    fp = fopen(file_path, "rb");
    if (fp == NULL) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }
    if (fseeko(fp, (off_t) offset, SEEK_SET) != 0) {
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not seek to the requested offset."
        );
        return FAILURE;
    }

    *data = pecalloc(1, bytes_to_read + 1, 1);
    if (*data == NULL) {
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not allocate a range-read buffer."
        );
        return FAILURE;
    }
    if (bytes_to_read > 0 && fread(*data, 1, bytes_to_read, fp) != bytes_to_read) {
        pefree(*data, 1);
        *data = NULL;
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not read the requested range bytes."
        );
        return FAILURE;
    }

    fclose(fp);
    *data_size = bytes_to_read;
    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_read_to_stream_internal(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size
)
{
    char file_path[PATH_MAX];
    struct stat st;
    king_object_metadata_t resolved_metadata;
    king_object_metadata_t *metadata_ptr = metadata;
    char computed_sha256[65];
    uint64_t source_size = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL || destination_stream == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("read", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }
    if ((uint64_t) offset > (uint64_t) st.st_size) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "requested range starts past the end of the object."
        );
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (metadata_ptr == NULL) {
        metadata_ptr = &resolved_metadata;
    }
    if (king_object_store_meta_read(object_id, metadata_ptr) != SUCCESS) {
        memset(metadata_ptr, 0, sizeof(*metadata_ptr));
        strncpy(metadata_ptr->object_id, object_id, sizeof(metadata_ptr->object_id) - 1);
        metadata_ptr->content_length = (uint64_t) st.st_size;
        metadata_ptr->created_at = st.st_mtime;
        metadata_ptr->modified_at = st.st_mtime;
        metadata_ptr->version = 1;
        metadata_ptr->distributed_present = 1;
    } else if (king_object_store_metadata_is_expired_now(metadata_ptr)) {
        return FAILURE;
    }

    if (!has_length && offset == 0 && metadata_ptr->integrity_sha256[0] != '\0') {
        if (king_object_store_compute_sha256_hex_for_path(file_path, computed_sha256, &source_size) != SUCCESS) {
            king_object_store_set_backend_filesystem_error(
                error,
                error_size,
                "distributed",
                "read",
                object_id,
                "could not compute integrity_sha256 for the committed payload."
            );
            return FAILURE;
        }
        if (strcmp(computed_sha256, metadata_ptr->integrity_sha256) != 0
            || source_size != metadata_ptr->content_length) {
            king_object_store_set_backend_filesystem_error(
                error,
                error_size,
                "distributed",
                "read",
                object_id,
                "integrity validation failed."
            );
            return FAILURE;
        }
    }

    if (king_object_store_copy_path_to_stream(
            file_path,
            destination_stream,
            offset,
            length,
            has_length
        ) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not stream the committed payload bytes."
        );
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_remove_internal(
    const char *object_id,
    zend_bool update_counters,
    zend_bool remove_metadata,
    char *error,
    size_t error_size
)
{
    char file_path[PATH_MAX];
    struct stat st;
    king_object_metadata_t metadata;
    uint64_t removed_size = 0;
    zend_bool size_resolved = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("delete", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "delete",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    if (king_object_store_runtime_metadata_cache_read(object_id, &metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &metadata) == SUCCESS) {
        removed_size = metadata.content_length;
        size_resolved = 1;
    } else if (stat(file_path, &st) == 0) {
        removed_size = (uint64_t) st.st_size;
        size_resolved = 1;
    }

    if (unlink(file_path) != 0) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "delete",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    if (update_counters) {
        if (size_resolved && king_object_store_runtime.current_stored_bytes >= removed_size) {
            king_object_store_runtime.current_stored_bytes -= removed_size;
        }
        if (king_object_store_runtime.current_object_count > 0) {
            king_object_store_runtime.current_object_count--;
        }
    }

    if (remove_metadata) {
        king_object_store_meta_remove(object_id);
        king_object_store_invalidate_cdn_cache_entry(object_id);
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_list_internal(
    zval *return_array,
    char *error,
    size_t error_size
)
{
    DIR *dir;
    struct dirent *ent;
    struct stat st;
    char dir_path[PATH_MAX];
    char file_path[PATH_MAX];
    zval exported_entry;
    king_object_metadata_t metadata;
    king_object_metadata_t *metadata_ptr;

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("list", error, error_size)) {
        return FAILURE;
    }

    king_object_store_build_distributed_objects_dir_path(dir_path, sizeof(dir_path));
    dir = opendir(dir_path);
    if (dir == NULL) {
        if (errno == ENOENT) {
            return SUCCESS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "list",
            NULL,
            strerror(errno)
        );
        return FAILURE;
    }

    while ((ent = readdir(dir)) != NULL) {
        if (ent->d_name[0] == '.') {
            continue;
        }
        if (king_object_store_build_distributed_path(file_path, sizeof(file_path), ent->d_name) != SUCCESS) {
            continue;
        }
        if (stat(file_path, &st) == 0 && S_ISREG(st.st_mode)) {
            metadata_ptr = NULL;
            if (king_object_store_meta_read(ent->d_name, &metadata) == SUCCESS) {
                if (king_object_store_metadata_is_expired_now(&metadata)) {
                    continue;
                }
                metadata_ptr = &metadata;
            }
            king_object_store_list_entry_from_metadata(
                &exported_entry,
                ent->d_name,
                metadata_ptr,
                (uint64_t) st.st_size,
                st.st_mtime
            );
            add_next_index_zval(return_array, &exported_entry);
        }
    }

    closedir(dir);
    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_remove_with_real_backup_semantics(const char *object_id)
{
    king_object_metadata_t metadata_snapshot;
    zend_bool has_metadata = 0;
    int payload_exists;
    king_storage_backend_t backup_backend;
    int logical_exists;
    char primary_error[512];

    if (object_id == NULL || object_id[0] == '\0') {
        return FAILURE;
    }

    backup_backend = king_object_store_normalize_backend(
        king_object_store_runtime.config.backup_backend
    );
    if (backup_backend == king_object_store_normalize_backend(king_object_store_runtime.config.primary_backend)
        || !king_object_store_backend_is_real(backup_backend)) {
        return king_object_store_distributed_remove_internal(
            object_id,
            1,
            1,
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        );
    }
    if (!king_object_store_distributed_root_available(
            "delete",
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        )) {
        return FAILURE;
    }

    payload_exists = king_object_store_distributed_payload_exists(object_id);
    if (king_object_store_meta_read(object_id, &metadata_snapshot) == SUCCESS
        || king_object_store_runtime_metadata_cache_read(object_id, &metadata_snapshot) == SUCCESS) {
        has_metadata = 1;
    }

    logical_exists = payload_exists || has_metadata;
    if (!logical_exists) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    if (king_object_store_remove_backup_object_from_backend(object_id, backup_backend) != SUCCESS) {
        snprintf(
            primary_error,
            sizeof(primary_error),
            "distributed delete for '%s' could not complete because backup removal failed: %s",
            object_id,
            king_object_store_runtime.backup_adapter_error[0] != '\0'
                ? king_object_store_runtime.backup_adapter_error
                : "backup delete failed."
        );
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            primary_error
        );
        return FAILURE;
    }

    if (has_metadata) {
        if (backup_backend == KING_STORAGE_BACKEND_DISTRIBUTED
            || backup_backend == KING_STORAGE_BACKEND_CLOUD_S3
            || backup_backend == KING_STORAGE_BACKEND_CLOUD_GCS
            || backup_backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
            metadata_snapshot.is_backed_up = 0;
        }
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            backup_backend,
            0
        );
        king_object_store_reconcile_replication_status(&metadata_snapshot);
        if (king_object_store_meta_write(object_id, &metadata_snapshot) != SUCCESS) {
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Primary object-store delete removed the backup copy but failed to update distributed metadata."
            );
            return FAILURE;
        }
    }

    if (payload_exists) {
        return king_object_store_distributed_remove_internal(
            object_id,
            1,
            1,
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        );
    }

    king_object_store_meta_remove(object_id);
    king_object_store_invalidate_cdn_cache_entry(object_id);
    return SUCCESS;
}

/* --- CDN stubs --- */

int king_cdn_distribute_object(
    const char *object_id,
    const king_storage_node_t *edge_nodes,
    uint32_t node_count)
{
    if (object_id == NULL || node_count == 0) {
        return SUCCESS;
    }

    king_object_metadata_t metadata;
    if (king_object_store_meta_read(object_id, &metadata) == SUCCESS) {
        metadata.is_distributed = 1;
        metadata.distribution_peer_count = node_count;
        king_object_store_meta_write(object_id, &metadata);
    }

    return SUCCESS;
}

int king_cdn_find_optimal_edge_node(const char *client_ip, king_storage_node_t **optimal_node)
{
    (void) client_ip;
    if (optimal_node != NULL) {
        *optimal_node = NULL;
    }
    return FAILURE; /* runtime: no provisioned edge nodes */
}

int king_object_store_cleanup_expired_objects(
    uint64_t *scanned_out,
    uint64_t *removed_out,
    uint64_t *bytes_reclaimed_out,
    uint64_t *failures_out
)
{
    DIR *dir;
    struct dirent *ent;
    char meta_path[1024];
    char object_id[256];
    king_object_metadata_t metadata;
    uint64_t scanned = 0;
    uint64_t removed = 0;
    uint64_t bytes_reclaimed = 0;
    uint64_t failures = 0;

    if (scanned_out != NULL) {
        *scanned_out = 0;
    }
    if (removed_out != NULL) {
        *removed_out = 0;
    }
    if (bytes_reclaimed_out != NULL) {
        *bytes_reclaimed_out = 0;
    }
    if (failures_out != NULL) {
        *failures_out = 0;
    }

    king_cdn_sweep_expired();

    if (!king_object_store_runtime.initialized
        || king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return SUCCESS;
    }

    dir = opendir(king_object_store_runtime.config.storage_root_path);
    if (dir == NULL) {
        return FAILURE;
    }

    while ((ent = readdir(dir)) != NULL) {
        size_t nlen;

        if (ent->d_name[0] == '.') {
            continue;
        }

        nlen = strlen(ent->d_name);
        if (nlen <= 5 || strcmp(ent->d_name + nlen - 5, ".meta") != 0) {
            continue;
        }

        if (snprintf(
                meta_path,
                sizeof(meta_path),
                "%s/%s",
                king_object_store_runtime.config.storage_root_path,
                ent->d_name
            ) >= (int) sizeof(meta_path)) {
            continue;
        }

        if (king_object_store_meta_read_from_path(meta_path, &metadata) != SUCCESS) {
            continue;
        }

        scanned++;
        if (!king_object_store_metadata_is_expired_now(&metadata)) {
            continue;
        }

        memset(object_id, 0, sizeof(object_id));
        if (metadata.object_id[0] != '\0') {
            strncpy(object_id, metadata.object_id, sizeof(object_id) - 1);
        } else {
            memcpy(object_id, ent->d_name, nlen - 5);
            object_id[nlen - 5] = '\0';
        }

        if (king_object_store_remove_object(object_id) == SUCCESS) {
            removed++;
            bytes_reclaimed += metadata.content_length;
        } else {
            failures++;
        }
    }

    closedir(dir);

    if (scanned_out != NULL) {
        *scanned_out = scanned;
    }
    if (removed_out != NULL) {
        *removed_out = removed;
    }
    if (bytes_reclaimed_out != NULL) {
        *bytes_reclaimed_out = bytes_reclaimed;
    }
    if (failures_out != NULL) {
        *failures_out = failures;
    }

    return SUCCESS;
}

/* --- Backend-specific simulated drivers (Runtime) --- */

static int king_object_store_simulated_backend_build_paths(
    const char *subdir,
    char *path,
    size_t path_len,
    const char *object_id
)
{
    char base_path[1024];
    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return FAILURE;
    }

    if (object_id == NULL || object_id[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    int full_len = snprintf(base_path, sizeof(base_path), "%s/%s", king_object_store_runtime.config.storage_root_path, subdir);
    if (full_len < 0 || (size_t) full_len >= sizeof(base_path)) {
        return FAILURE;
    }
    mkdir(base_path, 0755);

    if (snprintf(path, path_len, "%s/%s", base_path, object_id) >= (int) path_len) {
        return FAILURE;
    }
    return SUCCESS;
}

static int king_object_store_simulated_backend_write(const char *subdir, const char *object_id, const void *data, size_t data_size)
{
    char file_path[2048];
    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_simulated_backend_build_paths(subdir, file_path, sizeof(file_path), object_id) != SUCCESS) {
        return FAILURE;
    }

    FILE *fp = fopen(file_path, "wb");
    if (!fp) {
        return FAILURE;
    }
    if (fwrite(data, 1, data_size, fp) != data_size) {
        fclose(fp);
        unlink(file_path);
        return FAILURE;
    }
    fclose(fp);
    return SUCCESS;
}

static int king_object_store_simulated_backend_read(const char *subdir, const char *object_id, void **data, size_t *data_size)
{
    char file_path[2048];
    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return FAILURE;
    }

    if (king_object_store_simulated_backend_build_paths(subdir, file_path, sizeof(file_path), object_id) != SUCCESS) {
        return FAILURE;
    }

    struct stat st;
    if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
        return FAILURE;
    }

    *data_size = st.st_size;
    *data = pemalloc(*data_size, 1);
    if (*data == NULL) {
        *data_size = 0;
        return FAILURE;
    }

    FILE *fp = fopen(file_path, "rb");
    if (!fp) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        return FAILURE;
    }
    if (fread(*data, 1, *data_size, fp) != *data_size) {
        fclose(fp);
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        return FAILURE;
    }
    fclose(fp);
    return SUCCESS;
}

static int king_object_store_simulated_backend_remove(const char *subdir, const char *object_id)
{
    char file_path[2048];
    if (king_object_store_simulated_backend_build_paths(subdir, file_path, sizeof(file_path), object_id) != SUCCESS) {
        return FAILURE;
    }

    return (unlink(file_path) == 0) ? SUCCESS : FAILURE;
}

static int king_object_store_simulated_backend_list(const char *subdir, zval *return_array)
{
    if (!king_object_store_runtime.config.storage_root_path[0]) {
        return FAILURE;
    }

    char dir_path[1024];
    int dir_len = snprintf(dir_path, sizeof(dir_path), "%s/%s", king_object_store_runtime.config.storage_root_path, subdir);
    if (dir_len < 0 || (size_t) dir_len >= sizeof(dir_path)) {
        return FAILURE;
    }

    DIR *dir = opendir(dir_path);
    if (dir == NULL) {
        return FAILURE;
    }

    struct dirent *ent;
    struct stat st;
    char file_path[2048];
    zval exported_entry;
    while ((ent = readdir(dir)) != NULL) {
        if (ent->d_name[0] == '.') {
            continue;
        }
        size_t nlen = strlen(ent->d_name);
        if (nlen > 5 && strcmp(ent->d_name + nlen - 5, ".meta") == 0) {
            continue;
        }

        if (snprintf(file_path, sizeof(file_path), "%s/%s", dir_path, ent->d_name) >= (int) sizeof(file_path)) {
            continue;
        }
        if (stat(file_path, &st) == 0 && S_ISREG(st.st_mode)) {
            array_init(&exported_entry);
            add_assoc_string(&exported_entry, "object_id", ent->d_name);
            add_assoc_long(&exported_entry, "size_bytes", (zend_long) st.st_size);
            add_assoc_long(&exported_entry, "stored_at",  (zend_long) st.st_mtime);
            add_next_index_zval(return_array, &exported_entry);
        }
    }

    closedir(dir);
    return SUCCESS;
}

static int king_object_store_gcs_simulate_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata)
{
    (void) metadata;
    return king_object_store_simulated_backend_write("gcs", object_id, data, data_size);
}

static int king_object_store_azure_simulate_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata)
{
    (void) metadata;
    return king_object_store_simulated_backend_write("azure", object_id, data, data_size);
}

static int king_object_store_s3_simulate_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata)
{
    (void) metadata;
    return king_object_store_simulated_backend_write("s3", object_id, data, data_size);
}

static int king_object_store_memcached_simulate_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata)
{
    (void) metadata;
    return king_object_store_simulated_backend_write("memcached", object_id, data, data_size);
}

static int king_object_store_s3_simulate_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_simulated_backend_read("s3", object_id, data, data_size);
}

static int king_object_store_gcs_simulate_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_simulated_backend_read("gcs", object_id, data, data_size);
}

static int king_object_store_azure_simulate_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_simulated_backend_read("azure", object_id, data, data_size);
}

static int king_object_store_distributed_simulate_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_simulated_backend_read("memcached", object_id, data, data_size);
}

static int king_object_store_s3_simulate_remove(const char *object_id)
{
    return king_object_store_simulated_backend_remove("s3", object_id);
}

static int king_object_store_gcs_simulate_remove(const char *object_id)
{
    return king_object_store_simulated_backend_remove("gcs", object_id);
}

static int king_object_store_azure_simulate_remove(const char *object_id)
{
    return king_object_store_simulated_backend_remove("azure", object_id);
}

static int king_object_store_distributed_simulate_remove(const char *object_id)
{
    return king_object_store_simulated_backend_remove("memcached", object_id);
}

static int king_object_store_distributed_simulate_list(zval *return_array)
{
    return king_object_store_simulated_backend_list("memcached", return_array);
}

static int king_object_store_s3_simulate_list(zval *return_array)
{
    return king_object_store_simulated_backend_list("s3", return_array);
}

static int king_object_store_gcs_simulate_list(zval *return_array)
{
    return king_object_store_simulated_backend_list("gcs", return_array);
}

static int king_object_store_azure_simulate_list(zval *return_array)
{
    return king_object_store_simulated_backend_list("azure", return_array);
}

static void king_object_store_build_range_header(
    size_t offset,
    size_t length,
    zend_bool has_length,
    char *buffer,
    size_t buffer_size
)
{
    if (buffer == NULL || buffer_size == 0) {
        return;
    }

    if (has_length) {
        if (length == 0) {
            snprintf(buffer, buffer_size, "Range: bytes=%zu-%zu", offset, offset);
            return;
        }

        snprintf(
            buffer,
            buffer_size,
            "Range: bytes=%zu-%zu",
            offset,
            offset + length - 1
        );
        return;
    }

    snprintf(buffer, buffer_size, "Range: bytes=%zu-", offset);
}

static void king_object_store_list_entry_from_metadata(
    zval *entry,
    const char *object_id,
    const king_object_metadata_t *metadata,
    uint64_t fallback_size,
    time_t fallback_timestamp
)
{
    uint64_t size_bytes = fallback_size;
    time_t stored_at = fallback_timestamp;

    array_init(entry);
    add_assoc_string(entry, "object_id", (char *) object_id);

    if (metadata != NULL) {
        if (metadata->content_length > 0) {
            size_bytes = metadata->content_length;
        }
        if (metadata->modified_at > 0) {
            stored_at = metadata->modified_at;
        } else if (metadata->created_at > 0) {
            stored_at = metadata->created_at;
        }

        add_assoc_string(entry, "content_type", metadata->content_type[0] != '\0' ? metadata->content_type : "");
        add_assoc_string(entry, "content_encoding", metadata->content_encoding[0] != '\0' ? metadata->content_encoding : "");
        add_assoc_string(entry, "etag", metadata->etag[0] != '\0' ? metadata->etag : "");
        add_assoc_string(entry, "integrity_sha256", metadata->integrity_sha256[0] != '\0' ? metadata->integrity_sha256 : "");
        add_assoc_string(entry, "object_type_name", (char *) king_object_type_to_string(metadata->object_type));
        add_assoc_string(entry, "cache_policy_name", (char *) king_cache_policy_to_string(metadata->cache_policy));
        add_assoc_long(entry, "version", (zend_long) metadata->version);
        add_assoc_long(entry, "content_length", (zend_long) metadata->content_length);
        add_assoc_long(entry, "created_at", (zend_long) metadata->created_at);
        add_assoc_long(entry, "modified_at", (zend_long) metadata->modified_at);
        add_assoc_long(entry, "expires_at", (zend_long) metadata->expires_at);
        add_assoc_bool(entry, "is_expired", king_object_store_metadata_is_expired_now(metadata));
        add_assoc_long(entry, "object_type", (zend_long) metadata->object_type);
        add_assoc_long(entry, "cache_policy", (zend_long) metadata->cache_policy);
        add_assoc_long(entry, "cache_ttl_seconds", (zend_long) metadata->cache_ttl_seconds);
        add_assoc_long(entry, "local_fs_present", (zend_long) metadata->local_fs_present);
        add_assoc_long(entry, "distributed_present", (zend_long) metadata->distributed_present);
        add_assoc_long(entry, "cloud_s3_present", (zend_long) metadata->cloud_s3_present);
        add_assoc_long(entry, "cloud_gcs_present", (zend_long) metadata->cloud_gcs_present);
        add_assoc_long(entry, "cloud_azure_present", (zend_long) metadata->cloud_azure_present);
        add_assoc_long(entry, "is_backed_up", (zend_long) metadata->is_backed_up);
        add_assoc_long(entry, "replication_status", (zend_long) metadata->replication_status);
        add_assoc_long(entry, "is_distributed", (zend_long) metadata->is_distributed);
        add_assoc_long(entry, "distribution_peer_count", (zend_long) metadata->distribution_peer_count);
    }

    add_assoc_long(entry, "size_bytes", (zend_long) size_bytes);
    add_assoc_long(entry, "stored_at", (zend_long) stored_at);
}

#include "cloud_s3.inc"
#include "cloud_gcs.inc"
#include "cloud_azure.inc"

static struct curl_slist *king_object_store_append_metadata_headers(
    struct curl_slist *headers,
    const char *prefix,
    const king_object_metadata_t *metadata
)
{
    char header[512];

    if (prefix == NULL || metadata == NULL) {
        return headers;
    }

    snprintf(header, sizeof(header), "%sobject-id: %s", prefix, metadata->object_id);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%scontent-length: %" PRIu64, prefix, metadata->content_length);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    if (metadata->etag[0] != '\0') {
        snprintf(header, sizeof(header), "%setag: %s", prefix, metadata->etag);
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);
    }
    if (metadata->integrity_sha256[0] != '\0') {
        snprintf(header, sizeof(header), "%sintegrity-sha256: %s", prefix, metadata->integrity_sha256);
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);
    }
    if (metadata->created_at > 0) {
        snprintf(header, sizeof(header), "%screated-at: %" PRId64, prefix, (int64_t) metadata->created_at);
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);
    }
    if (metadata->modified_at > 0) {
        snprintf(header, sizeof(header), "%smodified-at: %" PRId64, prefix, (int64_t) metadata->modified_at);
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);
    }
    if (metadata->expires_at > 0) {
        snprintf(header, sizeof(header), "%sexpires-at: %" PRId64, prefix, (int64_t) metadata->expires_at);
        headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);
    }

    snprintf(header, sizeof(header), "%sversion: %" PRIu64, prefix, metadata->version);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%sobject-type: %d", prefix, (int) metadata->object_type);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%scache-policy: %d", prefix, (int) metadata->cache_policy);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%scache-ttl-seconds: %u", prefix, (unsigned) metadata->cache_ttl_seconds);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%slocal-fs-present: %u", prefix, (unsigned) metadata->local_fs_present);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%sdistributed-present: %u", prefix, (unsigned) metadata->distributed_present);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%scloud-s3-present: %u", prefix, (unsigned) metadata->cloud_s3_present);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%scloud-gcs-present: %u", prefix, (unsigned) metadata->cloud_gcs_present);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%scloud-azure-present: %u", prefix, (unsigned) metadata->cloud_azure_present);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%sis-backed-up: %u", prefix, (unsigned) metadata->is_backed_up);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%sreplication-status: %u", prefix, (unsigned) metadata->replication_status);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(header, sizeof(header), "%sis-distributed: %u", prefix, (unsigned) metadata->is_distributed);
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    snprintf(
        header,
        sizeof(header),
        "%sdistribution-peer-count: %u",
        prefix,
        (unsigned) metadata->distribution_peer_count
    );
    headers = king_object_store_libcurl.curl_slist_append_fn(headers, header);

    return headers;
}

int king_object_store_begin_upload_session(
    const char *object_id,
    const king_object_metadata_t *metadata,
    const char *expected_integrity_sha256,
    int adopted_lock_fd,
    char upload_id[65],
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    king_object_store_upload_session_t *session = NULL;
    king_storage_backend_t backend;
    uint8_t random_bytes[32];
    static const char hex[] = "0123456789abcdef";
    size_t i;
    int mutation_lock_fd = adopted_lock_fd;

    if (upload_id == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    upload_id[0] = '\0';
    error[0] = '\0';

    if (!king_object_store_runtime.initialized) {
        snprintf(error, error_size, "Object-store runtime is unavailable.");
        return FAILURE;
    }
    if (object_id == NULL || object_id[0] == '\0' || king_object_store_object_id_validate(object_id) != NULL) {
        snprintf(error, error_size, "Object-store upload sessions require a valid object id.");
        return FAILURE;
    }

    backend = king_object_store_normalize_backend(king_object_store_runtime.config.primary_backend);
    if (backend != KING_STORAGE_BACKEND_CLOUD_S3
        && backend != KING_STORAGE_BACKEND_CLOUD_GCS
        && backend != KING_STORAGE_BACKEND_CLOUD_AZURE) {
        snprintf(
            error,
            error_size,
            "Provider-native resumable upload sessions require a real cloud primary backend."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend("primary", backend, "resumable upload sessions") != SUCCESS) {
        snprintf(
            error,
            error_size,
            "%s",
            king_object_store_runtime.primary_adapter_error[0] != '\0'
                ? king_object_store_runtime.primary_adapter_error
                : "Primary object-store backend is unavailable for resumable upload sessions."
        );
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    if (king_object_store_fill_random_bytes(random_bytes, sizeof(random_bytes)) != SUCCESS) {
        snprintf(error, error_size, "Failed to create a resumable upload identifier.");
        return FAILURE;
    }
    for (i = 0; i < sizeof(random_bytes); ++i) {
        upload_id[i * 2] = hex[(random_bytes[i] >> 4) & 0x0f];
        upload_id[(i * 2) + 1] = hex[random_bytes[i] & 0x0f];
    }
    upload_id[64] = '\0';

    if (king_object_store_upload_sessions_ensure() != SUCCESS) {
        snprintf(error, error_size, "Failed to initialize the resumable upload registry.");
        return FAILURE;
    }

    session = ecalloc(1, sizeof(*session));
    session->lock_fd = -1;
    snprintf(session->upload_id, sizeof(session->upload_id), "%s", upload_id);
    snprintf(session->object_id, sizeof(session->object_id), "%s", object_id);
    session->backend = backend;
    session->protocol =
        backend == KING_STORAGE_BACKEND_CLOUD_S3 ? KING_OBJECT_STORE_UPLOAD_PROTOCOL_S3_MULTIPART :
        backend == KING_STORAGE_BACKEND_CLOUD_GCS ? KING_OBJECT_STORE_UPLOAD_PROTOCOL_GCS_RESUMABLE :
        KING_OBJECT_STORE_UPLOAD_PROTOCOL_AZURE_BLOCKS;
    session->created_at = time(NULL);
    session->updated_at = session->created_at;
    session->next_part_number = 1;
    session->chunk_size_bytes = (uint64_t) king_object_store_streaming_chunk_bytes();
    session->sequential_chunks_required = 1;
    session->final_chunk_may_be_shorter = 1;
    if (metadata != NULL) {
        session->metadata = *metadata;
    }
    if (expected_integrity_sha256 != NULL && expected_integrity_sha256[0] != '\0') {
        snprintf(
            session->expected_integrity_sha256,
            sizeof(session->expected_integrity_sha256),
            "%s",
            expected_integrity_sha256
        );
    }

    if (mutation_lock_fd < 0) {
        if (king_object_store_acquire_object_lock(
                object_id,
                &mutation_lock_fd,
                error,
                error_size
            ) != SUCCESS) {
            king_object_store_upload_session_destroy_ptr(session);
            return FAILURE;
        }
    }
    session->lock_fd = mutation_lock_fd;

    PHP_SHA256Init(&session->sha256_ctx);
    zend_hash_init(&session->provider_parts, 8, NULL, ZVAL_PTR_DTOR, 0);

    if (king_object_store_upload_session_assign_paths(session) != SUCCESS) {
        snprintf(error, error_size, "Failed to allocate a resumable upload assembly file.");
        king_object_store_upload_session_destroy_ptr(session);
        return FAILURE;
    }
    {
        FILE *assembly_fp = fopen(session->assembly_path, "wb");
        if (assembly_fp == NULL) {
            snprintf(error, error_size, "Failed to create a resumable upload assembly file.");
            king_object_store_upload_session_destroy_ptr(session);
            return FAILURE;
        }
        fclose(assembly_fp);
    }

    memset(&session->existing_metadata, 0, sizeof(session->existing_metadata));
    if (king_object_store_backend_read_metadata(object_id, &session->existing_metadata) == SUCCESS) {
        session->existed_before = 1;
        session->old_size = session->existing_metadata.content_length;
    }

    switch (backend) {
        case KING_STORAGE_BACKEND_CLOUD_S3:
            if (king_object_store_s3_begin_upload_session(
                    object_id,
                    metadata,
                    session->provider_token,
                    sizeof(session->provider_token),
                    error,
                    error_size
                ) != SUCCESS) {
                king_object_store_upload_session_destroy_ptr(session);
                return FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            if (king_object_store_gcs_begin_upload_session(
                    object_id,
                    metadata,
                    session->provider_token,
                    sizeof(session->provider_token),
                    error,
                    error_size
                ) != SUCCESS) {
                king_object_store_upload_session_destroy_ptr(session);
                return FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            if (king_object_store_azure_begin_upload_session(
                    object_id,
                    metadata,
                    session->provider_token,
                    sizeof(session->provider_token),
                    error,
                    error_size
                ) != SUCCESS) {
                king_object_store_upload_session_destroy_ptr(session);
                return FAILURE;
            }
            break;
        default:
            snprintf(error, error_size, "Unsupported primary backend for resumable upload sessions.");
            king_object_store_upload_session_destroy_ptr(session);
            return FAILURE;
    }

    if (king_object_store_upload_session_persist(session, error, error_size) != SUCCESS) {
        char abort_error[256];

        abort_error[0] = '\0';
        switch (backend) {
            case KING_STORAGE_BACKEND_CLOUD_S3:
                (void) king_object_store_s3_abort_upload_session(
                    session->object_id,
                    session->provider_token,
                    abort_error,
                    sizeof(abort_error)
                );
                break;
            case KING_STORAGE_BACKEND_CLOUD_GCS:
                if (session->provider_token[0] != '\0') {
                    (void) king_object_store_gcs_abort_upload_session(
                        session->provider_token,
                        abort_error,
                        sizeof(abort_error)
                    );
                }
                break;
            case KING_STORAGE_BACKEND_CLOUD_AZURE:
                break;
            default:
                break;
        }
        king_object_store_upload_session_destroy_ptr(session);
        return FAILURE;
    }

    if (king_object_store_upload_session_store(session) != SUCCESS) {
        snprintf(error, error_size, "Failed to store resumable upload session state.");
        king_object_store_upload_session_remove_persisted_state(session);
        king_object_store_upload_session_destroy_ptr(session);
        return FAILURE;
    }

    king_object_store_set_backend_runtime_result("primary", backend, SUCCESS, NULL);
    king_object_store_upload_session_export_status(session, status_out);
    return SUCCESS;
}

int king_object_store_append_upload_session_chunk(
    const char *upload_id,
    const char *source_path,
    uint64_t chunk_size,
    zend_bool is_final_chunk,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    king_object_store_upload_session_t *session;
    uint64_t bytes_appended = 0;
    char provider_part_token[256];
    zend_bool remote_completed = 0;
    zval stored_part;

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    error[0] = '\0';
    session = king_object_store_upload_session_find(upload_id);
    if (session == NULL) {
        snprintf(error, error_size, "Unknown resumable upload id.");
        return FAILURE;
    }
    if (session->completed || session->aborted) {
        snprintf(error, error_size, "Resumable upload '%s' is no longer writable.", upload_id);
        return FAILURE;
    }
    if (session->final_chunk_received) {
        snprintf(error, error_size, "Resumable upload '%s' already received its final chunk.", upload_id);
        return FAILURE;
    }
    if (source_path == NULL || source_path[0] == '\0' || chunk_size == 0) {
        snprintf(error, error_size, "Resumable upload chunks must contain at least one byte.");
        return FAILURE;
    }
    if (session->chunk_size_bytes > 0 && chunk_size > session->chunk_size_bytes) {
        snprintf(
            error,
            error_size,
            "Resumable upload chunk for '%s' exceeded configured chunk_size_kb limit of %" PRIu64 " bytes.",
            session->object_id,
            session->chunk_size_bytes
        );
        return FAILURE;
    }
    if (king_object_store_runtime_capacity_check_rewrite(
            session->uploaded_bytes + chunk_size,
            session->existed_before,
            session->old_size,
            error,
            error_size
        ) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "Resumable upload for '%s' would exceed the configured object-store runtime capacity.",
            session->object_id
        );
        return FAILURE;
    }

    provider_part_token[0] = '\0';
    switch (session->backend) {
        case KING_STORAGE_BACKEND_CLOUD_S3:
            if (king_object_store_s3_append_upload_chunk(
                    session->object_id,
                    session->provider_token,
                    session->next_part_number,
                    source_path,
                    chunk_size,
                    provider_part_token,
                    sizeof(provider_part_token),
                    error,
                    error_size
                ) != SUCCESS) {
                return FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            if (king_object_store_gcs_append_upload_chunk(
                    session->provider_token,
                    source_path,
                    chunk_size,
                    session->next_offset,
                    is_final_chunk,
                    session->uploaded_bytes + chunk_size,
                    &remote_completed,
                    error,
                    error_size
                ) != SUCCESS) {
                return FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            if (king_object_store_azure_append_upload_chunk(
                    session->object_id,
                    session->next_part_number,
                    source_path,
                    chunk_size,
                    provider_part_token,
                    sizeof(provider_part_token),
                    error,
                    error_size
                ) != SUCCESS) {
                return FAILURE;
            }
            break;
        default:
            snprintf(error, error_size, "Unsupported backend for resumable upload chunks.");
            return FAILURE;
    }

    if (king_object_store_append_path_to_file_and_hash(
            source_path,
            session->assembly_path,
            &session->sha256_ctx,
            &bytes_appended
        ) != SUCCESS || bytes_appended != chunk_size) {
        snprintf(error, error_size, "Resumable upload '%s' could not persist the uploaded chunk locally.", upload_id);
        return FAILURE;
    }

    if (provider_part_token[0] != '\0') {
        ZVAL_STRING(&stored_part, provider_part_token);
        zend_hash_index_update(&session->provider_parts, session->next_part_number, &stored_part);
    }

    session->uploaded_bytes += bytes_appended;
    session->next_offset += bytes_appended;
    session->next_part_number++;
    session->updated_at = time(NULL);
    session->final_chunk_received = is_final_chunk ? 1 : 0;
    if (remote_completed) {
        session->remote_completed = 1;
    }

    if (king_object_store_upload_session_persist(session, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_set_backend_runtime_result("primary", session->backend, SUCCESS, NULL);
    king_object_store_upload_session_export_status(session, status_out);
    return SUCCESS;
}

int king_object_store_complete_upload_session(
    const char *upload_id,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    king_object_store_upload_session_t *session;
    char completed_upload_id[65];
    king_storage_backend_t backend;

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    error[0] = '\0';
    session = king_object_store_upload_session_find(upload_id);
    if (session == NULL) {
        snprintf(error, error_size, "Unknown resumable upload id.");
        return FAILURE;
    }
    if (session->aborted) {
        snprintf(error, error_size, "Resumable upload '%s' was aborted.", upload_id);
        return FAILURE;
    }
    if (!session->final_chunk_received) {
        snprintf(error, error_size, "Resumable upload '%s' has not received its final chunk yet.", upload_id);
        return FAILURE;
    }

    if (!session->remote_completed) {
        switch (session->backend) {
            case KING_STORAGE_BACKEND_CLOUD_S3:
                if (king_object_store_s3_complete_upload_session(
                        session->object_id,
                        session->provider_token,
                        &session->provider_parts,
                        error,
                        error_size
                    ) != SUCCESS) {
                    return FAILURE;
                }
                session->remote_completed = 1;
                session->updated_at = time(NULL);
                break;
            case KING_STORAGE_BACKEND_CLOUD_AZURE:
                if (king_object_store_azure_complete_upload_session(
                        session->object_id,
                        &session->provider_parts,
                        &session->metadata,
                        error,
                        error_size
                    ) != SUCCESS) {
                    return FAILURE;
                }
                session->remote_completed = 1;
                session->updated_at = time(NULL);
                break;
            case KING_STORAGE_BACKEND_CLOUD_GCS:
                snprintf(error, error_size, "cloud_gcs resumable uploads must finish their final payload chunk before completion.");
                return FAILURE;
            default:
                snprintf(error, error_size, "Unsupported backend for resumable upload completion.");
                return FAILURE;
        }

        if (king_object_store_upload_session_persist(session, error, error_size) != SUCCESS) {
            return FAILURE;
        }
    }

    if (king_object_store_finalize_completed_upload_session(session, status_out, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    backend = session->backend;
    snprintf(completed_upload_id, sizeof(completed_upload_id), "%s", session->upload_id);
    if (king_object_store_upload_session_persist(session, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    king_object_store_set_backend_runtime_result("primary", backend, SUCCESS, NULL);
    zend_hash_str_del(&king_object_store_upload_sessions, completed_upload_id, strlen(completed_upload_id));
    return SUCCESS;
}

int king_object_store_abort_upload_session(
    const char *upload_id,
    char *error,
    size_t error_size
)
{
    king_object_store_upload_session_t *session;
    char aborted_upload_id[65];
    king_storage_backend_t backend;

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    error[0] = '\0';
    session = king_object_store_upload_session_find(upload_id);
    if (session == NULL) {
        snprintf(error, error_size, "Unknown resumable upload id.");
        return FAILURE;
    }

    if (!session->remote_completed) {
        switch (session->backend) {
            case KING_STORAGE_BACKEND_CLOUD_S3:
                if (king_object_store_s3_abort_upload_session(
                        session->object_id,
                        session->provider_token,
                        error,
                        error_size
                    ) != SUCCESS) {
                    return FAILURE;
                }
                break;
            case KING_STORAGE_BACKEND_CLOUD_GCS:
                if (session->provider_token[0] != '\0'
                    && king_object_store_gcs_abort_upload_session(
                        session->provider_token,
                        error,
                        error_size
                    ) != SUCCESS) {
                    return FAILURE;
                }
                break;
            case KING_STORAGE_BACKEND_CLOUD_AZURE:
                break;
            default:
                snprintf(error, error_size, "Unsupported backend for resumable upload abort.");
                return FAILURE;
        }
    }

    session->aborted = 1;
    session->updated_at = time(NULL);
    backend = session->backend;
    snprintf(aborted_upload_id, sizeof(aborted_upload_id), "%s", session->upload_id);
    if (king_object_store_upload_session_persist(session, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    king_object_store_set_backend_runtime_result("primary", backend, SUCCESS, NULL);
    zend_hash_str_del(&king_object_store_upload_sessions, aborted_upload_id, strlen(aborted_upload_id));
    return SUCCESS;
}

int king_object_store_get_upload_session_status(
    const char *upload_id,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    king_object_store_upload_session_t *session;

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }

    session = king_object_store_upload_session_find(upload_id);
    if (session == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Unknown resumable upload id.");
        }
        return FAILURE;
    }

    king_object_store_upload_session_export_status(session, status_out);
    return SUCCESS;
}

static int king_object_store_local_fs_restore_payload_from_backup(
    const char *object_id,
    const void *data,
    size_t data_size
)
{
    char file_path[1024];

    if (object_id == NULL || data == NULL) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (king_object_store_mkdir_parents(file_path) != SUCCESS) {
        return FAILURE;
    }

    return king_object_store_atomic_write_file(file_path, data, data_size);
}

static int king_object_store_local_fs_restore_payload_from_path(
    const char *object_id,
    const char *source_path
)
{
    char file_path[1024];

    if (object_id == NULL || source_path == NULL) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (king_object_store_mkdir_parents(file_path) != SUCCESS) {
        return FAILURE;
    }

    return king_object_store_copy_file_to_path(source_path, file_path);
}

static int king_object_store_local_fs_read_fallback_from_cloud_backup(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
)
{
    king_object_metadata_t sidecar_metadata;
    char backup_error[512] = {0};
    int rc;
    int mutation_lock_fd = -1;
    char lock_error[512] = {0};

    if (king_object_store_runtime.config.backup_backend != KING_STORAGE_BACKEND_CLOUD_S3) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    /*
     * Only heal from backup when the local metadata sidecar still says the
     * object logically exists. A true delete removes the sidecar, so stale
     * backup copies must not resurrect removed objects.
     */
    if (king_object_store_meta_read(object_id, &sidecar_metadata) != SUCCESS
        && king_object_store_runtime_metadata_cache_read(object_id, &sidecar_metadata) != SUCCESS) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }
    if (!king_object_store_metadata_matches_backend(
            object_id,
            &sidecar_metadata,
            KING_STORAGE_BACKEND_LOCAL_FS
        )) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    rc = king_object_store_s3_read_with_error(
        object_id,
        data,
        data_size,
        0,
        0,
        0,
        backup_error,
        sizeof(backup_error)
    );
    king_object_store_set_backend_runtime_result(
        "backup",
        king_object_store_runtime.config.backup_backend,
        rc,
        rc == SUCCESS ? NULL : backup_error
    );
    if (rc != SUCCESS) {
        return FAILURE;
    }

    if (sidecar_metadata.object_id[0] == '\0') {
        strncpy(sidecar_metadata.object_id, object_id, sizeof(sidecar_metadata.object_id) - 1);
    }
    sidecar_metadata.content_length = (uint64_t) *data_size;
    king_object_store_metadata_mark_backend_present(
        &sidecar_metadata,
        KING_STORAGE_BACKEND_LOCAL_FS,
        1
    );
    king_object_store_metadata_mark_backend_present(
        &sidecar_metadata,
        KING_STORAGE_BACKEND_CLOUD_S3,
        1
    );
    sidecar_metadata.is_backed_up = 1;

    if (metadata != NULL) {
        *metadata = sidecar_metadata;
    }

    if (king_object_store_acquire_object_lock(
            object_id,
            &mutation_lock_fd,
            lock_error,
            sizeof(lock_error)
        ) != SUCCESS) {
        return SUCCESS;
    }

    if (king_object_store_local_fs_restore_payload_from_backup(object_id, *data, *data_size) != SUCCESS) {
        king_object_store_append_adapter_error(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "Primary object-store backend read fallback succeeded but local payload rehydration failed."
        );
        king_object_store_release_object_lock(&mutation_lock_fd);
        king_object_store_release_read_buffer(data, data_size);
        return FAILURE;
    }

    if (king_object_store_meta_write(object_id, &sidecar_metadata) != SUCCESS) {
        king_object_store_append_adapter_error(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "Primary object-store backend read fallback succeeded but local metadata sidecar rehydration failed."
        );
        king_object_store_release_object_lock(&mutation_lock_fd);
        king_object_store_release_read_buffer(data, data_size);
        return FAILURE;
    }
    king_object_store_release_object_lock(&mutation_lock_fd);
    king_object_store_rehydrate_stats();
    return SUCCESS;
}

static int king_object_store_local_fs_read_fallback_from_cloud_backup_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata
)
{
    king_object_metadata_t sidecar_metadata;
    char backup_error[512] = {0};
    char temp_path[1024];
    int rc = FAILURE;
    int mutation_lock_fd = -1;
    char lock_error[512] = {0};

    if (destination_stream == NULL) {
        return FAILURE;
    }
    if (king_object_store_runtime.config.backup_backend != KING_STORAGE_BACKEND_CLOUD_S3) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }
    if (king_object_store_meta_read(object_id, &sidecar_metadata) != SUCCESS
        && king_object_store_runtime_metadata_cache_read(object_id, &sidecar_metadata) != SUCCESS) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }
    if (!king_object_store_metadata_matches_backend(
            object_id,
            &sidecar_metadata,
            KING_STORAGE_BACKEND_LOCAL_FS
        )) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }
    if (king_object_store_create_temp_file_path(temp_path, sizeof(temp_path)) != SUCCESS) {
        return FAILURE;
    }

    rc = king_object_store_s3_read_to_path_with_error(
        object_id,
        temp_path,
        offset,
        length,
        has_length,
        backup_error,
        sizeof(backup_error)
    );
    king_object_store_set_backend_runtime_result(
        "backup",
        king_object_store_runtime.config.backup_backend,
        rc,
        rc == SUCCESS ? NULL : backup_error
    );
    if (rc != SUCCESS) {
        unlink(temp_path);
        return FAILURE;
    }

    if (!has_length && offset == 0 && sidecar_metadata.integrity_sha256[0] != '\0') {
        char computed_sha256[65];
        uint64_t source_size = 0;

        if (king_object_store_compute_sha256_hex_for_path(temp_path, computed_sha256, &source_size) != SUCCESS
            || strcmp(computed_sha256, sidecar_metadata.integrity_sha256) != 0
            || source_size != sidecar_metadata.content_length) {
            unlink(temp_path);
            king_object_store_append_adapter_error(
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                "Primary object-store backend read fallback failed integrity validation."
            );
            return FAILURE;
        }

        king_object_store_metadata_mark_backend_present(&sidecar_metadata, KING_STORAGE_BACKEND_LOCAL_FS, 1);
        king_object_store_metadata_mark_backend_present(&sidecar_metadata, KING_STORAGE_BACKEND_CLOUD_S3, 1);
        sidecar_metadata.is_backed_up = 1;
        if (metadata != NULL) {
            *metadata = sidecar_metadata;
        }
        if (king_object_store_acquire_object_lock(
                object_id,
                &mutation_lock_fd,
                lock_error,
                sizeof(lock_error)
            ) == SUCCESS) {
            if (king_object_store_local_fs_restore_payload_from_path(object_id, temp_path) != SUCCESS) {
                unlink(temp_path);
                king_object_store_append_adapter_error(
                    king_object_store_runtime.primary_adapter_error,
                    sizeof(king_object_store_runtime.primary_adapter_error),
                    "Primary object-store backend read fallback succeeded but local payload rehydration failed."
                );
                king_object_store_release_object_lock(&mutation_lock_fd);
                return FAILURE;
            }
            if (king_object_store_meta_write(object_id, &sidecar_metadata) != SUCCESS) {
                unlink(temp_path);
                king_object_store_append_adapter_error(
                    king_object_store_runtime.primary_adapter_error,
                    sizeof(king_object_store_runtime.primary_adapter_error),
                    "Primary object-store backend read fallback succeeded but local metadata sidecar rehydration failed."
                );
                king_object_store_release_object_lock(&mutation_lock_fd);
                return FAILURE;
            }
            king_object_store_release_object_lock(&mutation_lock_fd);
            king_object_store_rehydrate_stats();
        }
    }

    rc = king_object_store_copy_path_to_stream(temp_path, destination_stream, 0, 0, 0);
    unlink(temp_path);
    return rc;
}

/* --- Backend-routing dispatch --- */

int king_object_store_write_object_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata
)
{
    int rc = FAILURE;
    struct stat source_stat;
    char capacity_error[256];

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store write."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "put operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    if (stat(source_path, &source_stat) != 0 || !S_ISREG(source_stat.st_mode)) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Primary object-store write source path is unavailable."
        );
        return FAILURE;
    }

    capacity_error[0] = '\0';
    if (king_object_store_runtime_capacity_check_object_size(
            object_id,
            (uint64_t) source_stat.st_size,
            capacity_error,
            sizeof(capacity_error)
        ) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            capacity_error
        );
        return FAILURE;
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_write_from_file(object_id, source_path, metadata);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_write_from_file_internal(
                object_id,
                source_path,
                metadata,
                1,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_write_from_file(
                object_id,
                source_path,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_write_from_file(
                object_id,
                source_path,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_write_from_file(
                object_id,
                source_path,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend."
            );
            return FAILURE;
    }

    king_object_store_set_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        rc == SUCCESS ? NULL : "Primary object-store backend write failed."
    );

    if (rc == SUCCESS &&
        king_object_store_runtime.config.backup_backend != king_object_store_runtime.config.primary_backend) {
        if (king_object_store_backup_object_from_file_to_backend(
                object_id,
                source_path,
                metadata,
                king_object_store_runtime.config.backup_backend
            ) != SUCCESS) {
            if (king_object_store_runtime.config.replication_factor > 0) {
                king_object_store_update_replication_status(object_id, 3);
            }
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Primary object-store write failed because backup operation failed."
            );
            return FAILURE;
        }
    }

    if (rc == SUCCESS && king_object_store_runtime.config.replication_factor > 0) {
        if (king_object_store_replicate_object(object_id, king_object_store_runtime.config.replication_factor) != SUCCESS) {
            if (king_object_store_runtime.primary_adapter_error[0] == '\0') {
                king_object_store_set_backend_runtime_result(
                    "primary",
                    king_object_store_runtime.config.primary_backend,
                    FAILURE,
                    "Primary object-store write failed because replication target was not reached."
                );
            }
            return FAILURE;
        }
    }

    return rc;
}

int king_object_store_write_object(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata)
{
    int rc = FAILURE;
    char capacity_error[256];
    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store write."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "put operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    capacity_error[0] = '\0';
    if (king_object_store_runtime_capacity_check_object_size(
            object_id,
            (uint64_t) data_size,
            capacity_error,
            sizeof(capacity_error)
        ) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            capacity_error
        );
        return FAILURE;
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_write(object_id, data, data_size, metadata);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_write_internal(
                object_id,
                data,
                data_size,
                metadata,
                1,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_write(
                object_id,
                data,
                data_size,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_write(
                object_id,
                data,
                data_size,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_write(
                object_id,
                data,
                data_size,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend."
            );
            return FAILURE;
    }
    king_object_store_set_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        rc == SUCCESS ? NULL : "Primary object-store backend write failed."
    );

    if (rc == SUCCESS &&
        king_object_store_runtime.config.backup_backend != king_object_store_runtime.config.primary_backend) {
        if (king_object_store_backup_object_to_backend(
                object_id,
                data,
                data_size,
                metadata,
                king_object_store_runtime.config.backup_backend
            ) != SUCCESS) {
            if (king_object_store_runtime.config.replication_factor > 0) {
                king_object_store_update_replication_status(object_id, 3);
            }
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Primary object-store write failed because backup operation failed."
            );
            return FAILURE;
        }
    }

    if (rc == SUCCESS && king_object_store_runtime.config.replication_factor > 0) {
        if (king_object_store_replicate_object(object_id, king_object_store_runtime.config.replication_factor) != SUCCESS) {
            if (king_object_store_runtime.primary_adapter_error[0] == '\0') {
                king_object_store_set_backend_runtime_result(
                    "primary",
                    king_object_store_runtime.config.primary_backend,
                    FAILURE,
                    "Primary object-store write failed because replication target was not reached."
                );
            }
            return FAILURE;
        }
    }

    return rc;
}

int king_object_store_read_object(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata)
{
    int rc = FAILURE;
    king_object_metadata_t visibility_metadata;
    zend_bool visibility_metadata_loaded = 0;
    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store read."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "get operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    if (king_object_store_read_visibility_metadata(object_id, &visibility_metadata) == SUCCESS) {
        visibility_metadata_loaded = 1;
        if (king_object_store_metadata_is_expired_now(&visibility_metadata)) {
            if (metadata != NULL) {
                *metadata = visibility_metadata;
            }
            return king_object_store_fail_expired_visibility("read");
        }
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_read(object_id, data, data_size, metadata);
            if (rc != SUCCESS && king_object_store_local_fs_payload_is_missing(object_id)) {
                int fallback_rc = king_object_store_local_fs_read_fallback_from_cloud_backup(
                    object_id,
                    data,
                    data_size,
                    metadata
                );

                if (fallback_rc != KING_OBJECT_STORE_RESULT_MISS) {
                    rc = fallback_rc;
                }
            }
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_read_internal(
                object_id,
                data,
                data_size,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_read(object_id, data, data_size);
            if (rc == SUCCESS && metadata != NULL) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else {
                    rc = king_object_store_backend_read_metadata(object_id, metadata);
                }
            }
            if (rc == SUCCESS && metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
                king_object_store_release_read_buffer(data, data_size);
                king_object_store_set_backend_runtime_result(
                    "primary",
                    king_object_store_runtime.config.primary_backend,
                    FAILURE,
                    "Primary object-store backend integrity validation failed during read."
                );
                rc = FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_read(object_id, data, data_size);
            if (rc == SUCCESS && metadata != NULL) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else {
                    rc = king_object_store_backend_read_metadata(object_id, metadata);
                }
            }
            if (rc == SUCCESS && metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
                king_object_store_release_read_buffer(data, data_size);
                king_object_store_set_backend_runtime_result(
                    "primary",
                    king_object_store_runtime.config.primary_backend,
                    FAILURE,
                    "Primary object-store backend integrity validation failed during read."
                );
                rc = FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_read(object_id, data, data_size);
            if (rc == SUCCESS && metadata != NULL) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else {
                    rc = king_object_store_backend_read_metadata(object_id, metadata);
                }
            }
            if (rc == SUCCESS && metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
                king_object_store_release_read_buffer(data, data_size);
                king_object_store_set_backend_runtime_result(
                    "primary",
                    king_object_store_runtime.config.primary_backend,
                    FAILURE,
                    "Primary object-store backend integrity validation failed during read."
                );
                rc = FAILURE;
            }
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend."
            );
            return FAILURE;
    }
    king_object_store_finalize_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        "Primary object-store backend read failed."
    );
    return rc;
}

int king_object_store_read_object_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata
)
{
    int rc = FAILURE;
    char temp_path[1024];
    king_object_metadata_t visibility_metadata;
    zend_bool visibility_metadata_loaded = 0;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store read."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "get operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    if (king_object_store_read_visibility_metadata(object_id, &visibility_metadata) == SUCCESS) {
        visibility_metadata_loaded = 1;
        if (king_object_store_metadata_is_expired_now(&visibility_metadata)) {
            if (metadata != NULL) {
                *metadata = visibility_metadata;
            }
            return king_object_store_fail_expired_visibility("read");
        }
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_read_to_stream(
                object_id,
                destination_stream,
                offset,
                length,
                has_length,
                metadata
            );
            if (rc != SUCCESS && king_object_store_local_fs_payload_is_missing(object_id)) {
                int fallback_rc = king_object_store_local_fs_read_fallback_from_cloud_backup_to_stream(
                    object_id,
                    destination_stream,
                    offset,
                    length,
                    has_length,
                    metadata
                );

                if (fallback_rc != KING_OBJECT_STORE_RESULT_MISS) {
                    rc = fallback_rc;
                }
            }
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_read_to_stream_internal(
                object_id,
                destination_stream,
                offset,
                length,
                has_length,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            if (king_object_store_create_temp_file_path(temp_path, sizeof(temp_path)) != SUCCESS) {
                return FAILURE;
            }
            rc = king_object_store_s3_read_to_path_with_error(
                object_id,
                temp_path,
                offset,
                length,
                has_length,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            if (rc == SUCCESS && metadata != NULL && !has_length && offset == 0) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else if (king_object_store_backend_read_metadata(object_id, metadata) != SUCCESS) {
                    rc = FAILURE;
                }
                if (rc == SUCCESS && metadata->integrity_sha256[0] != '\0') {
                    char computed_sha256[65];
                    uint64_t source_size = 0;
                    if (king_object_store_compute_sha256_hex_for_path(temp_path, computed_sha256, &source_size) != SUCCESS
                        || strcmp(computed_sha256, metadata->integrity_sha256) != 0
                        || source_size != metadata->content_length) {
                        king_object_store_set_backend_runtime_result(
                            "primary",
                            king_object_store_runtime.config.primary_backend,
                            FAILURE,
                            "Primary object-store backend integrity validation failed during read."
                        );
                        rc = FAILURE;
                    }
                }
            }
            if (rc == SUCCESS) {
                rc = king_object_store_copy_path_to_stream(temp_path, destination_stream, 0, 0, 0);
            }
            unlink(temp_path);
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            if (king_object_store_create_temp_file_path(temp_path, sizeof(temp_path)) != SUCCESS) {
                return FAILURE;
            }
            rc = king_object_store_gcs_read_to_path_with_error(
                object_id,
                temp_path,
                offset,
                length,
                has_length,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            if (rc == SUCCESS && metadata != NULL && !has_length && offset == 0) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else if (king_object_store_backend_read_metadata(object_id, metadata) != SUCCESS) {
                    rc = FAILURE;
                }
                if (rc == SUCCESS && metadata->integrity_sha256[0] != '\0') {
                    char computed_sha256[65];
                    uint64_t source_size = 0;
                    if (king_object_store_compute_sha256_hex_for_path(temp_path, computed_sha256, &source_size) != SUCCESS
                        || strcmp(computed_sha256, metadata->integrity_sha256) != 0
                        || source_size != metadata->content_length) {
                        king_object_store_set_backend_runtime_result(
                            "primary",
                            king_object_store_runtime.config.primary_backend,
                            FAILURE,
                            "Primary object-store backend integrity validation failed during read."
                        );
                        rc = FAILURE;
                    }
                }
            }
            if (rc == SUCCESS) {
                rc = king_object_store_copy_path_to_stream(temp_path, destination_stream, 0, 0, 0);
            }
            unlink(temp_path);
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            if (king_object_store_create_temp_file_path(temp_path, sizeof(temp_path)) != SUCCESS) {
                return FAILURE;
            }
            rc = king_object_store_azure_read_to_path_with_error(
                object_id,
                temp_path,
                offset,
                length,
                has_length,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            if (rc == SUCCESS && metadata != NULL && !has_length && offset == 0) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else if (king_object_store_backend_read_metadata(object_id, metadata) != SUCCESS) {
                    rc = FAILURE;
                }
                if (rc == SUCCESS && metadata->integrity_sha256[0] != '\0') {
                    char computed_sha256[65];
                    uint64_t source_size = 0;
                    if (king_object_store_compute_sha256_hex_for_path(temp_path, computed_sha256, &source_size) != SUCCESS
                        || strcmp(computed_sha256, metadata->integrity_sha256) != 0
                        || source_size != metadata->content_length) {
                        king_object_store_set_backend_runtime_result(
                            "primary",
                            king_object_store_runtime.config.primary_backend,
                            FAILURE,
                            "Primary object-store backend integrity validation failed during read."
                        );
                        rc = FAILURE;
                    }
                }
            }
            if (rc == SUCCESS) {
                rc = king_object_store_copy_path_to_stream(temp_path, destination_stream, 0, 0, 0);
            }
            unlink(temp_path);
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend."
            );
            return FAILURE;
    }

    king_object_store_finalize_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        "Primary object-store backend read failed."
    );
    return rc;
}

int king_object_store_read_object_range(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
)
{
    int rc = FAILURE;
    king_object_metadata_t visibility_metadata;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for ranged object-store read."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "range get operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    if (king_object_store_read_visibility_metadata(object_id, &visibility_metadata) == SUCCESS
        && king_object_store_metadata_is_expired_now(&visibility_metadata)) {
        if (metadata != NULL) {
            *metadata = visibility_metadata;
        }
        return king_object_store_fail_expired_visibility("range read");
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_read_range(
                object_id,
                offset,
                length,
                has_length,
                data,
                data_size,
                metadata
            );
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_read_range_internal(
                object_id,
                offset,
                length,
                has_length,
                data,
                data_size,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_read_range(
                object_id,
                offset,
                length,
                has_length,
                data,
                data_size,
                metadata
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_read_range(
                object_id,
                offset,
                length,
                has_length,
                data,
                data_size,
                metadata
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_read_range(
                object_id,
                offset,
                length,
                has_length,
                data,
                data_size,
                metadata
            );
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend for range reads."
            );
            return FAILURE;
    }

    king_object_store_finalize_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        "Primary object-store backend ranged read failed."
    );
    return rc;
}

int king_object_store_remove_object(const char *object_id)
{
    int rc = FAILURE;
    int mutation_lock_fd = -1;
    char lock_error[512] = {0};

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store remove."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "delete operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }
    if ((king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
            || king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE)
        && !king_object_store_local_fs_root_available("delete")) {
        king_object_store_finalize_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Primary object-store backend remove failed."
        );
        return FAILURE;
    }
    if (king_object_store_acquire_object_lock(
            object_id,
            &mutation_lock_fd,
            lock_error,
            sizeof(lock_error)
        ) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            lock_error[0] != '\0' ? lock_error : "Failed to acquire object-store mutation lock for delete."
        );
        if (strstr(lock_error, "active mutation") != NULL) {
            return KING_OBJECT_STORE_RESULT_CONFLICT;
        }
        return FAILURE;
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_remove_with_real_backup_semantics(object_id);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_remove_with_real_backup_semantics(object_id);
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_remove(object_id);
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_remove(object_id);
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_remove(object_id);
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend."
            );
            king_object_store_release_object_lock(&mutation_lock_fd);
            return FAILURE;
    }
    king_object_store_finalize_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        "Primary object-store backend remove failed."
    );
    king_object_store_release_object_lock(&mutation_lock_fd);
    return rc;
}

int king_object_store_list_object(zval *return_array)
{
    int rc = FAILURE;

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "list operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_list(return_array);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_list_internal(
                return_array,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_list(return_array);
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_list(return_array);
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_list(return_array);
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend."
            );
            rc = FAILURE;
            break;
    }

    king_object_store_finalize_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        "Primary object-store backend list failed."
    );
    return rc;
}
