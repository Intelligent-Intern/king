#define KING_TICKET_RING_SLOT_COUNT 8

typedef struct _king_ticket_ring_slot_t {
    uint32_t len;
    int64_t stored_at;
    uint8_t data[KING_MAX_TICKET_SIZE];
} king_ticket_ring_slot_t;

typedef struct _king_ticket_ring_shared_t {
    pthread_mutex_t lock;
    uint32_t capacity;
    uint32_t head;
    uint32_t tail;
    uint32_t count;
    zend_long ttl_seconds;
    uint8_t initialized;
    uint8_t reserved[7];
    king_ticket_ring_slot_t slots[KING_TICKET_RING_SLOT_COUNT];
} king_ticket_ring_shared_t;

int king_ini_module_number = 0;

static king_ticket_ring_shared_t *king_ticket_ring_shared(void)
{
    return (king_ticket_ring_shared_t *) king_globals.ticket_ring;
}
