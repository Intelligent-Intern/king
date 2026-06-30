/*
 * Internal CUDA attention numeric compares for block-0 BOS.
 */

static const char *king_inference_cuda_decoder_graph_executor_attention_compare_error_reason(
    zval *attention_probe,
    const char *compare_key,
    const char *fallback
) {
    zval *compare = attention_probe != NULL && Z_TYPE_P(attention_probe) == IS_ARRAY
        ? king_inference_array_find(attention_probe, compare_key)
        : NULL;
    zval *error_category = compare != NULL && Z_TYPE_P(compare) == IS_ARRAY
        ? king_inference_array_find(compare, "error_category")
        : NULL;
    zval *status = compare != NULL && Z_TYPE_P(compare) == IS_ARRAY
        ? king_inference_array_find(compare, "status")
        : NULL;

    if (error_category != NULL
        && Z_TYPE_P(error_category) == IS_STRING
        && zend_string_equals_literal(Z_STR_P(error_category), "numeric_mismatch")) {
        return "king.numeric_mismatch.detected";
    }
    if (status != NULL
        && Z_TYPE_P(status) == IS_STRING
        && zend_string_equals_literal(Z_STR_P(status), "numeric_mismatch")) {
        return "king.numeric_mismatch.detected";
    }

    return fallback;
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_block0_bos_attention_scores(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr query_output,
    king_inference_cuda_device_ptr key_cache,
    king_inference_cuda_device_ptr scores_output,
    zend_ulong layer,
    zend_ulong head,
    zend_ulong key_length,
    zend_ulong key_stride,
    zend_ulong slot_start,
    zend_ulong slot_count,
    double scale,
    zval *attention_probe
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    size_t vector_bytes;
    king_inference_cuda_device_ptr key_slot;
    float *query;
    float *key;
    float expected;
    float partials[256];
    double tolerance;
    zval compare;
    zval *matched;
    int result;

    if (layer != 0
        || head != 0
        || slot_start != 0
        || slot_count != 1
        || !king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }
    if (query_output == 0
        || key_cache == 0
        || scores_output == 0
        || key_length == 0
        || key_stride < key_length
        || key_length > UINT_MAX
        || !isfinite(scale)) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_score_numeric_compare");
        add_assoc_string(&compare, "status", "attention_score_compare_argument_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_score_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_score_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_score_numeric_compare");
        add_assoc_string(&compare, "status", "cuda_context_current_failed");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_score_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_score_numeric_compare", &compare);
        return FAILURE;
    }

    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    if (cu_memcpy_dtoh == NULL) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_score_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_score_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_score_numeric_compare", &compare);
        return FAILURE;
    }

    vector_bytes = (size_t) key_length * sizeof(float);
    query = emalloc(vector_bytes);
    key = emalloc(vector_bytes);
    key_slot = key_cache + ((king_inference_cuda_device_ptr) slot_start
        * (king_inference_cuda_device_ptr) key_stride
        * (king_inference_cuda_device_ptr) sizeof(float));
    result = cu_memcpy_dtoh(query, query_output, vector_bytes);
    if (result == 0) {
        result = cu_memcpy_dtoh(key, key_slot, vector_bytes);
    }
    if (result != 0) {
        efree(key);
        efree(query);
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_score_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_vector_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_score_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_score_numeric_compare", &compare);
        return FAILURE;
    }

    for (zend_ulong lane = 0; lane < 256; lane++) {
        float sum = 0.0f;

        for (zend_ulong i = lane; i < key_length; i += 256) {
            sum += query[i] * key[i];
        }
        partials[lane] = sum;
    }
    for (zend_ulong stride = 128; stride > 0; stride >>= 1) {
        for (zend_ulong lane = 0; lane < stride; lane++) {
            partials[lane] += partials[lane + stride];
        }
    }
    expected = partials[0] * (float) scale;
    tolerance = fmax(0.00005, fabs((double) expected) * 0.00005);

    efree(key);
    efree(query);
    if (king_inference_cuda_device_vector_numeric_compare(
            model,
            "block0_bos_attention_score_head0_slot0",
            "attention_score",
            (zend_long) layer,
            "qk_score",
            0,
            scores_output,
            1,
            &expected,
            1,
            tolerance,
            &compare
    ) != SUCCESS) {
        add_assoc_string(&compare, "type", "gpu_decoder_attention_score_numeric_compare");
        add_assoc_string(&compare, "reference", "cpu_float_attention_dot_kernel_order");
        add_assoc_long(&compare, "layer", (zend_long) layer);
        add_assoc_long(&compare, "head", (zend_long) head);
        add_assoc_long(&compare, "slot_start", (zend_long) slot_start);
        add_assoc_long(&compare, "slot_count", (zend_long) slot_count);
        add_assoc_long(&compare, "key_length", (zend_long) key_length);
        add_assoc_long(&compare, "key_stride", (zend_long) key_stride);
        add_assoc_double(&compare, "scale", scale);
        add_assoc_double(&compare, "cpu_reference", (double) expected);
        add_assoc_bool(attention_probe, "attention_score_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_score_numeric_compare", &compare);
        return FAILURE;
    }

    add_assoc_string(&compare, "type", "gpu_decoder_attention_score_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_float_attention_dot_kernel_order");
    add_assoc_long(&compare, "layer", (zend_long) layer);
    add_assoc_long(&compare, "head", (zend_long) head);
    add_assoc_long(&compare, "slot_start", (zend_long) slot_start);
    add_assoc_long(&compare, "slot_count", (zend_long) slot_count);
    add_assoc_long(&compare, "key_length", (zend_long) key_length);
    add_assoc_long(&compare, "key_stride", (zend_long) key_stride);
    add_assoc_double(&compare, "scale", scale);
    add_assoc_double(&compare, "cpu_reference", (double) expected);
    matched = king_inference_array_find(&compare, "matched");
    add_assoc_bool(
        attention_probe,
        "attention_score_numeric_compare_failed",
        matched == NULL || !zend_is_true(matched)
    );
    add_assoc_zval(attention_probe, "attention_score_numeric_compare", &compare);
    return matched != NULL && zend_is_true(matched) ? SUCCESS : FAILURE;
}

