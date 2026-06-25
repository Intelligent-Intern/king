/*
 * =========================================================================
 * FILENAME:   include/config/high_perf_compute_and_ai/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for the high-performance compute and AI
 * module.
 *
 * ARCHITECTURE:
 * This struct stores the DataFrame, GPU, CUDA, ROCm, and Arc settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_HIGH_PERF_COMPUTE_AI_BASE_H
#define KING_CONFIG_HIGH_PERF_COMPUTE_AI_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_high_perf_compute_ai_config_t {
    /* --- DataFrame Engine (CPU-based Analytics) --- */
    bool dataframe_enable;
    zend_long dataframe_memory_limit_mb;
    bool dataframe_string_interning_enable;
    zend_long dataframe_cpu_parallelism_default;

    /* --- General GPU Configuration --- */
    bool gpu_bindings_enable;
    char *gpu_default_backend;
    char *worker_gpu_affinity_map;
    zend_long gpu_memory_preallocation_mb;
    bool gpu_p2p_enable;
    bool storage_enable_directstorage;

    /* --- NVIDIA CUDA Specific Settings --- */
    bool cuda_enable_tensor_cores;
    zend_long cuda_stream_pool_size;

    /* --- AMD ROCm Specific Settings --- */
    bool rocm_enable_gfx_optimizations;

    /* --- Intel Arc (SYCL) Specific Settings --- */
    bool arc_enable_xmx_optimizations;
    bool arc_video_acceleration_enable;

} kg_high_perf_compute_ai_config_t;

/* Module-global configuration instance. */
extern kg_high_perf_compute_ai_config_t king_high_perf_compute_ai_config;

#endif /* KING_CONFIG_HIGH_PERF_COMPUTE_AI_BASE_H */
