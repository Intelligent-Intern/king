static zend_result king_inference_cuda_decoder_graph_executor_compare_embedding_row(
    king_inference_model_object *model,
    zend_ulong token_id,
    king_inference_cuda_device_ptr embedding_output,
    zend_ulong width,
    zval *embedding_probe
) {
    zend_string *tensor = NULL;
    zend_string *tensor_name = NULL;
    const char *status = NULL;
    king_inference_tensor_native_view view;
    zend_ulong cols = 0;
    zend_ulong rows = 0;
    zend_ulong base;
    zend_ulong chunk_limit;
    zend_ulong compared_values = 0;
    zend_ulong readback_bytes = 0;
    zend_ulong chunk_count = 0;
    double max_abs_diff = 0.0;
    bool matched = true;
    zval compare;
    zval chunks;

    if (!king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }

    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_embedding_row_numeric_compare");
    add_assoc_long(&compare, "token_id", (zend_long) token_id);
    add_assoc_long(&compare, "width", (zend_long) width);

    if (king_inference_resolve_token_embedding_tensor_name(model, NULL, &tensor, NULL, &status) != SUCCESS
        || tensor == NULL) {
        add_assoc_string(&compare, "status", status != NULL ? status : "embedding_tensor_not_resolved");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_zval(embedding_probe, "numeric_compare", &compare);
        return FAILURE;
    }

    tensor_name = zend_string_copy(tensor);
    add_assoc_str(&compare, "tensor", zend_string_copy(tensor_name));
    if (king_inference_tensor_native_view_resolve(model, tensor, &view) != SUCCESS) {
        zend_string_release(tensor);
        zend_string_release(tensor_name);
        zval_ptr_dtor(&compare);
        return FAILURE;
    }
    zend_string_release(tensor);

    if (view.rank != 2
        || king_inference_tensor_dimension_at(view.dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(view.dimensions, 1, &rows) != SUCCESS
        || cols != width
        || token_id >= rows
        || token_id > ((zend_ulong) -1) / cols) {
        add_assoc_string(&compare, "status", "embedding_tensor_shape_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_zval(embedding_probe, "numeric_compare", &compare);
        zend_string_release(tensor_name);
        return FAILURE;
    }

    base = token_id * cols;
    chunk_limit = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    if (chunk_limit == 0 || base > view.elements || width > view.elements - base) {
        add_assoc_string(&compare, "status", "embedding_tensor_compare_range_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_zval(embedding_probe, "numeric_compare", &compare);
        zend_string_release(tensor_name);
        return FAILURE;
    }

    zval_ptr_dtor(&compare);
    array_init(&compare);
    add_assoc_string(&compare, "label", "bos_token_embedding_row");
    add_assoc_bool(&compare, "enabled", true);
    add_assoc_bool(&compare, "public_api", false);
    add_assoc_string(&compare, "scope", "internal_cuda_device_vector_readback_compare");
    add_assoc_string(&compare, "type", "gpu_decoder_embedding_row_numeric_compare");
    add_assoc_string(&compare, "mode", "full_row_chunked");
    add_assoc_long(&compare, "token_id", (zend_long) token_id);
    add_assoc_long(&compare, "width", (zend_long) width);
    add_assoc_long(&compare, "chunk_limit", (zend_long) chunk_limit);
    add_assoc_str(&compare, "tensor", zend_string_copy(tensor_name));
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
            double value;

            if (king_inference_tensor_value_at(&view, base + offset + i, &value) != SUCCESS) {
                efree(expected);
                zval_ptr_dtor(&chunks);
                zval_ptr_dtor(&compare);
                zend_string_release(tensor_name);
                return FAILURE;
            }
            expected[i] = (float) value;
        }

        if (king_inference_cuda_device_vector_numeric_compare(
                model,
                "bos_token_embedding_row_chunk",
                embedding_output + ((king_inference_cuda_device_ptr) offset * (king_inference_cuda_device_ptr) sizeof(float)),
                chunk_values,
                expected,
                chunk_values,
                0.00001,
                &chunk_compare
        ) != SUCCESS) {
            efree(expected);
            add_assoc_long(&chunk_compare, "offset", (zend_long) offset);
            add_assoc_long(&chunk_compare, "token_id", (zend_long) token_id);
            add_assoc_long(&chunk_compare, "width", (zend_long) width);
            add_assoc_zval(embedding_probe, "numeric_compare", &chunk_compare);
            zval_ptr_dtor(&chunks);
            zval_ptr_dtor(&compare);
            zend_string_release(tensor_name);
            return FAILURE;
        }
        efree(expected);

        chunk_matched = king_inference_array_find(&chunk_compare, "matched");
        chunk_max_abs_diff = king_inference_array_find(&chunk_compare, "max_abs_diff");
        if (chunk_matched == NULL || !zend_is_true(chunk_matched)) {
            matched = false;
        }
        if (chunk_max_abs_diff != NULL
            && (Z_TYPE_P(chunk_max_abs_diff) == IS_DOUBLE || Z_TYPE_P(chunk_max_abs_diff) == IS_LONG)) {
            double value = Z_TYPE_P(chunk_max_abs_diff) == IS_DOUBLE
                ? Z_DVAL_P(chunk_max_abs_diff)
                : (double) Z_LVAL_P(chunk_max_abs_diff);
            if (value > max_abs_diff) {
                max_abs_diff = value;
            }
        }

        array_init(&chunk_summary);
        add_assoc_long(&chunk_summary, "offset", (zend_long) offset);
        add_assoc_long(&chunk_summary, "compared_values", (zend_long) chunk_values);
        add_assoc_bool(&chunk_summary, "matched", chunk_matched != NULL && zend_is_true(chunk_matched));
        add_assoc_double(&chunk_summary, "max_abs_diff", chunk_max_abs_diff != NULL && Z_TYPE_P(chunk_max_abs_diff) == IS_DOUBLE ? Z_DVAL_P(chunk_max_abs_diff) : 0.0);
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
    add_assoc_double(&compare, "tolerance", 0.00001);
    add_assoc_double(&compare, "max_abs_diff", max_abs_diff);
    add_assoc_long(&compare, "chunk_count", (zend_long) chunk_count);
    add_assoc_zval(&compare, "chunks", &chunks);
    add_assoc_long(&compare, "token_id", (zend_long) token_id);
    add_assoc_long(&compare, "width", (zend_long) width);
    zend_string_release(tensor_name);
    add_assoc_zval(embedding_probe, "numeric_compare", &compare);
    return matched ? SUCCESS : FAILURE;
}

static void king_inference_cuda_decoder_graph_executor_copy_embedding_compare(
    zval *result,
    zval *embedding_probe
) {
    zval *compare = king_inference_array_find(embedding_probe, "numeric_compare");
    zval copy;

    if (compare == NULL) {
        return;
    }

    ZVAL_COPY(&copy, compare);
    add_assoc_zval(result, "embedding_numeric_compare", &copy);
}