static void king_inference_cuda_decoder_graph_executor_copy_block0_attention_score_compare(
    zval *result,
    zval *kv_head_prepare_probe
) {
    zval *heads = king_inference_array_find(kv_head_prepare_probe, "query_heads");
    zval *head0 = heads != NULL && Z_TYPE_P(heads) == IS_ARRAY
        ? zend_hash_index_find(Z_ARRVAL_P(heads), 0)
        : NULL;
    zval *kv_probe = head0 != NULL && Z_TYPE_P(head0) == IS_ARRAY
        ? king_inference_array_find(head0, "kv_head_prepare_execution")
        : NULL;
    zval *attention_probe = kv_probe != NULL && Z_TYPE_P(kv_probe) == IS_ARRAY
        ? king_inference_array_find(kv_probe, "kv_attention_execution")
        : NULL;
    zval *compare = attention_probe != NULL && Z_TYPE_P(attention_probe) == IS_ARRAY
        ? king_inference_array_find(attention_probe, "attention_score_numeric_compare")
        : NULL;
    zval copy;

    if (compare == NULL || Z_TYPE_P(compare) != IS_ARRAY) {
        return;
    }

    ZVAL_COPY(&copy, compare);
    add_assoc_zval(result, "block0_attention_score_numeric_compare", &copy);
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_block0_bos_attention_softmax(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr probabilities_output,
    zend_ulong layer,
    zend_ulong head,
    zend_ulong slot_start,
    zend_ulong slot_count,
    zval *attention_probe
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    float actual_probability = 0.0f;
    float expected_probability = 1.0f;
    zval compare;
    zval *matched;
    int result;

    if (layer != 0
        || head != 0
        || slot_start != 0
        || slot_count != 1
        || !king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }
    if (probabilities_output == 0) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_softmax_numeric_compare");
        add_assoc_string(&compare, "status", "attention_softmax_compare_argument_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_softmax_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_softmax_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_softmax_numeric_compare");
        add_assoc_string(&compare, "status", "cuda_context_current_failed");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_softmax_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_softmax_numeric_compare", &compare);
        return FAILURE;
    }

    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    if (cu_memcpy_dtoh == NULL) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_softmax_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_softmax_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_softmax_numeric_compare", &compare);
        return FAILURE;
    }

    result = cu_memcpy_dtoh(&actual_probability, probabilities_output, sizeof(float));
    if (result != 0) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_softmax_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_probability_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_softmax_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_softmax_numeric_compare", &compare);
        return FAILURE;
    }

    if (king_inference_cuda_device_vector_numeric_compare(
            model,
            "block0_bos_attention_softmax_head0_slot0",
            "attention_softmax",
            (zend_long) layer,
            "attention_probability",
            0,
            probabilities_output,
            1,
            &expected_probability,
            1,
            0.000001,
            &compare
    ) != SUCCESS) {
        add_assoc_string(&compare, "type", "gpu_decoder_attention_softmax_numeric_compare");
        add_assoc_string(&compare, "reference", "cpu_softmax_single_slot");
        add_assoc_long(&compare, "layer", (zend_long) layer);
        add_assoc_long(&compare, "head", (zend_long) head);
        add_assoc_long(&compare, "slot_start", (zend_long) slot_start);
        add_assoc_long(&compare, "slot_count", (zend_long) slot_count);
        add_assoc_double(&compare, "probability_sum", (double) actual_probability);
        add_assoc_double(&compare, "cpu_reference_sum", 1.0);
        add_assoc_bool(attention_probe, "attention_softmax_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_softmax_numeric_compare", &compare);
        return FAILURE;
    }

    add_assoc_string(&compare, "type", "gpu_decoder_attention_softmax_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_softmax_single_slot");
    add_assoc_long(&compare, "layer", (zend_long) layer);
    add_assoc_long(&compare, "head", (zend_long) head);
    add_assoc_long(&compare, "slot_start", (zend_long) slot_start);
    add_assoc_long(&compare, "slot_count", (zend_long) slot_count);
    add_assoc_double(&compare, "probability_sum", (double) actual_probability);
    add_assoc_double(&compare, "cpu_reference_sum", 1.0);
    matched = king_inference_array_find(&compare, "matched");
    add_assoc_bool(
        attention_probe,
        "attention_softmax_numeric_compare_failed",
        matched == NULL || !zend_is_true(matched)
    );
    add_assoc_zval(attention_probe, "attention_softmax_numeric_compare", &compare);
    return matched != NULL && zend_is_true(matched) ? SUCCESS : FAILURE;
}

