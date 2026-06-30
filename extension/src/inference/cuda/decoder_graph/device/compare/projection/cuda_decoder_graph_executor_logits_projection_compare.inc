/*
 * Internal CUDA logits projection compare for bounded readback candidates.
 */

typedef struct _king_inference_cuda_decoder_graph_executor_cpu_top_logit {
    zend_ulong token_id;
    double logit;
    bool set;
} king_inference_cuda_decoder_graph_executor_cpu_top_logit;

static king_inference_cuda_memcpy_dtoh_fn
king_inference_cuda_decoder_graph_executor_logits_compare_dtoh(
    king_inference_model_object *model
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;

    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }

    return cu_memcpy_dtoh;
}

static void king_inference_cuda_decoder_graph_executor_logits_compare_fail(
    zval *logits_probe,
    const char *status
) {
    zval compare;

    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_logits_projection_numeric_compare");
    add_assoc_string(&compare, "status", status);
    add_assoc_bool(&compare, "matched", false);
    add_assoc_bool(logits_probe, "logits_projection_numeric_compare_failed", true);
    add_assoc_zval(logits_probe, "logits_projection_numeric_compare", &compare);
}

static bool king_inference_cuda_decoder_graph_executor_logit_precedes(
    double left,
    zend_ulong left_token_id,
    double right,
    zend_ulong right_token_id
) {
    return left > right || (left == right && left_token_id < right_token_id);
}

static void king_inference_cuda_decoder_graph_executor_insert_cpu_top_logit(
    king_inference_cuda_decoder_graph_executor_cpu_top_logit *top,
    zend_ulong limit,
    zend_ulong *count,
    zend_ulong token_id,
    double logit
) {
    zend_ulong slot;

    if (*count < limit) {
        slot = *count;
        (*count)++;
    } else {
        slot = limit - 1;
        if (!king_inference_cuda_decoder_graph_executor_logit_precedes(
                logit,
                token_id,
                top[slot].logit,
                top[slot].token_id
        )) {
            return;
        }
    }

    top[slot].token_id = token_id;
    top[slot].logit = logit;
    top[slot].set = true;
    while (slot > 0
        && king_inference_cuda_decoder_graph_executor_logit_precedes(
            top[slot].logit,
            top[slot].token_id,
            top[slot - 1].logit,
            top[slot - 1].token_id
    )) {
        king_inference_cuda_decoder_graph_executor_cpu_top_logit tmp = top[slot - 1];

        top[slot - 1] = top[slot];
        top[slot] = tmp;
        slot--;
    }
}

