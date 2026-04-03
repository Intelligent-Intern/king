/*
 * Distributed-backend slice. Manages the filesystem-backed distributed object
 * mirror and the bounded read/write/remove helpers behind distributed mode.
 */

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

