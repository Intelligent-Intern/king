#include "codec.h"
#include <cstring>
#include <algorithm>
#include <cmath>
#include <limits>

namespace wlvc {

namespace {

size_t plane_count(int width, int height) {
    return static_cast<size_t>(width) * static_cast<size_t>(height);
}

size_t rgba_offset(size_t pixel_index) {
    return pixel_index * static_cast<size_t>(4);
}

size_t rgba_offset(int row, int col, int width) {
    return rgba_offset(static_cast<size_t>(row) * static_cast<size_t>(width)
                     + static_cast<size_t>(col));
}

bool fits_int(size_t value) {
    return value <= static_cast<size_t>(std::numeric_limits<int>::max());
}

int count_to_int(size_t value) {
    return fits_int(value) ? static_cast<int>(value) : -1;
}

size_t int16_plane_bytes(size_t value_count) {
    return value_count * sizeof(int16_t);
}

size_t float_plane_bytes(size_t value_count) {
    return value_count * sizeof(float);
}

size_t rle_max_bytes_for_count(size_t value_count) {
    return static_cast<size_t>(RLE_HEADER_BYTES)
         + value_count * static_cast<size_t>(RLE_PAIR_BYTES);
}

} // namespace

// ---------------------------------------------------------------------------
// Encoder
// ---------------------------------------------------------------------------

Encoder::Encoder(const EncoderConfig& cfg) : cfg_(cfg) {
    const int w = cfg_.width, h = cfg_.height;
    const int uvW = cfg_.color_space == kYUV ? (w >> 1) : w;
    const int uvH = cfg_.color_space == kYUV ? (h >> 1) : h;
    const size_t y_count = plane_count(w, h);
    const size_t uv_count = plane_count(uvW, uvH);

    Y_.resize(y_count);
    U_.resize(uv_count);
    V_.resize(uv_count);
    Ydelta_.resize(y_count);
    Udelta_.resize(uv_count);
    Vdelta_.resize(uv_count);
    prevY_.resize(y_count);
    prevU_.resize(uv_count);
    prevV_.resize(uv_count);
    Yq_.resize(y_count);
    Uq_.resize(uv_count);
    Vq_.resize(uv_count);
    tmp_.resize(std::max(w, h));
    rle_buf_.resize(rle_max_bytes_for_count(std::max(y_count, uv_count)));
}

void Encoder::rgba_to_yuv(const uint8_t* rgba) {
    const int w = cfg_.width, h = cfg_.height;
    const int uvW = cfg_.color_space == kYUV ? (w >> 1) : w;
    const int uvH = cfg_.color_space == kYUV ? (h >> 1) : h;

    if (cfg_.color_space == kYUV) {
        for (int row = 0; row < h; ++row) {
            for (int col = 0; col < w; ++col) {
                const size_t i = rgba_offset(row, col, w);
                const float r = rgba[i], g = rgba[i + 1], b = rgba[i + 2];
                Y_[static_cast<size_t>(row) * static_cast<size_t>(w) + static_cast<size_t>(col)] =
                    0.299f * r + 0.587f * g + 0.114f * b - 128.0f;
            }
        }
        for (int row = 0; row < uvH; ++row) {
            for (int col = 0; col < uvW; ++col) {
                const size_t i = rgba_offset(static_cast<size_t>(row) * static_cast<size_t>(2) * static_cast<size_t>(w)
                                           + static_cast<size_t>(col) * static_cast<size_t>(2));
                const float r = rgba[i], g = rgba[i + 1], b = rgba[i + 2];
                const size_t uv_idx = static_cast<size_t>(row) * static_cast<size_t>(uvW)
                                    + static_cast<size_t>(col);
                U_[uv_idx] = -0.147f * r - 0.289f * g + 0.436f * b;
                V_[uv_idx] =  0.615f * r - 0.515f * g - 0.100f * b;
            }
        }
    } else {
        for (int row = 0; row < h; ++row) {
            for (int col = 0; col < w; ++col) {
                const size_t i = rgba_offset(row, col, w);
                const size_t idx = static_cast<size_t>(row) * static_cast<size_t>(w)
                                 + static_cast<size_t>(col);
                Y_[idx] = rgba[i];
                U_[idx] = rgba[i + 1];
                V_[idx] = rgba[i + 2];
            }
        }
    }
}

int Encoder::encode(const uint8_t* rgba, double timestamp_us,
                    uint8_t* out_buf, int out_capacity) {
    if (out_capacity < 0) return -1;

    const int w = cfg_.width, h = cfg_.height;
    const int uvW = cfg_.color_space == kYUV ? (w >> 1) : w;
    const int uvH = cfg_.color_space == kYUV ? (h >> 1) : h;
    const size_t y_count = plane_count(w, h);
    const size_t uv_count = plane_count(uvW, uvH);
    const int y_count_i = count_to_int(y_count);
    const int uv_count_i = count_to_int(uv_count);
    if (y_count_i < 0 || uv_count_i < 0) return -1;

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
        for (size_t i = 0; i < y_count; ++i)
            Ydelta_[i] = Y_[i] - prevY_[i];
        for (size_t i = 0; i < uv_count; ++i) {
            Udelta_[i] = U_[i] - prevU_[i];
            Vdelta_[i] = V_[i] - prevV_[i];
        }
        y_enc = Ydelta_.data();
        u_enc = Udelta_.data();
        v_enc = Vdelta_.data();
    }
    std::memcpy(prevY_.data(), Y_.data(), float_plane_bytes(y_count));
    std::memcpy(prevU_.data(), U_.data(), float_plane_bytes(uv_count));
    std::memcpy(prevV_.data(), V_.data(), float_plane_bytes(uv_count));

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
        y_rle_sz = int16_plane_bytes(y_count);
        u_rle_sz = int16_plane_bytes(uv_count);
        v_rle_sz = int16_plane_bytes(uv_count);
    } else {
        y_rle_sz = rle_encode(Yq_.data(), y_count_i, rle_buf_.data());
        u_rle_sz = rle_encode(Uq_.data(), uv_count_i, rle_buf_.data());
        v_rle_sz = rle_encode(Vq_.data(), uv_count_i, rle_buf_.data());
    }

    // Pack header + payload
    const size_t total = static_cast<size_t>(kHeaderBytes) + y_rle_sz + u_rle_sz + v_rle_sz;
    if (y_rle_sz > static_cast<size_t>(std::numeric_limits<uint32_t>::max())
        || u_rle_sz > static_cast<size_t>(std::numeric_limits<uint32_t>::max())
        || v_rle_sz > static_cast<size_t>(std::numeric_limits<uint32_t>::max())) return -1;
    if (total > static_cast<size_t>(std::numeric_limits<int>::max())
        || total > static_cast<size_t>(out_capacity)) return -1;

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
        const size_t y_bytes = rle_encode(Yq_.data(), y_count_i, p);
        p += y_bytes;
        const size_t u_bytes = rle_encode(Uq_.data(), uv_count_i, p);
        p += u_bytes;
        const size_t v_bytes = rle_encode(Vq_.data(), uv_count_i, p);
        p += v_bytes;
    }

    return static_cast<int>(p - out_buf);
}