static zend_result king_inference_cuda_decoder_graph_executor_compute_cpu_top_logits(
    king_inference_tensor_native_view *weight_view,
    zend_ulong final_norm_width,
    zend_ulong logits_rows,
    const double *input,
    unsigned int *host_indices,
    zend_ulong candidate_count,
    double *host_expected_logits,
    bool *host_expected_seen,
    king_inference_cuda_decoder_graph_executor_cpu_top_logit *cpu_top,
    zend_ulong *cpu_top_count,
    double *max_expected_abs
) {
    for (zend_ulong row = 0; row < logits_rows; row++) {
        double expected = 0.0;
        double abs_expected;

        if (row > ((zend_ulong) -1) / final_norm_width
            || king_inference_tensor_row_dot(
                weight_view,
                row * final_norm_width,
                final_norm_width,
                input,
                &expected
        ) != SUCCESS) {
            return FAILURE;
        }
        abs_expected = fabs(expected);
        if (abs_expected > *max_expected_abs) {
            *max_expected_abs = abs_expected;
        }
        king_inference_cuda_decoder_graph_executor_insert_cpu_top_logit(
            cpu_top,
            candidate_count,
            cpu_top_count,
            row,
            expected
        );
        for (zend_ulong candidate = 0; candidate < candidate_count; candidate++) {
            if ((zend_ulong) host_indices[candidate] == row) {
                host_expected_logits[candidate] = expected;
                host_expected_seen[candidate] = true;
                break;
            }
        }
    }

    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_bounded_logits_projection(
    king_inference_model_object *model,
    zend_string *weight_name,
    king_inference_cuda_device_ptr final_norm_output,
    zend_ulong final_norm_width,
    zend_ulong logits_rows,
    unsigned int *host_indices,
    float *host_logits,
    zend_ulong candidate_count,
    zval *logits_probe
) {
    king_inference_tensor_native_view weight_view;
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    zend_ulong cols = 0;
    zend_ulong rows = 0;
    float *final_norm = NULL;
    double *input = NULL;
    double host_expected_logits[KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES];
    bool host_expected_seen[KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES];
    king_inference_cuda_decoder_graph_executor_cpu_top_logit cpu_top[KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES];
    zend_ulong cpu_top_count = 0;
    double max_expected_abs = 0.0;
    double max_abs_diff = 0.0;
    double max_rank_abs_diff = 0.0;
    double tolerance;
    zend_ulong matched_values = 0;
    zend_ulong matched_ordering = 0;
    bool matched = true;
    int result;
    zval compare;
    zval samples;
    zval *sample;

    if (!king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }
    if (weight_name == NULL
        || final_norm_output == 0
        || final_norm_width == 0
        || logits_rows == 0
        || host_indices == NULL
        || host_logits == NULL
        || candidate_count == 0
        || candidate_count > KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES
        || final_norm_width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_logits_compare_fail(
            logits_probe,
            "logits_projection_compare_argument_invalid"
        );
        return FAILURE;
    }
    if (king_inference_tensor_native_view_resolve(model, weight_name, &weight_view) != SUCCESS) {
        return FAILURE;
    }
    if (weight_view.rank != 2
        || king_inference_tensor_dimension_at(weight_view.dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(weight_view.dimensions, 1, &rows) != SUCCESS
        || cols != final_norm_width
        || logits_rows == 0
        || logits_rows > rows) {
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_logits_projection_numeric_compare");
        add_assoc_string(&compare, "status", "logits_projection_tensor_shape_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_str(&compare, "weight_tensor", zend_string_copy(weight_name));
        add_assoc_long(&compare, "cols", (zend_long) cols);
        add_assoc_long(&compare, "rows", (zend_long) rows);
        add_assoc_long(&compare, "final_norm_width", (zend_long) final_norm_width);
        add_assoc_long(&compare, "logits_rows", (zend_long) logits_rows);
        add_assoc_bool(logits_probe, "logits_projection_numeric_compare_failed", true);
        add_assoc_zval(logits_probe, "logits_projection_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_decoder_graph_executor_logits_compare_fail(
            logits_probe,
            "cuda_context_current_failed"
        );
        return FAILURE;
    }
    cu_memcpy_dtoh = king_inference_cuda_decoder_graph_executor_logits_compare_dtoh(model);
    if (cu_memcpy_dtoh == NULL) {
        king_inference_cuda_decoder_graph_executor_logits_compare_fail(
            logits_probe,
            "cuMemcpyDtoH_unavailable"
        );
        return FAILURE;
    }

    final_norm = emalloc((size_t) final_norm_width * sizeof(float));
    input = emalloc((size_t) final_norm_width * sizeof(double));
    result = cu_memcpy_dtoh(final_norm, final_norm_output, (size_t) final_norm_width * sizeof(float));
    if (result != 0) {
        efree(input);
        efree(final_norm);
        array_init(&compare);
        add_assoc_string(&compare, "type", "gpu_decoder_logits_projection_numeric_compare");
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_final_norm_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(logits_probe, "logits_projection_numeric_compare_failed", true);
        add_assoc_zval(logits_probe, "logits_projection_numeric_compare", &compare);
        return FAILURE;
    }
    for (zend_ulong i = 0; i < final_norm_width; i++) {
        input[i] = (double) final_norm[i];
    }
    for (zend_ulong i = 0; i < candidate_count; i++) {
        host_expected_logits[i] = 0.0;
        host_expected_seen[i] = false;
        cpu_top[i].token_id = 0;
        cpu_top[i].logit = 0.0;
        cpu_top[i].set = false;
    }
    if (king_inference_cuda_decoder_graph_executor_compute_cpu_top_logits(
            &weight_view,
            final_norm_width,
            logits_rows,
            input,
            host_indices,
            candidate_count,
            host_expected_logits,
            host_expected_seen,
            cpu_top,
            &cpu_top_count,
            &max_expected_abs
    ) != SUCCESS
        || cpu_top_count != candidate_count) {
        efree(input);
        efree(final_norm);
        king_inference_cuda_decoder_graph_executor_logits_compare_fail(
            logits_probe,
            "logits_projection_cpu_top_k_failed"
        );
        return FAILURE;
    }

    array_init(&samples);
    for (zend_ulong i = 0; i < candidate_count; i++) {
        double expected = host_expected_logits[i];
        double rank_diff = fabs((double) host_logits[i] - cpu_top[i].logit);
        double diff;
        zend_ulong token_id = (zend_ulong) host_indices[i];
        bool value_match;
        bool order_match;
        bool rank_logit_match;

        if (token_id >= logits_rows
            || !host_expected_seen[i]
            || !cpu_top[i].set) {
            zval_ptr_dtor(&samples);
            efree(input);
            efree(final_norm);
            king_inference_cuda_decoder_graph_executor_logits_compare_fail(
                logits_probe,
                "logits_projection_candidate_invalid"
            );
            return FAILURE;
        }

        diff = fabs((double) host_logits[i] - expected);
        if (diff > max_abs_diff) {
            max_abs_diff = diff;
        }
        if (rank_diff > max_rank_abs_diff) {
            max_rank_abs_diff = rank_diff;
        }
        value_match = diff <= fmax(0.01, max_expected_abs * 0.0002);
        order_match = token_id == cpu_top[i].token_id;
        rank_logit_match = rank_diff <= fmax(0.01, max_expected_abs * 0.0002);
        if (order_match && rank_logit_match) {
            matched_ordering++;
        }

        {
            zval sample;

            array_init(&sample);
            add_assoc_long(&sample, "rank", (zend_long) i);
            add_assoc_long(&sample, "token_id", (zend_long) token_id);
            add_assoc_long(&sample, "cpu_token_id", (zend_long) cpu_top[i].token_id);
            add_assoc_double(&sample, "actual", (double) host_logits[i]);
            add_assoc_double(&sample, "expected", expected);
            add_assoc_double(&sample, "cpu_rank_logit", cpu_top[i].logit);
            add_assoc_double(&sample, "abs_diff", diff);
            add_assoc_double(&sample, "rank_abs_diff", rank_diff);
            add_assoc_bool(&sample, "value_match", value_match);
            add_assoc_bool(&sample, "order_match", order_match);
            add_assoc_bool(&sample, "rank_logit_match", rank_logit_match);
            add_next_index_zval(&samples, &sample);
        }
    }
    tolerance = fmax(0.01, max_expected_abs * 0.0002);
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(samples), sample) {
        zval *abs_diff = sample != NULL && Z_TYPE_P(sample) == IS_ARRAY
            ? king_inference_array_find(sample, "abs_diff")
            : NULL;

        if (abs_diff != NULL && Z_TYPE_P(abs_diff) == IS_DOUBLE && Z_DVAL_P(abs_diff) <= tolerance) {
            matched_values++;
        } else {
            matched = false;
        }
    } ZEND_HASH_FOREACH_END();
    if (matched_ordering != candidate_count) {
        matched = false;
    }

    efree(input);
    efree(final_norm);
    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_logits_projection_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_full_output_projection_top_k");
    add_assoc_string(&compare, "status", matched ? "matched" : "mismatch");
    add_assoc_bool(&compare, "matched", matched);
    add_assoc_bool(&compare, "ordering_matched", matched_ordering == candidate_count);
    add_assoc_str(&compare, "weight_tensor", zend_string_copy(weight_name));
    add_assoc_long(&compare, "candidate_count", (zend_long) candidate_count);
    add_assoc_long(&compare, "compared_values", (zend_long) candidate_count);
    add_assoc_long(&compare, "matched_values", (zend_long) matched_values);
    add_assoc_long(&compare, "ordering_compared_values", (zend_long) candidate_count);
    add_assoc_long(&compare, "ordering_matched_values", (zend_long) matched_ordering);
    add_assoc_long(&compare, "final_norm_width", (zend_long) final_norm_width);
    add_assoc_long(&compare, "logits_rows", (zend_long) logits_rows);
    add_assoc_double(&compare, "tolerance", tolerance);
    add_assoc_double(&compare, "max_abs_diff", max_abs_diff);
    add_assoc_double(&compare, "max_rank_abs_diff", max_rank_abs_diff);
    add_assoc_double(&compare, "max_expected_abs", max_expected_abs);
    add_assoc_zval(&compare, "samples", &samples);
    add_assoc_bool(logits_probe, "logits_projection_numeric_compare_failed", !matched);
    add_assoc_zval(logits_probe, "logits_projection_numeric_compare", &compare);
    if (!matched) {
        char message[256];

        snprintf(
            message,
            sizeof(message),
            "logits_projection_mismatch tensor=%s compared=%lu matched=%lu max_abs_diff=%.9g tolerance=%.9g",
            ZSTR_VAL(weight_name),
            (unsigned long) candidate_count,
            (unsigned long) matched_values,
            max_abs_diff,
            tolerance
        );
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, message);
    }
    return matched ? SUCCESS : FAILURE;
}
