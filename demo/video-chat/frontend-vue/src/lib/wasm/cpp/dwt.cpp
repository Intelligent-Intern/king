/**
 * 2-D separable Haar DWT — cache-optimised lifting implementation
 *
 * Cache strategy for column pass:
 *   Process kBlockCols columns at a time.  For each block we:
 *     1. Gather: copy col-major data into a local row-major buffer
 *        (contiguous in memory → sequential writes, fast).
 *     2. Transform: apply 1-D Haar row-wise inside the buffer
 *        (sequential reads/writes, fully in L1).
 *     3. Scatter: copy back to the original column-major positions
 *        (sequential reads from buffer → strided writes to data,
 *         but the stride misses are amortised over kBlockCols columns).
 *
 * Buffer size per block: kBlockCols × max_height × 4 bytes.
 * With kBlockCols=8, max_height=1080: 8×1080×4 = 34 560 B ≈ 34 KB.
 * Fits comfortably in most 32–48 KB L1 data caches.
 *
 * SIMD: row and column passes use pointer arithmetic with no aliasing
 * (__restrict__), allowing the compiler to auto-vectorise the inner loops
 * when targeting -msimd128 (WASM SIMD) or -msse4 (native).
 */

#include "dwt.h"
#include <algorithm>
#include <cstring>

namespace wlvc {

// ---------------------------------------------------------------------------
// 1-D Haar lifting — in-place, stride-aware
// ---------------------------------------------------------------------------
// Forward: interleaved (even, odd) pairs → contiguous (approx | detail)
//   approx[i] = (even[i] + odd[i]) / 2
//   detail[i] =  odd[i]  - even[i]
//
// Inverse:
//   even[i] = approx[i] - detail[i] / 2
//   odd [i] = detail[i] + even[i]

void haar1d_fwd(float* data, int n, int stride, float* tmp) {
    const int half = n >> 1;
    // Gather even/odd, compute lifting, write to tmp[0..n-1]
    for (int i = 0; i < half; ++i) {
        const float a = data[i * 2       * stride];
        const float b = data[(i * 2 + 1) * stride];
        tmp[i]        = (a + b) * 0.5f;   // approx
        tmp[half + i] = b - a;             // detail
    }
    if (n & 1) tmp[n - 1] = data[(n - 1) * stride];  // preserve odd tail
    for (int i = 0; i < n; ++i) data[i * stride] = tmp[i];
}

void haar1d_inv(float* data, int n, int stride, float* tmp) {
    const int half = n >> 1;
    for (int i = 0; i < half; ++i) {
        const float s = data[i          * stride];  // approx
        const float d = data[(half + i) * stride];  // detail
        const float even = s - d * 0.5f;
        tmp[i * 2]     = even;
        tmp[i * 2 + 1] = d + even;
    }
    if (n & 1) tmp[n - 1] = data[(n - 1) * stride];
    for (int i = 0; i < n; ++i) data[i * stride] = tmp[i];
}

// ---------------------------------------------------------------------------
// Cache-blocked column passes
// ---------------------------------------------------------------------------
// Local buffer: [kBlockCols][max_height] stored in column-major (so each
// column is a contiguous row in the buffer — cache-friendly during transform).

void haar_col_fwd_blocked(float* data, int full_w, int cw, int ch) {
    // Stack-allocated tile buffer.  kBlockCols × 1080 × 4 B ≈ 34 KB.
    // Increase kBlockCols cautiously if your L1 is larger.
    static float tile[kBlockCols][1080 + 1];  // WASM is single-threaded
    float row_tmp[1080 + 1];  // scratch for haar1d_fwd inside tile

    for (int col = 0; col < cw; col += kBlockCols) {
        const int bw = std::min(kBlockCols, cw - col);

        // ── Gather: data[r][col..col+bw-1] → tile[c][r] ─────────────────
        for (int r = 0; r < ch; ++r) {
            const float* src = data + r * full_w + col;
            for (int c = 0; c < bw; ++c) tile[c][r] = src[c];
        }

        // ── Transform each column (now a contiguous row in tile) ──────────
        for (int c = 0; c < bw; ++c)
            haar1d_fwd(tile[c], ch, 1, row_tmp);

        // ── Scatter: tile[c][r] → data[r][col..col+bw-1] ─────────────────
        for (int r = 0; r < ch; ++r) {
            float* dst = data + r * full_w + col;
            for (int c = 0; c < bw; ++c) dst[c] = tile[c][r];
        }
    }
}

void haar_col_inv_blocked(float* data, int full_w, int cw, int ch) {
    static thread_local float tile[kBlockCols][1080 + 1];
    float row_tmp[1080 + 1];

    for (int col = 0; col < cw; col += kBlockCols) {
        const int bw = std::min(kBlockCols, cw - col);

        for (int r = 0; r < ch; ++r) {
            const float* src = data + r * full_w + col;
            for (int c = 0; c < bw; ++c) tile[c][r] = src[c];
        }
        for (int c = 0; c < bw; ++c)
            haar1d_inv(tile[c], ch, 1, row_tmp);
        for (int r = 0; r < ch; ++r) {
            float* dst = data + r * full_w + col;
            for (int c = 0; c < bw; ++c) dst[c] = tile[c][r];
        }
    }
}

// ---------------------------------------------------------------------------
// 2-D DWT — public entry points
// ---------------------------------------------------------------------------

void dwt_forward(float* __restrict__ data, int w, int h, int levels,
                 float* __restrict__ tmp) {
    int cw = w, ch = h;
    for (int lv = 0; lv < levels; ++lv) {
        // Row pass — sequential memory access, compiler auto-vectorises.
        for (int row = 0; row < ch; ++row)
            haar1d_fwd(data + row * w, cw, 1, tmp);

        // Column pass — cache-blocked for L1 residency.
        haar_col_fwd_blocked(data, w, cw, ch);

        cw >>= 1;
        ch >>= 1;
    }
}

void dwt_inverse(float* __restrict__ data, int w, int h, int levels,
                 float* __restrict__ tmp) {
    // Unwind from coarsest to finest.
    int cw = w >> levels;
    int ch = h >> levels;
    for (int lv = levels - 1; lv >= 0; --lv) {
        cw <<= 1;
        ch <<= 1;
        // Inverse order: columns first, then rows.
        haar_col_inv_blocked(data, w, cw, ch);
        for (int row = 0; row < ch; ++row)
            haar1d_inv(data + row * w, cw, 1, tmp);
    }
}

} // namespace wlvc
