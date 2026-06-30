/*
 * Internal CUDA FFN RMSNorm compare for block-0.
 */

static zend_result king_inference_cuda_decoder_graph_executor_compare_block0_ffn_norm(
    king_inference_model_object *model,
    zend_string *weight_name,
    king_inference_cuda_device_ptr residual_output,
    zend_ulong residual_width,
    king_inference_cuda_device_ptr ffn_norm_output,
    zend_ulong ffn_norm_width,
    double epsilon,
    zval *ffn_norm_probe
) {
    king_inference_tensor_native_view weight_view;
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    float *residual = NULL;
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
    if (weight_name == NULL || !zend_string_equals_literal(weight_name, "blk.0.ffn_norm.weight")) {
        return SUCCESS;
    }
    if (residual_output == 0
        || ffn_norm_output == 0
        || residual_width == 0
        || ffn_norm_width != residual_width
        || residual_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || epsilon <= 0.0) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_ffn_norm_numeric_compare");
        add_assoc_string(&compare, "status", "ffn_norm_compare_argument_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_norm_probe, "ffn_norm_numeric_compare_failed", true);
        add_assoc_zval(ffn_norm_probe, "ffn_norm_numeric_compare", &compare);
        return FAILURE;
    }
    if (king_inference_tensor_native_view_resolve(model, weight_name, &weight_view) != SUCCESS) {
        return FAILURE;
    }
    if (weight_view.rank != 1 || weight_view.elements != residual_width) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_ffn_norm_numeric_compare");
        add_assoc_string(&compare, "status", "ffn_norm_weight_shape_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_str(&compare, "weight_tensor", zend_string_copy(weight_name));
        add_assoc_long(&compare, "weight_elements", (zend_long) weight_view.elements);
        add_assoc_long(&compare, "residual_width", (zend_long) residual_width);
        add_assoc_bool(ffn_norm_probe, "ffn_norm_numeric_compare_failed", true);
        add_assoc_zval(ffn_norm_probe, "ffn_norm_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_ffn_norm_numeric_compare");
        add_assoc_string(&compare, "status", "cuda_context_current_failed");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_norm_probe, "ffn_norm_numeric_compare_failed", true);
        add_assoc_zval(ffn_norm_probe, "ffn_norm_numeric_compare", &compare);
        return FAILURE;
    }
    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    if (cu_memcpy_dtoh == NULL) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_ffn_norm_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_norm_probe, "ffn_norm_numeric_compare_failed", true);
        add_assoc_zval(ffn_norm_probe, "ffn_norm_numeric_compare", &compare);
        return FAILURE;
    }

    residual = emalloc((size_t) residual_width * sizeof(float));
    actual = emalloc((size_t) residual_width * sizeof(float));
    expected = emalloc((size_t) residual_width * sizeof(double));
    result = cu_memcpy_dtoh(residual, residual_output, (size_t) residual_width * sizeof(float));
    if (result == 0) {
        result = cu_memcpy_dtoh(actual, ffn_norm_output, (size_t) residual_width * sizeof(float));
    }
    if (result != 0) {
        efree(expected);
        efree(actual);
        efree(residual);
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_ffn_norm_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_vector_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_norm_probe, "ffn_norm_numeric_compare_failed", true);
        add_assoc_zval(ffn_norm_probe, "ffn_norm_numeric_compare", &compare);
        return FAILURE;
    }

    for (zend_ulong i = 0; i < residual_width; i++) {
        sumsq += (double) residual[i] * (double) residual[i];
    }
    scale = 1.0 / king_inference_graph_sqrt((sumsq / (double) residual_width) + epsilon);
    if (!isfinite(scale)) {
        efree(expected);
        efree(actual);
        efree(residual);
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_ffn_norm_numeric_compare");
        add_assoc_string(&compare, "status", "ffn_norm_cpu_scale_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_norm_probe, "ffn_norm_numeric_compare_failed", true);
        add_assoc_zval(ffn_norm_probe, "ffn_norm_numeric_compare", &compare);
        return FAILURE;
    }
    for (zend_ulong i = 0; i < residual_width; i++) {
        double weight_value;
        double abs_value;

        if (king_inference_tensor_value_at(&weight_view, i, &weight_value) != SUCCESS) {
            efree(expected);
            efree(actual);
            efree(residual);
            return FAILURE;
        }
        expected[i] = (double) residual[i] * scale * weight_value;
        abs_value = fabs(expected[i]);
        if (abs_value > max_expected_abs) {
            max_expected_abs = abs_value;
        }
    }

    tolerance = fmax(0.0002, max_expected_abs * 0.00005);
    sample_limit = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    sample_limit = sample_limit < residual_width ? sample_limit : residual_width;
    array_init(&samples);
    for (zend_ulong i = 0; i < residual_width; i++) {
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
    efree(residual);
    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_ffn_norm_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_attention_residual_readback_rms_norm_weight");
    add_assoc_string(&compare, "status", matched ? "matched" : "mismatch");
    add_assoc_bool(&compare, "matched", matched);
    add_assoc_str(&compare, "weight_tensor", zend_string_copy(weight_name));
    add_assoc_long(&compare, "compared_values", (zend_long) residual_width);
    add_assoc_long(&compare, "matched_values", (zend_long) matched_values);
    add_assoc_long(&compare, "sampled_values", (zend_long) sample_limit);
    add_assoc_long(&compare, "residual_width", (zend_long) residual_width);
    add_assoc_long(&compare, "ffn_norm_width", (zend_long) ffn_norm_width);
    add_assoc_double(&compare, "epsilon", epsilon);
    add_assoc_double(&compare, "scale", scale);
    add_assoc_double(&compare, "tolerance", tolerance);
    add_assoc_double(&compare, "max_abs_diff", max_abs_diff);
    add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
    add_assoc_zval(&compare, "samples", &samples);
    add_assoc_bool(ffn_norm_probe, "ffn_norm_numeric_compare_failed", !matched);
    add_assoc_zval(ffn_norm_probe, "ffn_norm_numeric_compare", &compare);
    if (!matched) {
        char message[256];

        snprintf(
            message,
            sizeof(message),
            "ffn_norm_mismatch tensor=%s compared=%lu matched=%lu max_abs_diff=%.9g tolerance=%.9g",
            ZSTR_VAL(weight_name),
            (unsigned long) residual_width,
            (unsigned long) matched_values,
            max_abs_diff,
            tolerance
        );
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, message);
    }
    return matched ? SUCCESS : FAILURE;
}