static void king_inference_cuda_decoder_graph_executor_copy_block0_attention_softmax_compare(
    zval *result,
    zval *kv_head_prepare_probe
) {
    zval *heads = king_inference_array_find(kv_head_prepare_probe, "query_heads");
    zval *head0 = heads != NULL && Z_TYPE_P(heads) == IS_ARRAY
        ? zend_hash_index_find(Z_ARRVAL_P(heads), 0)
        : NULL;
    zval *kv_probe = head0 != NULL && Z_TYPE_P(head0) == IS_ARRAY
        ? king_inference_array_find(head0, "kv_head_prepare_execution")
        : NULL;
    zval *attention_probe = kv_probe != NULL && Z_TYPE_P(kv_probe) == IS_ARRAY
        ? king_inference_array_find(kv_probe, "kv_attention_execution")
        : NULL;
    zval *compare = attention_probe != NULL && Z_TYPE_P(attention_probe) == IS_ARRAY
        ? king_inference_array_find(attention_probe, "attention_softmax_numeric_compare")
        : NULL;
    zval copy;

    if (compare == NULL || Z_TYPE_P(compare) != IS_ARRAY) {
        return;
    }

    ZVAL_COPY(&copy, compare);
    add_assoc_zval(result, "block0_attention_softmax_numeric_compare", &copy);
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_block0_bos_attention_values(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr probabilities_output,
    king_inference_cuda_device_ptr value_cache,
    king_inference_cuda_device_ptr context_output,
    zend_ulong layer,
    zend_ulong head,
    zend_ulong value_length,
    zend_ulong value_stride,
    zend_ulong slot_start,
    zend_ulong slot_count,
    zval *attention_probe
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    king_inference_cuda_device_ptr value_slot;
    float probability = 0.0f;
    float *value;
    float *expected;
    size_t value_bytes;
    double max_expected_abs = 0.0;
    zval compare;
    zval *matched;
    int result;

    if (layer != 0
        || head != 0
        || slot_start != 0
        || slot_count != 1
        || !king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }
    if (probabilities_output == 0
        || value_cache == 0
        || context_output == 0
        || value_length == 0
        || value_stride < value_length
        || value_length > UINT_MAX) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_value_numeric_compare");
        add_assoc_string(&compare, "status", "attention_value_compare_argument_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_value_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_value_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_value_numeric_compare");
        add_assoc_string(&compare, "status", "cuda_context_current_failed");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_value_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_value_numeric_compare", &compare);
        return FAILURE;
    }

    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    if (cu_memcpy_dtoh == NULL) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_value_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_value_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_value_numeric_compare", &compare);
        return FAILURE;
    }

    value_bytes = (size_t) value_length * sizeof(float);
    value = emalloc(value_bytes);
    expected = emalloc(value_bytes);
    value_slot = value_cache + ((king_inference_cuda_device_ptr) slot_start
        * (king_inference_cuda_device_ptr) value_stride
        * (king_inference_cuda_device_ptr) sizeof(float));
    result = cu_memcpy_dtoh(&probability, probabilities_output, sizeof(float));
    if (result == 0) {
        result = cu_memcpy_dtoh(value, value_slot, value_bytes);
    }
    if (result != 0) {
        efree(expected);
        efree(value);
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_value_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_value_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(attention_probe, "attention_value_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_value_numeric_compare", &compare);
        return FAILURE;
    }

    for (zend_ulong i = 0; i < value_length; i++) {
        double abs_value;

        expected[i] = probability * value[i];
        abs_value = fabs((double) expected[i]);
        if (abs_value > max_expected_abs) {
            max_expected_abs = abs_value;
        }
    }

    if (king_inference_cuda_device_vector_numeric_compare(
            model,
            "block0_bos_attention_value_context_head0",
            "attention_value",
            (zend_long) layer,
            "attention_value_context",
            0,
            context_output,
            value_length,
            expected,
            value_length,
            fmax(0.00002, max_expected_abs * 0.00002),
            &compare
    ) != SUCCESS) {
        efree(expected);
        efree(value);
        add_assoc_string(&compare, "type", "gpu_decoder_attention_value_numeric_compare");
        add_assoc_string(&compare, "reference", "cpu_probability_weighted_value_cache");
        add_assoc_long(&compare, "layer", (zend_long) layer);
        add_assoc_long(&compare, "head", (zend_long) head);
        add_assoc_long(&compare, "slot_start", (zend_long) slot_start);
        add_assoc_long(&compare, "slot_count", (zend_long) slot_count);
        add_assoc_long(&compare, "value_length", (zend_long) value_length);
        add_assoc_long(&compare, "value_stride", (zend_long) value_stride);
        add_assoc_double(&compare, "probability", (double) probability);
        add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
        add_assoc_bool(attention_probe, "attention_value_numeric_compare_failed", true);
        add_assoc_zval(attention_probe, "attention_value_numeric_compare", &compare);
        return FAILURE;
    }

    efree(expected);
    efree(value);
    add_assoc_string(&compare, "type", "gpu_decoder_attention_value_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_probability_weighted_value_cache");
    add_assoc_long(&compare, "layer", (zend_long) layer);
    add_assoc_long(&compare, "head", (zend_long) head);
    add_assoc_long(&compare, "slot_start", (zend_long) slot_start);
    add_assoc_long(&compare, "slot_count", (zend_long) slot_count);
    add_assoc_long(&compare, "value_length", (zend_long) value_length);
    add_assoc_long(&compare, "value_stride", (zend_long) value_stride);
    add_assoc_double(&compare, "probability", (double) probability);
    add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
    matched = king_inference_array_find(&compare, "matched");
    add_assoc_bool(
        attention_probe,
        "attention_value_numeric_compare_failed",
        matched == NULL || !zend_is_true(matched)
    );
    add_assoc_zval(attention_probe, "attention_value_numeric_compare", &compare);
    return matched != NULL && zend_is_true(matched) ? SUCCESS : FAILURE;
}

