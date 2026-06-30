/*
 * Internal CUDA attention output-projection plus residual compare for block-0.
 */

static zend_result king_inference_cuda_decoder_graph_executor_compare_block0_attention_projection_residual(
    king_inference_model_object *model,
    zval *graph,
    zend_string *stack_id,
    king_inference_cuda_device_ptr stack_output,
    zend_ulong stack_width,
    king_inference_cuda_device_ptr hidden_output,
    zend_ulong hidden_width,
    king_inference_cuda_device_ptr residual_output,
    zend_ulong residual_width,
    zval *residual_probe
) {
    zval *projection_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, stack_id, "linear");
    zend_string *weight_name = NULL;
    king_inference_tensor_native_view weight_view;
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    zend_ulong cols = 0;
    zend_ulong rows = 0;
    float *stack = NULL;
    float *hidden = NULL;
    float *actual = NULL;
    double *stack_d = NULL;
    double *expected = NULL;
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
    if (projection_op == NULL
        || king_inference_graph_required_string(projection_op, "weight", &weight_name) != SUCCESS
        || !zend_string_equals_literal(weight_name, "blk.0.attn_output.weight")) {
        return SUCCESS;
    }

    if (stack_output == 0
        || hidden_output == 0
        || residual_output == 0
        || stack_width == 0
        || hidden_width == 0
        || residual_width == 0
        || hidden_width != residual_width
        || (stack_width > 0 && hidden_width > (zend_ulong) -1 / stack_width)
        || stack_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || hidden_width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_projection_residual_numeric_compare");
        add_assoc_string(&compare, "status", "attention_projection_residual_compare_argument_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(residual_probe, "attention_projection_residual_numeric_compare_failed", true);
        add_assoc_zval(residual_probe, "attention_projection_residual_numeric_compare", &compare);
        return FAILURE;
    }
    if (king_inference_tensor_native_view_resolve(model, weight_name, &weight_view) != SUCCESS) {
        return FAILURE;
    }
    if (weight_view.rank != 2
        || king_inference_tensor_dimension_at(weight_view.dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(weight_view.dimensions, 1, &rows) != SUCCESS
        || cols != stack_width
        || rows < hidden_width) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_projection_residual_numeric_compare");
        add_assoc_string(&compare, "status", "attention_projection_residual_tensor_shape_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_str(&compare, "tensor", zend_string_copy(weight_name));
        add_assoc_long(&compare, "stack_width", (zend_long) stack_width);
        add_assoc_long(&compare, "hidden_width", (zend_long) hidden_width);
        add_assoc_long(&compare, "rows", (zend_long) rows);
        add_assoc_long(&compare, "cols", (zend_long) cols);
        add_assoc_bool(residual_probe, "attention_projection_residual_numeric_compare_failed", true);
        add_assoc_zval(residual_probe, "attention_projection_residual_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_projection_residual_numeric_compare");
        add_assoc_string(&compare, "status", "cuda_context_current_failed");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(residual_probe, "attention_projection_residual_numeric_compare_failed", true);
        add_assoc_zval(residual_probe, "attention_projection_residual_numeric_compare", &compare);
        return FAILURE;
    }
    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    if (cu_memcpy_dtoh == NULL) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_projection_residual_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(residual_probe, "attention_projection_residual_numeric_compare_failed", true);
        add_assoc_zval(residual_probe, "attention_projection_residual_numeric_compare", &compare);
        return FAILURE;
    }

    stack = emalloc((size_t) stack_width * sizeof(float));
    hidden = emalloc((size_t) hidden_width * sizeof(float));
    actual = emalloc((size_t) hidden_width * sizeof(float));
    stack_d = emalloc((size_t) stack_width * sizeof(double));
    expected = emalloc((size_t) hidden_width * sizeof(double));
    result = cu_memcpy_dtoh(stack, stack_output, (size_t) stack_width * sizeof(float));
    if (result == 0) {
        result = cu_memcpy_dtoh(hidden, hidden_output, (size_t) hidden_width * sizeof(float));
    }
    if (result == 0) {
        result = cu_memcpy_dtoh(actual, residual_output, (size_t) hidden_width * sizeof(float));
    }
    if (result != 0) {
        efree(expected);
        efree(stack_d);
        efree(actual);
        efree(hidden);
        efree(stack);
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_projection_residual_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_vector_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(residual_probe, "attention_projection_residual_numeric_compare_failed", true);
        add_assoc_zval(residual_probe, "attention_projection_residual_numeric_compare", &compare);
        return FAILURE;
    }

    for (zend_ulong i = 0; i < stack_width; i++) {
        stack_d[i] = (double) stack[i];
    }
    for (zend_ulong row = 0; row < hidden_width; row++) {
        zend_ulong row_base = row * stack_width;
        double projected = 0.0;
        double abs_value;

        if (king_inference_tensor_row_dot(&weight_view, row_base, stack_width, stack_d, &projected) != SUCCESS) {
            efree(expected);
            efree(stack_d);
            efree(actual);
            efree(hidden);
            efree(stack);
            return FAILURE;
        }
        expected[row] = projected + (double) hidden[row];
        abs_value = fabs(expected[row]);
        if (abs_value > max_expected_abs) {
            max_expected_abs = abs_value;
        }
    }

    tolerance = fmax(0.002, max_expected_abs * 0.00005);
    sample_limit = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    sample_limit = sample_limit < hidden_width ? sample_limit : hidden_width;
    array_init(&samples);
    for (zend_ulong i = 0; i < hidden_width; i++) {
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
    efree(stack_d);
    efree(actual);
    efree(hidden);
    efree(stack);
    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_attention_projection_residual_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_attention_output_projection_row_dot_plus_hidden");
    add_assoc_string(&compare, "status", matched ? "matched" : "mismatch");
    add_assoc_bool(&compare, "matched", matched);
    add_assoc_str(&compare, "tensor", zend_string_copy(weight_name));
    add_assoc_long(&compare, "compared_values", (zend_long) hidden_width);
    add_assoc_long(&compare, "matched_values", (zend_long) matched_values);
    add_assoc_long(&compare, "sampled_values", (zend_long) sample_limit);
    add_assoc_long(&compare, "stack_width", (zend_long) stack_width);
    add_assoc_long(&compare, "hidden_width", (zend_long) hidden_width);
    add_assoc_long(&compare, "residual_width", (zend_long) residual_width);
    add_assoc_long(&compare, "rows", (zend_long) rows);
    add_assoc_long(&compare, "cols", (zend_long) cols);
    add_assoc_double(&compare, "tolerance", tolerance);
    add_assoc_double(&compare, "max_abs_diff", max_abs_diff);
    add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
    add_assoc_zval(&compare, "samples", &samples);
    add_assoc_bool(residual_probe, "attention_projection_residual_numeric_compare_failed", !matched);
    add_assoc_zval(residual_probe, "attention_projection_residual_numeric_compare", &compare);
    if (!matched) {
        char message[256];

        snprintf(
            message,
            sizeof(message),
            "attention_projection_residual_mismatch tensor=%s compared=%lu matched=%lu max_abs_diff=%.9g tolerance=%.9g",
            ZSTR_VAL(weight_name),
            (unsigned long) hidden_width,
            (unsigned long) matched_values,
            max_abs_diff,
            tolerance
        );
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, message);
    }
    return matched ? SUCCESS : FAILURE;
}
