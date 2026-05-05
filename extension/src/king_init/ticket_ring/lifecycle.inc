/*
 * Shared-memory ticket-ring lifecycle. Creates or reattaches the per-user,
 * per-module ring segment, initializes the robust process-shared mutex and
 * tears the ring back down with slot shredding on shutdown.
 */

#if defined(__APPLE__) || defined(__FreeBSD__) || defined(__OpenBSD__) || defined(__NetBSD__)
#define KING_TICKET_RING_MUTEX_PSHARED_FALLBACK_PRIVATE 1
#define KING_TICKET_RING_FLOCK_ENOTSUP_CONTINUE 1
#define KING_TICKET_RING_FILE_BACKED_SHM_FALLBACK 1
#endif

static int king_ticket_ring_unlink_name(const char *name)
{
    if (name == NULL || name[0] == '\0') {
        return 0;
    }

#if defined(KING_TICKET_RING_FILE_BACKED_SHM_FALLBACK)
    if (strncmp(name, "/tmp/king_ticket_ring_", sizeof("/tmp/king_ticket_ring_") - 1) == 0) {
        return unlink(name);
    }
#endif

    return shm_unlink(name);
}

static int king_ticket_ring_lock_fd(int fd, bool *locked)
{
    *locked = false;

    if (flock(fd, LOCK_EX) == 0) {
        *locked = true;
        return SUCCESS;
    }

#if defined(KING_TICKET_RING_FLOCK_ENOTSUP_CONTINUE)
    if (errno == ENOTSUP || errno == EOPNOTSUPP) {
        return SUCCESS;
    }
#endif

    return FAILURE;
}

static void king_ticket_ring_unlock_fd(int fd, bool locked)
{
    if (locked) {
        (void) flock(fd, LOCK_UN);
    }
}

static int king_ticket_ring_configure_mutexattr(pthread_mutexattr_t *attr)
{
    if (pthread_mutexattr_setpshared(attr, PTHREAD_PROCESS_SHARED) == 0) {
        return SUCCESS;
    }

#if defined(KING_TICKET_RING_MUTEX_PSHARED_FALLBACK_PRIVATE)
    return pthread_mutexattr_setpshared(attr, PTHREAD_PROCESS_PRIVATE) == 0
        ? SUCCESS
        : FAILURE;
#else
    return FAILURE;
#endif
}

#if defined(KING_TICKET_RING_FILE_BACKED_SHM_FALLBACK)
static int king_ticket_ring_open_file_backed_fallback(bool *created)
{
    int fd;

    snprintf(
        king_globals.ticket_ring_name,
        sizeof(king_globals.ticket_ring_name),
        "/tmp/king_ticket_ring_%ld_%d",
        (long) getuid(),
        king_ini_module_number
    );

    fd = open(
        king_globals.ticket_ring_name,
        O_CREAT | O_EXCL | O_RDWR,
        0600
    );
    if (fd >= 0) {
        *created = true;
        return fd;
    }

    if (errno == EEXIST) {
        return open(king_globals.ticket_ring_name, O_RDWR, 0600);
    }

    return -1;
}
#endif

