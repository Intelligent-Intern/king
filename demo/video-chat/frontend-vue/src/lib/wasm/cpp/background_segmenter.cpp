#include "background_segmenter.h"

#include <algorithm>
#include <cmath>

namespace kingbg {

namespace {

uint8_t clamp_u8(float value) {
    return static_cast<uint8_t>(std::max(0.0f, std::min(255.0f, std::round(value))));
}

float preset_threshold(MattePreset preset) {
    switch (preset) {
        case MattePreset::HardBlur:
            return 84.0f;
        case MattePreset::Replace:
            return 108.0f;
        case MattePreset::WeakBlur:
        default:
            return 56.0f;
    }
}

int preset_radius(MattePreset preset) {
    switch (preset) {
        case MattePreset::HardBlur:
            return 2;
        case MattePreset::Replace:
            return 3;
        case MattePreset::WeakBlur:
        default:
            return 1;
    }
}

float smoothstep(float edge0, float edge1, float x) {
    const float denom = std::max(1.0e-6f, edge1 - edge0);
    const float t = std::max(0.0f, std::min(1.0f, (x - edge0) / denom));
    return t * t * (3.0f - 2.0f * t);
}

void box_blur(std::vector<float>& alpha, int width, int height, int radius) {
    if (radius <= 0 || width <= 0 || height <= 0) return;
    std::vector<float> tmp(alpha.size(), 0.0f);
    std::vector<float> out(alpha.size(), 0.0f);

    for (int y = 0; y < height; y += 1) {
        const int row = y * width;
        for (int x = 0; x < width; x += 1) {
            float sum = 0.0f;
            int count = 0;
            for (int k = -radius; k <= radius; k += 1) {
                const int xx = x + k;
                if (xx < 0 || xx >= width) continue;
                sum += alpha[row + xx];
                count += 1;
            }
            tmp[row + x] = count > 0 ? sum / static_cast<float>(count) : 0.0f;
        }
    }

    for (int y = 0; y < height; y += 1) {
        const int row = y * width;
        for (int x = 0; x < width; x += 1) {
            float sum = 0.0f;
            int count = 0;
            for (int k = -radius; k <= radius; k += 1) {
                const int yy = y + k;
                if (yy < 0 || yy >= height) continue;
                sum += tmp[yy * width + x];
                count += 1;
            }
            out[row + x] = count > 0 ? sum / static_cast<float>(count) : 0.0f;
        }
    }

    alpha.swap(out);
}

} // namespace

std::vector<uint8_t> refine_mask_rgba(const uint8_t* rgba_mask, int rgba_len, const MatteConfig& cfg) {
    const int width = std::max(1, cfg.width);
    const int height = std::max(1, cfg.height);
    const int pixels = width * height;
    std::vector<float> alpha(static_cast<size_t>(pixels), 0.0f);
    std::vector<uint8_t> out(static_cast<size_t>(pixels), 0);
    if (!rgba_mask || rgba_len < pixels * 4) return out;

    const float threshold = preset_threshold(cfg.preset);
    const int radius = preset_radius(cfg.preset);
    for (int i = 0; i < pixels; i += 1) {
        const int p = i * 4;
        const float raw = static_cast<float>(std::max({ rgba_mask[p], rgba_mask[p + 1], rgba_mask[p + 2], rgba_mask[p + 3] }));
        alpha[i] = smoothstep(threshold, 192.0f, raw) * 255.0f;
    }

    box_blur(alpha, width, height, radius);
    if (cfg.preset == MattePreset::Replace) {
        for (float& v : alpha) {
            v = smoothstep(72.0f, 224.0f, v) * 255.0f;
        }
    }

    for (int i = 0; i < pixels; i += 1) {
        out[i] = clamp_u8(alpha[i]);
    }
    return out;
}

std::vector<uint8_t> segment_portrait_rgba(const uint8_t* rgba, int rgba_len, const MatteConfig& cfg) {
    const int width = std::max(1, cfg.width);
    const int height = std::max(1, cfg.height);
    const int pixels = width * height;
    std::vector<float> alpha(static_cast<size_t>(pixels), 0.0f);
    std::vector<uint8_t> out(static_cast<size_t>(pixels), 0);
    if (!rgba || rgba_len < pixels * 4) return out;

    const float cx = static_cast<float>(width - 1) * 0.5f;
    const float cy = static_cast<float>(height - 1) * 0.46f;
    const float rx = std::max(1.0f, static_cast<float>(width) * 0.38f);
    const float ry = std::max(1.0f, static_cast<float>(height) * 0.58f);
    const float threshold = cfg.preset == MattePreset::Replace ? 0.46f : 0.38f;

    for (int y = 0; y < height; y += 1) {
        for (int x = 0; x < width; x += 1) {
            const int i = y * width + x;
            const int p = i * 4;
            const float r = static_cast<float>(rgba[p]);
            const float g = static_cast<float>(rgba[p + 1]);
            const float b = static_cast<float>(rgba[p + 2]);
            const float maxc = std::max({ r, g, b });
            const float minc = std::min({ r, g, b });
            const float chroma = maxc - minc;
            const float luma = 0.299f * r + 0.587f * g + 0.114f * b;
            const float dx = (static_cast<float>(x) - cx) / rx;
            const float dy = (static_cast<float>(y) - cy) / ry;
            const float oval = 1.0f - std::sqrt(dx * dx + dy * dy);
            const float center_prior = smoothstep(-0.22f, 0.38f, oval);
            const float face_band = smoothstep(0.10f, 0.72f, 1.0f - std::abs((static_cast<float>(y) / std::max(1.0f, static_cast<float>(height))) - 0.34f) * 2.2f);
            const float skinish = smoothstep(8.0f, 58.0f, r - b) * smoothstep(12.0f, 96.0f, chroma) * smoothstep(32.0f, 232.0f, luma);
            const float torso_band = smoothstep(0.18f, 0.76f, static_cast<float>(y) / std::max(1.0f, static_cast<float>(height)));
            const float torso_prior = center_prior * torso_band;
            const float score = std::max(center_prior * 0.72f + skinish * face_band * 0.36f, torso_prior * 0.82f);
            alpha[i] = smoothstep(threshold, 0.86f, score) * 255.0f;
        }
    }

    box_blur(alpha, width, height, preset_radius(cfg.preset) + 1);
    for (int i = 0; i < pixels; i += 1) {
        out[i] = clamp_u8(alpha[i]);
    }
    return out;
}

} // namespace kingbg
