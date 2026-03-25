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
#include "object_store_internal.h"
#include <inttypes.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>
#include <dirent.h>

king_object_store_runtime_state king_object_store_runtime;

static const char *king_object_store_adapter_status_ok = "ok";
static const char *king_object_store_adapter_status_simulated = "simulated";
static const char *king_object_store_adapter_status_failed = "failed";
static const char *king_object_store_adapter_status_unimplemented = "unimplemented";
static const char *king_object_store_adapter_status_unknown = "unknown";
static const char *king_object_store_adapter_contract_local = "local";
static const char *king_object_store_adapter_contract_simulated = "simulated";
static const char *king_object_store_adapter_contract_unconfigured = "unconfigured";

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
    switch (backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            return king_object_store_adapter_contract_local;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
        case KING_STORAGE_BACKEND_CLOUD_S3:
        case KING_STORAGE_BACKEND_CLOUD_GCS:
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            return king_object_store_adapter_contract_simulated;
        default:
            return king_object_store_adapter_contract_unconfigured;
    }
}

static const char *king_object_store_initial_backend_status(king_storage_backend_t backend)
{
    switch (backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            return king_object_store_adapter_status_ok;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
        case KING_STORAGE_BACKEND_CLOUD_S3:
        case KING_STORAGE_BACKEND_CLOUD_GCS:
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            return king_object_store_adapter_status_simulated;
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
    const char *contract = king_object_store_backend_contract_to_string(backend);
    const char *status = success ? king_object_store_initial_backend_status(backend) : king_object_store_adapter_status_failed;
    if (!success && (error == NULL || error[0] == '\0')) {
        error = "Object-store backend operation failed.";
    }
    king_object_store_set_runtime_adapter_status(scope, status, contract, error);
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
    target->primary_backend       = source->primary_backend;
    target->backup_backend        = source->backup_backend;
    strncpy(target->storage_root_path, source->storage_root_path, sizeof(target->storage_root_path) - 1);
    target->max_storage_size_bytes = source->max_storage_size_bytes;
    target->replication_factor    = source->replication_factor;
    target->chunk_size_kb         = source->chunk_size_kb;
    target->enable_deduplication  = source->enable_deduplication;
    target->enable_encryption     = source->enable_encryption;
    target->enable_compression    = source->enable_compression;
    target->cdn_config            = source->cdn_config;
}

/* --- String conversion helpers (satisfy include/object_store/object_store.h declarations) --- */

const char *king_storage_backend_to_string(king_storage_backend_t backend)
{
    switch (backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:     return "local_fs";
        case KING_STORAGE_BACKEND_DISTRIBUTED:  return "distributed";
        case KING_STORAGE_BACKEND_CLOUD_S3:     return "cloud_s3";
        case KING_STORAGE_BACKEND_CLOUD_GCS:    return "cloud_gcs";
        case KING_STORAGE_BACKEND_CLOUD_AZURE:  return "cloud_azure";
        case KING_STORAGE_BACKEND_MEMORY_CACHE: return "memory_cache";
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

/* --- System lifecycle --- */

int king_object_store_init_system(king_object_store_config_t *config)
{
    if (config == NULL) {
        return FAILURE;
    }

    king_object_store_config_clear(&king_object_store_runtime.config);
    memset(&king_object_store_runtime, 0, sizeof(king_object_store_runtime));
    ZVAL_UNDEF(&king_object_store_runtime.config.cloud_credentials);

    king_object_store_config_copy(&king_object_store_runtime.config, config);
    king_object_store_runtime.initialized = true;
    king_object_store_initialize_adapter_statuses();

    if (strstr(king_object_store_runtime.primary_adapter_contract, "local") == NULL &&
        king_object_store_runtime.config.primary_backend != KING_STORAGE_BACKEND_DISTRIBUTED &&
        king_object_store_runtime.config.primary_backend != KING_STORAGE_BACKEND_CLOUD_S3 &&
        king_object_store_runtime.config.primary_backend != KING_STORAGE_BACKEND_CLOUD_GCS &&
        king_object_store_runtime.config.primary_backend != KING_STORAGE_BACKEND_CLOUD_AZURE) {
        king_object_store_set_runtime_adapter_status(
            "primary",
            king_object_store_adapter_status_unimplemented,
            king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
            "Unsupported primary object-store backend."
        );
    }

    if ((king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_S3 ||
         king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_GCS ||
         king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_CLOUD_AZURE) &&
        Z_TYPE(king_object_store_runtime.config.cloud_credentials) == IS_UNDEF) {
        king_object_store_set_runtime_adapter_status(
            "primary",
            king_object_store_adapter_status_simulated,
            king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
            "Cloud credentials not configured; operations will run under simulated adapter contract."
        );
    }

    if (king_object_store_runtime.config.backup_backend == KING_STORAGE_BACKEND_CLOUD_S3 ||
        king_object_store_runtime.config.backup_backend == KING_STORAGE_BACKEND_CLOUD_GCS ||
        king_object_store_runtime.config.backup_backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
        if (Z_TYPE(king_object_store_runtime.config.cloud_credentials) == IS_UNDEF) {
            king_object_store_set_runtime_adapter_status(
                "backup",
                king_object_store_adapter_status_simulated,
                king_object_store_backend_contract_to_string(king_object_store_runtime.config.backup_backend),
                "Cloud credentials not configured; backup status set to simulated adapter contract."
            );
        }
    }

    /* Ensure storage root exists for local_fs / memory_cache backends */
    if (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_LOCAL_FS ||
        king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        if (king_object_store_runtime.config.storage_root_path[0] != '\0') {
            mkdir(king_object_store_runtime.config.storage_root_path, 0755);
            /* Rehydrate live stats from any existing on-disk objects */
            king_object_store_rehydrate_stats();
        } else {
            king_object_store_set_runtime_adapter_status(
                "primary",
                king_object_store_adapter_status_unimplemented,
                king_object_store_backend_contract_to_string(king_object_store_runtime.config.primary_backend),
                "Missing required storage_root_path for local file-backed adapters."
            );
        }
    }

    return SUCCESS;
}

void king_object_store_shutdown_system(void)
{
    king_object_store_config_clear(&king_object_store_runtime.config);
    memset(&king_object_store_runtime, 0, sizeof(king_object_store_runtime));
}

/* --- local_fs backend --- */

void king_object_store_build_path(char *dest, size_t dest_len, const char *object_id)
{
    snprintf(dest, dest_len, "%s/%s",
        king_object_store_runtime.config.storage_root_path, object_id);
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
 *   content_length=<uint64>\n
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
    FILE *fp;
    char meta_path[1024];
    king_object_metadata_t scratch;

    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    king_object_store_build_meta_path(meta_path, sizeof(meta_path), object_id);

    fp = fopen(meta_path, "w");
    if (fp == NULL) {
        return FAILURE;
    }

    if (metadata == NULL) {
        /* Write a minimal stub so the file always exists */
        memset(&scratch, 0, sizeof(scratch));
        strncpy(scratch.object_id, object_id, sizeof(scratch.object_id) - 1);
        scratch.created_at = time(NULL);
        metadata = &scratch;
    }

    fprintf(fp,
        "object_id=%s\n"
        "content_type=%s\n"
        "content_encoding=%s\n"
        "etag=%s\n"
        "content_length=%" PRIu64 "\n"
        "created_at=%" PRId64 "\n"
        "modified_at=%" PRId64 "\n"
        "expires_at=%" PRId64 "\n"
        "object_type=%d\n"
        "cache_policy=%d\n"
        "cache_ttl_seconds=%u\n"
        "is_backed_up=%d\n"
        "replication_status=%d\n"
        "is_distributed=%d\n"
        "distribution_peer_count=%u\n",
        (metadata->object_id[0] != '\0' ? metadata->object_id : object_id),
        metadata->content_type,
        metadata->content_encoding,
        metadata->etag,
        (uint64_t) metadata->content_length,
        (int64_t)  metadata->created_at,
        (int64_t)  metadata->modified_at,
        (int64_t)  metadata->expires_at,
        (int)      metadata->object_type,
        (int)      metadata->cache_policy,
        (unsigned) metadata->cache_ttl_seconds,
        (int)      metadata->is_backed_up,
        (int)      metadata->replication_status,
        (int)      metadata->is_distributed,
        (unsigned) metadata->distribution_peer_count
    );

    fclose(fp);
    return SUCCESS;
}

int king_object_store_meta_read(const char *object_id, king_object_metadata_t *metadata)
{
    FILE *fp;
    char meta_path[1024];
    char line[640];

    if (king_object_store_object_id_validate(object_id) != NULL || metadata == NULL) {
        return FAILURE;
    }

    king_object_store_build_meta_path(meta_path, sizeof(meta_path), object_id);

    fp = fopen(meta_path, "r");
    if (fp == NULL) {
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
        /* strip trailing newline */
        size_t vlen = strlen(val);
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
        } else if (strcmp(key, "content_length") == 0) {
            metadata->content_length = (uint64_t) strtoull(val, NULL, 10);
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

    fclose(fp);
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

    while ((ent = readdir(dir)) != NULL) {
        size_t nlen;
        if (ent->d_name[0] == '.') {
            continue;
        }
        nlen = strlen(ent->d_name);
        /* Skip .meta sidecar files — count only object files */
        if (nlen > 5 && strcmp(ent->d_name + nlen - 5, ".meta") == 0) {
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

    closedir(dir);
}

int king_object_store_local_fs_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata)
{
    FILE *fp;
    char file_path[1024];
    struct stat st_old;
    int is_overwrite = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL) {
        return FAILURE;
    }

    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);

    /* Detect overwrite to fix capacity double-counting */
    if (stat(file_path, &st_old) == 0 && S_ISREG(st_old.st_mode)) {
        is_overwrite = 1;
    }

    fp = fopen(file_path, "wb");
    if (fp == NULL) {
        return FAILURE;
    }

    if (fwrite(data, 1, data_size, fp) != data_size) {
        fclose(fp);
        unlink(file_path);
        return FAILURE;
    }

    fclose(fp);

    if (is_overwrite) {
        if (king_object_store_runtime.current_stored_bytes >= (uint64_t)st_old.st_size) {
            king_object_store_runtime.current_stored_bytes -= (uint64_t)st_old.st_size;
        }
        /* Do NOT increment object count on overwrite */
    } else {
        king_object_store_runtime.current_object_count++;
    }
    king_object_store_runtime.current_stored_bytes += data_size;
    king_object_store_runtime.latest_object_at = time(NULL);

    /* Write durable metadata sidecar */
    king_object_store_meta_write(object_id, metadata);

    /* Replicate if replication_factor > 0 */
    if (king_object_store_runtime.config.replication_factor > 0) {
        king_object_store_replicate_object(object_id, king_object_store_runtime.config.replication_factor);
    }

    /* Primary-fallback sync (Backup) */
    if (king_object_store_runtime.config.backup_backend != king_object_store_runtime.config.primary_backend) {
        king_object_store_backup_object(object_id, king_object_store_runtime.config.backup_backend);
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

    king_object_store_build_path(file_path, sizeof(file_path), object_id);

    if (stat(file_path, &st) != 0) {
        return FAILURE;
    }

    fp = fopen(file_path, "rb");
    if (fp == NULL) {
        return FAILURE;
    }

    *data_size = st.st_size;
    *data = pecalloc(1, *data_size + 1, 1); /* +1 for null-terminator safety */
    if (*data == NULL) {
        fclose(fp);
        return FAILURE;
    }

    if (fread(*data, 1, *data_size, fp) != *data_size) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        fclose(fp);
        return FAILURE;
    }

    fclose(fp);

    if (metadata != NULL) {
        /* Try to load durable metadata from sidecar; fall back to stat-derived values */
        if (king_object_store_meta_read(object_id, metadata) != SUCCESS) {
            memset(metadata, 0, sizeof(*metadata));
            strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
            metadata->content_length = *data_size;
            metadata->created_at     = st.st_mtime;
        }
    }

    return SUCCESS;
}

int king_object_store_local_fs_remove(const char *object_id)
{
    char file_path[1024];
    struct stat st;

    if (!king_object_store_runtime.initialized || object_id == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);

    if (stat(file_path, &st) == 0) {
        if (king_object_store_runtime.current_stored_bytes >= (uint64_t)st.st_size) {
            king_object_store_runtime.current_stored_bytes -= st.st_size;
        }
        if (king_object_store_runtime.current_object_count > 0) {
            king_object_store_runtime.current_object_count--;
        }
    }

    if (unlink(file_path) != 0) {
        return FAILURE;
    }

    /* Remove durable metadata sidecar */
    king_object_store_meta_remove(object_id);

    /* Auto-invalidate matching CDN cache entry */
    {
        zend_string *zobj_id = zend_string_init(object_id, strlen(object_id), 0);
        if (king_cdn_cache_registry_initialized) {
            zend_hash_del(&king_cdn_cache_registry, zobj_id);
        }
        zend_string_release(zobj_id);
    }

    return SUCCESS;
}

int king_object_store_local_fs_list(zval *return_array)
{
    DIR *dir;
    struct dirent *ent;
    struct stat st;
    char file_path[1024];
    zval exported_entry;

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }

    dir = opendir(king_object_store_runtime.config.storage_root_path);
    if (dir == NULL) {
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

/* --- Replication (runtime: local_fs single file = replica 1) --- */

int king_object_store_replicate_object(const char *object_id, uint32_t replication_factor)
{
    /* Runtime hook: simulate replication to peer nodes */
    if (replication_factor == 0) {
        return SUCCESS;
    }

    king_object_metadata_t metadata;
    if (king_object_store_meta_read(object_id, &metadata) == SUCCESS) {
        metadata.replication_status = 2; /* 2: Completed */
        king_object_store_meta_write(object_id, &metadata);
    }

    return SUCCESS;
}

int king_object_store_backup_object(const char *object_id, king_storage_backend_t backup_backend)
{
    if (object_id == NULL || object_id[0] == '\0') {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Missing object id for backup operation."
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

    if (backup_backend == KING_STORAGE_BACKEND_LOCAL_FS ||
        backup_backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            SUCCESS,
            NULL
        );
        return SUCCESS;
    }

    switch (backup_backend) {
        case KING_STORAGE_BACKEND_DISTRIBUTED:
        case KING_STORAGE_BACKEND_CLOUD_S3:
        case KING_STORAGE_BACKEND_CLOUD_GCS:
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Unsupported backup object-store backend."
            );
            return FAILURE;
    }

    king_object_metadata_t metadata;
    if (king_object_store_meta_read(object_id, &metadata) == SUCCESS) {
        if (metadata.is_backed_up == 0) {
            metadata.is_backed_up = 1;
            king_object_store_meta_write(object_id, &metadata);
        }
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            SUCCESS,
            NULL
        );
        return SUCCESS;
    }

    king_object_store_set_backend_runtime_result(
        "backup",
        backup_backend,
        FAILURE,
        "Backup object-store metadata was unavailable."
    );
    return FAILURE;
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

void king_object_store_cleanup_expired_objects(void)
{
    /* Delegate CDN expiry sweep — called from PHP or periodic maintenance */
    king_cdn_sweep_expired();
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

/* --- Backend-routing dispatch --- */

int king_object_store_write_object(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata)
{
    int rc = FAILURE;
    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store write."
        );
        return FAILURE;
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_write(object_id, data, data_size, metadata);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_memcached_simulate_write(object_id, data, data_size, metadata);
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_simulate_write(object_id, data, data_size, metadata);
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_simulate_write(object_id, data, data_size, metadata);
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_simulate_write(object_id, data, data_size, metadata);
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
    return rc;
}

int king_object_store_read_object(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata)
{
    int rc = FAILURE;
    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store read."
        );
        return FAILURE;
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_read(object_id, data, data_size, metadata);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_simulate_read(object_id, data, data_size);
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_simulate_read(object_id, data, data_size);
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_simulate_read(object_id, data, data_size);
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_simulate_read(object_id, data, data_size);
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
        rc == SUCCESS ? NULL : "Primary object-store backend read failed."
    );
    return rc;
}

int king_object_store_remove_object(const char *object_id)
{
    int rc = FAILURE;
    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store remove."
        );
        return FAILURE;
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_remove(object_id);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_simulate_remove(object_id);
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_simulate_remove(object_id);
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_simulate_remove(object_id);
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_simulate_remove(object_id);
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
        rc == SUCCESS ? NULL : "Primary object-store backend remove failed."
    );
    return rc;
}

int king_object_store_list_object(zval *return_array)
{
    int rc = FAILURE;
    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_list(return_array);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_simulate_list(return_array);
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_simulate_list(return_array);
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_simulate_list(return_array);
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_simulate_list(return_array);
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

    king_object_store_set_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        rc == SUCCESS ? NULL : "Primary object-store backend list failed."
    );
    return rc;
}
