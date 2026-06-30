/*
 * Internal CUDA final RMSNorm compare against CPU readback.
 */

static king_inference_cuda_memcpy_dtoh_fn
king_inference_cuda_decoder_graph_executor_final_norm_compare_dtoh(
    king_inference_model_object *model
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;

    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }

    return cu_memcpy_dtoh;
}

static void king_inference_cuda_decoder_graph_executor_final_norm_compare_fail(
    zval *final_norm_probe,
    const char *status
) {
    zval compare;

    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_final_norm_numeric_compare");
    add_assoc_string(&compare, "status", status);
    add_assoc_bool(&compare, "matched", false);
    add_assoc_bool(final_norm_probe, "final_norm_numeric_compare_failed", true);
    add_assoc_zval(final_norm_probe, "final_norm_numeric_compare", &compare);
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_final_norm(
    king_inference_model_object *model,
    zend_string *input_id,
    zend_string *final_norm_id,
    zend_string *weight_name,
    king_inference_cuda_device_ptr input_output,
    zend_ulong input_width,
    king_inference_cuda_device_ptr final_norm_output,
    zend_ulong final_norm_width,
    double epsilon,
    zval *final_norm_probe
) {
    king_inference_tensor_native_view weight_view;
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    float *input = NULL;
    float *actual = NULL;
    double *expected = NULL;
    double sumsq = 0.0;
    double scale;
    double max_expected_abs = 0.0;
    double max_abs_diff = 0.0;
    double tolerance;
    zend_ulong matched_values = 0;
    zend_ulong sample_limit;
    bool matched = true;
    int result;
    zval compare;
    zval samples;

    if (!king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }
    if (final_norm_id == NULL || !zend_string_equals_literal(final_norm_id, "final_norm")) {
        return SUCCESS;
    }
    if (weight_name == NULL
        || input_output == 0
        || final_norm_output == 0
        || input_width == 0
        || final_norm_width != input_width
        || input_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || epsilon <= 0.0) {
        king_inference_cuda_decoder_graph_executor_final_norm_compare_fail(
            final_norm_probe,
            "final_norm_compare_argument_invalid"
        );
        return FAILURE;
    }
    if (king_inference_tensor_native_view_resolve(model, weight_name, &weight_view) != SUCCESS) {
        return FAILURE;
    }
    if (weight_view.rank != 1 || weight_view.elements != input_width) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_final_norm_numeric_compare");
        add_assoc_string(&compare, "status", "final_norm_weight_shape_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_str(&compare, "weight_tensor", zend_string_copy(weight_name));
        add_assoc_long(&compare, "weight_elements", (zend_long) weight_view.elements);
        add_assoc_long(&compare, "input_width", (zend_long) input_width);
        add_assoc_bool(final_norm_probe, "final_norm_numeric_compare_failed", true);
        add_assoc_zval(final_norm_probe, "final_norm_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_decoder_graph_executor_final_norm_compare_fail(
            final_norm_probe,
            "cuda_context_current_failed"
        );
        return FAILURE;
    }
    cu_memcpy_dtoh = king_inference_cuda_decoder_graph_executor_final_norm_compare_dtoh(model);
    if (cu_memcpy_dtoh == NULL) {
        king_inference_cuda_decoder_graph_executor_final_norm_compare_fail(
            final_norm_probe,
            "cuMemcpyDtoH_unavailable"
        );
        return FAILURE;
    }

    input = emalloc((size_t) input_width * sizeof(float));
    actual = emalloc((size_t) input_width * sizeof(float));
    expected = emalloc((size_t) input_width * sizeof(double));
    result = cu_memcpy_dtoh(input, input_output, (size_t) input_width * sizeof(float));
    if (result == 0) {
        result = cu_memcpy_dtoh(actual, final_norm_output, (size_t) input_width * sizeof(float));
    }
    if (result != 0) {
        efree(expected);
        efree(actual);
        efree(input);
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_final_norm_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_vector_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(final_norm_probe, "final_norm_numeric_compare_failed", true);
        add_assoc_zval(final_norm_probe, "final_norm_numeric_compare", &compare);
        return FAILURE;
    }

    for (zend_ulong i = 0; i < input_width; i++) {
        sumsq += (double) input[i] * (double) input[i];
    }
    scale = 1.0 / king_inference_graph_sqrt((sumsq / (double) input_width) + epsilon);
    if (!isfinite(scale)) {
        efree(expected);
        efree(actual);
        efree(input);
        king_inference_cuda_decoder_graph_executor_final_norm_compare_fail(
            final_norm_probe,
            "final_norm_cpu_scale_invalid"
        );
        return FAILURE;
    }
    for (zend_ulong i = 0; i < input_width; i++) {
        double weight_value;
        double abs_value;

        if (king_inference_tensor_value_at(&weight_view, i, &weight_value) != SUCCESS) {
            efree(expected);
            efree(actual);
            efree(input);
            return FAILURE;
        }
        expected[i] = (double) input[i] * scale * weight_value;
        abs_value = fabs(expected[i]);
        if (abs_value > max_expected_abs) {
            max_expected_abs = abs_value;
        }
    }

    tolerance = fmax(0.0002, max_expected_abs * 0.00005);
    sample_limit = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    sample_limit = sample_limit < input_width ? sample_limit : input_width;
    array_init(&samples);
    for (zend_ulong i = 0; i < input_width; i++) {
        double diff = fabs((double) actual[i] - expected[i]);

        if (diff <= tolerance) {
            matched_values++;
        } else {
            matched = false;
        }
        if (diff > max_abs_diff) {
            max_abs_diff = diff;
        }
        if (i < sample_limit) {
            zval sample;

            array_init(&sample);
            add_assoc_long(&sample, "index", (zend_long) i);
            add_assoc_double(&sample, "actual", (double) actual[i]);
            add_assoc_double(&sample, "expected", expected[i]);
            add_assoc_double(&sample, "abs_diff", diff);
            add_next_index_zval(&samples, &sample);
        }
    }

    efree(expected);
    efree(actual);
    efree(input);
    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_final_norm_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_final_hidden_readback_rms_norm_weight");
    add_assoc_string(&compare, "status", matched ? "matched" : "mismatch");
    add_assoc_bool(&compare, "matched", matched);
    if (input_id != NULL) {
        add_assoc_str(&compare, "input", zend_string_copy(input_id));
    }
    add_assoc_str(&compare, "final_norm", zend_string_copy(final_norm_id));
    add_assoc_str(&compare, "weight_tensor", zend_string_copy(weight_name));
    add_assoc_long(&compare, "compared_values", (zend_long) input_width);
    add_assoc_long(&compare, "matched_values", (zend_long) matched_values);
    add_assoc_long(&compare, "sampled_values", (zend_long) sample_limit);
    add_assoc_long(&compare, "input_width", (zend_long) input_width);
    add_assoc_long(&compare, "final_norm_width", (zend_long) final_norm_width);
    add_assoc_double(&compare, "epsilon", epsilon);
    add_assoc_double(&compare, "scale", scale);
    add_assoc_double(&compare, "tolerance", tolerance);
    add_assoc_double(&compare, "max_abs_diff", max_abs_diff);
    add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
    add_assoc_zval(&compare, "samples", &samples);
    add_assoc_bool(final_norm_probe, "final_norm_numeric_compare_failed", !matched);
    add_assoc_zval(final_norm_probe, "final_norm_numeric_compare", &compare);
    if (!matched) {
        char message[256];

        snprintf(
            message,
            sizeof(message),
            "final_norm_mismatch tensor=%s compared=%lu matched=%lu max_abs_diff=%.9g tolerance=%.9g",
            ZSTR_VAL(weight_name),
            (unsigned long) input_width,
            (unsigned long) matched_values,
            max_abs_diff,
            tolerance
        );
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, message);
    }
    return matched ? SUCCESS : FAILURE;
}
