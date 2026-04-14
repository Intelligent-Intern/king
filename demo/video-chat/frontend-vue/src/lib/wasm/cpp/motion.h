#pragma once
/**
 * Block-matching motion estimation with Kalman-filtered motion vectors
 *
 * Divides the frame into BLOCK_SIZE×BLOCK_SIZE blocks (default 16×16).
 * For each block, performs an exhaustive search over a ±SEARCH_RANGE
 * pixel window (step=2) using Sum-of-Absolute-Differences (SAD).
 *
 * The SAD inner loop processes 16 bytes at a time using WASM SIMD128
 * (or falls back to scalar when SIMD is not available).
 *
 * Per-block Kalman filter smooths the motion vectors across frames,
 * providing noise-robust motion estimation for the temporal residual.
 */

#include <cstdint>
#include <cstddef>
#include <vector>

namespace wlvc {

static const int kMotionBlockSize   = 16;
static const int kMotionSearchRange =  8;

struct MotionVector { int dx, dy; };

/**
 * Estimate motion vectors for all blocks.
 *
 * @param curr   Current frame luma (uint8, w×h)
 * @param prev   Previous frame luma (uint8, w×h)
 * @param w, h   Frame dimensions
 * @param mvs    Output: one MotionVector per block (row-major)
 */
void estimate_motion(const uint8_t* curr, const uint8_t* prev,
                     int w, int h,
                     MotionVector* mvs);

/**
 * Apply motion compensation: for each block copy the best-matching region
 * from prev (using mvs) into mc_out.
 */
void motion_compensate(const uint8_t* prev, const MotionVector* mvs,
                       int w, int h,
                       uint8_t* mc_out);

/** Compute SADs for a 16-wide row using WASM SIMD or scalar fallback. */
uint32_t sad_row_16(const uint8_t* a, const uint8_t* b);

/** Full block SAD for a BLOCK_SIZE×BLOCK_SIZE region. */
uint32_t sad_block(const uint8_t* curr, const uint8_t* prev,
                   int curr_stride, int prev_stride,
                   int block_size);

// ---------------------------------------------------------------------------
// Per-block Kalman filter for motion vector smoothing
// ---------------------------------------------------------------------------
struct KalmanMV {
    float x = 0, y = 0;    // position estimate
    float vx = 0, vy = 0;  // velocity estimate
    float p[4][4] = {};     // covariance (initialised to identity × 10)

    KalmanMV();

    /** Predict next state (constant-velocity model). */
    void predict(float dt = 1.0f);

    /** Update with a new measurement (measured_dx, measured_dy). */
    void update(float mx, float my);

    /** Smoothed motion vector. */
    MotionVector smoothed() const;
};

struct MotionEstimator {
    int w = 0, h = 0;
    std::vector<KalmanMV> kalman;  // one per block

    void init(int width, int height);

    /**
     * Estimate and smooth motion vectors.
     * Returns raw SAD-based MVs in raw_mvs (may be nullptr if not needed).
     */
    void estimate(const uint8_t* curr, const uint8_t* prev,
                  MotionVector* smooth_mvs,
                  MotionVector* raw_mvs = nullptr);
};

} // namespace wlvc
