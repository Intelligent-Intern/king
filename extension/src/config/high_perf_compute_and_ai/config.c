/*
 * =========================================================================
 * FILENAME:   src/config/high_perf_compute_and_ai/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the high-perf compute / AI config
 * family. This file validates the narrow `King\\Config` subset that can
 * tune dataframe limits, GPU backend/affinity, memory preallocation, and
 * vendor-specific acceleration toggles on the live HPC snapshot.
 * =========================================================================
 */

#include "config/high_perf_compute_and_ai/config.h"
#include "config/high_perf_compute_and_ai/base_layer.h"
#include "php_king/globals.h"

#include "validation/config_param/validate_bool.h"
#include "validation/config_param/validate_cpu_affinity_map_string.h"
#include "validation/config_param/validate_positive_long.h"
#include "validation/config_param/validate_string_from_allowlist.h"

#include "php.h"
#include <Zend/zend_exceptions.h>
#include <ext/spl/spl_exceptions.h>
#include <math.h>

static int kg_high_perf_apply_bool_field(zval *value, const char *name, bool *target)
{
    if (kg_validate_bool(value, name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

static int kg_validate_non_negative_long_local(zval *value, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid type provided. An integer is required.");
        return FAILURE;
    }

    if (Z_LVAL_P(value) < 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value provided. A non-negative integer is required.");
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static const char *k_high_perf_gpu_backend_allowed[] = {"auto", "cuda", "rocm", "sycl", NULL};
static const char *k_high_perf_inference_profile_allowed[] = {"auto", "gpu", "cpu", NULL};

static int kg_high_perf_apply_string_field(zval *value, const char *name, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type for %s. A string is required.",
            name
        );
        return FAILURE;
    }

    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(Z_STRVAL_P(value), 1);
    return SUCCESS;
}

static int kg_high_perf_apply_positive_double_field(zval *value, const char *name, double *target)
{
    double number;

    if (Z_TYPE_P(value) == IS_LONG) {
        number = (double) Z_LVAL_P(value);
    } else if (Z_TYPE_P(value) == IS_DOUBLE) {
        number = Z_DVAL_P(value);
    } else {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type for %s. A positive finite number is required.",
            name
        );
        return FAILURE;
    }

    if (!isfinite(number) || number <= 0.0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for %s. A positive finite number is required.",
            name
        );
        return FAILURE;
    }

    *target = number;
    return SUCCESS;
}

static int kg_high_perf_apply_non_negative_double_field(zval *value, const char *name, double *target)
{
    double number;

    if (Z_TYPE_P(value) == IS_LONG) {
        number = (double) Z_LVAL_P(value);
    } else if (Z_TYPE_P(value) == IS_DOUBLE) {
        number = Z_DVAL_P(value);
    } else {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type for %s. A non-negative finite number is required.",
            name
        );
        return FAILURE;
    }

    if (!isfinite(number) || number < 0.0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for %s. A non-negative finite number is required.",
            name
        );
        return FAILURE;
    }

    *target = number;
    return SUCCESS;
}

