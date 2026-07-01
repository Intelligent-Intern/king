static double king_inference_cuda_decoder_graph_executor_compare_numeric_value(zval *value)
{
    if (value == NULL) {
        return 0.0;
    }
    if (Z_TYPE_P(value) == IS_DOUBLE) {
        return Z_DVAL_P(value);
    }
    if (Z_TYPE_P(value) == IS_LONG) {
        return (double) Z_LVAL_P(value);
    }

    return 0.0;
}

static void king_inference_cuda_decoder_graph_executor_add_first_rms_norm_compare_failure(
    zval *rms_norm_probe,
    zval *compare,
    const char *status
) {
    add_assoc_string(compare, "status", status != NULL ? status : "first_rms_norm_numeric_compare_failed");
    add_assoc_bool(compare, "matched", false);
    add_assoc_zval(rms_norm_probe, "numeric_compare", compare);
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_first_rms_norm(
    king_inference_model_object *model,
    zend_ulong token_id,
    zend_string *weight_tensor,
    king_inference_cuda_device_ptr rms_norm_output,
    zend_ulong width,
    double epsilon,
    double embedding_scale,
    zval *rms_norm_probe
) {
    zend_string *embedding_tensor = NULL;
    zend_string *embedding_name = NULL;
    const char *status = NULL;
    king_inference_tensor_native_view embedding_view;
    king_inference_tensor_native_view weight_view;
    zend_ulong cols = 0;
    zend_ulong rows = 0;
    zend_ulong base;
    zend_ulong chunk_limit;
    zend_ulong compared_values = 0;
    zend_ulong readback_bytes = 0;
    zend_ulong chunk_count = 0;
    double sumsq = 0.0;
    double scale;
    double max_abs_diff = 0.0;
    bool matched = true;
    zval compare;
    zval chunks;

    if (!king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }

    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_first_rms_norm_numeric_compare");
    add_assoc_long(&compare, "token_id", (zend_long) token_id);
    add_assoc_long(&compare, "width", (zend_long) width);
    add_assoc_double(&compare, "embedding_scale", embedding_scale);
    if (weight_tensor != NULL) {
        add_assoc_str(&compare, "weight_tensor", zend_string_copy(weight_tensor));
    }

    if (rms_norm_output == 0 || width == 0 || weight_tensor == NULL || epsilon <= 0.0) {
        king_inference_cuda_decoder_graph_executor_add_first_rms_norm_compare_failure(
            rms_norm_probe,
            &compare,
            "first_rms_norm_compare_arguments_invalid"
        );
        return FAILURE;
    }
    if (king_inference_resolve_token_embedding_tensor_name(model, NULL, &embedding_tensor, NULL, &status) != SUCCESS
        || embedding_tensor == NULL) {
        king_inference_cuda_decoder_graph_executor_add_first_rms_norm_compare_failure(
            rms_norm_probe,
            &compare,
            status != NULL ? status : "embedding_tensor_not_resolved"
        );
        return FAILURE;
    }

    embedding_name = zend_string_copy(embedding_tensor);
    add_assoc_str(&compare, "input_tensor", zend_string_copy(embedding_name));
    if (king_inference_tensor_native_view_resolve(model, embedding_tensor, &embedding_view) != SUCCESS) {
        zend_string_release(embedding_tensor);
        zend_string_release(embedding_name);
        zval_ptr_dtor(&compare);
        return FAILURE;
    }
    zend_string_release(embedding_tensor);
    if (king_inference_tensor_native_view_resolve(model, weight_tensor, &weight_view) != SUCCESS) {
        zend_string_release(embedding_name);
        zval_ptr_dtor(&compare);
        return FAILURE;
    }

    if (embedding_view.rank != 2
        || king_inference_tensor_dimension_at(embedding_view.dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(embedding_view.dimensions, 1, &rows) != SUCCESS
        || cols != width
        || token_id >= rows
        || token_id > ((zend_ulong) -1) / cols
        || weight_view.rank != 1
        || weight_view.elements != width) {
        king_inference_cuda_decoder_graph_executor_add_first_rms_norm_compare_failure(
            rms_norm_probe,
            &compare,
            "first_rms_norm_tensor_shape_invalid"
        );
        zend_string_release(embedding_name);
        return FAILURE;
    }

    base = token_id * cols;
    if (base > embedding_view.elements || width > embedding_view.elements - base) {
        king_inference_cuda_decoder_graph_executor_add_first_rms_norm_compare_failure(
            rms_norm_probe,
            &compare,
            "first_rms_norm_compare_range_invalid"
        );
        zend_string_release(embedding_name);
        return FAILURE;
    }
    for (zend_ulong i = 0; i < width; i++) {
        double value;

        if (king_inference_tensor_value_at(&embedding_view, base + i, &value) != SUCCESS) {
            zval_ptr_dtor(&compare);
            zend_string_release(embedding_name);
            return FAILURE;
        }
        value *= embedding_scale;
        sumsq += value * value;
    }
    scale = 1.0 / king_inference_graph_sqrt((sumsq / (double) width) + epsilon);
    if (!isfinite(scale)) {
        king_inference_cuda_decoder_graph_executor_add_first_rms_norm_compare_failure(
            rms_norm_probe,
            &compare,
            "first_rms_norm_cpu_scale_invalid"
        );
        zend_string_release(embedding_name);
        return FAILURE;
    }

    chunk_limit = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    if (chunk_limit == 0) {
        king_inference_cuda_decoder_graph_executor_add_first_rms_norm_compare_failure(
            rms_norm_probe,
            &compare,
            "first_rms_norm_chunk_limit_invalid"
        );
        zend_string_release(embedding_name);
        return FAILURE;
    }

    zval_ptr_dtor(&compare);
    array_init(&compare);
    add_assoc_string(&compare, "label", "bos_first_rms_norm_output");
    add_assoc_bool(&compare, "enabled", true);
    add_assoc_bool(&compare, "public_api", false);
    add_assoc_string(&compare, "scope", "internal_cuda_device_vector_readback_compare");
    add_assoc_string(&compare, "type", "gpu_decoder_first_rms_norm_numeric_compare");
    add_assoc_string(&compare, "mode", "full_row_chunked");
    add_assoc_string(&compare, "reference", "cpu_embedding_dequant_plus_rms_norm_weight");
    add_assoc_long(&compare, "token_id", (zend_long) token_id);
    add_assoc_long(&compare, "width", (zend_long) width);
    add_assoc_long(&compare, "chunk_limit", (zend_long) chunk_limit);
    add_assoc_double(&compare, "epsilon", epsilon);
    add_assoc_double(&compare, "embedding_scale", embedding_scale);
    add_assoc_double(&compare, "scale", scale);
    add_assoc_str(&compare, "input_tensor", zend_string_copy(embedding_name));
    add_assoc_str(&compare, "weight_tensor", zend_string_copy(weight_tensor));
    array_init(&chunks);

    for (zend_ulong offset = 0; offset < width; offset += chunk_limit) {
        zend_ulong chunk_values = width - offset;
        float *expected;
        zval chunk_compare;
        zval chunk_summary;
        zval *chunk_matched;
        zval *chunk_max_abs_diff;

        if (chunk_values > chunk_limit) {
            chunk_values = chunk_limit;
        }
        expected = emalloc((size_t) chunk_values * sizeof(float));
        for (zend_ulong i = 0; i < chunk_values; i++) {
            double input_value;
            double weight_value;

            if (king_inference_tensor_value_at(&embedding_view, base + offset + i, &input_value) != SUCCESS
                || king_inference_tensor_value_at(&weight_view, offset + i, &weight_value) != SUCCESS) {
                efree(expected);
                zval_ptr_dtor(&chunks);
                zval_ptr_dtor(&compare);
                zend_string_release(embedding_name);
                return FAILURE;
            }
            input_value *= embedding_scale;
            expected[i] = (float) (input_value * scale * weight_value);
        }

        if (king_inference_cuda_device_vector_numeric_compare(
                model,
                "bos_first_rms_norm_output_chunk",
                "rms_norm",
                0,
                ZSTR_VAL(weight_tensor),
                offset,
                rms_norm_output + ((king_inference_cuda_device_ptr) offset * (king_inference_cuda_device_ptr) sizeof(float)),
                chunk_values,
                expected,
                chunk_values,
                0.0001,
                &chunk_compare
        ) != SUCCESS) {
            efree(expected);
            add_assoc_long(&chunk_compare, "offset", (zend_long) offset);
            add_assoc_long(&chunk_compare, "token_id", (zend_long) token_id);
            add_assoc_long(&chunk_compare, "width", (zend_long) width);
            add_assoc_zval(rms_norm_probe, "numeric_compare", &chunk_compare);
            zval_ptr_dtor(&chunks);
            zval_ptr_dtor(&compare);
            zend_string_release(embedding_name);
            return FAILURE;
        }
        efree(expected);

        chunk_matched = king_inference_array_find(&chunk_compare, "matched");
        chunk_max_abs_diff = king_inference_array_find(&chunk_compare, "max_abs_diff");
        if (chunk_matched == NULL || !zend_is_true(chunk_matched)) {
            matched = false;
        }
        {
            double value = king_inference_cuda_decoder_graph_executor_compare_numeric_value(chunk_max_abs_diff);
            if (value > max_abs_diff) {
                max_abs_diff = value;
            }
        }

        array_init(&chunk_summary);
        add_assoc_long(&chunk_summary, "offset", (zend_long) offset);
        add_assoc_long(&chunk_summary, "compared_values", (zend_long) chunk_values);
        add_assoc_bool(&chunk_summary, "matched", chunk_matched != NULL && zend_is_true(chunk_matched));
        add_assoc_double(
            &chunk_summary,
            "max_abs_diff",
            king_inference_cuda_decoder_graph_executor_compare_numeric_value(chunk_max_abs_diff)
        );
        add_next_index_zval(&chunks, &chunk_summary);
        zval_ptr_dtor(&chunk_compare);
        compared_values += chunk_values;
        readback_bytes += chunk_values * sizeof(float);
        chunk_count++;
    }

    add_assoc_string(&compare, "status", matched ? "matched" : "mismatch");
    add_assoc_bool(&compare, "matched", matched);
    add_assoc_long(&compare, "compared_values", (zend_long) compared_values);
    add_assoc_long(&compare, "readback_bytes", (zend_long) readback_bytes);
    add_assoc_double(&compare, "tolerance", 0.0001);
    add_assoc_double(&compare, "max_abs_diff", max_abs_diff);
    add_assoc_long(&compare, "chunk_count", (zend_long) chunk_count);
    add_assoc_zval(&compare, "chunks", &chunks);
    add_assoc_long(&compare, "token_id", (zend_long) token_id);
    add_assoc_long(&compare, "width", (zend_long) width);
    zend_string_release(embedding_name);
    add_assoc_zval(rms_norm_probe, "numeric_compare", &compare);
    return matched ? SUCCESS : FAILURE;
}

static void king_inference_cuda_decoder_graph_executor_copy_first_rms_norm_compare(
    zval *result,
    zval *rms_norm_probe
) {
    zval *compare = king_inference_array_find(rms_norm_probe, "numeric_compare");
    zval copy;

    if (compare == NULL) {
        return;
    }

    ZVAL_COPY(&copy, compare);
    add_assoc_zval(result, "first_rms_norm_numeric_compare", &copy);
}

static void king_inference_cuda_decoder_graph_executor_record_first_rms_norm_compare(
    king_inference_model_object *model,
    zend_ulong token_id,
    zend_string *weight_tensor,
    king_inference_cuda_device_ptr rms_norm_output,
    zend_ulong width,
    double epsilon,
    double embedding_scale,
    zval *rms_norm_probe
) {
    if (king_inference_cuda_decoder_graph_executor_compare_first_rms_norm(
            model,
            token_id,
            weight_tensor,
            rms_norm_output,
            width,
            epsilon,
            embedding_scale,
            rms_norm_probe
    ) != SUCCESS) {
        add_assoc_bool(rms_norm_probe, "numeric_compare_failed", true);
    }
}
