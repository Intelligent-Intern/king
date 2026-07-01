/*
 * Block-0 Q/K/V projection slice numeric compare.
 */

static void king_inference_cuda_decoder_graph_executor_add_qkv_projection_compare_failure(
    zval *linear_probe,
    zval *compare,
    const char *status
) {
    add_assoc_string(compare, "status", status != NULL ? status : "qkv_projection_numeric_compare_failed");
    add_assoc_bool(compare, "matched", false);
    add_assoc_zval(linear_probe, "qkv_projection_numeric_compare", compare);
}

static zend_ulong king_inference_cuda_decoder_graph_executor_qkv_projection_slice_length(
    king_inference_model_object *model,
    king_inference_attention_role role,
    zend_ulong rows
) {
    zend_ulong query_heads = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT];
    zend_ulong kv_heads = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV];
    zend_ulong configured = role == KING_INFERENCE_ATTENTION_VALUE
        ? model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH]
        : model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH];
    zend_ulong heads = role == KING_INFERENCE_ATTENTION_QUERY ? query_heads : kv_heads;

    if (heads == 0) {
        heads = query_heads;
    }
    if (configured > 0 && configured <= rows) {
        return configured;
    }
    if (heads > 0 && rows >= heads && rows % heads == 0) {
        return rows / heads;
    }
    return rows;
}

