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