int kg_config_high_perf_compute_and_ai_apply_userland_config_to(
    kg_high_perf_compute_ai_config_t *target,
    zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration must be provided as an array.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "dataframe_enable")) {
            if (kg_high_perf_apply_bool_field(value, "dataframe_enable", &target->dataframe_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "dataframe_memory_limit_mb")) {
            if (kg_validate_positive_long(value, &target->dataframe_memory_limit_mb) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "dataframe_string_interning_enable")) {
            if (kg_high_perf_apply_bool_field(value, "dataframe_string_interning_enable", &target->dataframe_string_interning_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "dataframe_cpu_parallelism_default")) {
            if (kg_validate_non_negative_long_local(value, &target->dataframe_cpu_parallelism_default) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_with_memory")) {
            if (kg_high_perf_apply_bool_field(value, "inference_with_memory", &target->inference_with_memory) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_context_tokens")) {
            if (kg_validate_positive_long(value, &target->inference_context_tokens) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_kv_page_tokens")) {
            if (kg_validate_positive_long(value, &target->inference_kv_page_tokens) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_kv_element_bytes")) {
            if (kg_validate_positive_long(value, &target->inference_kv_element_bytes) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_preferred_model_profile")) {
            if (kg_validate_string_from_allowlist(value, k_high_perf_inference_profile_allowed, &target->inference_preferred_model_profile) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_cpu_model_name")) {
            if (kg_high_perf_apply_string_field(value, "inference_cpu_model_name", &target->inference_cpu_model_name) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_cpu_model_artifact")) {
            if (kg_high_perf_apply_string_field(value, "inference_cpu_model_artifact", &target->inference_cpu_model_artifact) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_model_name")) {
            if (kg_high_perf_apply_string_field(value, "inference_gpu_model_name", &target->inference_gpu_model_name) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_model_artifact")) {
            if (kg_high_perf_apply_string_field(value, "inference_gpu_model_artifact", &target->inference_gpu_model_artifact) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_max_gpu_layers")) {
            if (kg_validate_non_negative_long_local(value, &target->inference_gpu_max_gpu_layers) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_vram_reserve_mb")) {
            if (kg_validate_non_negative_long_local(value, &target->inference_gpu_vram_reserve_mb) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_min_free_vram_mb")) {
            if (kg_validate_non_negative_long_local(value, &target->inference_gpu_min_free_vram_mb) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_thermal_sensor_path")) {
            if (kg_high_perf_apply_string_field(value, "inference_gpu_thermal_sensor_path", &target->inference_gpu_thermal_sensor_path) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_thermal_sensor_command")) {
            if (kg_high_perf_apply_string_field(value, "inference_gpu_thermal_sensor_command", &target->inference_gpu_thermal_sensor_command) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_thermal_max_temperature_c")) {
            if (kg_high_perf_apply_positive_double_field(value, "inference_gpu_thermal_max_temperature_c", &target->inference_gpu_thermal_max_temperature_c) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_thermal_check_interval_sec")) {
            if (kg_validate_non_negative_long_local(value, &target->inference_gpu_thermal_check_interval_sec) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_allow_unmonitored")) {
            if (kg_high_perf_apply_bool_field(value, "inference_gpu_allow_unmonitored", &target->inference_gpu_allow_unmonitored) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_power_sensor_command")) {
            if (kg_high_perf_apply_string_field(value, "inference_gpu_power_sensor_command", &target->inference_gpu_power_sensor_command) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_power_max_watts")) {
            if (kg_high_perf_apply_non_negative_double_field(value, "inference_gpu_power_max_watts", &target->inference_gpu_power_max_watts) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_power_check_interval_sec")) {
            if (kg_validate_non_negative_long_local(value, &target->inference_gpu_power_check_interval_sec) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_gpu_batch_prefill_experimental_enable")) {
            if (kg_high_perf_apply_bool_field(value, "inference_gpu_batch_prefill_experimental_enable", &target->inference_gpu_batch_prefill_experimental_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_cuda_numeric_compare_enable")) {
            if (kg_high_perf_apply_bool_field(value, "inference_cuda_numeric_compare_enable", &target->inference_cuda_numeric_compare_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_cuda_numeric_compare_max_values")) {
            if (kg_validate_positive_long(value, &target->inference_cuda_numeric_compare_max_values) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_llm_cache_enable")) {
            if (kg_high_perf_apply_bool_field(value, "inference_llm_cache_enable", &target->inference_llm_cache_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_llm_cache_path")) {
            if (kg_high_perf_apply_string_field(value, "inference_llm_cache_path", &target->inference_llm_cache_path) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_llm_cache_min_free_mb")) {
            if (kg_validate_non_negative_long_local(value, &target->inference_llm_cache_min_free_mb) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_llm_cache_fail_closed")) {
            if (kg_high_perf_apply_bool_field(value, "inference_llm_cache_fail_closed", &target->inference_llm_cache_fail_closed) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_llm_cache_disk_alert_webhook")) {
            if (kg_high_perf_apply_string_field(value, "inference_llm_cache_disk_alert_webhook", &target->inference_llm_cache_disk_alert_webhook) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_llm_cache_disk_alert_mcp_service")) {
            if (kg_high_perf_apply_string_field(value, "inference_llm_cache_disk_alert_mcp_service", &target->inference_llm_cache_disk_alert_mcp_service) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "inference_llm_cache_disk_alert_mcp_method")) {
            if (kg_high_perf_apply_string_field(value, "inference_llm_cache_disk_alert_mcp_method", &target->inference_llm_cache_disk_alert_mcp_method) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "gpu_bindings_enable")) {
            if (kg_high_perf_apply_bool_field(value, "gpu_bindings_enable", &target->gpu_bindings_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "gpu_default_backend")) {
            if (kg_validate_string_from_allowlist(value, k_high_perf_gpu_backend_allowed, &target->gpu_default_backend) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "worker_gpu_affinity_map")) {
            if (kg_validate_cpu_affinity_map_string(value, &target->worker_gpu_affinity_map) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "gpu_memory_preallocation_mb")) {
            if (kg_validate_positive_long(value, &target->gpu_memory_preallocation_mb) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "gpu_p2p_enable")) {
            if (kg_high_perf_apply_bool_field(value, "gpu_p2p_enable", &target->gpu_p2p_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "storage_enable_directstorage")) {
            if (kg_high_perf_apply_bool_field(value, "storage_enable_directstorage", &target->storage_enable_directstorage) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "cuda_enable_tensor_cores")) {
            if (kg_high_perf_apply_bool_field(value, "cuda_enable_tensor_cores", &target->cuda_enable_tensor_cores) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "cuda_stream_pool_size")) {
            if (kg_validate_positive_long(value, &target->cuda_stream_pool_size) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "rocm_enable_gfx_optimizations")) {
            if (kg_high_perf_apply_bool_field(value, "rocm_enable_gfx_optimizations", &target->rocm_enable_gfx_optimizations) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "arc_enable_xmx_optimizations")) {
            if (kg_high_perf_apply_bool_field(value, "arc_enable_xmx_optimizations", &target->arc_enable_xmx_optimizations) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "arc_video_acceleration_enable")) {
            if (kg_high_perf_apply_bool_field(value, "arc_video_acceleration_enable", &target->arc_video_acceleration_enable) != SUCCESS) return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_high_perf_compute_and_ai_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    return kg_config_high_perf_compute_and_ai_apply_userland_config_to(
        &king_high_perf_compute_ai_config,
        config_arr
    );
}
