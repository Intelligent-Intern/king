/*
 * Shared-memory ticket-ring lifecycle. Creates or reattaches the per-user,
 * per-module ring segment, initializes the robust process-shared mutex and
 * tears the ring back down with slot shredding on shutdown.
 */

static int king_ticket_ring_fail_with_errno(const char *stage, int error_number)
{
    char message[256];

    if (error_number != 0) {
        snprintf(
            message,
            sizeof(message),
            "%s failed: %s (%d)",
            stage,
            strerror(error_number),
            error_number
        );
    } else {
        snprintf(message, sizeof(message), "%s failed.", stage);
    }

    king_set_error(message);
    return FAILURE;
}

static bool king_ticket_ring_should_fallback_process_local(int error_number)
{
    if (error_number == EPERM || error_number == EACCES || error_number == ENOSYS) {
        return true;
    }
#ifdef ENOTSUP
    if (error_number == ENOTSUP) {
        return true;
    }
#endif
#ifdef EOPNOTSUPP
    if (error_number == EOPNOTSUPP) {
        return true;
    }
#endif
    return false;
}

static int king_ticket_ring_init_process_local(void)
{
    king_ticket_ring_shared_t *ring;

    ring = calloc(1, sizeof(*ring));
    if (ring == NULL) {
        return king_ticket_ring_fail_with_errno("ticket ring process-local allocation", errno);
    }

    if (pthread_mutex_init(&ring->lock, NULL) != 0) {
        int saved_errno = errno;
        free(ring);
        return king_ticket_ring_fail_with_errno("ticket ring process-local mutex initialization", saved_errno);
    }

    ring->capacity = KING_TICKET_RING_SLOT_COUNT;
    ring->ttl_seconds = king_tls_and_crypto_config.tls_session_ticket_lifetime_sec;
    ring->initialized = 1;

    king_globals.ticket_ring_fd = -1;
    king_globals.ticket_ring_size = sizeof(*ring);
    king_globals.ticket_ring = ring;
    king_globals.ticket_ring_name[0] = '\0';

    return SUCCESS;
}

