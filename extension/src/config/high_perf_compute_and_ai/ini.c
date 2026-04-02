/*
 * =========================================================================
 * FILENAME:   src/config/high_perf_compute_and_ai/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the high-perf compute / AI
 * config family. This file exposes the system-level dataframe, GPU backend,
 * memory-preallocation, storage, and vendor-acceleration directives and
 * keeps `king_high_perf_compute_ai_config` aligned with validated updates.
 * =========================================================================
 */

#include "include/config/high_perf_compute_and_ai/ini.h"
#include "include/config/high_perf_compute_and_ai/base_layer.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <zend_ini.h>

static void high_perf_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }

    *target = pestrdup(ZSTR_VAL(value), 1);
}

static ZEND_INI_MH(OnUpdateAiPositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for an AI/Compute directive. A positive integer is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.dataframe_memory_limit_mb")) {
        king_high_perf_compute_ai_config.dataframe_memory_limit_mb = val;
    } else if (zend_string_equals_literal(entry->name, "king.gpu_memory_preallocation_mb")) {
        king_high_perf_compute_ai_config.gpu_memory_preallocation_mb = val;
    } else if (zend_string_equals_literal(entry->name, "king.cuda_stream_pool_size")) {
        king_high_perf_compute_ai_config.cuda_stream_pool_size = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateGpuBackend)
{
    const char *allowed[] = {"auto", "cuda", "rocm", "sycl", NULL};
    bool is_allowed = false;

    for (int i = 0; allowed[i] != NULL; i++) {
        if (strcasecmp(ZSTR_VAL(new_value), allowed[i]) == 0) {
            is_allowed = true;
            break;
        }
    }

    if (!is_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for GPU backend. Must be one of 'auto', 'cuda', 'rocm', or 'sycl'.");
        return FAILURE;
    }

    high_perf_replace_string(&king_high_perf_compute_ai_config.gpu_default_backend, new_value);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.dataframe_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, dataframe_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    ZEND_INI_ENTRY_EX("king.dataframe_memory_limit_mb", "1024", PHP_INI_SYSTEM, OnUpdateAiPositiveLong, NULL)
    STD_PHP_INI_ENTRY("king.dataframe_string_interning_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, dataframe_string_interning_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    STD_PHP_INI_ENTRY("king.dataframe_cpu_parallelism_default", "0", PHP_INI_SYSTEM, OnUpdateLong, dataframe_cpu_parallelism_default, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)

    STD_PHP_INI_ENTRY("king.gpu_bindings_enable", "0", PHP_INI_SYSTEM, OnUpdateBool, gpu_bindings_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    ZEND_INI_ENTRY_EX("king.gpu_default_backend", "auto", PHP_INI_SYSTEM, OnUpdateGpuBackend, NULL)
    STD_PHP_INI_ENTRY("king.worker_gpu_affinity_map", "", PHP_INI_SYSTEM, OnUpdateString, worker_gpu_affinity_map, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    ZEND_INI_ENTRY_EX("king.gpu_memory_preallocation_mb", "2048", PHP_INI_SYSTEM, OnUpdateAiPositiveLong, NULL)
    STD_PHP_INI_ENTRY("king.gpu_p2p_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, gpu_p2p_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    STD_PHP_INI_ENTRY("king.gpu_storage_enable_directstorage", "0", PHP_INI_SYSTEM, OnUpdateBool, storage_enable_directstorage, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)

    STD_PHP_INI_ENTRY("king.cuda_enable_tensor_cores", "1", PHP_INI_SYSTEM, OnUpdateBool, cuda_enable_tensor_cores, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    ZEND_INI_ENTRY_EX("king.cuda_stream_pool_size", "4", PHP_INI_SYSTEM, OnUpdateAiPositiveLong, NULL)

    STD_PHP_INI_ENTRY("king.rocm_enable_gfx_optimizations", "1", PHP_INI_SYSTEM, OnUpdateBool, rocm_enable_gfx_optimizations, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    STD_PHP_INI_ENTRY("king.arc_enable_xmx_optimizations", "1", PHP_INI_SYSTEM, OnUpdateBool, arc_enable_xmx_optimizations, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    STD_PHP_INI_ENTRY("king.arc_video_acceleration_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, arc_video_acceleration_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_high_perf_compute_and_ai_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_high_perf_compute_and_ai_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
