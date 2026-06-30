/*
 * Internal CUDA FFN SwiGLU compare for block-0.
 */

static double king_inference_cuda_decoder_graph_executor_cpu_silu(double value)
{
    if (value >= 0.0) {
        double e = exp(-value);
        return value / (1.0 + e);
    }

    {
        double e = exp(value);
        return (value * e) / (1.0 + e);
    }
}

static double king_inference_cuda_decoder_graph_executor_cpu_gelu_tanh(double value)
{
    return 0.5 * value * (1.0 + tanh(0.7978845608028654 * (value + 0.044715 * value * value * value)));
}

static double king_inference_cuda_decoder_graph_executor_cpu_ffn_activation(double value, zend_ulong activation)
{
    return activation == 1
        ? king_inference_cuda_decoder_graph_executor_cpu_gelu_tanh(value)
        : king_inference_cuda_decoder_graph_executor_cpu_silu(value);
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_block0_ffn_swiglu(
    king_inference_model_object *model,
    zend_string *gate_id,
    king_inference_cuda_device_ptr gate_output,
    zend_ulong gate_rows,
    zend_string *up_id,
    king_inference_cuda_device_ptr up_output,
    zend_ulong up_rows,
    king_inference_cuda_device_ptr swiglu_output,
    zend_ulong swiglu_width,
    zend_ulong activation,
    zval *ffn_swiglu_probe
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    float *gate = NULL;
    float *up = NULL;
    float *actual = NULL;
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
    if (gate_id == NULL
        || up_id == NULL
        || !zend_string_equals_literal(gate_id, "l0_ffn_gate")
        || !zend_string_equals_literal(up_id, "l0_ffn_up")) {
        return SUCCESS;
    }

    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_ffn_swiglu_numeric_compare");
    add_assoc_string(&compare, "reference", activation == 1 ? "cpu_gelu_tanh_gate_times_up_readback" : "cpu_silu_gate_times_up_readback");
    add_assoc_long(&compare, "activation", (zend_long) activation);
    if (gate_output == 0
        || up_output == 0
        || swiglu_output == 0
        || gate_rows == 0
        || gate_rows != up_rows
        || gate_rows != swiglu_width
        || gate_rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        add_assoc_string(&compare, "status", "ffn_swiglu_compare_argument_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_swiglu_probe, "ffn_swiglu_numeric_compare_failed", true);
        add_assoc_zval(ffn_swiglu_probe, "ffn_swiglu_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        add_assoc_string(&compare, "status", "cuda_context_current_failed");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_swiglu_probe, "ffn_swiglu_numeric_compare_failed", true);
        add_assoc_zval(ffn_swiglu_probe, "ffn_swiglu_numeric_compare", &compare);
        return FAILURE;
    }
    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    if (cu_memcpy_dtoh == NULL) {
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_swiglu_probe, "ffn_swiglu_numeric_compare_failed", true);
        add_assoc_zval(ffn_swiglu_probe, "ffn_swiglu_numeric_compare", &compare);
        return FAILURE;
    }

    gate = emalloc((size_t) gate_rows * sizeof(float));
    up = emalloc((size_t) up_rows * sizeof(float));
    actual = emalloc((size_t) swiglu_width * sizeof(float));
    result = cu_memcpy_dtoh(gate, gate_output, (size_t) gate_rows * sizeof(float));
    if (result == 0) {
        result = cu_memcpy_dtoh(up, up_output, (size_t) up_rows * sizeof(float));
    }
    if (result == 0) {
        result = cu_memcpy_dtoh(actual, swiglu_output, (size_t) swiglu_width * sizeof(float));
    }
    if (result != 0) {
        efree(actual);
        efree(up);
        efree(gate);
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_vector_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_swiglu_probe, "ffn_swiglu_numeric_compare_failed", true);
        add_assoc_zval(ffn_swiglu_probe, "ffn_swiglu_numeric_compare", &compare);
        return FAILURE;
    }

    sample_limit = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    sample_limit = sample_limit < swiglu_width ? sample_limit : swiglu_width;
    array_init(&samples);
    for (zend_ulong i = 0; i < swiglu_width; i++) {
        double expected = king_inference_cuda_decoder_graph_executor_cpu_ffn_activation((double) gate[i], activation) * (double) up[i];
        double diff = fabs((double) actual[i] - expected);
        double abs_expected = fabs(expected);

        if (abs_expected > max_expected_abs) {
            max_expected_abs = abs_expected;
        }
        if (diff > max_abs_diff) {
            max_abs_diff = diff;
        }
        if (i < sample_limit) {
            zval sample;

            array_init(&sample);
            add_assoc_long(&sample, "index", (zend_long) i);
            add_assoc_double(&sample, "actual", (double) actual[i]);
            add_assoc_double(&sample, "expected", expected);
            add_assoc_double(&sample, "abs_diff", diff);
            add_next_index_zval(&samples, &sample);
        }
    }
    tolerance = fmax(0.0005, max_expected_abs * 0.00005);
    for (zend_ulong i = 0; i < swiglu_width; i++) {
        double expected = king_inference_cuda_decoder_graph_executor_cpu_ffn_activation((double) gate[i], activation) * (double) up[i];
        double diff = fabs((double) actual[i] - expected);

        if (diff <= tolerance) {
            matched_values++;
        } else {
            matched = false;
        }
    }

    efree(actual);
    efree(up);
    efree(gate);
    add_assoc_string(&compare, "status", matched ? "matched" : "mismatch");
    add_assoc_bool(&compare, "matched", matched);
    add_assoc_long(&compare, "compared_values", (zend_long) swiglu_width);
    add_assoc_long(&compare, "matched_values", (zend_long) matched_values);
    add_assoc_long(&compare, "width", (zend_long) swiglu_width);
    add_assoc_long(&compare, "sampled_values", (zend_long) sample_limit);
    add_assoc_double(&compare, "tolerance", tolerance);
    add_assoc_double(&compare, "max_abs_diff", max_abs_diff);
    add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
    add_assoc_zval(&compare, "samples", &samples);
    add_assoc_bool(ffn_swiglu_probe, "ffn_swiglu_numeric_compare_failed", !matched);
    add_assoc_zval(ffn_swiglu_probe, "ffn_swiglu_numeric_compare", &compare);
    if (!matched) {
        char message[256];

        snprintf(
            message,
            sizeof(message),
            "ffn_swiglu_mismatch compared=%lu matched=%lu max_abs_diff=%.9g tolerance=%.9g",
            (unsigned long) swiglu_width,
            (unsigned long) matched_values,
            max_abs_diff,
            tolerance
        );
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, message);
    }
    return matched ? SUCCESS : FAILURE;
}
