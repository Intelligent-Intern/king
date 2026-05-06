#include "codec.h"
#include <cstring>
#include <algorithm>
#include <cmath>
#include <limits>
#include <stdexcept>

namespace wlvc {

namespace {

constexpr int kMaxWireDimension = std::numeric_limits<uint16_t>::max();
constexpr int kMaxPlaneValues =
    (std::numeric_limits<int>::max() - kHeaderBytes - (3 * RLE_HEADER_BYTES))
    / (3 * RLE_PAIR_BYTES);

struct PlaneLayout {
    int w;
    int h;
    int uvW;
    int uvH;
    int yCount;
    int uvCount;
    size_t yFloatBytes;
    size_t uvFloatBytes;
    size_t yInt16Bytes;
    size_t uvInt16Bytes;
};

int checked_dimension(int value, const char* label) {
    if (value <= 0 || value > kMaxWireDimension) {
        throw std::invalid_argument(label);
    }
    return value;
}

int checked_plane_value_count(int width, int height, const char* label) {
    const size_t product = static_cast<size_t>(width) * static_cast<size_t>(height);
    if (product == 0 || product > static_cast<size_t>(kMaxPlaneValues)) {
        throw std::overflow_error(label);
    }
    return static_cast<int>(product);
}

size_t checked_value_bytes(int value_count, size_t element_bytes, const char* label) {
    const size_t count = static_cast<size_t>(value_count);
    if (element_bytes != 0 && count > std::numeric_limits<size_t>::max() / element_bytes) {
        throw std::overflow_error(label);
    }
    return count * element_bytes;
}

int checked_encoded_size(size_t y_bytes, size_t u_bytes, size_t v_bytes) {
    if (
        y_bytes > std::numeric_limits<uint32_t>::max()
        || u_bytes > std::numeric_limits<uint32_t>::max()
        || v_bytes > std::numeric_limits<uint32_t>::max()
    ) {
        throw std::overflow_error("encoded channel byte count");
    }
    const size_t payload_bytes = y_bytes + u_bytes + v_bytes;
    if (payload_bytes > static_cast<size_t>(std::numeric_limits<int>::max() - kHeaderBytes)) {
        throw std::overflow_error("encoded frame byte count");
    }
    return kHeaderBytes + static_cast<int>(payload_bytes);
}

PlaneLayout make_plane_layout(int width, int height, ColorSpace color_space) {
    if (color_space != kYUV && color_space != kRGB) {
        throw std::invalid_argument("color space");
    }

    const int w = checked_dimension(width, "frame width");
    const int h = checked_dimension(height, "frame height");
    const int uvW = color_space == kYUV ? (w >> 1) : w;
    const int uvH = color_space == kYUV ? (h >> 1) : h;
    if (uvW <= 0 || uvH <= 0) {
        throw std::invalid_argument("chroma plane dimensions");
    }

    const int yCount = checked_plane_value_count(w, h, "luma plane value count");
    const int uvCount = checked_plane_value_count(uvW, uvH, "chroma plane value count");
    return {
        w,
        h,
        uvW,
        uvH,
        yCount,
        uvCount,
        checked_value_bytes(yCount, sizeof(float), "luma float byte count"),
        checked_value_bytes(uvCount, sizeof(float), "chroma float byte count"),
        checked_value_bytes(yCount, sizeof(int16_t), "luma int16 byte count"),
        checked_value_bytes(uvCount, sizeof(int16_t), "chroma int16 byte count"),
    };
}

} // namespace

// ---------------------------------------------------------------------------
// Encoder
// ---------------------------------------------------------------------------

Encoder::Encoder(const EncoderConfig& cfg) : cfg_(cfg) {
    const PlaneLayout layout = make_plane_layout(cfg_.width, cfg_.height, cfg_.color_space);

    Y_.resize(static_cast<size_t>(layout.yCount));
    U_.resize(static_cast<size_t>(layout.uvCount));
    V_.resize(static_cast<size_t>(layout.uvCount));
    Ydelta_.resize(static_cast<size_t>(layout.yCount));
    Udelta_.resize(static_cast<size_t>(layout.uvCount));
    Vdelta_.resize(static_cast<size_t>(layout.uvCount));
    prevY_.resize(static_cast<size_t>(layout.yCount));
    prevU_.resize(static_cast<size_t>(layout.uvCount));
    prevV_.resize(static_cast<size_t>(layout.uvCount));
    Yq_.resize(static_cast<size_t>(layout.yCount));
    Uq_.resize(static_cast<size_t>(layout.uvCount));
    Vq_.resize(static_cast<size_t>(layout.uvCount));
    tmp_.resize(static_cast<size_t>(std::max(layout.w, layout.h)));
    rle_buf_.resize(rle_max_bytes(std::max(layout.yCount, layout.uvCount)));
}

void Encoder::rgba_to_yuv(const uint8_t* rgba) {
    const PlaneLayout layout = make_plane_layout(cfg_.width, cfg_.height, cfg_.color_space);
    const int w = layout.w;
    const int h = layout.h;
    const int uvW = layout.uvW;
    const int uvH = layout.uvH;
    const size_t width = static_cast<size_t>(w);
    const size_t uvWidth = static_cast<size_t>(uvW);

    if (cfg_.color_space == kYUV) {
        for (int row = 0; row < h; ++row) {
            const size_t rowOffset = static_cast<size_t>(row) * width;
            for (int col = 0; col < w; ++col) {
                const size_t idx = rowOffset + static_cast<size_t>(col);
                const size_t i = idx * 4u;
                const float r = rgba[i], g = rgba[i + 1], b = rgba[i + 2];
                Y_[idx] = 0.299f * r + 0.587f * g + 0.114f * b - 128.0f;
            }
        }
        for (int row = 0; row < uvH; ++row) {
            const size_t uvRowOffset = static_cast<size_t>(row) * uvWidth;
            const size_t sourceRow = static_cast<size_t>(row) * 2u;
            for (int col = 0; col < uvW; ++col) {
                const size_t idx = uvRowOffset + static_cast<size_t>(col);
                const size_t sourceCol = static_cast<size_t>(col) * 2u;
                const size_t i = ((sourceRow * width) + sourceCol) * 4u;
                const float r = rgba[i], g = rgba[i + 1], b = rgba[i + 2];
                U_[idx] = -0.147f * r - 0.289f * g + 0.436f * b;
                V_[idx] =  0.615f * r - 0.515f * g - 0.100f * b;
            }
        }
    } else {
        for (int row = 0; row < h; ++row) {
            const size_t rowOffset = static_cast<size_t>(row) * width;
            for (int col = 0; col < w; ++col) {
                const size_t idx = rowOffset + static_cast<size_t>(col);
                const size_t i = idx * 4u;
                Y_[idx] = rgba[i];
                U_[idx] = rgba[i + 1];
                V_[idx] = rgba[i + 2];
            }
        }
    }
}

int Encoder::encode(const uint8_t* rgba, double timestamp_us,
                    uint8_t* out_buf, int out_capacity) {
    const PlaneLayout layout = make_plane_layout(cfg_.width, cfg_.height, cfg_.color_space);
    const int w = layout.w;
    const int h = layout.h;
    const int uvW = layout.uvW;
    const int uvH = layout.uvH;
    const bool is_key = (frame_count_ % cfg_.key_frame_interval) == 0;
    ++frame_count_;

    rgba_to_yuv(rgba);

    // Temporal residuals for delta frames. Chroma residuals are guarded by a
    // distinct header flag so older motion-estimation metadata stays readable.
    float* y_enc = Y_.data();
    float* u_enc = U_.data();
    float* v_enc = V_.data();
    const bool use_temporal_residual = cfg_.motion_estimation && !is_key && frame_count_ > 1;
    if (use_temporal_residual) {
        for (int i = 0; i < layout.yCount; ++i)
            Ydelta_[i] = Y_[i] - prevY_[i];
        for (int i = 0; i < layout.uvCount; ++i) {
            Udelta_[i] = U_[i] - prevU_[i];
            Vdelta_[i] = V_[i] - prevV_[i];
        }
        y_enc = Ydelta_.data();
        u_enc = Udelta_.data();
        v_enc = Vdelta_.data();
    }
    std::memcpy(prevY_.data(), Y_.data(), layout.yFloatBytes);
    std::memcpy(prevU_.data(), U_.data(), layout.uvFloatBytes);
    std::memcpy(prevV_.data(), V_.data(), layout.uvFloatBytes);

    // Forward DWT
    dwt_forward(y_enc, w, h, cfg_.levels, tmp_.data());
    dwt_forward(u_enc, uvW, uvH, cfg_.levels, tmp_.data());
    dwt_forward(v_enc, uvW, uvH, cfg_.levels, tmp_.data());

    // Quantise
    QuantConfig qcfg = { cfg_.quality, cfg_.levels };
    quantize_2d(y_enc, Yq_.data(), w, h, qcfg);
    quantize_2d(u_enc, Uq_.data(), uvW, uvH, qcfg);
    quantize_2d(v_enc, Vq_.data(), uvW, uvH, qcfg);

    size_t y_rle_sz = 0;
    size_t u_rle_sz = 0;
    size_t v_rle_sz = 0;
    if (cfg_.entropy_coding == kNone) {
        y_rle_sz = layout.yInt16Bytes;
        u_rle_sz = layout.uvInt16Bytes;
        v_rle_sz = layout.uvInt16Bytes;
    } else {
        y_rle_sz = rle_encode(Yq_.data(), layout.yCount, rle_buf_.data());
        u_rle_sz = rle_encode(Uq_.data(), layout.uvCount, rle_buf_.data());
        v_rle_sz = rle_encode(Vq_.data(), layout.uvCount, rle_buf_.data());
    }

    // Pack header + payload
    const int total = checked_encoded_size(y_rle_sz, u_rle_sz, v_rle_sz);
    if (total > out_capacity) return -1;

    uint8_t* p = out_buf;
    auto w32 = [&](uint32_t v) {
        p[0] = static_cast<uint8_t>(v >> 24);
        p[1] = static_cast<uint8_t>(v >> 16);
        p[2] = static_cast<uint8_t>(v >> 8);
        p[3] = static_cast<uint8_t>(v);
        p += 4;
    };
    auto w16 = [&](uint16_t v) {
        p[0] = static_cast<uint8_t>(v >> 8);
        p[1] = static_cast<uint8_t>(v);
        p += 2;
    };
    auto w8 = [&](uint8_t v) { *p++ = v; };

    w32(kMagic);
    w8(2);                                    // version
    w8(is_key ? 0 : 1);                      // frame type
    w8(static_cast<uint8_t>(cfg_.quality));
    w8(static_cast<uint8_t>(cfg_.levels));
    w16(static_cast<uint16_t>(w));
    w16(static_cast<uint16_t>(h));
    w32(static_cast<uint32_t>(y_rle_sz));
    w32(static_cast<uint32_t>(u_rle_sz));
    w32(static_cast<uint32_t>(v_rle_sz));
    w16(static_cast<uint16_t>(uvW));
    w16(static_cast<uint16_t>(uvH));
    w8(static_cast<uint8_t>(cfg_.wavelet_type));
    w8(static_cast<uint8_t>(cfg_.color_space));
    w8(static_cast<uint8_t>(cfg_.entropy_coding));
    uint8_t flags = 0;
    if (cfg_.motion_estimation) flags |= kFrameFlagMotionEstimation;
    if (use_temporal_residual) flags |= kFrameFlagChromaTemporalResidual;
    w8(flags);
    w8(0);                                   // blur radius reserved

    if (cfg_.entropy_coding == kNone) {
        std::memcpy(p, Yq_.data(), y_rle_sz);
        p += y_rle_sz;
        std::memcpy(p, Uq_.data(), u_rle_sz);
        p += u_rle_sz;
        std::memcpy(p, Vq_.data(), v_rle_sz);
        p += v_rle_sz;
    } else {
        const size_t y_bytes = rle_encode(Yq_.data(), layout.yCount, p);
        p += y_bytes;
        const size_t u_bytes = rle_encode(Uq_.data(), layout.uvCount, p);
        p += u_bytes;
        const size_t v_bytes = rle_encode(Vq_.data(), layout.uvCount, p);
        p += v_bytes;
    }

    return static_cast<int>(p - out_buf);
}

int Encoder::max_encoded_bytes() const {
    const PlaneLayout layout = make_plane_layout(cfg_.width, cfg_.height, cfg_.color_space);
    if (cfg_.entropy_coding == kNone) {
        return checked_encoded_size(layout.yInt16Bytes, layout.uvInt16Bytes, layout.uvInt16Bytes);
    }
    return checked_encoded_size(
        rle_max_bytes(layout.yCount),
        rle_max_bytes(layout.uvCount),
        rle_max_bytes(layout.uvCount)
    );
}

void Encoder::reset() {
    frame_count_ = 0;
    std::fill(prevY_.begin(), prevY_.end(), 0.0f);
    std::fill(prevU_.begin(), prevU_.end(), 0.0f);
    std::fill(prevV_.begin(), prevV_.end(), 0.0f);
}

// ---------------------------------------------------------------------------
// Decoder
// ---------------------------------------------------------------------------

Decoder::Decoder(const DecoderConfig& cfg) : cfg_(cfg) {
    const PlaneLayout layout = make_plane_layout(cfg_.width, cfg_.height, cfg_.color_space);

    Y_.resize(static_cast<size_t>(layout.yCount));
    U_.resize(static_cast<size_t>(layout.uvCount));
    V_.resize(static_cast<size_t>(layout.uvCount));
    prevY_.resize(static_cast<size_t>(layout.yCount));
    prevU_.resize(static_cast<size_t>(layout.uvCount));
    prevV_.resize(static_cast<size_t>(layout.uvCount));
    Yq_.resize(static_cast<size_t>(layout.yCount));
    Uq_.resize(static_cast<size_t>(layout.uvCount));
    Vq_.resize(static_cast<size_t>(layout.uvCount));
    tmp_.resize(static_cast<size_t>(std::max(layout.w, layout.h)));
}

int Decoder::decode(const uint8_t* enc, int enc_size, uint8_t* rgba_out) {
    if (enc_size < kHeaderBytes) return -1;

    const uint8_t* p = enc;
    auto r32 = [&]() -> uint32_t {
        uint32_t v = (static_cast<uint32_t>(p[0]) << 24)
                   | (static_cast<uint32_t>(p[1]) << 16)
                   | (static_cast<uint32_t>(p[2]) << 8)
                   |  static_cast<uint32_t>(p[3]);
        p += 4;
        return v;
    };
    auto r16 = [&]() -> uint16_t {
        uint16_t v = (static_cast<uint16_t>(p[0]) << 8)
                   |  static_cast<uint16_t>(p[1]);
        p += 2;
        return v;
    };
    auto r8 = [&]() -> uint8_t { return *p++; };

    if (r32() != kMagic) return -1;
    const int version = r8();
    const bool is_key = (r8() == 0);
    const int quality = r8();
    const int levels  = r8();
    const int w       = r16();
    const int h       = r16();
    const uint32_t y_bytes = r32();
    const uint32_t u_bytes = r32();
    const uint32_t v_bytes = r32();
    const int uvW = r16();
    const int uvH = r16();
    int colorSpace = kYUV;
    int entropyMode = kRLE;
    uint8_t flags = 0;
    if (version >= 2) {
        r8(); // wavelet type
        colorSpace = r8();
        entropyMode = r8();
        flags = r8();
        r8(); // blur radius
    }

    if (colorSpace != kYUV && colorSpace != kRGB) return -1;

    PlaneLayout layout{};
    try {
        layout = make_plane_layout(w, h, static_cast<ColorSpace>(colorSpace));
    } catch (...) {
        return -1;
    }

    if (
        layout.w != cfg_.width
        || layout.h != cfg_.height
        || layout.uvW != uvW
        || layout.uvH != uvH
        || static_cast<ColorSpace>(colorSpace) != cfg_.color_space
    ) {
        return -1;
    }

    const uint64_t payload_end = static_cast<uint64_t>(kHeaderBytes)
        + static_cast<uint64_t>(y_bytes)
        + static_cast<uint64_t>(u_bytes)
        + static_cast<uint64_t>(v_bytes);
    if (payload_end > static_cast<uint64_t>(enc_size))
        return -1;

    int yn = 0;
    int un = 0;
    int vn = 0;
    if (entropyMode == kNone) {
        const size_t y_expected = layout.yInt16Bytes;
        const size_t u_expected = layout.uvInt16Bytes;
        const size_t v_expected = layout.uvInt16Bytes;
        if (y_bytes != y_expected || u_bytes != u_expected || v_bytes != v_expected) return -1;
        std::memcpy(Yq_.data(), p, y_bytes);
        p += y_bytes;
        std::memcpy(Uq_.data(), p, u_bytes);
        p += u_bytes;
        std::memcpy(Vq_.data(), p, v_bytes);
        p += v_bytes;
        yn = layout.yCount;
        un = layout.uvCount;
        vn = layout.uvCount;
    } else {
        yn = rle_decode(p, y_bytes, Yq_.data(), layout.yCount);
        p += y_bytes;
        un = rle_decode(p, u_bytes, Uq_.data(), layout.uvCount);
        p += u_bytes;
        vn = rle_decode(p, v_bytes, Vq_.data(), layout.uvCount);
        p += v_bytes;
    }

    if (yn != layout.yCount || un != layout.uvCount || vn != layout.uvCount) return -1;

    // Dequantise
    QuantConfig qcfg = { quality, levels };
    dequantize_2d(Yq_.data(), Y_.data(), w, h, qcfg);
    dequantize_2d(Uq_.data(), U_.data(), uvW, uvH, qcfg);
    dequantize_2d(Vq_.data(), V_.data(), uvW, uvH, qcfg);

    // Inverse DWT
    dwt_inverse(Y_.data(), w, h, levels, tmp_.data());
    dwt_inverse(U_.data(), uvW, uvH, levels, tmp_.data());
    dwt_inverse(V_.data(), uvW, uvH, levels, tmp_.data());

    // Temporal residual reconstruction
    if (!is_key) {
        for (int i = 0; i < layout.yCount; ++i)
            Y_[i] += prevY_[i];
    }
    if (!is_key && (flags & kFrameFlagChromaTemporalResidual)) {
        for (int i = 0; i < layout.uvCount; ++i) {
            U_[i] += prevU_[i];
            V_[i] += prevV_[i];
        }
    }
    std::memcpy(prevY_.data(), Y_.data(), layout.yFloatBytes);
    std::memcpy(prevU_.data(), U_.data(), layout.uvFloatBytes);
    std::memcpy(prevV_.data(), V_.data(), layout.uvFloatBytes);

    if (colorSpace == kRGB) {
        for (int i = 0; i < layout.yCount; ++i) {
            const size_t pi = static_cast<size_t>(i) * 4u;
            rgba_out[pi] = static_cast<uint8_t>(std::fmaxf(0.0f, std::fminf(255.0f, Y_[i])));
            rgba_out[pi + 1] = static_cast<uint8_t>(std::fmaxf(0.0f, std::fminf(255.0f, U_[i])));
            rgba_out[pi + 2] = static_cast<uint8_t>(std::fmaxf(0.0f, std::fminf(255.0f, V_[i])));
            rgba_out[pi + 3] = 255;
        }
    } else {
        yuv_to_rgba(rgba_out, w, h, Y_.data(), U_.data(), V_.data(), uvW, uvH);
    }
    return 0;
}

void Decoder::yuv_to_rgba(uint8_t* rgba, int w, int h,
                          const float* Y, const float* U, const float* V,
                          int uvW, int uvH) {
    (void)uvH;
    const size_t width = static_cast<size_t>(w);
    const size_t uvWidth = static_cast<size_t>(uvW);
    for (int row = 0; row < h; ++row) {
        const size_t rowOffset = static_cast<size_t>(row) * width;
        const size_t uvRowOffset = static_cast<size_t>(row >> 1) * uvWidth;
        for (int col = 0; col < w; ++col) {
            const size_t yi  = rowOffset + static_cast<size_t>(col);
            const size_t uvi = uvRowOffset + static_cast<size_t>(col >> 1);

            const float y = Y[yi] + 128.0f;
            const float u = U[uvi];
            const float v = V[uvi];

            const float r = y + 1.13983f * v;
            const float g = y - 0.39465f * u - 0.58060f * v;
            const float b = y + 2.03211f * u;

            const size_t pi = yi * 4u;
            rgba[pi]     = static_cast<uint8_t>(std::fmaxf(0.0f, std::fminf(255.0f, r)));
            rgba[pi + 1] = static_cast<uint8_t>(std::fmaxf(0.0f, std::fminf(255.0f, g)));
            rgba[pi + 2] = static_cast<uint8_t>(std::fmaxf(0.0f, std::fminf(255.0f, b)));
            rgba[pi + 3] = 255;
        }
    }
}

void Decoder::reset() {
    std::fill(prevY_.begin(), prevY_.end(), 0.0f);
    std::fill(prevU_.begin(), prevU_.end(), 0.0f);
    std::fill(prevV_.begin(), prevV_.end(), 0.0f);
}

} // namespace wlvc
