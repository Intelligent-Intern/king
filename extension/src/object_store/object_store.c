/*
 * =========================================================================
 * FILENAME:   src/object_store/object_store.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Exposes the root object store native backend core (local_fs).
 * =========================================================================
 */

#include "php_king.h"
#include "object_store_internal.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>
#include <dirent.h>

king_object_store_runtime_state king_object_store_runtime;

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

    target->primary_backend = source->primary_backend;
    target->backup_backend = source->backup_backend;
    strncpy(target->storage_root_path, source->storage_root_path, sizeof(target->storage_root_path) - 1);
    
    target->max_storage_size_bytes = source->max_storage_size_bytes;
    target->replication_factor = source->replication_factor;
    target->chunk_size_kb = source->chunk_size_kb;
    target->enable_deduplication = source->enable_deduplication;
    target->enable_encryption = source->enable_encryption;
    target->enable_compression = source->enable_compression;
    target->cdn_config = source->cdn_config;
}

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

    /* Ensure storage root exists if we use local_fs */
    if (king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_LOCAL_FS || king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        if (king_object_store_runtime.config.storage_root_path[0] != '\0') {
            mkdir(king_object_store_runtime.config.storage_root_path, 0755);
        }
    }

    return SUCCESS;
}

void king_object_store_shutdown_system(void)
{
    king_object_store_config_clear(&king_object_store_runtime.config);
    memset(&king_object_store_runtime, 0, sizeof(king_object_store_runtime));
}

/* Helper function to generate file path */
static void king_object_store_build_path(char *dest, size_t dest_len, const char *object_id)
{
    snprintf(dest, dest_len, "%s/%s", king_object_store_runtime.config.storage_root_path, object_id);
}

int king_object_store_local_fs_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata)
{
    FILE *fp;
    char file_path[1024];

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);

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

    king_object_store_runtime.current_object_count++;
    king_object_store_runtime.current_stored_bytes += data_size;
    king_object_store_runtime.latest_object_at = time(NULL);

    return SUCCESS;
}

int king_object_store_local_fs_read(const char *object_id, void **data, size_t *data_size, king_object_metadata_t *metadata)
{
    FILE *fp;
    char file_path[1024];
    struct stat st;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL || data_size == NULL) {
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
    *data = pecalloc(1, *data_size + 1, 1); // +1 for null terminator safety in strings
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
        // Mocking basic metadata recovery
        memset(metadata, 0, sizeof(*metadata));
        strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
        metadata->content_length = *data_size;
        metadata->created_at = st.st_mtime;
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
        if (king_object_store_runtime.current_stored_bytes >= st.st_size) {
            king_object_store_runtime.current_stored_bytes -= st.st_size;
        }
        if (king_object_store_runtime.current_object_count > 0) {
            king_object_store_runtime.current_object_count--;
        }
    }

    if (unlink(file_path) != 0) {
        return FAILURE;
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
            array_init(&exported_entry);
            add_assoc_string(&exported_entry, "object_id", ent->d_name);
            add_assoc_long(&exported_entry, "size_bytes", (zend_long) st.st_size);
            add_assoc_long(&exported_entry, "stored_at", (zend_long) st.st_mtime);

            add_next_index_zval(return_array, &exported_entry);
        }
    }

    closedir(dir);
    return SUCCESS;
}

int king_object_store_write_object(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata)
{
    // Right now, default is local fs. Distributed logic comes later in epic 8.
    return king_object_store_local_fs_write(object_id, data, data_size, metadata);
}

int king_object_store_read_object(const char *object_id, void **data, size_t *data_size, king_object_metadata_t *metadata)
{
    return king_object_store_local_fs_read(object_id, data, data_size, metadata);
}

int king_object_store_remove_object(const char *object_id)
{
    return king_object_store_local_fs_remove(object_id);
}
