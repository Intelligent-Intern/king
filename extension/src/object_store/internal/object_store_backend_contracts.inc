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