static int king_ticket_ring_init(void)
{
    king_ticket_ring_shared_t *ring;
    pthread_mutexattr_t attr;
    bool created = false;
    bool fd_locked = false;
    bool needs_resize = false;
    struct stat statbuf;
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

#if defined(KING_TICKET_RING_FILE_BACKED_SHM_FALLBACK)
    if (fd < 0 && (errno == EPERM || errno == EACCES)) {
        created = false;
        fd = king_ticket_ring_open_file_backed_fallback(&created);
    }
#endif

    if (fd < 0) {
        php_error_docref(NULL, E_WARNING, "king_ticket_ring_init shm_open failed: %s", strerror(errno));
        king_globals.ticket_ring_name[0] = '\0';
        return FAILURE;
    }

    if (king_ticket_ring_lock_fd(fd, &fd_locked) != SUCCESS) {
        php_error_docref(NULL, E_WARNING, "king_ticket_ring_init flock failed: %s", strerror(errno));
        close(fd);
        king_globals.ticket_ring_name[0] = '\0';
        return FAILURE;
    }

    needs_resize = created;
    if (fstat(fd, &statbuf) != 0) {
        php_error_docref(NULL, E_WARNING, "king_ticket_ring_init fstat failed: %s", strerror(errno));
        king_ticket_ring_unlock_fd(fd, fd_locked);
        close(fd);
        if (created) {
            king_ticket_ring_unlink_name(king_globals.ticket_ring_name);
        }
        king_globals.ticket_ring_name[0] = '\0';
        return FAILURE;
    }
    if ((off_t) statbuf.st_size < (off_t) sizeof(king_ticket_ring_shared_t)) {
        needs_resize = true;
    }

    if (needs_resize && ftruncate(fd, sizeof(king_ticket_ring_shared_t)) != 0) {
        php_error_docref(NULL, E_WARNING, "king_ticket_ring_init ftruncate failed: %s", strerror(errno));
        king_ticket_ring_unlock_fd(fd, fd_locked);
        close(fd);
        if (created) {
            king_ticket_ring_unlink_name(king_globals.ticket_ring_name);
        }
        king_globals.ticket_ring_name[0] = '\0';
        return FAILURE;
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
        php_error_docref(NULL, E_WARNING, "king_ticket_ring_init mmap failed: %s", strerror(errno));
        king_ticket_ring_unlock_fd(fd, fd_locked);
        if (created) {
            king_ticket_ring_unlink_name(king_globals.ticket_ring_name);
        }
        close(fd);
        king_globals.ticket_ring_name[0] = '\0';
        return FAILURE;
    }

    if (created || ring->initialized != 1) {
        memset(ring, 0, sizeof(*ring));
        if (pthread_mutexattr_init(&attr) != 0) {
            php_error_docref(NULL, E_WARNING, "king_ticket_ring_init pthread_mutexattr_init failed");
            king_ticket_ring_unlock_fd(fd, fd_locked);
            munmap(ring, sizeof(*ring));
            king_ticket_ring_unlink_name(king_globals.ticket_ring_name);
            close(fd);
            king_globals.ticket_ring_name[0] = '\0';
            return FAILURE;
        }

        if (king_ticket_ring_configure_mutexattr(&attr) != SUCCESS) {
            php_error_docref(NULL, E_WARNING, "king_ticket_ring_init pthread mutex pshared configuration failed");
            pthread_mutexattr_destroy(&attr);
            munmap(ring, sizeof(*ring));
            king_ticket_ring_unlock_fd(fd, fd_locked);
            king_ticket_ring_unlink_name(king_globals.ticket_ring_name);
            close(fd);
            king_globals.ticket_ring_name[0] = '\0';
            return FAILURE;
        }

#ifdef PTHREAD_MUTEX_ROBUST
        if (pthread_mutexattr_setrobust(&attr, PTHREAD_MUTEX_ROBUST) != 0) {
            php_error_docref(NULL, E_WARNING, "king_ticket_ring_init pthread robust mutex configuration failed");
            pthread_mutexattr_destroy(&attr);
            munmap(ring, sizeof(*ring));
            king_ticket_ring_unlock_fd(fd, fd_locked);
            king_ticket_ring_unlink_name(king_globals.ticket_ring_name);
            close(fd);
            king_globals.ticket_ring_name[0] = '\0';
            return FAILURE;
        }
#endif

        if (pthread_mutex_init(&ring->lock, &attr) != 0) {
            php_error_docref(NULL, E_WARNING, "king_ticket_ring_init pthread_mutex_init failed");
            pthread_mutexattr_destroy(&attr);
            munmap(ring, sizeof(*ring));
            king_ticket_ring_unlock_fd(fd, fd_locked);
            king_ticket_ring_unlink_name(king_globals.ticket_ring_name);
            close(fd);
            king_globals.ticket_ring_name[0] = '\0';
            return FAILURE;
        }

        pthread_mutexattr_destroy(&attr);
        ring->capacity = KING_TICKET_RING_SLOT_COUNT;
        ring->ttl_seconds = king_tls_and_crypto_config.tls_session_ticket_lifetime_sec;
        ring->initialized = 1;
    } else {
        if (king_ticket_ring_lock(ring) != SUCCESS) {
            php_error_docref(NULL, E_WARNING, "king_ticket_ring_init existing ring lock failed");
            munmap(ring, sizeof(*ring));
            king_ticket_ring_unlock_fd(fd, fd_locked);
            close(fd);
            king_globals.ticket_ring_name[0] = '\0';
            return FAILURE;
        }
        ring->ttl_seconds = king_tls_and_crypto_config.tls_session_ticket_lifetime_sec;
        king_ticket_ring_unlock(ring);
    }

    king_ticket_ring_unlock_fd(fd, fd_locked);

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

    munmap(ring, king_globals.ticket_ring_size);
    if (king_globals.ticket_ring_fd >= 0) {
        close(king_globals.ticket_ring_fd);
    }
    if (king_globals.ticket_ring_name[0] != '\0') {
        king_ticket_ring_unlink_name(king_globals.ticket_ring_name);
    }
    king_globals.ticket_ring_fd = -1;
    king_globals.ticket_ring_size = 0;
    king_globals.ticket_ring = NULL;
    king_globals.ticket_ring_name[0] = '\0';
}
