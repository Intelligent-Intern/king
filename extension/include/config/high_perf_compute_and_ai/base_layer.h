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

    bool inference_with_memory;
    zend_long inference_context_tokens;
    zend_long inference_kv_page_tokens;
    zend_long inference_kv_element_bytes;
    char *inference_preferred_model_profile;
    char *inference_models;
    char *inference_cpu_model_name;
    char *inference_cpu_model_artifact;
    char *inference_gpu_model_name;
    char *inference_gpu_model_artifact;
    zend_long inference_gpu_max_gpu_layers;
    zend_long inference_gpu_vram_reserve_mb;
    zend_long inference_gpu_min_free_vram_mb;
    bool inference_gpu_allow_system_ram_offload;
    zend_long inference_gpu_system_ram_offload_max_mb;
    zend_long inference_gpu_system_ram_offload_min_free_mb;
    char *inference_gpu_thermal_sensor_path;
    char *inference_gpu_thermal_sensor_command;
    double inference_gpu_thermal_max_temperature_c;
    zend_long inference_gpu_thermal_check_interval_sec;
    bool inference_gpu_allow_unmonitored;
    char *inference_gpu_power_sensor_command;
    double inference_gpu_power_max_watts;
    zend_long inference_gpu_power_check_interval_sec;
    bool inference_gpu_batch_prefill_experimental_enable;
    bool inference_cuda_numeric_compare_enable;
    zend_long inference_cuda_numeric_compare_max_values;
    bool inference_llm_cache_enable;
    char *inference_llm_cache_path;
    zend_long inference_llm_cache_min_free_mb;
    bool inference_llm_cache_fail_closed;
    char *inference_llm_cache_disk_alert_webhook;
    char *inference_llm_cache_disk_alert_mcp_service;
    char *inference_llm_cache_disk_alert_mcp_method;

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
