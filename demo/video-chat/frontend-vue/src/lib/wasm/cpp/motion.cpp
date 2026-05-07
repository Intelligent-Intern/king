#include "motion.h"
#include <algorithm>
#include <cstring>
#include <cmath>
#include <limits>

// WASM SIMD128 optional
#if defined(__wasm_simd128__)
#  include <wasm_simd128.h>
#  define HAVE_SIMD 1
#endif

namespace wlvc {

// ---------------------------------------------------------------------------
// SAD primitives
// ---------------------------------------------------------------------------

uint32_t sad_row_16(const uint8_t* a, const uint8_t* b) {
#if HAVE_SIMD
    v128_t va   = wasm_v128_load(a);
    v128_t vb   = wasm_v128_load(b);
    // |a-b| = saturating_sub(a,b) | saturating_sub(b,a)
    v128_t diff = wasm_v128_or(wasm_u8x16_sub_sat(va, vb),
                               wasm_u8x16_sub_sat(vb, va));
    // Widen u8×16 → u16×8 in two halves, then → u32×4
    v128_t lo16  = wasm_u16x8_extend_low_u8x16(diff);
    v128_t hi16  = wasm_u16x8_extend_high_u8x16(diff);
    v128_t lo32a = wasm_u32x4_extend_low_u16x8(lo16);
    v128_t lo32b = wasm_u32x4_extend_high_u16x8(lo16);
    v128_t hi32a = wasm_u32x4_extend_low_u16x8(hi16);
    v128_t hi32b = wasm_u32x4_extend_high_u16x8(hi16);
    v128_t sum32 = wasm_i32x4_add(wasm_i32x4_add(lo32a, lo32b),
                                   wasm_i32x4_add(hi32a, hi32b));
    return (uint32_t)(wasm_i32x4_extract_lane(sum32, 0)
                    + wasm_i32x4_extract_lane(sum32, 1)
                    + wasm_i32x4_extract_lane(sum32, 2)
                    + wasm_i32x4_extract_lane(sum32, 3));
#else
    uint32_t s = 0;
    for (int i = 0; i < 16; ++i)
        s += static_cast<uint32_t>(std::abs(static_cast<int>(a[i]) - static_cast<int>(b[i])));
    return s;
#endif
}

uint32_t sad_block(const uint8_t* curr, const uint8_t* prev,
                   int curr_stride, int prev_stride,
                   int block_size) {
    uint32_t total = 0;
    for (int row = 0; row < block_size; ++row) {
        const uint8_t* cr = curr + row * curr_stride;
        const uint8_t* pr = prev + row * prev_stride;
        // Process 16 bytes at a time if block is wide enough
        int col = 0;
        for (; col + 16 <= block_size; col += 16)
            total += sad_row_16(cr + col, pr + col);
        // Scalar remainder
        for (; col < block_size; ++col)
            total += static_cast<uint32_t>(
                std::abs(static_cast<int>(cr[col]) - static_cast<int>(pr[col])));
    }
    return total;
}

// ---------------------------------------------------------------------------
// Full-frame motion estimation (exhaustive search)
// ---------------------------------------------------------------------------

void estimate_motion(const uint8_t* curr, const uint8_t* prev,
                     int w, int h,
                     MotionVector* mvs) {
    const int bs     = kMotionBlockSize;
    const int range  = kMotionSearchRange;
    const int step   = 2;                    // sub-pixel not needed here
    const int bw     = (w + bs - 1) / bs;   // blocks per row

    for (int by = 0; by < h / bs; ++by) {
        for (int bx = 0; bx < w / bs; ++bx) {
            const int cx = bx * bs;
            const int cy = by * bs;

            uint32_t best_sad = std::numeric_limits<uint32_t>::max();
            int best_dx = 0, best_dy = 0;

            for (int dy = -range; dy <= range; dy += step) {
                for (int dx = -range; dx <= range; dx += step) {
                    const int rx = cx + dx;
                    const int ry = cy + dy;
                    if (rx < 0 || ry < 0 || rx + bs > w || ry + bs > h) continue;

                    uint32_t s = sad_block(curr + cy * w + cx,
                                           prev + ry * w + rx,
                                           w, w, bs);
                    if (s < best_sad) {
                        best_sad = s;
                        best_dx  = dx;
                        best_dy  = dy;
                    }
                }
            }

            mvs[by * bw + bx] = { best_dx, best_dy };
        }
    }
}

// ---------------------------------------------------------------------------
// Motion compensation
// ---------------------------------------------------------------------------

void motion_compensate(const uint8_t* prev, const MotionVector* mvs,
                       int w, int h, uint8_t* mc_out) {
    if (w <= 0 || h <= 0) return;

    const int bs = kMotionBlockSize;
    const int bw = (w + bs - 1) / bs;
    const size_t frame_bytes = static_cast<size_t>(w) * static_cast<size_t>(h);

    std::memcpy(mc_out, prev, frame_bytes);  // default: copy prev

    for (int by = 0; by < h / bs; ++by) {
        for (int bx = 0; bx < w / bs; ++bx) {
            const MotionVector& mv = mvs[by * bw + bx];
            const int cx = bx * bs, cy = by * bs;
            const int rx = cx + mv.dx, ry = cy + mv.dy;
            if (rx < 0 || ry < 0 || rx + bs > w || ry + bs > h) continue;

            for (int row = 0; row < bs; ++row)
                std::memcpy(mc_out + (cy + row) * w + cx,
                            prev   + (ry + row) * w + rx,
                            static_cast<size_t>(bs));
        }
    }
}

// ---------------------------------------------------------------------------
// Kalman filter for motion-vector smoothing
// ---------------------------------------------------------------------------

KalmanMV::KalmanMV() {
    // Identity covariance × 10 (high initial uncertainty)
    for (int i = 0; i < 4; ++i)
        for (int j = 0; j < 4; ++j)
            p[i][j] = (i == j) ? 10.0f : 0.0f;
}

void KalmanMV::predict(float dt) {
    // State: [x, y, vx, vy]  Transition: x += vx*dt, y += vy*dt
    x  += vx * dt;
    y  += vy * dt;
    // P = F·P·Fᵀ + Q  (simplified: add process noise to diagonal)
    static const float q = 0.01f;
    for (int i = 0; i < 4; ++i) p[i][i] += q;
}

void KalmanMV::update(float mx, float my) {
    // Observation: position only → H = [1 0 0 0; 0 1 0 0]
    const float r    = 2.0f;   // measurement noise
    const float s0   = p[0][0] + r;
    const float s1   = p[1][1] + r;
    const float k_x  = p[0][0] / s0;
    const float k_y  = p[1][1] / s1;
    const float k_vx = p[2][0] / s0;
    const float k_vy = p[3][1] / s1;

    const float ex = mx - x;
    const float ey = my - y;
    x  += k_x  * ex;
    y  += k_y  * ey;
    vx += k_vx * ex;
    vy += k_vy * ey;

    // P = (I - K·H)·P
    p[0][0] *= (1.0f - k_x);
    p[1][1] *= (1.0f - k_y);
    p[2][0] -= k_vx * p[0][0];
    p[3][1] -= k_vy * p[1][1];
}

MotionVector KalmanMV::smoothed() const {
    return { static_cast<int>(std::roundf(x)),
             static_cast<int>(std::roundf(y)) };
}

// ---------------------------------------------------------------------------
// MotionEstimator
// ---------------------------------------------------------------------------

void MotionEstimator::init(int width, int height) {
    w = width; h = height;
    const int n_blocks = ((w + kMotionBlockSize - 1) / kMotionBlockSize)
                       * ((h + kMotionBlockSize - 1) / kMotionBlockSize);
    kalman.assign(n_blocks, KalmanMV{});
}

void MotionEstimator::estimate(const uint8_t* curr, const uint8_t* prev,
                               MotionVector* smooth_mvs,
                               MotionVector* raw_mvs) {
    const int n_blocks_w = (w + kMotionBlockSize - 1) / kMotionBlockSize;
    const int n_blocks_h = (h + kMotionBlockSize - 1) / kMotionBlockSize;
    const int n_blocks   = n_blocks_w * n_blocks_h;

    std::vector<MotionVector> raw(n_blocks);
    estimate_motion(curr, prev, w, h, raw.data());

    for (int i = 0; i < n_blocks; ++i) {
        kalman[i].predict();
        kalman[i].update(static_cast<float>(raw[i].dx),
                         static_cast<float>(raw[i].dy));
        smooth_mvs[i] = kalman[i].smoothed();
        if (raw_mvs) raw_mvs[i] = raw[i];
    }
}

} // namespace wlvc
