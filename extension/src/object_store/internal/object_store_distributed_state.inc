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
