#pragma once
/**
 * 2-D separable Haar DWT — cache-optimised lifting implementation
 *
 * Forward pass (per level):
 *   1. Row pass  — processes each row in-place, fully cache-friendly.
 *   2. Column pass — processes columns in BLOCK_COLS-wide blocks so the
 *      working set fits in the L1 data cache (≤ 32 KB).
 *
 * After N levels the subbands are arranged in the standard Mallat pyramid
 * packed inside the original w×h array:
 *
 *   [LL_N | LH_N | LH_{N-1} | … | LH_1]
 *   [HL_N | HH_N |           |   | HL_1]
 *   [HL_{N-1} …                  | HH_1]
 *
 * All operations are in-place; no temporary allocation except for the
 * per-1-D-pass scratch buffer (allocated once per encode/decode call,
 * reused across rows/columns via a caller-provided scratch pointer).
 */

#include <cstdint>
#include <cstddef>

namespace wlvc {

// Width of the column-processing tile.
// 16 columns × 1080 rows × 4 bytes = 69 KB — a bit large for a 32 KB L1.
// For safety use 8 (8 × 1080 × 4 = 34 KB); override at compile time.
#ifndef DWT_BLOCK_COLS
#  define DWT_BLOCK_COLS 8
#endif

static constexpr int kBlockCols = DWT_BLOCK_COLS;

/**
 * In-place 2-D Haar DWT forward transform.
 * data  — float32 array, row-major, size w*h
 * w, h  — frame dimensions (even at each level)
 * levels— number of decomposition levels
 * tmp   — caller-supplied scratch buffer, minimum size: max(w, h) floats
 */
void dwt_forward(float* __restrict__ data, int w, int h, int levels,
                 float* __restrict__ tmp);

/**
 * In-place 2-D Haar DWT inverse transform.
 * Reconstructs from a Mallat-packed coefficient array.
 */
void dwt_inverse(float* __restrict__ data, int w, int h, int levels,
                 float* __restrict__ tmp);

// ---------------------------------------------------------------------------
// Internal helpers — exposed for unit testing
// ---------------------------------------------------------------------------

// 1-D in-place Haar forward, stride-aware.
// Processes n elements at data[0], data[stride], data[2*stride], …
// tmp must hold n floats.
void haar1d_fwd(float* data, int n, int stride, float* tmp);

// 1-D in-place Haar inverse, stride-aware.
void haar1d_inv(float* data, int n, int stride, float* tmp);

// Cache-blocked column forward pass.
// Processes columns [0..cw-1] of rows [0..ch-1].
// Uses a BLOCK_COLS-wide transpose buffer to keep the working set in L1.
void haar_col_fwd_blocked(float* data, int full_w, int cw, int ch);

// Cache-blocked column inverse pass.
void haar_col_inv_blocked(float* data, int full_w, int cw, int ch);

} // namespace wlvc
