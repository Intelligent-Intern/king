#pragma once
/**
 * Audio processing pipeline
 *
 * Processes float32 stereo PCM samples in-place.
 *
 * Pipeline (in order):
 *   1. Noise gate   — attenuates signal below threshold with hysteresis
 *                     to prevent chattering on low-level passages.
 *   2. Compressor   — feed-forward RMS compressor, ratio 3:1 above
 *                     threshold.  Attack/release times are tuned for
 *                     speech (fast attack, moderate release).
 *   3. Limiter      — hard clip at ±0.99 to prevent output clipping.
 *
 * All parameters are configurable at construction time.
 */

#include <cstdint>
#include <cstddef>

namespace wlvc {

struct AudioConfig {
    float sample_rate           = 48000.0f;  // Hz
    float gate_threshold        = 0.01f;     // linear amplitude
    float gate_hysteresis       = 0.005f;    // hysteresis band
    float comp_threshold        = 0.5f;      // linear amplitude (RMS)
    float comp_ratio            = 3.0f;      // compression ratio
    float comp_attack_ms        = 5.0f;      // attack time constant
    float comp_release_ms       = 80.0f;     // release time constant
    float comp_makeup_gain_db   = 3.0f;      // make-up gain after comp
};

class AudioProcessor {
public:
    explicit AudioProcessor(const AudioConfig& cfg = AudioConfig{});

    /**
     * Process stereo float32 samples in-place.
     * Samples are interleaved: [L0, R0, L1, R1, …].
     */
    void process_interleaved(float* samples, int n_frames);

    /**
     * Process planar stereo float32 samples in-place.
     * left[0..n_frames-1], right[0..n_frames-1]
     */
    void process_planar(float* left, float* right, int n_frames);

    void reset();

private:
    AudioConfig cfg_;

    // Gate state
    bool  gate_open_     = false;
    float gate_envelope_ = 0.0f;

    // Compressor state
    float comp_gain_     = 1.0f;   // current gain reduction
    float comp_env_      = 0.0f;   // RMS envelope follower

    // Coefficients (pre-computed from config)
    float attack_coeff_  = 0.0f;
    float release_coeff_ = 0.0f;
    float makeup_gain_   = 1.0f;

    void compute_coeffs();
    void process_sample(float& l, float& r);
};

AudioProcessor* audio_create(const AudioConfig& cfg);
void            audio_destroy(AudioProcessor* p);

} // namespace wlvc
