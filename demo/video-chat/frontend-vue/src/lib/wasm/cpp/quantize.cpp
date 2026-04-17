#include "quantize.h"
#include <cmath>
#include <algorithm>

namespace wlvc {

// ---------------------------------------------------------------------------
// Step-size helpers
// ---------------------------------------------------------------------------

float base_step(int quality) {
    float qf = (quality < 50)
        ? 5000.0f / static_cast<float>(quality)
        : 200.0f  - 2.0f * static_cast<float>(quality);
    return qf / 100.0f;
}

float detail_step(int lv, int levels, int quality) {
    // lv=0 → finest details → largest step (most zeros)
    // lv=levels-1 → coarsest details → smallest step for details
    return base_step(quality) * static_cast<float>(1 << (levels - lv));
}

// ---------------------------------------------------------------------------
// Internal: quantise a rectangular region of the packed 2-D array
// ---------------------------------------------------------------------------
static inline int16_t quantize_coeff(float v, float step) {
    const float dz = step * 0.5f;
    if (v > -dz && v < dz) return 0;
    // clamp to int16
    float q = std::roundf(v / step);
    q = std::max(-32768.0f, std::min(32767.0f, q));
    return static_cast<int16_t>(q);
}

static inline float dequantize_coeff(int16_t q, float step) {
    return static_cast<float>(q) * step;
}

// ---------------------------------------------------------------------------
// Public: per-subband quantise / dequantise
// ---------------------------------------------------------------------------

void quantize_2d(const float* __restrict__ coeffs,
                 int16_t*    __restrict__ out,
                 int w, int h,
                 const QuantConfig& cfg) {
    const int levels  = cfg.levels;
    const int quality = cfg.quality;

    // ── LL subband ─────────────────────────────────────────────────────────
    const int llW  = w >> levels;
    const int llH  = h >> levels;
    const float llS = base_step(quality);
    for (int r = 0; r < llH; ++r) {
        const float*   ci = coeffs + r * w;
        int16_t*       oi = out    + r * w;
        for (int c = 0; c < llW; ++c) oi[c] = quantize_coeff(ci[c], llS);
    }

    // ── Detail subbands — level by level ───────────────────────────────────
    for (int lv = 0; lv < levels; ++lv) {
        const float step = detail_step(lv, levels, quality);
        const int cw     = w >> lv;
        const int ch     = h >> lv;
        const int hw     = cw >> 1;   // half width  = col where detail starts
        const int hh     = ch >> 1;   // half height = row where detail starts

        // LH: rows [0, hh), cols [hw, cw)
        for (int r = 0; r < hh; ++r)
            for (int c = hw; c < cw; ++c)
                out[r * w + c] = quantize_coeff(coeffs[r * w + c], step);

        // HL: rows [hh, ch), cols [0, hw)
        for (int r = hh; r < ch; ++r)
            for (int c = 0; c < hw; ++c)
                out[r * w + c] = quantize_coeff(coeffs[r * w + c], step);

        // HH: rows [hh, ch), cols [hw, cw)
        for (int r = hh; r < ch; ++r)
            for (int c = hw; c < cw; ++c)
                out[r * w + c] = quantize_coeff(coeffs[r * w + c], step);
    }
}

void dequantize_2d(const int16_t* __restrict__ quant,
                   float*         __restrict__ out,
                   int w, int h,
                   const QuantConfig& cfg) {
    const int levels  = cfg.levels;
    const int quality = cfg.quality;

    const int llW   = w >> levels;
    const int llH   = h >> levels;
    const float llS = base_step(quality);
    for (int r = 0; r < llH; ++r)
        for (int c = 0; c < llW; ++c)
            out[r * w + c] = dequantize_coeff(quant[r * w + c], llS);

    for (int lv = 0; lv < levels; ++lv) {
        const float step = detail_step(lv, levels, quality);
        const int cw = w >> lv, ch = h >> lv;
        const int hw = cw >> 1,  hh = ch >> 1;

        for (int r = 0; r < hh; ++r)
            for (int c = hw; c < cw; ++c)
                out[r * w + c] = dequantize_coeff(quant[r * w + c], step);

        for (int r = hh; r < ch; ++r)
            for (int c = 0; c < hw; ++c)
                out[r * w + c] = dequantize_coeff(quant[r * w + c], step);

        for (int r = hh; r < ch; ++r)
            for (int c = hw; c < cw; ++c)
                out[r * w + c] = dequantize_coeff(quant[r * w + c], step);
    }
}

} // namespace wlvc
