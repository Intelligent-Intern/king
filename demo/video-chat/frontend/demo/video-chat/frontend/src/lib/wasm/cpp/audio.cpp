#include "audio.h"
#include <cmath>
#include <algorithm>

namespace wlvc {

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

AudioProcessor::AudioProcessor(const AudioConfig& cfg) : cfg_(cfg) {
    compute_coeffs();
}

void AudioProcessor::compute_coeffs() {
    // Convert ms time constants to per-sample coefficients
    // coeff = exp(−1 / (time_ms × sample_rate / 1000))
    const float sr = cfg_.sample_rate;
    attack_coeff_  = std::expf(-1.0f / (cfg_.comp_attack_ms  * sr / 1000.0f));
    release_coeff_ = std::expf(-1.0f / (cfg_.comp_release_ms * sr / 1000.0f));
    // Make-up gain from dB
    makeup_gain_   = std::powf(10.0f, cfg_.comp_makeup_gain_db / 20.0f);
}

void AudioProcessor::reset() {
    gate_open_     = false;
    gate_envelope_ = 0.0f;
    comp_gain_     = 1.0f;
    comp_env_      = 0.0f;
}

// ---------------------------------------------------------------------------
// Per-sample processing
// ---------------------------------------------------------------------------

void AudioProcessor::process_sample(float& l, float& r) {
    const float amp = std::fmaxf(std::fabsf(l), std::fabsf(r));

    // ── 1. Noise gate ─────────────────────────────────────────────────────
    const float open_thresh  = cfg_.gate_threshold;
    const float close_thresh = open_thresh - cfg_.gate_hysteresis;
    if (gate_open_) {
        if (amp < close_thresh) gate_open_ = false;
    } else {
        if (amp > open_thresh)  gate_open_ = true;
    }
    if (!gate_open_) {
        // Smooth the gate (avoid hard clicks)
        gate_envelope_ = gate_envelope_ * 0.99f;
        l *= gate_envelope_;
        r *= gate_envelope_;
    } else {
        gate_envelope_ = gate_envelope_ * 0.99f + 0.01f;  // ramp up
    }

    // ── 2. Compressor (feed-forward RMS) ──────────────────────────────────
    const float rms_in = amp;
    // Envelope follower with attack/release
    const float coeff = (rms_in > comp_env_) ? attack_coeff_ : release_coeff_;
    comp_env_ = coeff * comp_env_ + (1.0f - coeff) * rms_in;

    float gain_db = 0.0f;
    if (comp_env_ > cfg_.comp_threshold) {
        // Gain reduction in dB
        const float over_db = 20.0f * std::log10f(comp_env_ / cfg_.comp_threshold);
        gain_db = -(over_db * (1.0f - 1.0f / cfg_.comp_ratio));
    }
    const float target_gain = std::powf(10.0f, gain_db / 20.0f) * makeup_gain_;
    comp_gain_ = comp_gain_ * 0.95f + target_gain * 0.05f;  // smooth gain

    l *= comp_gain_;
    r *= comp_gain_;

    // ── 3. Limiter ────────────────────────────────────────────────────────
    l = std::fmaxf(-0.99f, std::fminf(0.99f, l));
    r = std::fmaxf(-0.99f, std::fminf(0.99f, r));
}

// ---------------------------------------------------------------------------
// Public process methods
// ---------------------------------------------------------------------------

void AudioProcessor::process_interleaved(float* samples, int n_frames) {
    for (int i = 0; i < n_frames; ++i) {
        float& l = samples[i * 2];
        float& r = samples[i * 2 + 1];
        process_sample(l, r);
    }
}

void AudioProcessor::process_planar(float* left, float* right, int n_frames) {
    for (int i = 0; i < n_frames; ++i)
        process_sample(left[i], right[i]);
}

// ---------------------------------------------------------------------------
// Factory
// ---------------------------------------------------------------------------

AudioProcessor* audio_create(const AudioConfig& cfg) {
    return new AudioProcessor(cfg);
}

void audio_destroy(AudioProcessor* p) {
    delete p;
}

} // namespace wlvc
