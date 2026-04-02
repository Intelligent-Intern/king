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

#include "include/config/high_perf_compute_and_ai/config.h"
#include "include/config/high_perf_compute_and_ai/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_cpu_affinity_map_string.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"

#include "php.h"
#include <Zend/zend_exceptions.h>
#include <ext/spl/spl_exceptions.h>

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

int kg_config_high_perf_compute_and_ai_apply_userland_config(zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

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
            if (kg_high_perf_apply_bool_field(value, "dataframe_enable", &king_high_perf_compute_ai_config.dataframe_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "dataframe_memory_limit_mb")) {
            if (kg_validate_positive_long(value, &king_high_perf_compute_ai_config.dataframe_memory_limit_mb) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "dataframe_string_interning_enable")) {
            if (kg_high_perf_apply_bool_field(value, "dataframe_string_interning_enable", &king_high_perf_compute_ai_config.dataframe_string_interning_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "dataframe_cpu_parallelism_default")) {
            if (kg_validate_non_negative_long_local(value, &king_high_perf_compute_ai_config.dataframe_cpu_parallelism_default) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "gpu_bindings_enable")) {
            if (kg_high_perf_apply_bool_field(value, "gpu_bindings_enable", &king_high_perf_compute_ai_config.gpu_bindings_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "gpu_default_backend")) {
            if (kg_validate_string_from_allowlist(value, k_high_perf_gpu_backend_allowed, &king_high_perf_compute_ai_config.gpu_default_backend) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "worker_gpu_affinity_map")) {
            if (kg_validate_cpu_affinity_map_string(value, &king_high_perf_compute_ai_config.worker_gpu_affinity_map) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "gpu_memory_preallocation_mb")) {
            if (kg_validate_positive_long(value, &king_high_perf_compute_ai_config.gpu_memory_preallocation_mb) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "gpu_p2p_enable")) {
            if (kg_high_perf_apply_bool_field(value, "gpu_p2p_enable", &king_high_perf_compute_ai_config.gpu_p2p_enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "storage_enable_directstorage")) {
            if (kg_high_perf_apply_bool_field(value, "storage_enable_directstorage", &king_high_perf_compute_ai_config.storage_enable_directstorage) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "cuda_enable_tensor_cores")) {
            if (kg_high_perf_apply_bool_field(value, "cuda_enable_tensor_cores", &king_high_perf_compute_ai_config.cuda_enable_tensor_cores) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "cuda_stream_pool_size")) {
            if (kg_validate_positive_long(value, &king_high_perf_compute_ai_config.cuda_stream_pool_size) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "rocm_enable_gfx_optimizations")) {
            if (kg_high_perf_apply_bool_field(value, "rocm_enable_gfx_optimizations", &king_high_perf_compute_ai_config.rocm_enable_gfx_optimizations) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "arc_enable_xmx_optimizations")) {
            if (kg_high_perf_apply_bool_field(value, "arc_enable_xmx_optimizations", &king_high_perf_compute_ai_config.arc_enable_xmx_optimizations) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "arc_video_acceleration_enable")) {
            if (kg_high_perf_apply_bool_field(value, "arc_video_acceleration_enable", &king_high_perf_compute_ai_config.arc_video_acceleration_enable) != SUCCESS) return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
