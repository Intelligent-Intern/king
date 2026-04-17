#include "codec.h"
#include <cstring>
#include <algorithm>
#include <cmath>

namespace wlvc {

// ---------------------------------------------------------------------------
// Encoder
// ---------------------------------------------------------------------------

Encoder::Encoder(const EncoderConfig& cfg) : cfg_(cfg) {
    const int w = cfg_.width, h = cfg_.height;
    const int uvW = w >> 1, uvH = h >> 1;

    Y_.resize(w * h);
    U_.resize(uvW * uvH);
    V_.resize(uvW * uvH);
    Ydelta_.resize(w * h);
    prevY_.resize(w * h);
    Yq_.resize(w * h);
    Uq_.resize(uvW * uvH);
    Vq_.resize(uvW * uvH);
    tmp_.resize(std::max(w, h));
    rle_buf_.resize(rle_max_bytes(std::max(w * h, uvW * uvH)));
}

void Encoder::rgba_to_yuv(const uint8_t* rgba) {
    const int w = cfg_.width, h = cfg_.height;
    const int uvW = w >> 1, uvH = h >> 1;

    // Y channel (full resolution, centered at 0)
    for (int row = 0; row < h; ++row) {
        for (int col = 0; col < w; ++col) {
            const int i = (row * w + col) * 4;
            const float r = rgba[i], g = rgba[i + 1], b = rgba[i + 2];
            Y_[row * w + col] = 0.299f * r + 0.587f * g + 0.114f * b - 128.0f;
        }
    }

    // U/V channels (4:2:0 chroma subsampling)
    for (int row = 0; row < uvH; ++row) {
        for (int col = 0; col < uvW; ++col) {
            const int i = (row * 2 * w + col * 2) * 4;
            const float r = rgba[i], g = rgba[i + 1], b = rgba[i + 2];
            U_[row * uvW + col] = -0.147f * r - 0.289f * g + 0.436f * b;
            V_[row * uvW + col] =  0.615f * r - 0.515f * g - 0.100f * b;
        }
    }
}

int Encoder::encode(const uint8_t* rgba, double timestamp_us,
                    uint8_t* out_buf, int out_capacity) {
    const int w = cfg_.width, h = cfg_.height;
    const int uvW = w >> 1, uvH = h >> 1;
    const bool is_key = (frame_count_ % cfg_.key_frame_interval) == 0;
    ++frame_count_;

    rgba_to_yuv(rgba);

    // Temporal residual on Y for delta frames
    float* y_enc = Y_.data();
    if (!is_key && frame_count_ > 1) {
        for (int i = 0; i < w * h; ++i)
            Ydelta_[i] = Y_[i] - prevY_[i];
        y_enc = Ydelta_.data();
    }
    std::memcpy(prevY_.data(), Y_.data(), w * h * sizeof(float));

    // Forward DWT
    dwt_forward(y_enc, w, h, cfg_.levels, tmp_.data());
    dwt_forward(U_.data(), uvW, uvH, cfg_.levels, tmp_.data());
    dwt_forward(V_.data(), uvW, uvH, cfg_.levels, tmp_.data());

    // Quantise
    QuantConfig qcfg = { cfg_.quality, cfg_.levels };
    quantize_2d(y_enc, Yq_.data(), w, h, qcfg);
    quantize_2d(U_.data(), Uq_.data(), uvW, uvH, qcfg);
    quantize_2d(V_.data(), Vq_.data(), uvW, uvH, qcfg);

    // RLE encode each channel
    const size_t y_rle_sz = rle_encode(Yq_.data(), w * h, rle_buf_.data());
    const size_t u_rle_sz = rle_encode(Uq_.data(), uvW * uvH, rle_buf_.data());
    const size_t v_rle_sz = rle_encode(Vq_.data(), uvW * uvH, rle_buf_.data());

    // Pack header + payload
    const int total = kHeaderBytes + static_cast<int>(y_rle_sz + u_rle_sz + v_rle_sz);
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
    w8(1);                                    // version
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

    // Re-encode in-place (RLE was written to rle_buf_ each time)
    const size_t y_bytes = rle_encode(Yq_.data(), w * h, p);
    p += y_bytes;
    const size_t u_bytes = rle_encode(Uq_.data(), uvW * uvH, p);
    p += u_bytes;
    const size_t v_bytes = rle_encode(Vq_.data(), uvW * uvH, p);
    p += v_bytes;

    return static_cast<int>(p - out_buf);
}

int Encoder::max_encoded_bytes() const {
    const int w = cfg_.width, h = cfg_.height;
    const int uvW = w >> 1, uvH = h >> 1;
    return kHeaderBytes
         + static_cast<int>(rle_max_bytes(w * h))
         + static_cast<int>(rle_max_bytes(uvW * uvH)) * 2;
}

void Encoder::reset() {
    frame_count_ = 0;
    std::fill(prevY_.begin(), prevY_.end(), 0.0f);
}

// ---------------------------------------------------------------------------
// Decoder
// ---------------------------------------------------------------------------

Decoder::Decoder(const DecoderConfig& cfg) : cfg_(cfg) {
    const int w = cfg_.width, h = cfg_.height;
    const int uvW = w >> 1, uvH = h >> 1;

    Y_.resize(w * h);
    U_.resize(uvW * uvH);
    V_.resize(uvW * uvH);
    prevY_.resize(w * h);
    Yq_.resize(w * h);
    Uq_.resize(uvW * uvH);
    Vq_.resize(uvW * uvH);
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
    r8();  // version
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

    if (kHeaderBytes + y_bytes + u_bytes + v_bytes > static_cast<uint32_t>(enc_size))
        return -1;

    // RLE decode
    const int yn = rle_decode(p, y_bytes, Yq_.data(), w * h);
    p += y_bytes;
    const int un = rle_decode(p, u_bytes, Uq_.data(), uvW * uvH);
    p += u_bytes;
    const int vn = rle_decode(p, v_bytes, Vq_.data(), uvW * uvH);
    p += v_bytes;

    if (yn != w * h || un != uvW * uvH || vn != uvW * uvH) return -1;

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
        for (int i = 0; i < w * h; ++i)
            Y_[i] += prevY_[i];
    }
    std::memcpy(prevY_.data(), Y_.data(), w * h * sizeof(float));

