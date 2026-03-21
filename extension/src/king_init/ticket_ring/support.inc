static void king_secure_zero(void *buffer, size_t len)
{
    volatile unsigned char *cursor = (volatile unsigned char *) buffer;
    size_t i;

    for (i = 0; i < len; ++i) {
        cursor[i] = 0;
    }
}

static void king_best_effort_random_fill(void *buffer, size_t len)
{
    unsigned char *cursor = (unsigned char *) buffer;
    size_t offset = 0;

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
