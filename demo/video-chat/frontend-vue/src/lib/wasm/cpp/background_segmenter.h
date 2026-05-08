#pragma once

#include <cstdint>
#include <vector>

namespace kingbg {

enum class MattePreset : int {
    WeakBlur = 0,
    HardBlur = 1,
    Replace = 2,
};

struct MatteConfig {
    int width = 0;
    int height = 0;
    MattePreset preset = MattePreset::WeakBlur;
};

std::vector<uint8_t> refine_mask_rgba(const uint8_t* rgba_mask, int rgba_len, const MatteConfig& cfg);
std::vector<uint8_t> segment_portrait_rgba(const uint8_t* rgba, int rgba_len, const MatteConfig& cfg);

} // namespace kingbg
