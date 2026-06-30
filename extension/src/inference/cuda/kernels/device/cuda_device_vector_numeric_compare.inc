/*
 * CUDA device-vector numeric compare diagnostics for native inference.
 */

static bool king_inference_cuda_device_vector_numeric_compare_enabled(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");
    zval *debug = gpu != NULL && Z_TYPE_P(gpu) == IS_ARRAY
        ? king_inference_array_find(gpu, "debug")
        : NULL;
    zval *enabled = debug != NULL && Z_TYPE_P(debug) == IS_ARRAY
        ? king_inference_array_find(debug, "numeric_compare_enabled")
        : NULL;

    if (enabled != NULL && (Z_TYPE_P(enabled) == IS_TRUE || Z_TYPE_P(enabled) == IS_FALSE)) {
        return zend_is_true(enabled);
    }

    return king_high_perf_compute_ai_config.inference_cuda_numeric_compare_enable;
}

static zend_ulong king_inference_cuda_device_vector_numeric_compare_max_values(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");
    zval *debug = gpu != NULL && Z_TYPE_P(gpu) == IS_ARRAY
        ? king_inference_array_find(gpu, "debug")
        : NULL;
    zval *configured = debug != NULL && Z_TYPE_P(debug) == IS_ARRAY
        ? king_inference_array_find(debug, "numeric_compare_max_values")
        : NULL;
    zend_long max_values = king_high_perf_compute_ai_config.inference_cuda_numeric_compare_max_values;

    if (configured != NULL && Z_TYPE_P(configured) == IS_LONG && Z_LVAL_P(configured) > 0) {
        max_values = Z_LVAL_P(configured);
    }
    if (max_values <= 0) {
        max_values = 1;
    }
    if (max_values > 1024) {
        max_values = 1024;
    }

    return (zend_ulong) max_values;
}

static void king_inference_cuda_device_vector_numeric_compare_status(
    king_inference_model_object *model,
    zval *return_value
) {
    bool enabled = king_inference_cuda_device_vector_numeric_compare_enabled(&model->config);
    bool available = enabled
        && model->cuda_driver_handle != NULL
        && model->cuda_device_allocator_available;
    zval tolerance_contract;
    zval reference_contract;

    array_init(return_value);
    add_assoc_bool(return_value, "enabled", enabled);
    add_assoc_bool(return_value, "available", available);
    add_assoc_bool(return_value, "public_api", false);
    add_assoc_bool(return_value, "unavailable_by_default", true);
    add_assoc_long(
        return_value,
        "max_values",
        (zend_long) king_inference_cuda_device_vector_numeric_compare_max_values(&model->config)
    );
    add_assoc_string(return_value, "scope", "internal_cuda_device_vector_readback_compare");
    add_assoc_string(return_value, "reference_backend", "king_internal_cpu_reference");
    add_assoc_bool(return_value, "active_runtime_backend", false);
    add_assoc_bool(return_value, "production_execution_path", false);
    add_assoc_string(return_value, "readback", "cuMemcpyDtoH");
    add_assoc_string(return_value, "status", enabled ? (available ? "ready" : "unavailable") : "disabled");
    king_inference_reference_backend_contract_array(KING_INFERENCE_BACKEND_KING_NATIVE_GPU, &reference_contract);
    add_assoc_zval(return_value, "reference_contract", &reference_contract);
    king_inference_cuda_numeric_tolerance_contract_table(&tolerance_contract);
    add_assoc_zval(return_value, "tolerance_contract", &tolerance_contract);
}

