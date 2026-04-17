#pragma once
/**
 * Run-length coding of int16 arrays
 *
 * Wire format (little-endian):
 *   uint32  n_values  — total number of int16 values in the decoded stream
 *   uint32  n_pairs   — number of (value, count) pairs
 *   [n_pairs × 4 B]  — int16 value | uint16 count  (little-endian each)
 *
 * This is identical to the TypeScript codec format so encoded streams can
 * be decoded by either implementation.
 *
 * For sparse wavelet data (≥90% zeros at quality 60) the zero runs are
 * typically very long, giving ~8–12× average compression before any
 * entropy coding.
 */

#include <cstdint>
#include <cstddef>

namespace wlvc {

static const int RLE_HEADER_BYTES = 8;          // 2 × uint32
static const int RLE_PAIR_BYTES   = 4;          // int16 + uint16

/**
 * Encode an int16 array using RLE.
 *
 * @param in        Input quantised values.
 * @param n_values  Number of values.
 * @param out       Output buffer. Must be at least rle_max_bytes(n_values).
 * @returns         Number of bytes written.
 */
size_t rle_encode(const int16_t* in, int n_values, uint8_t* out);

/**
 * Decode an RLE-encoded stream.
 *
 * @param in        Encoded byte stream.
 * @param in_bytes  Length of the encoded stream.
 * @param out       Output buffer for decoded int16 values.
 * @param max_out   Maximum number of values to decode.
 * @returns         Number of int16 values written.
 */
int rle_decode(const uint8_t* in, size_t in_bytes,
               int16_t* out, int max_out);

/** Worst-case encoded size for n_values int16s (no compression). */
inline size_t rle_max_bytes(int n_values) {
    // Each value gets its own pair: header + n_values × 4 B
    return static_cast<size_t>(RLE_HEADER_BYTES + n_values * RLE_PAIR_BYTES);
}

} // namespace wlvc
