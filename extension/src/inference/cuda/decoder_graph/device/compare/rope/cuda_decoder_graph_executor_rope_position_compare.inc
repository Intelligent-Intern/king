/*
 * Position-focused RoPE numeric compare for native King GPU inference.
 */

static void king_inference_cuda_decoder_graph_executor_store_rope_position_compare(
    zval *rope_probe,
    zend_ulong position,
    zval *compare,
    bool failed
) {
    if (position == 0) {
        add_assoc_bool(rope_probe, "position0_numeric_compare_failed", failed);
        add_assoc_zval(rope_probe, "position0_numeric_compare", compare);
        return;
    }
    if (position == 1) {
        add_assoc_bool(rope_probe, "position1_numeric_compare_failed", failed);
        add_assoc_zval(rope_probe, "position1_numeric_compare", compare);
        return;
    }

    add_assoc_bool(rope_probe, "position_numeric_compare_failed", failed);
    add_assoc_zval(rope_probe, "position_numeric_compare", compare);
}

static const char *king_inference_cuda_decoder_graph_executor_rope_position_compare_type(
    zend_ulong position
) {
    if (position == 0) {
        return "gpu_decoder_rope_position0_numeric_compare";
    }
    if (position == 1) {
        return "gpu_decoder_rope_position1_numeric_compare";
    }
    return "gpu_decoder_rope_position_numeric_compare";
}

