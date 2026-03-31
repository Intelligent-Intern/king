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

#include "internal/object_store_distributed_state.inc"

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

#include "internal/object_store_streaming_helpers.inc"
#include "internal/object_store_runtime_state.inc"
#include "internal/object_store_backend_contracts.inc"
#include "internal/object_store_lifecycle.inc"
#include "internal/object_store_local_fs_io.inc"
#include "internal/object_store_local_fs_primary.inc"
#include "internal/object_store_capacity_transfer.inc"
#include "internal/object_store_replication_backup.inc"
#include "internal/object_store_distributed_backend.inc"
#include "internal/object_store_cdn_and_simulated.inc"
#include "cloud_s3.inc"
#include "cloud_gcs.inc"
#include "cloud_azure.inc"

#include "internal/object_store_metadata_headers_and_upload_api.inc"
#include "internal/object_store_local_fallbacks.inc"
#include "internal/object_store_dispatch.inc"