int Encoder::max_encoded_bytes() const {
    const int w = cfg_.width, h = cfg_.height;
    const int uvW = cfg_.color_space == kYUV ? (w >> 1) : w;
    const int uvH = cfg_.color_space == kYUV ? (h >> 1) : h;
    const size_t y_count = plane_count(w, h);
    const size_t uv_count = plane_count(uvW, uvH);
    size_t total = 0;
    if (cfg_.entropy_coding == kNone) {
        total = static_cast<size_t>(kHeaderBytes)
              + int16_plane_bytes(y_count)
              + int16_plane_bytes(uv_count)
              + int16_plane_bytes(uv_count);
    } else {
        total = static_cast<size_t>(kHeaderBytes)
              + rle_max_bytes_for_count(y_count)
              + rle_max_bytes_for_count(uv_count)
              + rle_max_bytes_for_count(uv_count);
    }
    return count_to_int(total);
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
    const int w = cfg_.width, h = cfg_.height;
    const int uvW = cfg_.color_space == kYUV ? (w >> 1) : w;
    const int uvH = cfg_.color_space == kYUV ? (h >> 1) : h;
    const size_t y_count = plane_count(w, h);
    const size_t uv_count = plane_count(uvW, uvH);

    Y_.resize(y_count);
    U_.resize(uv_count);
    V_.resize(uv_count);
    prevY_.resize(y_count);
    prevU_.resize(uv_count);
    prevV_.resize(uv_count);
    Yq_.resize(y_count);
    Uq_.resize(uv_count);
    Vq_.resize(uv_count);
    tmp_.resize(std::max(w, h));
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

    const size_t payload_end = static_cast<size_t>(kHeaderBytes)
                             + static_cast<size_t>(y_bytes)
                             + static_cast<size_t>(u_bytes)
                             + static_cast<size_t>(v_bytes);
    if (payload_end > static_cast<size_t>(enc_size))
        return -1;

    const size_t y_count = plane_count(w, h);
    const size_t uv_count = plane_count(uvW, uvH);
    const int y_count_i = count_to_int(y_count);
    const int uv_count_i = count_to_int(uv_count);
    if (y_count_i < 0 || uv_count_i < 0) return -1;

    int yn = 0;
    int un = 0;
    int vn = 0;
    if (entropyMode == kNone) {
        const size_t y_expected = int16_plane_bytes(y_count);
        const size_t u_expected = int16_plane_bytes(uv_count);
        const size_t v_expected = int16_plane_bytes(uv_count);
        if (y_bytes != y_expected || u_bytes != u_expected || v_bytes != v_expected) return -1;
        std::memcpy(Yq_.data(), p, y_bytes);
        p += y_bytes;
        std::memcpy(Uq_.data(), p, u_bytes);
        p += u_bytes;
        std::memcpy(Vq_.data(), p, v_bytes);
        p += v_bytes;
        yn = y_count_i;
        un = uv_count_i;
        vn = uv_count_i;
    } else {
        yn = rle_decode(p, y_bytes, Yq_.data(), y_count_i);
        p += y_bytes;
        un = rle_decode(p, u_bytes, Uq_.data(), uv_count_i);
        p += u_bytes;
        vn = rle_decode(p, v_bytes, Vq_.data(), uv_count_i);
        p += v_bytes;
    }

    if (yn != y_count_i || un != uv_count_i || vn != uv_count_i) return -1;

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
        for (size_t i = 0; i < y_count; ++i)
            Y_[i] += prevY_[i];
    }
    if (!is_key && (flags & kFrameFlagChromaTemporalResidual)) {
        for (size_t i = 0; i < uv_count; ++i) {
            U_[i] += prevU_[i];
            V_[i] += prevV_[i];
        }
    }
    std::memcpy(prevY_.data(), Y_.data(), float_plane_bytes(y_count));
    std::memcpy(prevU_.data(), U_.data(), float_plane_bytes(uv_count));
    std::memcpy(prevV_.data(), V_.data(), float_plane_bytes(uv_count));

    if (colorSpace == kRGB) {
        for (size_t i = 0; i < y_count; ++i) {
            const size_t pi = rgba_offset(i);
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
    for (int row = 0; row < h; ++row) {
        for (int col = 0; col < w; ++col) {
            const size_t yi = static_cast<size_t>(row) * static_cast<size_t>(w)
                            + static_cast<size_t>(col);
            const size_t uvi = static_cast<size_t>(row >> 1) * static_cast<size_t>(uvW)
                             + static_cast<size_t>(col >> 1);

            const float y = Y[yi] + 128.0f;
            const float u = U[uvi];
            const float v = V[uvi];

            const float r = y + 1.13983f * v;
            const float g = y - 0.39465f * u - 0.58060f * v;
            const float b = y + 2.03211f * u;

            const size_t pi = rgba_offset(yi);
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
