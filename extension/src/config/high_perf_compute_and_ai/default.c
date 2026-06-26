/*
 * =========================================================================
 * FILENAME:   src/config/high_perf_compute_and_ai/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the high-perf compute / AI config family. This
 * slice seeds the baseline dataframe, GPU backend/affinity, memory
 * preallocation, direct-storage, and CUDA/ROCm/Arc acceleration defaults
 * before INI and any allowed userland overrides refine the live snapshot.
 * =========================================================================
 */

#include "config/high_perf_compute_and_ai/default.h"
#include "config/high_perf_compute_and_ai/base_layer.h"

void kg_config_high_perf_compute_and_ai_defaults_load(void)
{
    king_high_perf_compute_ai_config.dataframe_enable = true;
    king_high_perf_compute_ai_config.dataframe_memory_limit_mb = 1024;
    king_high_perf_compute_ai_config.dataframe_string_interning_enable = true;
    king_high_perf_compute_ai_config.dataframe_cpu_parallelism_default = 0;
    king_high_perf_compute_ai_config.inference_with_memory = false;
    king_high_perf_compute_ai_config.inference_preferred_model_profile = pestrdup("auto", 1);
    king_high_perf_compute_ai_config.inference_cpu_model_name = pestrdup("gemma3:1b", 1);
    king_high_perf_compute_ai_config.inference_cpu_model_artifact = pestrdup("", 1);
    king_high_perf_compute_ai_config.inference_gpu_model_name = pestrdup("gemma4:12b", 1);
    king_high_perf_compute_ai_config.inference_gpu_model_artifact = pestrdup("", 1);
    king_high_perf_compute_ai_config.inference_gpu_max_gpu_layers = 0;
    king_high_perf_compute_ai_config.inference_gpu_vram_reserve_mb = 2048;
    king_high_perf_compute_ai_config.inference_gpu_min_free_vram_mb = 4096;
    king_high_perf_compute_ai_config.inference_gpu_thermal_sensor_path = pestrdup("", 1);
    king_high_perf_compute_ai_config.inference_gpu_thermal_sensor_command = pestrdup("", 1);
    king_high_perf_compute_ai_config.inference_gpu_thermal_max_temperature_c = 78.0;
    king_high_perf_compute_ai_config.inference_gpu_allow_unmonitored = false;
    king_high_perf_compute_ai_config.inference_llm_cache_enable = true;
    king_high_perf_compute_ai_config.inference_llm_cache_path = pestrdup("/tmp/king-llm-cache", 1);
    king_high_perf_compute_ai_config.inference_llm_cache_min_free_mb = 5120;
    king_high_perf_compute_ai_config.inference_llm_cache_fail_closed = true;
    king_high_perf_compute_ai_config.inference_llm_cache_disk_alert_webhook = pestrdup("", 1);
    king_high_perf_compute_ai_config.inference_llm_cache_disk_alert_mcp_service = pestrdup("", 1);
    king_high_perf_compute_ai_config.inference_llm_cache_disk_alert_mcp_method = pestrdup("", 1);

    king_high_perf_compute_ai_config.gpu_bindings_enable = false;
    king_high_perf_compute_ai_config.gpu_default_backend = NULL;
    king_high_perf_compute_ai_config.worker_gpu_affinity_map = NULL;
    king_high_perf_compute_ai_config.gpu_memory_preallocation_mb = 2048;
    king_high_perf_compute_ai_config.gpu_p2p_enable = true;
    king_high_perf_compute_ai_config.storage_enable_directstorage = false;

    king_high_perf_compute_ai_config.cuda_enable_tensor_cores = true;
    king_high_perf_compute_ai_config.cuda_stream_pool_size = 4;
    king_high_perf_compute_ai_config.rocm_enable_gfx_optimizations = true;
    king_high_perf_compute_ai_config.arc_enable_xmx_optimizations = true;
    king_high_perf_compute_ai_config.arc_video_acceleration_enable = true;
}
