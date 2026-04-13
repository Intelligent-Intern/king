#pragma once
/**
 * Per-subband scalar quantisation with deadzone
 *
 * Step-size formula (JPEG-style quality):
 *   base  = (quality < 50) ? (5000/quality)/100
 *                           : (200 − 2·quality)/100
 *
 * Detail subbands (lv = DWT iteration, 0 = finest):
 *   step(lv) = base × 2^(levels − lv)
 *
 * LL subband:
 *   step(LL) = base × 1
 *
 * This makes finest subbands (large area, high spatial frequency) carry the
 * largest quantisation step so they produce the most zeros — maximising RLE
 * compression while preserving low-frequency energy.
 *
 * Deadzone: |coeff| < step × 0.5  →  quantised to 0.
 * Output quantised values are clamped to int16 range [−32768, 32767].
 */

#include <cstdint>

namespace wlvc {

struct QuantConfig {
    int   quality;     // 1–100
    int   levels;      // DWT depth (must match codec)
};

/**
 * Quantise all subbands of a 2-D coefficient array in-place.
 * Input:  float32 array (DWT output, Mallat layout)
 * Output: int16 array   (same shape)
 */
void quantize_2d(const float* __restrict__ coeffs,
                 int16_t*    __restrict__ out,
                 int w, int h,
                 const QuantConfig& cfg);

/**
 * Dequantise: int16 → float32 using the same step sizes.
 */
void dequantize_2d(const int16_t* __restrict__ quant,
                   float*         __restrict__ out,
                   int w, int h,
                   const QuantConfig& cfg);

// Per-subband step helpers (exposed for testing / tuning)
float base_step(int quality);
float detail_step(int lv, int levels, int quality);

} // namespace wlvc