static zend_result king_inference_cuda_device_vector_numeric_compare(
    king_inference_model_object *model,
    const char *label,
    const char *op,
    zend_long layer,
    const char *tensor,
    zend_ulong sample_offset,
    king_inference_cuda_device_ptr device_vector,
    zend_ulong length,
    const float *expected,
    zend_ulong expected_length,
    double tolerance,
    zval *return_value
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    zend_ulong max_values;
    zend_ulong compare_count;
    size_t readback_bytes;
    float *actual;
    double max_abs_diff = 0.0;
    double max_relative_diff = 0.0;
    double relative_tolerance = king_inference_cuda_numeric_tolerance_relative(op);
    bool matched = true;
    zend_ulong matched_values = 0;
    zend_long first_failure_sample_index = -1;
    const char *first_failure_classification = "";
    int result;
    zval samples;

    array_init(return_value);
    add_assoc_string(return_value, "label", label != NULL ? label : "");
    add_assoc_long(return_value, "tolerance_contract_version", 1);
    add_assoc_string(return_value, "op", op != NULL ? op : "unknown");
    if (layer >= 0) {
        add_assoc_long(return_value, "layer", layer);
    } else {
        add_assoc_null(return_value, "layer");
    }
    if (tensor != NULL && tensor[0] != '\0') {
        add_assoc_string(return_value, "tensor", tensor);
    } else {
        add_assoc_null(return_value, "tensor");
    }
    add_assoc_bool(return_value, "enabled", king_inference_cuda_device_vector_numeric_compare_enabled(&model->config));
    add_assoc_bool(return_value, "public_api", false);
    add_assoc_string(return_value, "scope", "internal_cuda_device_vector_readback_compare");
    add_assoc_string(return_value, "match_rule", "abs_or_relative");
    add_assoc_string(return_value, "top_k_equivalence", king_inference_cuda_numeric_tolerance_top_k_rule(op));

    if (!king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        add_assoc_string(return_value, "status", "disabled");
        return SUCCESS;
    }
    if (device_vector == 0 || expected == NULL || length == 0 || expected_length == 0) {
        add_assoc_string(return_value, "status", "invalid_arguments");
        add_assoc_bool(return_value, "matched", false);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        add_assoc_string(return_value, "status", "cuda_context_current_failed");
        add_assoc_bool(return_value, "matched", false);
        return FAILURE;
    }

    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    if (cu_memcpy_dtoh == NULL) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "cuMemcpyDtoH_unavailable");
        add_assoc_string(return_value, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(return_value, "matched", false);
        return FAILURE;
    }

    max_values = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    compare_count = length < expected_length ? length : expected_length;
    compare_count = compare_count < max_values ? compare_count : max_values;
    readback_bytes = (size_t) compare_count * sizeof(float);
    actual = emalloc(readback_bytes);
    result = cu_memcpy_dtoh(actual, device_vector, readback_bytes);
    if (result != 0) {
        efree(actual);
        king_inference_cuda_device_vector_ops_set_error(model, result, "cuMemcpyDtoH_failed");
        add_assoc_string(return_value, "status", "cuMemcpyDtoH_failed");
        add_assoc_long(return_value, "result", result);
        add_assoc_bool(return_value, "matched", false);
        return FAILURE;
    }

    array_init(&samples);
    for (zend_ulong i = 0; i < compare_count; i++) {
        double diff = fabs((double) actual[i] - (double) expected[i]);
        double rel_diff = king_inference_cuda_numeric_relative_diff((double) actual[i], (double) expected[i]);
        const char *classification = king_inference_cuda_numeric_sample_classification(
            (double) actual[i],
            (double) expected[i],
            diff,
            rel_diff,
            tolerance,
            relative_tolerance
        );
        zval sample;

        if (king_inference_cuda_numeric_sample_matches(classification)) {
            matched_values++;
        } else {
            matched = false;
            if (first_failure_sample_index < 0) {
                first_failure_sample_index = (zend_long) (sample_offset + i);
                first_failure_classification = classification;
            }
        }
        if (diff > max_abs_diff) {
            max_abs_diff = diff;
        }
        if (rel_diff > max_relative_diff && isfinite(rel_diff)) {
            max_relative_diff = rel_diff;
        }

        array_init(&sample);
        add_assoc_long(&sample, "index", (zend_long) i);
        add_assoc_long(&sample, "sample_index", (zend_long) (sample_offset + i));
        add_assoc_string(&sample, "op", op != NULL ? op : "unknown");
        if (layer >= 0) {
            add_assoc_long(&sample, "layer", layer);
        } else {
            add_assoc_null(&sample, "layer");
        }
        if (tensor != NULL && tensor[0] != '\0') {
            add_assoc_string(&sample, "tensor", tensor);
        } else {
            add_assoc_null(&sample, "tensor");
        }
        add_assoc_double(&sample, "actual", (double) actual[i]);
        add_assoc_double(&sample, "expected", (double) expected[i]);
        add_assoc_double(&sample, "abs_diff", diff);
        add_assoc_double(&sample, "relative_diff", rel_diff);
        add_assoc_double(&sample, "max_abs_tolerance", tolerance);
        add_assoc_double(&sample, "max_relative_tolerance", relative_tolerance);
        add_assoc_string(&sample, "classification", classification);
        add_next_index_zval(&samples, &sample);
    }

    efree(actual);
    add_assoc_string(return_value, "status", matched ? "matched" : "mismatch");
    add_assoc_bool(return_value, "matched", matched);
    add_assoc_long(return_value, "compared_values", (zend_long) compare_count);
    add_assoc_long(return_value, "matched_values", (zend_long) matched_values);
    add_assoc_long(return_value, "readback_bytes", (zend_long) readback_bytes);
    add_assoc_double(return_value, "tolerance", tolerance);
    add_assoc_double(return_value, "max_abs_tolerance", tolerance);
    add_assoc_double(return_value, "max_relative_tolerance", relative_tolerance);
    add_assoc_double(return_value, "max_abs_diff", max_abs_diff);
    add_assoc_double(return_value, "max_relative_diff", max_relative_diff);
    if (first_failure_sample_index >= 0) {
        add_assoc_long(return_value, "first_failure_sample_index", first_failure_sample_index);
        add_assoc_string(return_value, "first_failure_classification", first_failure_classification);
    } else {
        add_assoc_null(return_value, "first_failure_sample_index");
        add_assoc_null(return_value, "first_failure_classification");
    }
    add_assoc_zval(return_value, "samples", &samples);

    return matched ? SUCCESS : FAILURE;
}
