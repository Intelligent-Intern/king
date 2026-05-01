#include "audio.h"
#include <algorithm>
#include <cmath>

namespace wlvc {

AudioProcessor::AudioProcessor(const AudioConfig& cfg) : cfg_(cfg) {
    compute_coeffs();
}

void AudioProcessor::compute_coeffs() {
    const float sampleRate = std::max(1.0f, cfg_.sample_rate);
    const float attackSeconds = std::max(0.0001f, cfg_.comp_attack_ms / 1000.0f);
    const float releaseSeconds = std::max(0.0001f, cfg_.comp_release_ms / 1000.0f);
    attack_coeff_ = std::exp(-1.0f / (sampleRate * attackSeconds));
    release_coeff_ = std::exp(-1.0f / (sampleRate * releaseSeconds));
    makeup_gain_ = std::pow(10.0f, cfg_.comp_makeup_gain_db / 20.0f);
}

void AudioProcessor::process_sample(float& l, float& r) {
    const float absPeak = std::max(std::fabs(l), std::fabs(r));

    if (gate_open_) {
        if (absPeak < std::max(0.0f, cfg_.gate_threshold - cfg_.gate_hysteresis)) {
            gate_open_ = false;
        }
    } else if (absPeak > std::max(0.0f, cfg_.gate_threshold + cfg_.gate_hysteresis)) {
        gate_open_ = true;
    }

    gate_envelope_ = gate_open_ ? 1.0f : 0.0f;
    l *= gate_envelope_;
    r *= gate_envelope_;

    const float rms = std::sqrt((l * l + r * r) * 0.5f);
    if (rms > comp_env_) {
      comp_env_ = attack_coeff_ * comp_env_ + (1.0f - attack_coeff_) * rms;
    } else {
      comp_env_ = release_coeff_ * comp_env_ + (1.0f - release_coeff_) * rms;
    }

    float desiredGain = 1.0f;
    if (comp_env_ > cfg_.comp_threshold && cfg_.comp_ratio > 1.0f) {
        const float over = comp_env_ / std::max(0.0001f, cfg_.comp_threshold);
        desiredGain = std::pow(over, -(1.0f - 1.0f / cfg_.comp_ratio));
    }

    if (desiredGain < comp_gain_) {
        comp_gain_ = attack_coeff_ * comp_gain_ + (1.0f - attack_coeff_) * desiredGain;
    } else {
        comp_gain_ = release_coeff_ * comp_gain_ + (1.0f - release_coeff_) * desiredGain;
    }

    l = std::clamp(l * comp_gain_ * makeup_gain_, -0.99f, 0.99f);
    r = std::clamp(r * comp_gain_ * makeup_gain_, -0.99f, 0.99f);
}

void AudioProcessor::process_interleaved(float* samples, int n_frames) {
    if (!samples || n_frames <= 0) return;
    for (int i = 0; i < n_frames; ++i) {
        float& l = samples[i * 2];
        float& r = samples[i * 2 + 1];
        process_sample(l, r);
    }
}

void AudioProcessor::process_planar(float* left, float* right, int n_frames) {
    if (!left || !right || n_frames <= 0) return;
    for (int i = 0; i < n_frames; ++i) {
        process_sample(left[i], right[i]);
    }
}

void AudioProcessor::reset() {
    gate_open_ = false;
    gate_envelope_ = 0.0f;
    comp_gain_ = 1.0f;
    comp_env_ = 0.0f;
}

AudioProcessor* audio_create(const AudioConfig& cfg) {
    return new AudioProcessor(cfg);
}

void audio_destroy(AudioProcessor* p) {
    delete p;
}

} // namespace wlvc