static void king_inference_cuda_decoder_graph_executor_set_rope_position_compare_error(
    king_inference_model_object *model,
    zval *rope_probe,
    zend_ulong position
) {
    zval *compare;
    zval *status;
    zval *max_abs_diff;
    zval *compared_values;
    zval *matched_values;
    zval *samples;
    zval *sample;
    zval *sample_index;
    zval *sample_actual;
    zval *sample_expected;
    zval *sample_diff;
    const char *compare_key = position == 0
        ? "position0_numeric_compare"
        : (position == 1 ? "position1_numeric_compare" : "position_numeric_compare");
    const char *status_value = "unknown";
    double max_abs_diff_value = -1.0;
    zend_long compared_value_count = -1;
    zend_long matched_value_count = -1;
    zend_long first_index = -1;
    double first_actual = 0.0;
    double first_expected = 0.0;
    double first_diff = 0.0;
    char message[512];

    compare = rope_probe != NULL && Z_TYPE_P(rope_probe) == IS_ARRAY
        ? king_inference_array_find(rope_probe, compare_key)
        : NULL;
    if (compare == NULL || Z_TYPE_P(compare) != IS_ARRAY) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_rope_position_compare_failed"
        );
        return;
    }

    status = king_inference_array_find(compare, "status");
    if (status != NULL && Z_TYPE_P(status) == IS_STRING) {
        status_value = Z_STRVAL_P(status);
    }
    max_abs_diff = king_inference_array_find(compare, "max_abs_diff");
    if (max_abs_diff != NULL && Z_TYPE_P(max_abs_diff) == IS_DOUBLE) {
        max_abs_diff_value = Z_DVAL_P(max_abs_diff);
    }
    compared_values = king_inference_array_find(compare, "compared_values");
    if (compared_values != NULL && Z_TYPE_P(compared_values) == IS_LONG) {
        compared_value_count = Z_LVAL_P(compared_values);
    }
    matched_values = king_inference_array_find(compare, "matched_values");
    if (matched_values != NULL && Z_TYPE_P(matched_values) == IS_LONG) {
        matched_value_count = Z_LVAL_P(matched_values);
    }
    samples = king_inference_array_find(compare, "samples");
    if (samples != NULL && Z_TYPE_P(samples) == IS_ARRAY) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(samples), sample) {
            if (sample == NULL || Z_TYPE_P(sample) != IS_ARRAY) {
                continue;
            }
            sample_diff = king_inference_array_find(sample, "abs_diff");
            if (sample_diff == NULL || Z_TYPE_P(sample_diff) != IS_DOUBLE || Z_DVAL_P(sample_diff) <= 0.00001) {
                continue;
            }
            sample_index = king_inference_array_find(sample, "index");
            sample_actual = king_inference_array_find(sample, "actual");
            sample_expected = king_inference_array_find(sample, "expected");
            first_index = sample_index != NULL && Z_TYPE_P(sample_index) == IS_LONG ? Z_LVAL_P(sample_index) : -1;
            first_actual = sample_actual != NULL && Z_TYPE_P(sample_actual) == IS_DOUBLE ? Z_DVAL_P(sample_actual) : 0.0;
            first_expected = sample_expected != NULL && Z_TYPE_P(sample_expected) == IS_DOUBLE ? Z_DVAL_P(sample_expected) : 0.0;
            first_diff = Z_DVAL_P(sample_diff);
            break;
        } ZEND_HASH_FOREACH_END();
    }

    snprintf(
        message,
        sizeof(message),
        "cuda_decoder_graph_rope_position_compare_failed position=%lu status=%s compared=%ld matched=%ld max_abs_diff=%.9g first_mismatch_index=%ld actual=%.9g expected=%.9g abs_diff=%.9g",
        (unsigned long) position,
        status_value,
        (long) compared_value_count,
        (long) matched_value_count,
        max_abs_diff_value,
        (long) first_index,
        first_actual,
        first_expected,
        first_diff
    );
    king_inference_cuda_decoder_graph_executor_set_error(model, -1, message);
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_rope_position_reference(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr slice_output,
    king_inference_cuda_device_ptr rope_output,
    zend_ulong length,
    zend_ulong offset,
    zend_ulong head_dim,
    zend_ulong position,
    double position_scale,
    double rope_base,
    zend_ulong pairing,
    zval *rope_probe
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    zend_ulong compare_count;
    zend_ulong input_count;
    size_t readback_bytes;
    size_t input_readback_bytes;
    float *input;
    float *expected;
    double max_expected_abs = 0.0;
    double tolerance = 0.00001;
    zval compare;
    zval *matched;
    int result;

    if (position > 1 || !king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }
    if (slice_output == 0 || rope_output == 0 || length == 0) {
        array_init(&compare);
        add_assoc_string(&compare, "type", king_inference_cuda_decoder_graph_executor_rope_position_compare_type(position));
        add_assoc_string(&compare, "status", "rope_position_compare_argument_invalid");
        add_assoc_bool(&compare, "matched", false);
        king_inference_cuda_decoder_graph_executor_store_rope_position_compare(rope_probe, position, &compare, true);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        array_init(&compare);
        add_assoc_string(&compare, "type", king_inference_cuda_decoder_graph_executor_rope_position_compare_type(position));
        add_assoc_string(&compare, "status", "cuda_context_current_failed");
        add_assoc_bool(&compare, "matched", false);
        king_inference_cuda_decoder_graph_executor_store_rope_position_compare(rope_probe, position, &compare, true);
        return FAILURE;
    }

    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    if (cu_memcpy_dtoh == NULL) {
        array_init(&compare);
        add_assoc_string(&compare, "type", king_inference_cuda_decoder_graph_executor_rope_position_compare_type(position));
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(&compare, "matched", false);
        king_inference_cuda_decoder_graph_executor_store_rope_position_compare(rope_probe, position, &compare, true);
        return FAILURE;
    }

    compare_count = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    compare_count = compare_count < length ? compare_count : length;
    input_count = offset + head_dim;
    if (input_count < compare_count) {
        input_count = compare_count;
    }
    if (input_count > length) {
        input_count = length;
    }
    readback_bytes = (size_t) compare_count * sizeof(float);
    input_readback_bytes = (size_t) input_count * sizeof(float);
    input = emalloc(input_readback_bytes);
    expected = emalloc(readback_bytes);
    result = cu_memcpy_dtoh(input, slice_output, input_readback_bytes);
    if (result != 0) {
        efree(input);
        efree(expected);
        array_init(&compare);
        add_assoc_string(&compare, "type", king_inference_cuda_decoder_graph_executor_rope_position_compare_type(position));
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_input_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        king_inference_cuda_decoder_graph_executor_store_rope_position_compare(rope_probe, position, &compare, true);
        return FAILURE;
    }

    for (zend_ulong i = 0; i < compare_count; i++) {
        expected[i] = input[i];
    }
    for (zend_ulong pair = 0; pair < head_dim / 2; pair++) {
        zend_ulong half = head_dim / 2;
        zend_ulong even = pairing == 1 ? offset + pair : offset + pair * 2;
        zend_ulong odd = pairing == 1 ? offset + half + pair : even + 1;
        double exponent;
        double inv_freq;
        double angle;
        double sin_value;
        double cos_value;
        double x0;
        double x1;

        if (even >= input_count || odd >= input_count) {
            continue;
        }
        exponent = ((double) (pair * 2)) / (double) head_dim;
        inv_freq = exp(-log(rope_base) * exponent);
        angle = (double) position * position_scale * inv_freq;
        sin_value = sin(angle);
        cos_value = cos(angle);
        x0 = (double) input[even];
        x1 = (double) input[odd];
        if (even < compare_count) {
            expected[even] = (float) (x0 * cos_value - x1 * sin_value);
        }
        if (odd < compare_count) {
            expected[odd] = (float) (x0 * sin_value + x1 * cos_value);
        }
    }
    for (zend_ulong i = 0; i < compare_count; i++) {
        double abs_expected = fabs((double) expected[i]);

        if (abs_expected > max_expected_abs) {
            max_expected_abs = abs_expected;
        }
    }
    tolerance = fmax(tolerance, max_expected_abs * 0.0000015);
    if (tolerance > 0.0005) {
        tolerance = 0.0005;
    }
    efree(input);

    if (king_inference_cuda_device_vector_numeric_compare(
            model,
            position == 0 ? "rope_position0_identity" : "rope_position1_cpu_reference",
            rope_output,
            length,
            expected,
            compare_count,
            tolerance,
            &compare
    ) != SUCCESS) {
        add_assoc_string(&compare, "type", king_inference_cuda_decoder_graph_executor_rope_position_compare_type(position));
        add_assoc_long(&compare, "offset", (zend_long) offset);
        add_assoc_long(&compare, "head_dim", (zend_long) head_dim);
        add_assoc_long(&compare, "position", (zend_long) position);
        add_assoc_double(&compare, "position_scale", position_scale);
        add_assoc_double(&compare, "rope_base", rope_base);
        add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
        king_inference_cuda_decoder_graph_executor_store_rope_position_compare(rope_probe, position, &compare, true);
        efree(expected);
        return FAILURE;
    }

    add_assoc_string(&compare, "type", king_inference_cuda_decoder_graph_executor_rope_position_compare_type(position));
    add_assoc_string(&compare, "reference", position == 0 ? "position0_identity" : "cpu_rope_rotation_formula");
    add_assoc_long(&compare, "offset", (zend_long) offset);
    add_assoc_long(&compare, "head_dim", (zend_long) head_dim);
    add_assoc_long(&compare, "position", (zend_long) position);
    add_assoc_double(&compare, "position_scale", position_scale);
    add_assoc_double(&compare, "rope_base", rope_base);
    add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
    matched = king_inference_array_find(&compare, "matched");
    king_inference_cuda_decoder_graph_executor_store_rope_position_compare(
        rope_probe,
        position,
        &compare,
        matched == NULL || !zend_is_true(matched)
    );
    efree(expected);
    return matched != NULL && zend_is_true(matched) ? SUCCESS : FAILURE;
}
