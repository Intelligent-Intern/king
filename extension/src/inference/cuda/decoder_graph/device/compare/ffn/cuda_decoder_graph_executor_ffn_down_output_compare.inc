/*
 * Internal CUDA FFN down-projection and output-residual compares for selected blocks.
 */

static bool king_inference_cuda_decoder_graph_executor_parse_layer_id(
    zend_string *value,
    const char *suffix,
    zend_ulong *layer
) {
    const char *cursor;
    const char *end;
    char *parse_end;
    unsigned long parsed;
    size_t suffix_len = strlen(suffix);

    if (value == NULL || ZSTR_LEN(value) <= 1 + suffix_len || ZSTR_VAL(value)[0] != 'l') {
        return false;
    }
    if (memcmp(ZSTR_VAL(value) + ZSTR_LEN(value) - suffix_len, suffix, suffix_len) != 0) {
        return false;
    }
    cursor = ZSTR_VAL(value) + 1;
    end = ZSTR_VAL(value) + ZSTR_LEN(value) - suffix_len;
    if (cursor >= end) {
        return false;
    }
    parsed = strtoul(cursor, &parse_end, 10);
    if (parse_end != end) {
        return false;
    }
    *layer = (zend_ulong) parsed;
    return true;
}

static bool king_inference_cuda_decoder_graph_executor_compare_selected_ffn_layer(
    king_inference_model_object *model,
    zend_ulong layer
) {
    zend_ulong block_count = model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT];

    return layer == 0 || (block_count > 0 && layer + 1 == block_count);
}

static king_inference_cuda_memcpy_dtoh_fn king_inference_cuda_decoder_graph_executor_compare_dtoh(
    king_inference_model_object *model
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;

    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    return cu_memcpy_dtoh;
}