static void king_inference_cuda_decoder_graph_executor_copy_block0_attention_value_compare(
    zval *result,
    zval *kv_head_prepare_probe
) {
    zval *heads = king_inference_array_find(kv_head_prepare_probe, "query_heads");
    zval *head0 = heads != NULL && Z_TYPE_P(heads) == IS_ARRAY
        ? zend_hash_index_find(Z_ARRVAL_P(heads), 0)
        : NULL;
    zval *kv_probe = head0 != NULL && Z_TYPE_P(head0) == IS_ARRAY
        ? king_inference_array_find(head0, "kv_head_prepare_execution")
        : NULL;
    zval *attention_probe = kv_probe != NULL && Z_TYPE_P(kv_probe) == IS_ARRAY
        ? king_inference_array_find(kv_probe, "kv_attention_execution")
        : NULL;
    zval *compare = attention_probe != NULL && Z_TYPE_P(attention_probe) == IS_ARRAY
        ? king_inference_array_find(attention_probe, "attention_value_numeric_compare")
        : NULL;
    zval copy;

    if (compare == NULL || Z_TYPE_P(compare) != IS_ARRAY) {
        return;
    }

    ZVAL_COPY(&copy, compare);
    add_assoc_zval(result, "block0_attention_value_numeric_compare", &copy);
}
