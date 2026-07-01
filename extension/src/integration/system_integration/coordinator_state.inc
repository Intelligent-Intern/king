static void king_system_build_coordinator_dir_path(char *dest, size_t dest_len)
{
    if (dest == NULL || dest_len == 0) {
        return;
    }

    if (king_system_runtime_config.state_root_path[0] == '\0') {
        dest[0] = '\0';
        return;
    }

    snprintf(
        dest,
        dest_len,
        "%s/.king-system",
        king_system_runtime_config.state_root_path
    );
}

static void king_system_build_coordinator_state_path(char *dest, size_t dest_len)
{
    char coordinator_dir[PATH_MAX];

    if (dest == NULL || dest_len == 0) {
        return;
    }

    king_system_build_coordinator_dir_path(
        coordinator_dir,
        sizeof(coordinator_dir)
    );
    if (coordinator_dir[0] == '\0') {
        dest[0] = '\0';
        return;
    }

    snprintf(dest, dest_len, "%s/coordinator.state", coordinator_dir);
}

static int king_system_ensure_directory_recursive(const char *path)
{
    char current[PATH_MAX];
    size_t len;
    size_t idx;

    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }

    len = strlen(path);
    if (len >= sizeof(current)) {
        return FAILURE;
    }

    memcpy(current, path, len + 1);
    for (idx = 1; idx < len; idx++) {
        if (current[idx] != '/') {
            continue;
        }

        current[idx] = '\0';
        if (mkdir(current, 0755) != 0 && errno != EEXIST) {
            return FAILURE;
        }
        current[idx] = '/';
    }

    if (mkdir(current, 0755) != 0 && errno != EEXIST) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_system_write_coordinator_state(
    const char *state_path,
    uint64_t version,
    uint64_t generation,
    time_t created_at,
    const char *cluster_id,
    const char *active_node_id,
    zend_bool clean_shutdown
)
{
    char payload[1024];
    char temp_path[PATH_MAX];
    FILE *file;
    int payload_len;

    if (state_path == NULL || state_path[0] == '\0') {
        return FAILURE;
    }

    payload_len = snprintf(
        payload,
        sizeof(payload),
        "version=%" PRIu64 "\n"
        "generation=%" PRIu64 "\n"
        "created_at=%" PRIu64 "\n"
        "updated_at=%" PRIu64 "\n"
        "cluster_id=%s\n"
        "active_node_id=%s\n"
        "clean_shutdown=%d\n",
        version,
        generation,
        (uint64_t) created_at,
        (uint64_t) time(NULL),
        cluster_id != NULL ? cluster_id : "",
        active_node_id != NULL ? active_node_id : "",
        clean_shutdown ? 1 : 0
    );
    if (payload_len < 0 || (size_t) payload_len >= sizeof(payload)) {
        return FAILURE;
    }

    snprintf(temp_path, sizeof(temp_path), "%s.tmp." ZEND_LONG_FMT, state_path, (zend_long) getpid());
    file = fopen(temp_path, "wb");
    if (file == NULL) {
        return FAILURE;
    }

    if (
        fwrite(payload, 1, (size_t) payload_len, file) != (size_t) payload_len
        || fflush(file) != 0
        || fsync(fileno(file)) != 0
    ) {
        fclose(file);
        unlink(temp_path);
        return FAILURE;
    }

    if (fclose(file) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    if (rename(temp_path, state_path) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_system_load_coordinator_state(
    const char *state_path,
    uint64_t *version_out,
    uint64_t *generation_out,
    time_t *created_at_out,
    char *cluster_id_out,
    size_t cluster_id_out_len,
    char *active_node_id_out,
    size_t active_node_id_out_len,
    zend_bool *clean_shutdown_out
)
{
    char buffer[2048];
    char *line;
    char *saveptr = NULL;
    FILE *file;
    uint64_t created_at = 0;
    uint64_t generation = 0;
    zend_bool clean_shutdown = 0;
    uint64_t version = 0;
    int has_clean_shutdown = 0;
    int has_created_at = 0;
    int has_generation = 0;
    int has_version = 0;

    if (
        state_path == NULL
        || version_out == NULL
        || generation_out == NULL
        || created_at_out == NULL
        || cluster_id_out == NULL
        || cluster_id_out_len == 0
        || active_node_id_out == NULL
        || active_node_id_out_len == 0
        || clean_shutdown_out == NULL
    ) {
        return FAILURE;
    }

    file = fopen(state_path, "rb");
    if (file == NULL) {
        return FAILURE;
    }

    memset(buffer, 0, sizeof(buffer));
    if (fread(buffer, 1, sizeof(buffer) - 1, file) == 0 && ferror(file)) {
        fclose(file);
        return FAILURE;
    }
    fclose(file);

    cluster_id_out[0] = '\0';
    active_node_id_out[0] = '\0';

    for (line = strtok_r(buffer, "\n", &saveptr);
         line != NULL;
         line = strtok_r(NULL, "\n", &saveptr)) {
        char *eq = strchr(line, '=');
        const char *value = NULL;
        size_t value_len = 0;

        if (eq == NULL) {
            continue;
        }

        *eq = '\0';
        value = eq + 1;
        value_len = strlen(value);
        if (strcmp(line, "version") == 0) {
            version = strtoull(value, NULL, 10);
            has_version = 1;
        } else if (strcmp(line, "generation") == 0) {
            generation = strtoull(value, NULL, 10);
            has_generation = 1;
        } else if (strcmp(line, "created_at") == 0) {
            created_at = strtoull(value, NULL, 10);
            has_created_at = 1;
        } else if (strcmp(line, "cluster_id") == 0) {
            if (value_len >= cluster_id_out_len) {
                return FAILURE;
            }
            memcpy(cluster_id_out, value, value_len + 1);
        } else if (strcmp(line, "active_node_id") == 0) {
            if (value_len >= active_node_id_out_len) {
                return FAILURE;
            }
            memcpy(active_node_id_out, value, value_len + 1);
        } else if (strcmp(line, "clean_shutdown") == 0) {
            clean_shutdown = atoi(value) != 0 ? 1 : 0;
            has_clean_shutdown = 1;
        }
    }

    if (
        !has_version
        || !has_generation
        || !has_created_at
        || !has_clean_shutdown
        || version == 0
        || generation == 0
    ) {
        return FAILURE;
    }

    *version_out = version;
    *generation_out = generation;
    *created_at_out = (time_t) created_at;
    *clean_shutdown_out = clean_shutdown;
    return SUCCESS;
}

static int king_system_initialize_coordinator_state(void)
{
    char coordinator_dir[PATH_MAX];
    char loaded_cluster_id[64];
    char loaded_node_id[64];
    char state_path[PATH_MAX];
    time_t created_at;
    time_t now;
    uint64_t generation;
    uint64_t version;
    zend_bool clean_shutdown = 0;

    king_system_reset_coordinator_state_runtime();

    if (king_system_runtime_config.state_root_path[0] == '\0') {
        return SUCCESS;
    }

    king_system_build_coordinator_dir_path(
        coordinator_dir,
        sizeof(coordinator_dir)
    );
    king_system_build_coordinator_state_path(
        state_path,
        sizeof(state_path)
    );

    if (king_system_ensure_directory_recursive(coordinator_dir) != SUCCESS) {
        snprintf(
            king_system_coordinator_state_status,
            sizeof(king_system_coordinator_state_status),
            "%s",
            "failed"
        );
        snprintf(
            king_system_coordinator_state_error,
            sizeof(king_system_coordinator_state_error),
            "Could not create system coordinator directory '%s'.",
            coordinator_dir
        );
        return FAILURE;
    }

    now = time(NULL);
    if (access(state_path, F_OK) != 0) {
        version = 1;
        generation = 1;
        created_at = now;
    } else {
        if (king_system_load_coordinator_state(
                state_path,
                &version,
                &generation,
                &created_at,
                loaded_cluster_id,
                sizeof(loaded_cluster_id),
                loaded_node_id,
                sizeof(loaded_node_id),
                &clean_shutdown
            ) != SUCCESS) {
            snprintf(
                king_system_coordinator_state_status,
                sizeof(king_system_coordinator_state_status),
                "%s",
                "failed"
            );
            snprintf(
                king_system_coordinator_state_error,
                sizeof(king_system_coordinator_state_error),
                "System coordinator state at '%s' is unreadable or corrupted.",
                state_path
            );
            return FAILURE;
        }

        if (
            loaded_cluster_id[0] != '\0'
            && king_system_runtime_config.cluster_id[0] != '\0'
            && strcmp(loaded_cluster_id, king_system_runtime_config.cluster_id) != 0
        ) {
            snprintf(
                king_system_coordinator_state_status,
                sizeof(king_system_coordinator_state_status),
                "%s",
                "failed"
            );
            snprintf(
                king_system_coordinator_state_error,
                sizeof(king_system_coordinator_state_error),
                "System coordinator state at '%s' belongs to cluster '%s', not '%s'.",
                state_path,
                loaded_cluster_id,
                king_system_runtime_config.cluster_id
            );
            return FAILURE;
        }

        if (!clean_shutdown && loaded_node_id[0] != '\0') {
            king_system_coordinator_state_recovered = true;
            king_system_start_recovery_plan(
                KING_SYSTEM_RECOVERY_MODE_NODE,
                loaded_node_id
            );
        }

        generation++;
    }

    if (king_system_write_coordinator_state(
            state_path,
            version,
            generation,
            created_at,
            king_system_runtime_config.cluster_id,
            king_system_runtime_config.node_id,
            0
        ) != SUCCESS) {
        snprintf(
            king_system_coordinator_state_status,
            sizeof(king_system_coordinator_state_status),
            "%s",
            "failed"
        );
        snprintf(
            king_system_coordinator_state_error,
            sizeof(king_system_coordinator_state_error),
            "Could not persist system coordinator state at '%s'.",
            state_path
        );
        return FAILURE;
    }

    king_system_coordinator_state_present = true;
    king_system_coordinator_state_version = version;
    king_system_coordinator_generation = generation;
    king_system_coordinator_created_at = created_at;
    king_system_coordinator_last_loaded_at = now;
    strncpy(
        king_system_coordinator_state_path,
        state_path,
        sizeof(king_system_coordinator_state_path) - 1
    );
    snprintf(
        king_system_coordinator_state_status,
        sizeof(king_system_coordinator_state_status),
        "%s",
        king_system_coordinator_state_recovered ? "recovered" : "initialized"
    );
    return SUCCESS;
}

static void king_system_mark_coordinator_clean_shutdown(void)
{
    if (
        !king_system_coordinator_state_present
        || king_system_coordinator_state_path[0] == '\0'
    ) {
        return;
    }

    if (king_system_write_coordinator_state(
            king_system_coordinator_state_path,
            king_system_coordinator_state_version > 0
                ? king_system_coordinator_state_version
                : 1,
            king_system_coordinator_generation > 0
                ? king_system_coordinator_generation
                : 1,
            king_system_coordinator_created_at > 0
                ? king_system_coordinator_created_at
                : time(NULL),
            king_system_runtime_config.cluster_id,
            king_system_runtime_config.node_id,
            1
        ) == SUCCESS) {
        king_system_coordinator_last_loaded_at = time(NULL);
    }
}
