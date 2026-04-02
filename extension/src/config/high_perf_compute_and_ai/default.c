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

#include "include/config/high_perf_compute_and_ai/default.h"
#include "include/config/high_perf_compute_and_ai/base_layer.h"

void kg_config_high_perf_compute_and_ai_defaults_load(void)
{
    king_high_perf_compute_ai_config.dataframe_enable = true;
    king_high_perf_compute_ai_config.dataframe_memory_limit_mb = 1024;
    king_high_perf_compute_ai_config.dataframe_string_interning_enable = true;
    king_high_perf_compute_ai_config.dataframe_cpu_parallelism_default = 0;

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
