static zend_result king_object_store_fill_random_bytes(uint8_t *target, size_t target_len)
{
    int fd;
    size_t total = 0;

    if (target == NULL || target_len == 0) {
        return FAILURE;
    }

    fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (fd < 0) {
        return FAILURE;
    }

    while (total < target_len) {
        ssize_t chunk = read(fd, target + total, target_len - total);

        if (chunk <= 0) {
            close(fd);
            return FAILURE;
        }

        total += (size_t) chunk;
    }

    close(fd);
    return SUCCESS;
}

static int king_object_store_mutation_barrier_fds_ensure(void)
{
    if (king_object_store_mutation_barrier_fds_initialized) {
        return SUCCESS;
    }

    zend_hash_init(&king_object_store_mutation_barrier_fds, 8, NULL, NULL, 0);
    king_object_store_mutation_barrier_fds_initialized = true;
    return SUCCESS;
}

static void king_object_store_release_barrier_lock_fd(int *lock_fd)
{
    if (lock_fd == NULL || *lock_fd < 0) {
        return;
    }

    (void) flock(*lock_fd, LOCK_UN);
    close(*lock_fd);
    *lock_fd = -1;
}

static void king_object_store_mutation_barrier_fds_reset(void)
{
    if (king_object_store_active_restore_barrier_fd >= 0) {
        king_object_store_release_barrier_lock_fd(&king_object_store_active_restore_barrier_fd);
    }

    if (!king_object_store_mutation_barrier_fds_initialized) {
        return;
    }

    zend_hash_destroy(&king_object_store_mutation_barrier_fds);
    king_object_store_mutation_barrier_fds_initialized = false;
}

static int king_object_store_mutation_barrier_fd_store(int object_lock_fd, int barrier_lock_fd)
{
    zval stored_fd;

    if (object_lock_fd < 0 || barrier_lock_fd < 0) {
        return FAILURE;
    }

    if (king_object_store_mutation_barrier_fds_ensure() != SUCCESS) {
        return FAILURE;
    }

    ZVAL_LONG(&stored_fd, barrier_lock_fd);
    zend_hash_index_update(&king_object_store_mutation_barrier_fds, (zend_ulong) object_lock_fd, &stored_fd);
    return SUCCESS;
}

static int king_object_store_mutation_barrier_fd_take(int object_lock_fd)
{
    zval *entry;
    int barrier_lock_fd = -1;

    if (object_lock_fd < 0 || !king_object_store_mutation_barrier_fds_initialized) {
        return -1;
    }

    entry = zend_hash_index_find(&king_object_store_mutation_barrier_fds, (zend_ulong) object_lock_fd);
    if (entry != NULL && Z_TYPE_P(entry) == IS_LONG) {
        barrier_lock_fd = (int) Z_LVAL_P(entry);
        zend_hash_index_del(&king_object_store_mutation_barrier_fds, (zend_ulong) object_lock_fd);
    }

    return barrier_lock_fd;
}

static int king_object_store_acquire_shared_mutation_barrier(
    int *lock_fd_out,
    char *error,
    size_t error_size
)
{
    char lock_path[1024];
    int fd;

    if (lock_fd_out != NULL) {
        *lock_fd_out = -1;
    }
    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }

    if (!king_object_store_runtime.initialized) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Object-store runtime is unavailable for restore-aware mutation locking.");
        }
        return FAILURE;
    }

    if (king_object_store_active_restore_barrier_fd >= 0) {
        return SUCCESS;
    }

    if (king_object_store_build_mutation_barrier_path(lock_path, sizeof(lock_path)) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to prepare the object-store restore barrier.");
        }
        return FAILURE;
    }

    fd = open(lock_path, O_RDWR | O_CREAT | O_CLOEXEC, 0666);
    if (fd < 0) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to open the object-store restore barrier.");
        }
        return FAILURE;
    }

    if (flock(fd, LOCK_SH | LOCK_NB) != 0) {
        if (error != NULL && error_size > 0) {
            if (errno == EWOULDBLOCK || errno == EAGAIN) {
                snprintf(error, error_size, "Object-store restore is already in progress.");
            } else {
                snprintf(error, error_size, "Failed to acquire the object-store restore barrier.");
            }
        }
        close(fd);
        return FAILURE;
    }

    if (lock_fd_out == NULL) {
        king_object_store_release_barrier_lock_fd(&fd);
        return FAILURE;
    }

    *lock_fd_out = fd;
    return SUCCESS;
}

