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

    /* Ensure storage root exists for local_fs / memory_cache backends */
    if (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_LOCAL_FS ||
        king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        if (king_object_store_runtime.config.storage_root_path[0] != '\0') {
            mkdir(king_object_store_runtime.config.storage_root_path, 0755);
            /* Rehydrate live stats from any existing on-disk objects */
            king_object_store_rehydrate_stats();
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

static void king_object_store_build_path(char *dest, size_t dest_len, const char *object_id)
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
        "replication_status=%d\n",
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
        (int)      metadata->replication_status
    );

    fclose(fp);
    return SUCCESS;
}

int king_object_store_meta_read(const char *object_id, king_object_metadata_t *metadata)
{
    FILE *fp;
    char meta_path[1024];
    char line[640];

    if (metadata == NULL) {
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
        }
    }

    fclose(fp);
    return SUCCESS;
}

void king_object_store_meta_remove(const char *object_id)
{
    char meta_path[1024];
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

/* --- Replication (skeleton: local_fs single file = replica 1) --- */

int king_object_store_replicate_object(const char *object_id, uint32_t replication_factor)
{
    /* Skeleton hook: simulate replication to peer nodes */
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

    /* Skeleton hook: simulate sync to Cloud Backend (S3/GCS) */
    if (backup_backend == KING_STORAGE_BACKEND_LOCAL_FS) {
        return SUCCESS;
    }

    king_object_metadata_t metadata;
    if (king_object_store_meta_read(object_id, &metadata) == SUCCESS) {
        if (metadata.is_backed_up == 0) {
            metadata.is_backed_up = 1;
            king_object_store_meta_write(object_id, &metadata);
        }
    }

    return SUCCESS;
}

/* --- CDN stubs --- */

int king_cdn_distribute_object(
    const char *object_id,
    const king_storage_node_t *edge_nodes,
    uint32_t node_count)
{
    (void) object_id;
    (void) edge_nodes;
    (void) node_count;
    return SUCCESS; /* skeleton: noop */
}

int king_cdn_find_optimal_edge_node(const char *client_ip, king_storage_node_t **optimal_node)
{
    (void) client_ip;
    if (optimal_node != NULL) {
        *optimal_node = NULL;
    }
    return FAILURE; /* skeleton: no provisioned edge nodes */
}

void king_object_store_cleanup_expired_objects(void)
{
    /* Delegate CDN expiry sweep — called from PHP or periodic maintenance */
    king_cdn_sweep_expired();
}

/* --- Backend-routing dispatch --- */

int king_object_store_write_object(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata)
{
    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            return king_object_store_local_fs_write(object_id, data, data_size, metadata);
        default:
            return SUCCESS; /* distributed/cloud: skeleton noop */
    }
}

int king_object_store_read_object(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata)
{
    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            return king_object_store_local_fs_read(object_id, data, data_size, metadata);
        default:
            return FAILURE;
    }
}

int king_object_store_remove_object(const char *object_id)
{
    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            return king_object_store_local_fs_remove(object_id);
        default:
            return SUCCESS; /* skeleton noop for distributed/cloud */
    }
}
