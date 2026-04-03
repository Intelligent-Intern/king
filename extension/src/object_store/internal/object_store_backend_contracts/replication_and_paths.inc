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

