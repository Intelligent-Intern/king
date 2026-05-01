#pragma once
/**
 * WLVC — Wavelet-Lifting Video Codec
 *
 * Encoder / Decoder that the Emscripten JS bindings (exports.cpp) expose.
 * Internally uses:
 *   • 2-D separable Haar DWT  (dwt.h)   — cache-blocked column pass
 *   • Per-subband quantisation (quantize.h)
 *   • RLE entropy coding       (entropy.h)
 *   • Motion estimation        (motion.h) — used for temporal residuals
 *   • Audio processing         (audio.h)
 *
 * Wire format (FrameData.data / inner payload):
 *   Bytes  0–3   magic   0x574C5643 ("WLVC")
 *   Byte   4     version = 2
 *   Byte   5     frame_type: 0 = keyframe, 1 = delta
 *   Byte   6     quality (1–100)
 *   Byte   7     levels  (DWT depth, currently 4)
 *   Bytes  8–9   width  (uint16 LE)
 *   Bytes 10–11  height (uint16 LE)
 *   Bytes 12–15  Y encoded byte count (uint32 LE)
 *   Bytes 16–19  U encoded byte count (uint32 LE)
 *   Bytes 20–23  V encoded byte count (uint32 LE)
 *   Bytes 24–25  uvW (uint16 LE)
 *   Bytes 26–27  uvH (uint16 LE)
 *   Byte  28     wavelet_type (0=haar, 1=db4, 2=cdf97)
 *   Byte  29     color_space (0=yuv, 1=rgb)
 *   Byte  30     entropy_coding (0=rle, 1=arithmetic, 2=none)
 *   Byte  31     flags: bit0=motion_estimation, bit1=blur_background,
 *                bit2=chroma_temporal_residual
 *   Byte  32     blur_radius (0=off; reserved for higher-level pipeline use)
 *   [33+]        Y_data | U_data | V_data
 *
 * This format is byte-compatible with the TypeScript codec in codec.ts.
 */

#include "dwt.h"
#include "quantize.h"
#include "entropy.h"
#include "motion.h"
#include "audio.h"
#include <cstdint>
#include <vector>
#include <memory>

namespace wlvc {

static const uint32_t kMagic        = 0x574C5643u;
static const int      kHeaderBytes  = 33;
static const int      kDefaultLevels = 4;
static const uint8_t  kFrameFlagMotionEstimation = 0x01;
static const uint8_t  kFrameFlagChromaTemporalResidual = 0x04;

enum WaveletType {
    kHaar = 0,
    kDB4 = 1,
    kCDF97 = 2
};

enum ColorSpace {
    kYUV = 0,
    kRGB = 1
};

enum EntropyMode {
    kRLE = 0,
    kArithmetic = 1,
    kNone = 2
};

// ---------------------------------------------------------------------------
// Encoder
// ---------------------------------------------------------------------------

struct EncoderConfig {
    int width            = 640;
    int height           = 480;
    int quality          = 60;    // 1–100
    int key_frame_interval = 30;
    int levels           = kDefaultLevels;
    WaveletType wavelet_type = kHaar;
    ColorSpace color_space = kYUV;
    EntropyMode entropy_coding = kRLE;
    bool motion_estimation = true;
};

class Encoder {
public:
    explicit Encoder(const EncoderConfig& cfg);
    ~Encoder() = default;

    /**
     * Encode one RGBA frame.
     *
     * @param rgba        Input pixels, width×height×4 bytes.
     * @param timestamp_us Presentation timestamp in microseconds.
     * @param out_buf     Output buffer.  Must be at least max_encoded_bytes().
     * @returns           Number of bytes written, or <0 on error.
     */
    int encode(const uint8_t* rgba, double timestamp_us,
               uint8_t* out_buf, int out_capacity);

    /** Conservative upper bound on encoded frame size in bytes. */
    int max_encoded_bytes() const;

    void reset();

private:
    EncoderConfig cfg_;
    int frame_count_ = 0;

    // Planar channel buffers (allocated once)
    std::vector<float>   Y_, U_, V_;     // float DWT workspace
    std::vector<float>   Ydelta_, Udelta_, Vdelta_; // temporal residual buffers
    std::vector<float>   prevY_, prevU_, prevV_;    // previous channels for delta coding
    std::vector<int16_t> Yq_, Uq_, Vq_; // quantised output
    std::vector<float>   tmp_;           // 1-D DWT scratch (max(w,h) floats)
    std::vector<uint8_t> rle_buf_;       // per-channel RLE workspace

    void rgba_to_yuv(const uint8_t* rgba);
};

// ---------------------------------------------------------------------------
// Decoder
// ---------------------------------------------------------------------------

struct DecoderConfig {
    int width   = 640;
    int height  = 480;
    int quality = 60;
    int levels  = kDefaultLevels;
    WaveletType wavelet_type = kHaar;
    ColorSpace color_space = kYUV;
    EntropyMode entropy_coding = kRLE;
};

class Decoder {
public:
    explicit Decoder(const DecoderConfig& cfg);
    ~Decoder() = default;

    /**
     * Decode one encoded frame to RGBA.
     *
     * @param encoded     Encoded byte stream (inner payload, without outer wrapper).
     * @param enc_size    Byte count.
     * @param rgba_out    Output buffer, width×height×4 bytes.
     * @returns           0 on success, <0 on error.
     */
    int decode(const uint8_t* encoded, int enc_size, uint8_t* rgba_out);

    void reset();

private:
    DecoderConfig cfg_;

    std::vector<float>   Y_, U_, V_;
    std::vector<float>   prevY_, prevU_, prevV_;
    std::vector<int16_t> Yq_, Uq_, Vq_;
    std::vector<float>   tmp_;

    void yuv_to_rgba(uint8_t* rgba, int w, int h,
                     const float* Y, const float* U, const float* V,
                     int uvW, int uvH);
};

} // namespace wlvc