static bool king_inference_cuda_decoder_graph_executor_resolve_qkv_projection_role(
    king_inference_model_object *model,
    zend_string *linear_weight_tensor,
    king_inference_attention_role *role_out,
    const char **projection_out,
    zend_ulong *slice_length_out,
    zend_ulong rows
) {
    static const king_inference_attention_role roles[] = {
        KING_INFERENCE_ATTENTION_QUERY,
        KING_INFERENCE_ATTENTION_KEY,
        KING_INFERENCE_ATTENTION_VALUE
    };
    static const char *names[] = {"query", "key", "value"};

    for (size_t i = 0; i < sizeof(roles) / sizeof(roles[0]); i++) {
        zend_string *resolved = NULL;

        if (king_inference_resolve_attention_tensor_name(model, roles[i], 0, &resolved, NULL, NULL) == SUCCESS
            && resolved != NULL) {
            bool matched = linear_weight_tensor != NULL && zend_string_equals(resolved, linear_weight_tensor);

            zend_string_release(resolved);
            if (matched) {
                *role_out = roles[i];
                *projection_out = names[i];
                *slice_length_out = king_inference_cuda_decoder_graph_executor_qkv_projection_slice_length(
                    model,
                    roles[i],
                    rows
                );
                return true;
            }
        }
    }

    return false;
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_qkv_projection_slice(
    king_inference_model_object *model,
    zend_ulong token_id,
    zend_string *rms_weight_tensor,
    zend_string *linear_weight_tensor,
    king_inference_cuda_device_ptr linear_output,
    zend_ulong input_width,
    zend_ulong linear_rows,
    double epsilon,
    zval *linear_probe
) {
    king_inference_tensor_native_view weight_view;
    king_inference_tensor_type_info type_info;
    king_inference_attention_role role = KING_INFERENCE_ATTENTION_QUERY;
    const char *projection = NULL;
    zend_ulong cols = 0;
    zend_ulong rows = 0;
    zend_ulong slice_length = 0;
    double *input_vector = NULL;
    double scale = 0.0;
    float *expected = NULL;
    zval compare;
    zval *matched;
    char label[96];

    if (!king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }
    if (linear_weight_tensor == NULL) {
        return SUCCESS;
    }
    if (king_inference_tensor_native_view_resolve(model, linear_weight_tensor, &weight_view) != SUCCESS) {
        return FAILURE;
    }
    if (weight_view.rank != 2
        || king_inference_tensor_dimension_at(weight_view.dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(weight_view.dimensions, 1, &rows) != SUCCESS
        || !king_inference_cuda_decoder_graph_executor_resolve_qkv_projection_role(
            model,
            linear_weight_tensor,
            &role,
            &projection,
            &slice_length,
            rows
        )) {
        return SUCCESS;
    }

    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_qkv_projection_slice_numeric_compare");
    add_assoc_string(&compare, "projection", projection);
    add_assoc_long(&compare, "token_id", (zend_long) token_id);
    add_assoc_str(&compare, "tensor", zend_string_copy(linear_weight_tensor));

    if (linear_output == 0
        || input_width == 0
        || linear_rows == 0
        || rms_weight_tensor == NULL
        || cols != input_width
        || rows == 0
        || linear_rows > rows
        || slice_length == 0
        || slice_length > linear_rows
        || slice_length > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_add_qkv_projection_compare_failure(
            linear_probe,
            &compare,
            "qkv_projection_compare_argument_invalid"
        );
        return FAILURE;
    }
    if (!king_inference_tensor_type_lookup(weight_view.type, &type_info)) {
        king_inference_cuda_decoder_graph_executor_add_qkv_projection_compare_failure(
            linear_probe,
            &compare,
            "qkv_projection_tensor_type_unknown"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_build_bos_first_rms_norm_vector(
            model,
            token_id,
            rms_weight_tensor,
            input_width,
            epsilon,
            &input_vector,
            &scale
    ) != SUCCESS) {
        zval_ptr_dtor(&compare);
        return FAILURE;
    }

    expected = ecalloc((size_t) slice_length, sizeof(float));
    for (zend_ulong i = 0; i < slice_length; i++) {
        zend_ulong row_base = i * input_width;
        double value = 0.0;

        if (king_inference_tensor_row_dot(&weight_view, row_base, input_width, input_vector, &value) != SUCCESS) {
            efree(expected);
            efree(input_vector);
            zval_ptr_dtor(&compare);
            return FAILURE;
        }
        expected[i] = (float) value;
    }
    efree(input_vector);

    snprintf(label, sizeof(label), "blk0_attn_%s_projection_head0_slice", projection);
    zval_ptr_dtor(&compare);
    if (king_inference_cuda_device_vector_numeric_compare(
            model,
            label,
            "qkv_projection",
            0,
            ZSTR_VAL(linear_weight_tensor),
            0,
            linear_output,
            slice_length,
            expected,
            slice_length,
            0.001,
            &compare
    ) != SUCCESS) {
        add_assoc_string(&compare, "type", "gpu_decoder_qkv_projection_slice_numeric_compare");
        add_assoc_string(&compare, "projection", projection);
        add_assoc_str(&compare, "tensor", zend_string_copy(linear_weight_tensor));
        add_assoc_string(&compare, "tensor_type", type_info.name);
        add_assoc_long(&compare, "slice_offset", 0);
        add_assoc_long(&compare, "slice_length", (zend_long) slice_length);
        add_assoc_long(&compare, "input_width", (zend_long) input_width);
        add_assoc_long(&compare, "rows", (zend_long) rows);
        add_assoc_double(&compare, "rms_norm_scale", scale);
        add_assoc_zval(linear_probe, "qkv_projection_numeric_compare", &compare);
        efree(expected);
        return FAILURE;
    }

    add_assoc_string(&compare, "type", "gpu_decoder_qkv_projection_slice_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_projection_row_dot_first_rms_norm_head0_slice");
    add_assoc_string(&compare, "projection", projection);
    add_assoc_str(&compare, "tensor", zend_string_copy(linear_weight_tensor));
    add_assoc_string(&compare, "tensor_type", type_info.name);
    add_assoc_long(&compare, "slice_offset", 0);
    add_assoc_long(&compare, "slice_length", (zend_long) slice_length);
    add_assoc_long(&compare, "input_width", (zend_long) input_width);
    add_assoc_long(&compare, "rows", (zend_long) rows);
    add_assoc_double(&compare, "rms_norm_scale", scale);
    matched = king_inference_array_find(&compare, "matched");
    add_assoc_bool(linear_probe, "qkv_projection_numeric_compare_failed", matched == NULL || !zend_is_true(matched));
    add_assoc_zval(linear_probe, "qkv_projection_numeric_compare", &compare);
    efree(expected);
    return matched != NULL && zend_is_true(matched) ? SUCCESS : FAILURE;
}

static void king_inference_cuda_decoder_graph_executor_record_qkv_projection_compare(
    king_inference_model_object *model,
    zend_ulong token_id,
    zend_string *rms_weight_tensor,
    zend_string *linear_weight_tensor,
    king_inference_cuda_device_ptr linear_output,
    zend_ulong input_width,
    zend_ulong linear_rows,
    double epsilon,
    zval *linear_probe
) {
    (void) king_inference_cuda_decoder_graph_executor_compare_qkv_projection_slice(
        model,
        token_id,
        rms_weight_tensor,
        linear_weight_tensor,
        linear_output,
        input_width,
        linear_rows,
        epsilon,
        linear_probe
    );
}

static void king_inference_cuda_decoder_graph_executor_add_qkv_projection_execution_probe(
    king_inference_model_object *model,
    zval *parent_probe,
    const char *field,
    const char *type,
    zend_ulong token_id,
    zend_string *rms_weight_tensor,
    zval *linear_op,
    king_inference_cuda_device_ptr linear_output,
    zend_ulong input_width,
    zend_ulong linear_rows,
    double epsilon
) {
    zval probe;
    zval *weight = linear_op != NULL ? king_inference_array_find(linear_op, "weight") : NULL;
    zend_string *weight_tensor = weight != NULL && Z_TYPE_P(weight) == IS_STRING ? Z_STR_P(weight) : NULL;

    array_init(&probe);
    add_assoc_string(&probe, "type", type);
    add_assoc_bool(&probe, "executed", true);
    add_assoc_long(&probe, "rows", (zend_long) linear_rows);
    king_inference_cuda_decoder_graph_executor_record_qkv_projection_compare(
        model,
        token_id,
        rms_weight_tensor,
        weight_tensor,
        linear_output,
        input_width,
        linear_rows,
        epsilon,
        &probe
    );
    add_assoc_zval(parent_probe, field, &probe);
}

static zval *king_inference_cuda_decoder_graph_executor_first_head_qkv_projection_compare(
    zval *kv_head_prepare_probe,
    const char *field
) {
    zval *heads = king_inference_array_find(kv_head_prepare_probe, "query_heads");
    zval *head0 = heads != NULL && Z_TYPE_P(heads) == IS_ARRAY
        ? zend_hash_index_find(Z_ARRVAL_P(heads), 0)
        : NULL;
    zval *kv_probe = head0 != NULL && Z_TYPE_P(head0) == IS_ARRAY
        ? king_inference_array_find(head0, "kv_head_prepare_execution")
        : NULL;
    zval *projection = kv_probe != NULL && Z_TYPE_P(kv_probe) == IS_ARRAY
        ? king_inference_array_find(kv_probe, field)
        : NULL;

    return projection != NULL && Z_TYPE_P(projection) == IS_ARRAY
        ? king_inference_array_find(projection, "qkv_projection_numeric_compare")
        : NULL;
}

static void king_inference_cuda_decoder_graph_executor_copy_block0_qkv_projection_compares(
    zval *result,
    zval *linear_probe,
    zval *kv_head_prepare_probe
) {
    zval compares;
    zval copy;
    zval *query = king_inference_array_find(linear_probe, "qkv_projection_numeric_compare");
    zval *key = king_inference_cuda_decoder_graph_executor_first_head_qkv_projection_compare(
        kv_head_prepare_probe,
        "key_projection_device_execution"
    );
    zval *value = king_inference_cuda_decoder_graph_executor_first_head_qkv_projection_compare(
        kv_head_prepare_probe,
        "value_projection_device_execution"
    );
    zend_ulong copied = 0;

    array_init(&compares);
    if (query != NULL && Z_TYPE_P(query) == IS_ARRAY) {
        ZVAL_COPY(&copy, query);
        add_assoc_zval(&compares, "query", &copy);
        copied++;
    }
    if (key != NULL && Z_TYPE_P(key) == IS_ARRAY) {
        ZVAL_COPY(&copy, key);
        add_assoc_zval(&compares, "key", &copy);
        copied++;
    }
    if (value != NULL && Z_TYPE_P(value) == IS_ARRAY) {
        ZVAL_COPY(&copy, value);
        add_assoc_zval(&compares, "value", &copy);
        copied++;
    }

    if (copied == 0) {
        zval_ptr_dtor(&compares);
        return;
    }
    add_assoc_bool(result, "block0_qkv_projection_numeric_compares_complete", copied == 3);
    add_assoc_zval(result, "block0_qkv_projection_numeric_compares", &compares);
}

static void king_inference_cuda_decoder_graph_executor_copy_qkv_projection_compare(
    zval *result,
    zval *linear_probe
) {
    zval *compare = king_inference_array_find(linear_probe, "qkv_projection_numeric_compare");
    zval copy;

    if (compare == NULL) {
        return;
    }

    ZVAL_COPY(&copy, compare);
    add_assoc_zval(result, "qkv_projection_numeric_compare", &copy);
}