static int king_object_store_acquire_exclusive_restore_barrier(
    int *lock_fd_out,
    char *error,
    size_t error_size
)
{
    char lock_path[1024];
    int fd;

    if (lock_fd_out != NULL) {
        *lock_fd_out = -1;
    }
    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }

    if (!king_object_store_runtime.initialized) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Object-store runtime is unavailable for batch restore.");
        }
        return FAILURE;
    }

    if (king_object_store_build_mutation_barrier_path(lock_path, sizeof(lock_path)) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to prepare the object-store restore barrier.");
        }
        return FAILURE;
    }

    fd = open(lock_path, O_RDWR | O_CREAT | O_CLOEXEC, 0666);
    if (fd < 0) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to open the object-store restore barrier.");
        }
        return FAILURE;
    }

    if (flock(fd, LOCK_EX | LOCK_NB) != 0) {
        if (error != NULL && error_size > 0) {
            if (errno == EWOULDBLOCK || errno == EAGAIN) {
                snprintf(
                    error,
                    error_size,
                    "Object-store batch restore requires a quiescent runtime with no active mutations or concurrent restore."
                );
            } else {
                snprintf(error, error_size, "Failed to acquire the object-store restore barrier.");
            }
        }
        close(fd);
        return FAILURE;
    }

    king_object_store_active_restore_barrier_fd = fd;
    if (lock_fd_out == NULL) {
        king_object_store_release_exclusive_restore_barrier(&fd);
        return FAILURE;
    }

    *lock_fd_out = fd;
    return SUCCESS;
}

static void king_object_store_release_exclusive_restore_barrier(int *lock_fd)
{
    if (lock_fd == NULL || *lock_fd < 0) {
        return;
    }

    if (king_object_store_active_restore_barrier_fd == *lock_fd) {
        king_object_store_active_restore_barrier_fd = -1;
    }

    king_object_store_release_barrier_lock_fd(lock_fd);
}

int king_object_store_acquire_object_lock(
    const char *object_id,
    int *lock_fd_out,
    char *error,
    size_t error_size
)
{
    char lock_path[1024];
    int fd;
    int barrier_lock_fd = -1;

    if (lock_fd_out != NULL) {
        *lock_fd_out = -1;
    }
    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }

    if (!king_object_store_runtime.initialized || object_id == NULL || object_id[0] == '\0') {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Object-store runtime is unavailable for mutation locking.");
        }
        return FAILURE;
    }

    if (king_object_store_active_restore_barrier_fd < 0
        && king_object_store_acquire_shared_mutation_barrier(
            &barrier_lock_fd,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_build_lock_path(object_id, lock_path, sizeof(lock_path)) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to prepare the object-store mutation lock for '%s'.", object_id);
        }
        king_object_store_release_barrier_lock_fd(&barrier_lock_fd);
        return FAILURE;
    }

    fd = open(lock_path, O_RDWR | O_CREAT | O_CLOEXEC, 0666);
    if (fd < 0) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to open the object-store mutation lock for '%s'.", object_id);
        }
        king_object_store_release_barrier_lock_fd(&barrier_lock_fd);
        return FAILURE;
    }

    if (flock(fd, LOCK_EX | LOCK_NB) != 0) {
        if (error != NULL && error_size > 0) {
            if (errno == EWOULDBLOCK || errno == EAGAIN) {
                snprintf(error, error_size, "Object-store object '%s' already has an active mutation.", object_id);
            } else {
                snprintf(error, error_size, "Failed to acquire the object-store mutation lock for '%s'.", object_id);
            }
        }
        close(fd);
        king_object_store_release_barrier_lock_fd(&barrier_lock_fd);
        return FAILURE;
    }

    if (barrier_lock_fd >= 0
        && king_object_store_mutation_barrier_fd_store(fd, barrier_lock_fd) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to store the object-store restore barrier state for '%s'.", object_id);
        }
        (void) flock(fd, LOCK_UN);
        close(fd);
        king_object_store_release_barrier_lock_fd(&barrier_lock_fd);
        return FAILURE;
    }

    if (lock_fd_out != NULL) {
        *lock_fd_out = fd;
    } else {
        close(fd);
        king_object_store_release_barrier_lock_fd(&barrier_lock_fd);
        return FAILURE;
    }

    return SUCCESS;
}

void king_object_store_release_object_lock(int *lock_fd)
{
    int barrier_lock_fd;

    if (lock_fd == NULL || *lock_fd < 0) {
        return;
    }

    barrier_lock_fd = king_object_store_mutation_barrier_fd_take(*lock_fd);
    (void) flock(*lock_fd, LOCK_UN);
    close(*lock_fd);
    *lock_fd = -1;
    king_object_store_release_barrier_lock_fd(&barrier_lock_fd);
}

