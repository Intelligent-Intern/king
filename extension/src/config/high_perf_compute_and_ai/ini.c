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

#include "config/high_perf_compute_and_ai/ini.h"
#include "config/high_perf_compute_and_ai/base_layer.h"
#include "php_king/init.h"
#include "validation/config_param/validate_cpu_affinity_map_string.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <zend_ini.h>
#include <errno.h>
#include <ctype.h>
#include <math.h>
#include <stdlib.h>
#include <strings.h>

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

static ZEND_INI_MH(OnUpdateAiNonNegativeLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val < 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for an AI/Compute directive. A non-negative integer is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.inference_gpu_max_gpu_layers")) {
        king_high_perf_compute_ai_config.inference_gpu_max_gpu_layers = val;
    } else if (zend_string_equals_literal(entry->name, "king.inference_gpu_vram_reserve_mb")) {
        king_high_perf_compute_ai_config.inference_gpu_vram_reserve_mb = val;
    } else if (zend_string_equals_literal(entry->name, "king.inference_llm_cache_min_free_mb")) {
        king_high_perf_compute_ai_config.inference_llm_cache_min_free_mb = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateAiPositiveDouble)
{
    char *endptr;
    double val;

    errno = 0;
    val = strtod(ZSTR_VAL(new_value), &endptr);
    while (*endptr != '\0' && isspace((unsigned char) *endptr)) {
        endptr++;
    }
    if (errno != 0
        || endptr == ZSTR_VAL(new_value)
        || *endptr != '\0'
        || !isfinite(val)
        || val <= 0.0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for an AI/Compute directive. A positive finite number is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.inference_gpu_thermal_max_temperature_c")) {
        king_high_perf_compute_ai_config.inference_gpu_thermal_max_temperature_c = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateInferenceProfile)
{
    const char *allowed[] = {"auto", "gpu", "cpu", NULL};
    bool is_allowed = false;

    for (int i = 0; allowed[i] != NULL; i++) {
        if (strcasecmp(ZSTR_VAL(new_value), allowed[i]) == 0) {
            is_allowed = true;
            break;
        }
    }

    if (!is_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for inference model profile. Must be one of 'auto', 'gpu', or 'cpu'.");
        return FAILURE;
    }

    high_perf_replace_string(
        &king_high_perf_compute_ai_config.inference_preferred_model_profile,
        new_value
    );
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateInferenceString)
{
    if (zend_string_equals_literal(entry->name, "king.inference_cpu_model_name")) {
        high_perf_replace_string(&king_high_perf_compute_ai_config.inference_cpu_model_name, new_value);
    } else if (zend_string_equals_literal(entry->name, "king.inference_cpu_model_artifact")) {
        high_perf_replace_string(&king_high_perf_compute_ai_config.inference_cpu_model_artifact, new_value);
    } else if (zend_string_equals_literal(entry->name, "king.inference_gpu_model_name")) {
        high_perf_replace_string(&king_high_perf_compute_ai_config.inference_gpu_model_name, new_value);
    } else if (zend_string_equals_literal(entry->name, "king.inference_gpu_model_artifact")) {
        high_perf_replace_string(&king_high_perf_compute_ai_config.inference_gpu_model_artifact, new_value);
    } else if (zend_string_equals_literal(entry->name, "king.inference_gpu_thermal_sensor_path")) {
        high_perf_replace_string(
            &king_high_perf_compute_ai_config.inference_gpu_thermal_sensor_path,
            new_value
        );
    } else if (zend_string_equals_literal(entry->name, "king.inference_gpu_thermal_sensor_command")) {
        high_perf_replace_string(
            &king_high_perf_compute_ai_config.inference_gpu_thermal_sensor_command,
            new_value
        );
    } else if (zend_string_equals_literal(entry->name, "king.inference_llm_cache_path")) {
        high_perf_replace_string(&king_high_perf_compute_ai_config.inference_llm_cache_path, new_value);
    } else if (zend_string_equals_literal(entry->name, "king.inference_llm_cache_disk_alert_webhook")) {
        high_perf_replace_string(
            &king_high_perf_compute_ai_config.inference_llm_cache_disk_alert_webhook,
            new_value
        );
    } else if (zend_string_equals_literal(entry->name, "king.inference_llm_cache_disk_alert_mcp_service")) {
        high_perf_replace_string(
            &king_high_perf_compute_ai_config.inference_llm_cache_disk_alert_mcp_service,
            new_value
        );
    } else if (zend_string_equals_literal(entry->name, "king.inference_llm_cache_disk_alert_mcp_method")) {
        high_perf_replace_string(
            &king_high_perf_compute_ai_config.inference_llm_cache_disk_alert_mcp_method,
            new_value
        );
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

static ZEND_INI_MH(OnUpdateWorkerGpuAffinityString)
{
    zval zv;

    ZVAL_STR_COPY(&zv, new_value);
    if (kg_validate_cpu_affinity_map_string(&zv, &king_high_perf_compute_ai_config.worker_gpu_affinity_map) != SUCCESS) {
        zval_ptr_dtor(&zv);
        return FAILURE;
    }

    zval_ptr_dtor(&zv);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.dataframe_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, dataframe_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    ZEND_INI_ENTRY_EX("king.dataframe_memory_limit_mb", "1024", PHP_INI_SYSTEM, OnUpdateAiPositiveLong, NULL)
    STD_PHP_INI_ENTRY("king.dataframe_string_interning_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, dataframe_string_interning_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    STD_PHP_INI_ENTRY("king.dataframe_cpu_parallelism_default", "0", PHP_INI_SYSTEM, OnUpdateLong, dataframe_cpu_parallelism_default, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)

    STD_PHP_INI_ENTRY("king.inference_with_memory", "0", PHP_INI_SYSTEM, OnUpdateBool, inference_with_memory, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    ZEND_INI_ENTRY_EX("king.inference_preferred_model_profile", "auto", PHP_INI_SYSTEM, OnUpdateInferenceProfile, NULL)
    ZEND_INI_ENTRY_EX("king.inference_cpu_model_name", "gemma3:1b", PHP_INI_SYSTEM, OnUpdateInferenceString, NULL)
    ZEND_INI_ENTRY_EX("king.inference_cpu_model_artifact", "", PHP_INI_SYSTEM, OnUpdateInferenceString, NULL)
    ZEND_INI_ENTRY_EX("king.inference_gpu_model_name", "gemma4:12b", PHP_INI_SYSTEM, OnUpdateInferenceString, NULL)
    ZEND_INI_ENTRY_EX("king.inference_gpu_model_artifact", "", PHP_INI_SYSTEM, OnUpdateInferenceString, NULL)
    ZEND_INI_ENTRY_EX("king.inference_gpu_max_gpu_layers", "0", PHP_INI_SYSTEM, OnUpdateAiNonNegativeLong, NULL)
    ZEND_INI_ENTRY_EX("king.inference_gpu_vram_reserve_mb", "2048", PHP_INI_SYSTEM, OnUpdateAiNonNegativeLong, NULL)
    ZEND_INI_ENTRY_EX("king.inference_gpu_thermal_sensor_path", "", PHP_INI_SYSTEM, OnUpdateInferenceString, NULL)
    ZEND_INI_ENTRY_EX("king.inference_gpu_thermal_sensor_command", "", PHP_INI_SYSTEM, OnUpdateInferenceString, NULL)
    ZEND_INI_ENTRY_EX("king.inference_gpu_thermal_max_temperature_c", "78", PHP_INI_SYSTEM, OnUpdateAiPositiveDouble, NULL)
    STD_PHP_INI_ENTRY("king.inference_gpu_allow_unmonitored", "0", PHP_INI_SYSTEM, OnUpdateBool, inference_gpu_allow_unmonitored, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    STD_PHP_INI_ENTRY("king.inference_llm_cache_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, inference_llm_cache_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    ZEND_INI_ENTRY_EX("king.inference_llm_cache_path", "/tmp/king-llm-cache", PHP_INI_SYSTEM, OnUpdateInferenceString, NULL)
    ZEND_INI_ENTRY_EX("king.inference_llm_cache_min_free_mb", "5120", PHP_INI_SYSTEM, OnUpdateAiNonNegativeLong, NULL)
    STD_PHP_INI_ENTRY("king.inference_llm_cache_fail_closed", "1", PHP_INI_SYSTEM, OnUpdateBool, inference_llm_cache_fail_closed, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    ZEND_INI_ENTRY_EX("king.inference_llm_cache_disk_alert_webhook", "", PHP_INI_SYSTEM, OnUpdateInferenceString, NULL)
    ZEND_INI_ENTRY_EX("king.inference_llm_cache_disk_alert_mcp_service", "", PHP_INI_SYSTEM, OnUpdateInferenceString, NULL)
    ZEND_INI_ENTRY_EX("king.inference_llm_cache_disk_alert_mcp_method", "", PHP_INI_SYSTEM, OnUpdateInferenceString, NULL)

    STD_PHP_INI_ENTRY("king.gpu_bindings_enable", "0", PHP_INI_SYSTEM, OnUpdateBool, gpu_bindings_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    ZEND_INI_ENTRY_EX("king.gpu_default_backend", "auto", PHP_INI_SYSTEM, OnUpdateGpuBackend, NULL)
    ZEND_INI_ENTRY_EX("king.worker_gpu_affinity_map", "", PHP_INI_SYSTEM, OnUpdateWorkerGpuAffinityString, NULL)
    ZEND_INI_ENTRY_EX("king.gpu_memory_preallocation_mb", "2048", PHP_INI_SYSTEM, OnUpdateAiPositiveLong, NULL)
    STD_PHP_INI_ENTRY("king.gpu_p2p_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, gpu_p2p_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    STD_PHP_INI_ENTRY("king.gpu_storage_enable_directstorage", "0", PHP_INI_SYSTEM, OnUpdateBool, storage_enable_directstorage, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)

    STD_PHP_INI_ENTRY("king.cuda_enable_tensor_cores", "1", PHP_INI_SYSTEM, OnUpdateBool, cuda_enable_tensor_cores, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    ZEND_INI_ENTRY_EX("king.cuda_stream_pool_size", "4", PHP_INI_SYSTEM, OnUpdateAiPositiveLong, NULL)

    STD_PHP_INI_ENTRY("king.rocm_enable_gfx_optimizations", "1", PHP_INI_SYSTEM, OnUpdateBool, rocm_enable_gfx_optimizations, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    STD_PHP_INI_ENTRY("king.arc_enable_xmx_optimizations", "1", PHP_INI_SYSTEM, OnUpdateBool, arc_enable_xmx_optimizations, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
    STD_PHP_INI_ENTRY("king.arc_video_acceleration_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, arc_video_acceleration_enable, kg_high_perf_compute_ai_config_t, king_high_perf_compute_ai_config)
PHP_INI_END()


void kg_config_high_perf_compute_and_ai_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_high_perf_compute_and_ai_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
