void king_ticket_ring_put(const uint8_t *ticket, size_t len)
{
    king_ticket_ring_shared_t *ring = king_ticket_ring_shared();
    king_ticket_ring_slot_t *slot;

    if (ring == NULL || ticket == NULL || len == 0 || len > KING_MAX_TICKET_SIZE) {
        return;
    }

    if (king_ticket_ring_lock(ring) != SUCCESS) {
        return;
    }
    king_ticket_ring_prune_expired_locked(ring, time(NULL));

    slot = &ring->slots[ring->head];
    if (slot->len > 0 && ring->count == ring->capacity) {
        ring->tail = (ring->tail + 1U) % ring->capacity;
    }

    king_ticket_ring_shred_slot(slot);
    memcpy(slot->data, ticket, len);
    slot->len = (uint32_t) len;
    slot->stored_at = (int64_t) time(NULL);

    if (ring->count < ring->capacity) {
        ring->count++;
    }

    ring->head = (ring->head + 1U) % ring->capacity;
    king_ticket_ring_unlock(ring);
}

int king_ticket_ring_get(uint8_t *out, size_t *out_len)
{
    king_ticket_ring_shared_t *ring = king_ticket_ring_shared();
    king_ticket_ring_slot_t *selected = NULL;
    int64_t newest_time = 0;
    uint32_t i;
    time_t now;

    if (ring == NULL || out == NULL || out_len == NULL || *out_len < KING_MAX_TICKET_SIZE) {
        return 0;
    }

    now = time(NULL);

    if (king_ticket_ring_lock(ring) != SUCCESS) {
        return 0;
    }
    ring->ttl_seconds = king_tls_and_crypto_config.tls_session_ticket_lifetime_sec;
    king_ticket_ring_prune_expired_locked(ring, now);

    for (i = 0; i < ring->capacity; ++i) {
        king_ticket_ring_slot_t *slot = &ring->slots[i];

        if (slot->len == 0 || slot->stored_at == 0) {
            continue;
        }

        if (slot->stored_at >= newest_time) {
            newest_time = slot->stored_at;
            selected = slot;
        }
    }

    if (selected == NULL) {
        king_ticket_ring_unlock(ring);
        return 0;
    }

    memcpy(out, selected->data, selected->len);
    *out_len = selected->len;
    king_ticket_ring_unlock(ring);

    return 1;
}