    // YUV → RGBA
    yuv_to_rgba(rgba_out, w, h, Y_.data(), U_.data(), V_.data(), uvW, uvH);
    return 0;
}

void Decoder::yuv_to_rgba(uint8_t* rgba, int w, int h,
                          const float* Y, const float* U, const float* V,
                          int uvW, int uvH) {
    for (int row = 0; row < h; ++row) {
        for (int col = 0; col < w; ++col) {
            const int yi  = row * w + col;
            const int uvi = (row >> 1) * uvW + (col >> 1);

            const float y = Y[yi] + 128.0f;
            const float u = U[uvi];
            const float v = V[uvi];

            const float r = y + 1.13983f * v;
            const float g = y - 0.39465f * u - 0.58060f * v;
            const float b = y + 2.03211f * u;

            const int pi = yi * 4;
            rgba[pi]     = static_cast<uint8_t>(std::fmaxf(0.0f, std::fminf(255.0f, r)));
            rgba[pi + 1] = static_cast<uint8_t>(std::fmaxf(0.0f, std::fminf(255.0f, g)));
            rgba[pi + 2] = static_cast<uint8_t>(std::fmaxf(0.0f, std::fminf(255.0f, b)));
            rgba[pi + 3] = 255;
        }
    }
}

void Decoder::reset() {
    std::fill(prevY_.begin(), prevY_.end(), 0.0f);
}

} // namespace wlvc
