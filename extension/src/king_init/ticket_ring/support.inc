/*
 * Ticket-ring support helpers. Handles slot shredding, expiry pruning, robust
 * mutex recovery and the small best-effort random fill used to harden ticket
 * zeroization before a slot is reused.
 */

static void king_best_effort_random_fill(void *buffer, size_t len)
{
    unsigned char *cursor = (unsigned char *) buffer;
    size_t offset = 0;

    if (buffer == NULL || len == 0) {
        return;
    }

#if defined(__APPLE__) || defined(__FreeBSD__) || defined(__OpenBSD__) || defined(__NetBSD__)
    arc4random_buf(buffer, len);
    return;
#elif defined(__linux__)
    while (offset < len) {
        ssize_t written = getrandom(cursor + offset, len - offset, 0);

        if (written < 0) {
            if (errno == EINTR) {
                continue;
            }
            break;
        }

        offset += (size_t) written;
    }

    if (offset == len) {
        return;
    }
#endif

    while (offset < len) {
        ssize_t written;
        int fd = open("/dev/urandom", O_RDONLY);

        if (fd < 0) {
            return;
        }

        do {
            written = read(fd, cursor + offset, len - offset);
        } while (written < 0 && errno == EINTR);

        (void) close(fd);

        if (written <= 0) {
            return;
        }

        offset += (size_t) written;
    }
}

static void king_ticket_ring_shred_slot(king_ticket_ring_slot_t *slot)
{
    if (slot == NULL) {
        return;
    }

    king_secure_zero(slot->data, sizeof(slot->data));
    king_best_effort_random_fill(slot->data, sizeof(slot->data));
    king_secure_zero(slot->data, sizeof(slot->data));
    slot->len = 0;
    slot->stored_at = 0;
}

static void king_ticket_ring_prune_expired_locked(
    king_ticket_ring_shared_t *ring,
    time_t now
)
{
    uint32_t i;

    if (ring->ttl_seconds <= 0) {
        return;
    }

    for (i = 0; i < ring->capacity; ++i) {
        king_ticket_ring_slot_t *slot = &ring->slots[i];

        if (slot->len == 0 || slot->stored_at == 0) {
            continue;
        }

        if ((zend_long) (now - (time_t) slot->stored_at) <= ring->ttl_seconds) {
            continue;
        }

        king_ticket_ring_shred_slot(slot);
        if (ring->count > 0) {
            ring->count--;
        }
    }
}

static void king_ticket_ring_reset_locked(king_ticket_ring_shared_t *ring)
{
    uint32_t i;

    if (ring == NULL) {
        return;
    }

    for (i = 0; i < KING_TICKET_RING_SLOT_COUNT; ++i) {
        king_ticket_ring_shred_slot(&ring->slots[i]);
    }

    ring->capacity = KING_TICKET_RING_SLOT_COUNT;
    ring->head = 0;
    ring->tail = 0;
    ring->count = 0;
    ring->ttl_seconds = king_tls_and_crypto_config.tls_session_ticket_lifetime_sec;
    ring->initialized = 1;
}

static int king_ticket_ring_lock(king_ticket_ring_shared_t *ring)
{
    int rc;

    if (ring == NULL) {
        return FAILURE;
    }

    rc = pthread_mutex_lock(&ring->lock);
    if (rc == 0) {
        return SUCCESS;
    }

#ifdef EOWNERDEAD
    if (rc == EOWNERDEAD) {
        king_ticket_ring_reset_locked(ring);
#ifdef PTHREAD_MUTEX_ROBUST
        if (pthread_mutex_consistent(&ring->lock) != 0) {
            return FAILURE;
        }
#else
        return FAILURE;
#endif
        return SUCCESS;
    }
#endif

    return FAILURE;
}

static void king_ticket_ring_unlock(king_ticket_ring_shared_t *ring)
{
    if (ring == NULL) {
        return;
    }

    (void) pthread_mutex_unlock(&ring->lock);
}