static int king_ticket_ring_init(void)
{
    king_ticket_ring_shared_t *ring;
    pthread_mutexattr_t attr;
    bool created = false;
    int fd;

    if (king_globals.ticket_ring != NULL) {
        return SUCCESS;
    }

    snprintf(
        king_globals.ticket_ring_name,
        sizeof(king_globals.ticket_ring_name),
        "/king_ticket_ring_%ld_%d",
        (long) getuid(),
        king_ini_module_number
    );

    fd = shm_open(
        king_globals.ticket_ring_name,
        O_CREAT | O_EXCL | O_RDWR,
        0600
    );
    if (fd >= 0) {
        created = true;
    } else if (errno == EEXIST) {
        fd = shm_open(king_globals.ticket_ring_name, O_RDWR, 0600);
    }

    if (fd < 0) {
        int saved_errno = errno;
        if (king_ticket_ring_should_fallback_process_local(saved_errno)) {
            /*
             * Restricted runtimes (including some container/sandbox profiles)
             * can deny POSIX shared-memory namespaces. Fall back to a local
             * in-process ring so module startup remains functional.
             */
            return king_ticket_ring_init_process_local();
        }
        king_globals.ticket_ring_name[0] = '\0';
        return king_ticket_ring_fail_with_errno("ticket ring shared-memory open", saved_errno);
    }

    if (flock(fd, LOCK_EX) != 0) {
        int saved_errno = errno;
        close(fd);
        if (created) {
            shm_unlink(king_globals.ticket_ring_name);
        }
        if (king_ticket_ring_should_fallback_process_local(saved_errno)) {
            return king_ticket_ring_init_process_local();
        }
        king_globals.ticket_ring_name[0] = '\0';
        return king_ticket_ring_fail_with_errno("ticket ring lock acquisition", saved_errno);
    }

    if (created && ftruncate(fd, sizeof(king_ticket_ring_shared_t)) != 0) {
        int saved_errno = errno;
        (void) flock(fd, LOCK_UN);
        close(fd);
        shm_unlink(king_globals.ticket_ring_name);
        king_globals.ticket_ring_name[0] = '\0';
        return king_ticket_ring_fail_with_errno("ticket ring shared-memory sizing", saved_errno);
    }

    ring = mmap(
        NULL,
        sizeof(king_ticket_ring_shared_t),
        PROT_READ | PROT_WRITE,
        MAP_SHARED,
        fd,
        0
    );
    if (ring == MAP_FAILED) {
        int saved_errno = errno;
        (void) flock(fd, LOCK_UN);
        if (created) {
            shm_unlink(king_globals.ticket_ring_name);
        }
        close(fd);
        king_globals.ticket_ring_name[0] = '\0';
        return king_ticket_ring_fail_with_errno("ticket ring shared-memory mapping", saved_errno);
    }

    if (created || ring->initialized != 1) {
        memset(ring, 0, sizeof(*ring));
        if (pthread_mutexattr_init(&attr) != 0) {
            int saved_errno = errno;
            (void) flock(fd, LOCK_UN);
            munmap(ring, sizeof(*ring));
            shm_unlink(king_globals.ticket_ring_name);
            close(fd);
            king_globals.ticket_ring_name[0] = '\0';
            return king_ticket_ring_fail_with_errno("ticket ring mutex attribute init", saved_errno);
        }

        if (pthread_mutexattr_setpshared(&attr, PTHREAD_PROCESS_SHARED) != 0) {
            int saved_errno = errno;
            pthread_mutexattr_destroy(&attr);
            munmap(ring, sizeof(*ring));
            (void) flock(fd, LOCK_UN);
            shm_unlink(king_globals.ticket_ring_name);
            close(fd);
            king_globals.ticket_ring_name[0] = '\0';
            return king_ticket_ring_fail_with_errno("ticket ring mutex process-shared configuration", saved_errno);
        }

#ifdef PTHREAD_MUTEX_ROBUST
        if (pthread_mutexattr_setrobust(&attr, PTHREAD_MUTEX_ROBUST) != 0) {
            int saved_errno = errno;
            pthread_mutexattr_destroy(&attr);
            munmap(ring, sizeof(*ring));
            (void) flock(fd, LOCK_UN);
            shm_unlink(king_globals.ticket_ring_name);
            close(fd);
            king_globals.ticket_ring_name[0] = '\0';
            return king_ticket_ring_fail_with_errno("ticket ring mutex robust configuration", saved_errno);
        }
#endif

        if (pthread_mutex_init(&ring->lock, &attr) != 0) {
            int saved_errno = errno;
            pthread_mutexattr_destroy(&attr);
            munmap(ring, sizeof(*ring));
            (void) flock(fd, LOCK_UN);
            shm_unlink(king_globals.ticket_ring_name);
            close(fd);
            king_globals.ticket_ring_name[0] = '\0';
            return king_ticket_ring_fail_with_errno("ticket ring mutex initialization", saved_errno);
        }

        pthread_mutexattr_destroy(&attr);
        ring->capacity = KING_TICKET_RING_SLOT_COUNT;
        ring->ttl_seconds = king_tls_and_crypto_config.tls_session_ticket_lifetime_sec;
        ring->initialized = 1;
    } else {
        if (king_ticket_ring_lock(ring) != SUCCESS) {
            int saved_errno = errno;
            munmap(ring, sizeof(*ring));
            (void) flock(fd, LOCK_UN);
            close(fd);
            king_globals.ticket_ring_name[0] = '\0';
            return king_ticket_ring_fail_with_errno("ticket ring state lock", saved_errno);
        }
        ring->ttl_seconds = king_tls_and_crypto_config.tls_session_ticket_lifetime_sec;
        king_ticket_ring_unlock(ring);
    }

    (void) flock(fd, LOCK_UN);

    king_globals.ticket_ring_fd = fd;
    king_globals.ticket_ring_size = sizeof(*ring);
    king_globals.ticket_ring = ring;

    return SUCCESS;
}

static void king_ticket_ring_shutdown(void)
{
    king_ticket_ring_shared_t *ring = king_ticket_ring_shared();
    uint32_t i;

    if (ring == NULL) {
        return;
    }

    if (king_ticket_ring_lock(ring) == SUCCESS) {
        for (i = 0; i < ring->capacity; ++i) {
            king_ticket_ring_shred_slot(&ring->slots[i]);
        }
        ring->count = 0;
        ring->head = 0;
        ring->tail = 0;
        king_ticket_ring_unlock(ring);
    }

    if (king_globals.ticket_ring_fd >= 0) {
        munmap(ring, king_globals.ticket_ring_size);
        close(king_globals.ticket_ring_fd);
        if (king_globals.ticket_ring_name[0] != '\0') {
            shm_unlink(king_globals.ticket_ring_name);
        }
    } else {
        (void) pthread_mutex_destroy(&ring->lock);
        free(ring);
    }
    king_globals.ticket_ring_fd = -1;
    king_globals.ticket_ring_size = 0;
    king_globals.ticket_ring = NULL;
    king_globals.ticket_ring_name[0] = '\0';
}
