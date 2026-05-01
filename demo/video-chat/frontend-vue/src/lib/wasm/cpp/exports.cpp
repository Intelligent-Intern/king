/**
 * Emscripten bindings — exposes C++ codec to JavaScript
 *
 * Compiled with:
 *   emcc -O3 -msimd128 --bind -s WASM=1 -s ALLOW_MEMORY_GROWTH=1 \
 *        -s ENVIRONMENT=web -o wlvc.js exports.cpp codec.cpp dwt.cpp \
 *        quantize.cpp entropy.cpp motion.cpp audio.cpp
 *
 * JavaScript usage:
 *   const Module = await createWLVCModule();
 *   const enc = new Module.Encoder(
 *     640, 480, 60, 30,
 *     3, 0, 0, 0, true
 *   );
 *   const rgba = new Uint8Array(...);
 *   const out = enc.encode(rgba, timestamp_us);
 *   // out is a Uint8Array view of the encoded frame
 */

#include "codec.h"
#include <emscripten/bind.h>
#include <emscripten/val.h>
#include <vector>

using namespace emscripten;
using namespace wlvc;

// ---------------------------------------------------------------------------
// Helper: create codec configs from JS constructor args
// ---------------------------------------------------------------------------

static EncoderConfig make_encoder_config(int w, int h, int quality, int key_interval,
                                         int levels, int wavelet_type, int color_space,
                                         int entropy_coding, bool motion_estimation) {
    EncoderConfig cfg;
    cfg.width = w;
    cfg.height = h;
    cfg.quality = quality;
    cfg.key_frame_interval = key_interval;
    cfg.levels = levels;
    cfg.wavelet_type = static_cast<WaveletType>(wavelet_type);
    cfg.color_space = static_cast<ColorSpace>(color_space);
    cfg.entropy_coding = static_cast<EntropyMode>(entropy_coding);
    cfg.motion_estimation = motion_estimation;
    return cfg;
}

static DecoderConfig make_decoder_config(int w, int h, int quality, int levels,
                                         int wavelet_type, int color_space,
                                         int entropy_coding) {
    DecoderConfig cfg;
    cfg.width = w;
    cfg.height = h;
    cfg.quality = quality;
    cfg.levels = levels;
    cfg.wavelet_type = static_cast<WaveletType>(wavelet_type);
    cfg.color_space = static_cast<ColorSpace>(color_space);
    cfg.entropy_coding = static_cast<EntropyMode>(entropy_coding);
    return cfg;
}

// ---------------------------------------------------------------------------
// Encoder wrapper
// ---------------------------------------------------------------------------

class EncoderJS {
public:
    EncoderJS(int w, int h, int quality, int key_interval,
              int levels = kDefaultLevels,
              int wavelet_type = kHaar,
              int color_space = kYUV,
              int entropy_coding = kRLE,
              bool motion_estimation = true)
        : enc_(make_encoder_config(
            w, h, quality, key_interval, levels, wavelet_type, color_space, entropy_coding, motion_estimation
        ))
        , out_buf_(enc_.max_encoded_bytes())
    {}

    /**
     * Encode one RGBA frame.
     * @param rgba_js  Uint8Array or Uint8ClampedArray (w×h×4 bytes)
     * @param timestamp_us  double (microseconds)
     * @returns Uint8Array view of the encoded frame (invalidated on next encode)
     */
    val encode(val rgba_js, double timestamp_us) {
        // Copy JS typed array → WASM heap
        const auto len = rgba_js["byteLength"].as<unsigned>();
        std::vector<uint8_t> rgba(len);
        val memoryView = val(typed_memory_view(len, rgba.data()));
        memoryView.call<void>("set", rgba_js);

        const int n = enc_.encode(rgba.data(), timestamp_us,
                                  out_buf_.data(),
                                  static_cast<int>(out_buf_.size()));
        if (n < 0) return val::null();

        // Return a Uint8Array view (zero-copy)
        return val(typed_memory_view(n, out_buf_.data()));
    }

    void reset() { enc_.reset(); }

private:
    Encoder enc_;
    std::vector<uint8_t> out_buf_;
};

// ---------------------------------------------------------------------------
// Decoder wrapper
// ---------------------------------------------------------------------------

class DecoderJS {
public:
    DecoderJS(int w, int h, int quality,
              int levels = kDefaultLevels,
              int wavelet_type = kHaar,
              int color_space = kYUV,
              int entropy_coding = kRLE)
        : dec_(make_decoder_config(
            w, h, quality, levels, wavelet_type, color_space, entropy_coding
        ))
        , rgba_out_(w * h * 4)
    {}

    /**
     * Decode one encoded frame.
     * @param enc_js  Uint8Array of the encoded payload
     * @returns Uint8Array view of RGBA pixels (w×h×4), or null on error
     */
    val decode(val enc_js) {
        const auto len = enc_js["byteLength"].as<unsigned>();
        std::vector<uint8_t> enc(len);
        val memoryView = val(typed_memory_view(len, enc.data()));
        memoryView.call<void>("set", enc_js);

        const int ret = dec_.decode(enc.data(), static_cast<int>(len),
                                    rgba_out_.data());
        if (ret < 0) return val::null();

        return val(typed_memory_view(rgba_out_.size(), rgba_out_.data()));
    }

    void reset() { dec_.reset(); }

private:
    Decoder dec_;
    std::vector<uint8_t> rgba_out_;
};

// ---------------------------------------------------------------------------
// Audio processor wrapper
// ---------------------------------------------------------------------------

class AudioProcessorJS {
public:
    AudioProcessorJS(float sample_rate, float gate_thresh, float comp_thresh)
        : proc_(audio_create({
            sample_rate, gate_thresh, 0.005f,
            comp_thresh, 3.0f, 5.0f, 80.0f, 3.0f
        }))
    {}

    ~AudioProcessorJS() { audio_destroy(proc_); }

    /**
     * Process interleaved stereo float32 samples in-place.
     * @param samples_js  Float32Array (n_frames × 2)
     */
    void process(val samples_js) {
        const auto len = samples_js["length"].as<unsigned>();
        std::vector<float> samples(len);
        val memoryView = val(typed_memory_view(len, samples.data()));
        memoryView.call<void>("set", samples_js);

        proc_->process_interleaved(samples.data(), static_cast<int>(len / 2));

        // Write back
        val dest = val(typed_memory_view(len, samples.data()));
        samples_js.call<void>("set", dest);
    }

    void reset() { proc_->reset(); }

private:
    AudioProcessor* proc_;
};

// ---------------------------------------------------------------------------
// Embind declarations
// ---------------------------------------------------------------------------

EMSCRIPTEN_BINDINGS(wlvc_module) {
    class_<EncoderJS>("Encoder")
        .constructor<int, int, int, int, int, int, int, int, bool>()
        .function("encode", &EncoderJS::encode)
        .function("reset",  &EncoderJS::reset);

    class_<DecoderJS>("Decoder")
        .constructor<int, int, int, int, int, int, int>()
        .function("decode", &DecoderJS::decode)
        .function("reset",  &DecoderJS::reset);

    class_<AudioProcessorJS>("AudioProcessor")
        .constructor<float, float, float>()
        .function("process", &AudioProcessorJS::process)
        .function("reset",   &AudioProcessorJS::reset);
}