static void king_inference_cuda_decoder_graph_executor_compare_down_set_error(
    king_inference_model_object *model,
    const char *prefix,
    zend_ulong compared,
    zend_ulong matched,
    double max_abs_diff,
    double tolerance
) {
    char message[256];

    snprintf(
        message,
        sizeof(message),
        "%s compared=%lu matched=%lu max_abs_diff=%.9g tolerance=%.9g",
        prefix,
        (unsigned long) compared,
        (unsigned long) matched,
        max_abs_diff,
        tolerance
    );
    king_inference_cuda_decoder_graph_executor_set_error(model, -1, message);
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_block0_ffn_down_projection(
    king_inference_model_object *model,
    zval *down_op,
    king_inference_cuda_device_ptr swiglu_output,
    zend_ulong swiglu_width,
    king_inference_cuda_device_ptr down_output,
    zend_ulong down_width,
    zval *ffn_down_probe
) {
    zend_string *weight_name = NULL;
    zend_string *down_id = NULL;
    king_inference_tensor_native_view weight_view;
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    zend_ulong cols = 0;
    zend_ulong rows = 0;
    zend_ulong layer = 0;
    zend_ulong block_count = model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT];
    float *swiglu = NULL;
    float *actual = NULL;
    double *input = NULL;
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
    if (down_op == NULL
        || king_inference_graph_required_string(down_op, "id", &down_id) != SUCCESS
        || king_inference_graph_required_string(down_op, "weight", &weight_name) != SUCCESS
        || !king_inference_cuda_decoder_graph_executor_parse_layer_id(down_id, "_ffn_down", &layer)
        || !king_inference_cuda_decoder_graph_executor_compare_selected_ffn_layer(model, layer)) {
        return SUCCESS;
    }

    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_ffn_down_projection_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_ffn_down_row_dot_from_swiglu_readback");
    add_assoc_str(&compare, "down", zend_string_copy(down_id));
    add_assoc_str(&compare, "tensor", zend_string_copy(weight_name));
    add_assoc_long(&compare, "layer", (zend_long) layer);
    add_assoc_long(&compare, "block_count", (zend_long) block_count);
    add_assoc_bool(&compare, "last_block", block_count > 0 && layer + 1 == block_count);
    if (swiglu_output == 0
        || down_output == 0
        || swiglu_width == 0
        || down_width == 0
        || swiglu_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || down_width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        add_assoc_string(&compare, "status", "ffn_down_compare_argument_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_down_probe, "ffn_down_numeric_compare_failed", true);
        add_assoc_zval(ffn_down_probe, "ffn_down_numeric_compare", &compare);
        return FAILURE;
    }
    if (king_inference_tensor_native_view_resolve(model, weight_name, &weight_view) != SUCCESS) {
        zval_ptr_dtor(&compare);
        return FAILURE;
    }
    if (weight_view.rank != 2
        || king_inference_tensor_dimension_at(weight_view.dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(weight_view.dimensions, 1, &rows) != SUCCESS
        || cols != swiglu_width
        || rows != down_width) {
        add_assoc_string(&compare, "status", "ffn_down_tensor_shape_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_long(&compare, "swiglu_width", (zend_long) swiglu_width);
        add_assoc_long(&compare, "down_width", (zend_long) down_width);
        add_assoc_long(&compare, "rows", (zend_long) rows);
        add_assoc_long(&compare, "cols", (zend_long) cols);
        add_assoc_bool(ffn_down_probe, "ffn_down_numeric_compare_failed", true);
        add_assoc_zval(ffn_down_probe, "ffn_down_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        add_assoc_string(&compare, "status", "cuda_context_current_failed");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_down_probe, "ffn_down_numeric_compare_failed", true);
        add_assoc_zval(ffn_down_probe, "ffn_down_numeric_compare", &compare);
        return FAILURE;
    }
    cu_memcpy_dtoh = king_inference_cuda_decoder_graph_executor_compare_dtoh(model);
    if (cu_memcpy_dtoh == NULL) {
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_down_probe, "ffn_down_numeric_compare_failed", true);
        add_assoc_zval(ffn_down_probe, "ffn_down_numeric_compare", &compare);
        return FAILURE;
    }

    swiglu = emalloc((size_t) swiglu_width * sizeof(float));
    actual = emalloc((size_t) down_width * sizeof(float));
    input = emalloc((size_t) swiglu_width * sizeof(double));
    result = cu_memcpy_dtoh(swiglu, swiglu_output, (size_t) swiglu_width * sizeof(float));
    if (result == 0) {
        result = cu_memcpy_dtoh(actual, down_output, (size_t) down_width * sizeof(float));
    }
    if (result != 0) {
        efree(input);
        efree(actual);
        efree(swiglu);
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_vector_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_down_probe, "ffn_down_numeric_compare_failed", true);
        add_assoc_zval(ffn_down_probe, "ffn_down_numeric_compare", &compare);
        return FAILURE;
    }
    for (zend_ulong i = 0; i < swiglu_width; i++) {
        input[i] = (double) swiglu[i];
    }

    tolerance = 0.003;
    sample_limit = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    sample_limit = sample_limit < down_width ? sample_limit : down_width;
    array_init(&samples);
    for (zend_ulong row = 0; row < down_width; row++) {
        double expected = 0.0;
        double diff;
        double abs_expected;

        if (king_inference_tensor_row_dot(&weight_view, row * cols, cols, input, &expected) != SUCCESS) {
            zval_ptr_dtor(&samples);
            efree(input);
            efree(actual);
            efree(swiglu);
            zval_ptr_dtor(&compare);
            return FAILURE;
        }
        diff = fabs((double) actual[row] - expected);
        abs_expected = fabs(expected);
        if (diff <= tolerance) {
            matched_values++;
        } else {
            matched = false;
        }
        if (diff > max_abs_diff) {
            max_abs_diff = diff;
        }
        if (abs_expected > max_expected_abs) {
            max_expected_abs = abs_expected;
        }
        if (row < sample_limit) {
            zval sample;

            array_init(&sample);
            add_assoc_long(&sample, "index", (zend_long) row);
            add_assoc_double(&sample, "actual", (double) actual[row]);
            add_assoc_double(&sample, "expected", expected);
            add_assoc_double(&sample, "abs_diff", diff);
            add_next_index_zval(&samples, &sample);
        }
    }

    efree(input);
    efree(actual);
    efree(swiglu);
    add_assoc_string(&compare, "status", matched ? "matched" : "mismatch");
    add_assoc_bool(&compare, "matched", matched);
    add_assoc_long(&compare, "compared_values", (zend_long) down_width);
    add_assoc_long(&compare, "matched_values", (zend_long) matched_values);
    add_assoc_long(&compare, "swiglu_width", (zend_long) swiglu_width);
    add_assoc_long(&compare, "down_width", (zend_long) down_width);
    add_assoc_long(&compare, "sampled_values", (zend_long) sample_limit);
    add_assoc_double(&compare, "tolerance", tolerance);
    add_assoc_double(&compare, "max_abs_diff", max_abs_diff);
    add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
    add_assoc_zval(&compare, "samples", &samples);
    add_assoc_bool(ffn_down_probe, "ffn_down_numeric_compare_failed", !matched);
    add_assoc_zval(ffn_down_probe, "ffn_down_numeric_compare", &compare);
    if (!matched) {
        king_inference_cuda_decoder_graph_executor_compare_down_set_error(
            model,
            "ffn_down_mismatch",
            down_width,
            matched_values,
            max_abs_diff,
            tolerance
        );
    }
    return matched ? SUCCESS : FAILURE;
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_block0_ffn_output_residual(
    king_inference_model_object *model,
    zend_string *residual_id,
    king_inference_cuda_device_ptr residual_output,
    zend_ulong residual_width,
    zend_string *down_id,
    king_inference_cuda_device_ptr down_output,
    zend_ulong down_width,
    zend_string *output_id,
    king_inference_cuda_device_ptr output,
    zend_ulong output_width,
    zval *ffn_output_probe
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    zend_ulong layer = 0;
    zend_ulong block_count = model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT];
    float *residual = NULL;
    float *down = NULL;
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
    if (down_id == NULL
        || !king_inference_cuda_decoder_graph_executor_parse_layer_id(down_id, "_ffn_down", &layer)
        || !king_inference_cuda_decoder_graph_executor_compare_selected_ffn_layer(model, layer)) {
        return SUCCESS;
    }

    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_ffn_output_residual_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_attention_residual_plus_ffn_down_readback");
    add_assoc_long(&compare, "layer", (zend_long) layer);
    add_assoc_long(&compare, "block_count", (zend_long) block_count);
    add_assoc_bool(&compare, "last_block", block_count > 0 && layer + 1 == block_count);
    if (residual_id != NULL) {
        add_assoc_str(&compare, "residual", zend_string_copy(residual_id));
    }
    add_assoc_str(&compare, "down", zend_string_copy(down_id));
    if (output_id != NULL) {
        add_assoc_str(&compare, "output", zend_string_copy(output_id));
    }
    if (residual_output == 0
        || down_output == 0
        || output == 0
        || residual_width == 0
        || residual_width != down_width
        || residual_width != output_width
        || residual_width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        add_assoc_string(&compare, "status", "ffn_output_residual_compare_argument_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_output_probe, "ffn_output_residual_numeric_compare_failed", true);
        add_assoc_zval(ffn_output_probe, "ffn_output_residual_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        add_assoc_string(&compare, "status", "cuda_context_current_failed");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_output_probe, "ffn_output_residual_numeric_compare_failed", true);
        add_assoc_zval(ffn_output_probe, "ffn_output_residual_numeric_compare", &compare);
        return FAILURE;
    }
    cu_memcpy_dtoh = king_inference_cuda_decoder_graph_executor_compare_dtoh(model);
    if (cu_memcpy_dtoh == NULL) {
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_output_probe, "ffn_output_residual_numeric_compare_failed", true);
        add_assoc_zval(ffn_output_probe, "ffn_output_residual_numeric_compare", &compare);
        return FAILURE;
    }

    residual = emalloc((size_t) residual_width * sizeof(float));
    down = emalloc((size_t) down_width * sizeof(float));
    actual = emalloc((size_t) output_width * sizeof(float));
    result = cu_memcpy_dtoh(residual, residual_output, (size_t) residual_width * sizeof(float));
    if (result == 0) {
        result = cu_memcpy_dtoh(down, down_output, (size_t) down_width * sizeof(float));
    }
    if (result == 0) {
        result = cu_memcpy_dtoh(actual, output, (size_t) output_width * sizeof(float));
    }
    if (result != 0) {
        efree(actual);
        efree(down);
        efree(residual);
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_vector_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_output_probe, "ffn_output_residual_numeric_compare_failed", true);
        add_assoc_zval(ffn_output_probe, "ffn_output_residual_numeric_compare", &compare);
        return FAILURE;
    }

    for (zend_ulong i = 0; i < output_width; i++) {
        double expected = (double) residual[i] + (double) down[i];
        double abs_expected = fabs(expected);

        if (abs_expected > max_expected_abs) {
            max_expected_abs = abs_expected;
        }
    }
    tolerance = fmax(0.003, max_expected_abs * 0.00005);
    sample_limit = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    sample_limit = sample_limit < output_width ? sample_limit : output_width;
    array_init(&samples);
    for (zend_ulong i = 0; i < output_width; i++) {
        double expected = (double) residual[i] + (double) down[i];
        double diff = fabs((double) actual[i] - expected);

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
            add_assoc_double(&sample, "expected", expected);
            add_assoc_double(&sample, "abs_diff", diff);
            add_next_index_zval(&samples, &sample);
        }
    }

    efree(actual);
    efree(down);
    efree(residual);
    add_assoc_string(&compare, "status", matched ? "matched" : "mismatch");
    add_assoc_bool(&compare, "matched", matched);
    add_assoc_long(&compare, "compared_values", (zend_long) output_width);
    add_assoc_long(&compare, "matched_values", (zend_long) matched_values);
    add_assoc_long(&compare, "width", (zend_long) output_width);
    add_assoc_long(&compare, "sampled_values", (zend_long) sample_limit);
    add_assoc_double(&compare, "tolerance", tolerance);
    add_assoc_double(&compare, "max_abs_diff", max_abs_diff);
    add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
    add_assoc_zval(&compare, "samples", &samples);
    add_assoc_bool(ffn_output_probe, "ffn_output_residual_numeric_compare_failed", !matched);
    add_assoc_zval(ffn_output_probe, "ffn_output_residual_numeric_compare", &compare);
    if (!matched) {
        king_inference_cuda_decoder_graph_executor_compare_down_set_error(
            model,
            "ffn_output_residual_mismatch",
            output_width,
            matched_values,
            max_abs_diff,
            tolerance
        );
    }
    return matched ? SUCCESS : FAILURE;
}
