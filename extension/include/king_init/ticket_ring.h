/*
 * include/king_init/ticket_ring.h - Shared TLS ticket-ring API
 */

#ifndef KING_INIT_TICKET_RING_H
#define KING_INIT_TICKET_RING_H

#include <stddef.h>
#include <stdint.h>

void king_ticket_ring_put(const uint8_t *ticket, size_t len);
int king_ticket_ring_get(uint8_t *out, size_t *out_len);

#endif /* KING_INIT_TICKET_RING_H */
