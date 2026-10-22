#ifndef KING_HTTP3_HEADER_BUDGET_H
#define KING_HTTP3_HEADER_BUDGET_H

#include <stdbool.h>
#include <stddef.h>

/* RFC 9114 section 4.2.2: charge every field, including repeated names. */
#define KING_HTTP3_HEADER_FIELD_OVERHEAD 32U

static inline bool king_http3_header_budget_consume(
    size_t *used, size_t limit, size_t name_len, size_t value_len)
{
    size_t remaining;

    if (*used > limit || limit - *used < KING_HTTP3_HEADER_FIELD_OVERHEAD) {
        return false;
    }
    remaining = limit - *used - KING_HTTP3_HEADER_FIELD_OVERHEAD;
    if (name_len > remaining || value_len > remaining - name_len) {
        return false;
    }
    *used += name_len + value_len + KING_HTTP3_HEADER_FIELD_OVERHEAD;
    return true;
}

/* QPACK may request geometric growth while decoding Huffman strings. Bound
 * this reusable scratch buffer independently; charge actual field lengths
 * before retaining them. No allocation follows an exhausted field budget. */
static inline bool king_http3_header_decode_allowed(size_t used, size_t limit, size_t space)
{
    return used <= limit && limit - used >= KING_HTTP3_HEADER_FIELD_OVERHEAD
        && space <= limit - KING_HTTP3_HEADER_FIELD_OVERHEAD;
}

#endif
