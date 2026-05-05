#include "entropy.h"
#include <cstring>
#include <algorithm>

namespace wlvc {

// ---------------------------------------------------------------------------
// Little-endian helpers
// ---------------------------------------------------------------------------
static inline void write_u32(uint8_t* p, uint32_t v) {
    p[0] = static_cast<uint8_t>(v);
    p[1] = static_cast<uint8_t>(v >> 8);
    p[2] = static_cast<uint8_t>(v >> 16);
    p[3] = static_cast<uint8_t>(v >> 24);
}

static inline uint32_t read_u32(const uint8_t* p) {
    return static_cast<uint32_t>(p[0])
         | (static_cast<uint32_t>(p[1]) << 8)
         | (static_cast<uint32_t>(p[2]) << 16)
         | (static_cast<uint32_t>(p[3]) << 24);
}

static inline void write_i16(uint8_t* p, int16_t v) {
    p[0] = static_cast<uint8_t>(static_cast<uint16_t>(v));
    p[1] = static_cast<uint8_t>(static_cast<uint16_t>(v) >> 8);
}

static inline int16_t read_i16(const uint8_t* p) {
    uint16_t u = static_cast<uint16_t>(p[0])
               | (static_cast<uint16_t>(p[1]) << 8);
    int16_t v;
    memcpy(&v, &u, 2);
    return v;
}

static inline void write_u16(uint8_t* p, uint16_t v) {
    p[0] = static_cast<uint8_t>(v);
    p[1] = static_cast<uint8_t>(v >> 8);
}

static inline uint16_t read_u16(const uint8_t* p) {
    return static_cast<uint16_t>(p[0])
         | (static_cast<uint16_t>(p[1]) << 8);
}

// ---------------------------------------------------------------------------
// RLE encode
// ---------------------------------------------------------------------------
size_t rle_encode(const int16_t* in, int n_values, uint8_t* out) {
    // We write pairs into a temporary region after the header, then
    // fix up the header.  Pairs are written directly to out+8.
    uint8_t* pairs_ptr  = out + RLE_HEADER_BYTES;
    uint32_t pair_count = 0;

    int i = 0;
    while (i < n_values) {
        const int16_t val = in[i];
        uint16_t run = 1;
        while (i + run < n_values
               && in[i + run] == val
               && run < 0xFFFFu)
            ++run;

        write_i16(pairs_ptr, val);
        write_u16(pairs_ptr + 2, run);
        pairs_ptr += 4;
        ++pair_count;
        i += run;
    }

    write_u32(out,     static_cast<uint32_t>(n_values));
    write_u32(out + 4, pair_count);

    return static_cast<size_t>(RLE_HEADER_BYTES)
         + static_cast<size_t>(pair_count) * static_cast<size_t>(RLE_PAIR_BYTES);
}

// ---------------------------------------------------------------------------
// RLE decode
// ---------------------------------------------------------------------------
int rle_decode(const uint8_t* in, size_t in_bytes,
               int16_t* out, int max_out) {
    if (in_bytes < static_cast<size_t>(RLE_HEADER_BYTES)) return 0;

    const uint32_t n_values = read_u32(in);
    const uint32_t n_pairs  = read_u32(in + 4);

    const size_t expected = static_cast<size_t>(RLE_HEADER_BYTES)
                          + static_cast<size_t>(n_pairs) * RLE_PAIR_BYTES;
    if (in_bytes < expected) return 0;  // truncated stream

    int out_idx = 0;
    const uint8_t* p = in + RLE_HEADER_BYTES;
    for (uint32_t j = 0; j < n_pairs && out_idx < max_out; ++j, p += 4) {
        const int16_t  val   = read_i16(p);
        const uint16_t count = read_u16(p + 2);
        const int fill = std::min(static_cast<int>(count), max_out - out_idx);
        for (int k = 0; k < fill; ++k) out[out_idx++] = val;
    }

    return out_idx;
}

} // namespace wlvc
