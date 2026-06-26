/*
 * CUDA decoder graph K/V head preparation helpers for native King GPU inference.
 */

static zval *king_inference_cuda_decoder_graph_executor_nth_op_for_input(
    zval *graph,
    zend_string *input_id,
    const char *name,
    zend_ulong ordinal
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    zend_ulong matched = 0;
    size_t name_length = name != NULL ? strlen(name) : 0;

    if (ops == NULL || input_id == NULL || name_length == 0) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *input;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        input = king_inference_array_find(entry, "input");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && Z_STRLEN_P(op_name) == name_length
            && memcmp(Z_STRVAL_P(op_name), name, name_length) == 0
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(input, input_id)) {
            if (matched == ordinal) {
                return entry;
            }
            matched++;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_cuda_decoder_graph_executor_linear_to_device(
    king_inference_model_object *model,
    zval *linear_op,
    king_inference_cuda_device_ptr input,
    zend_ulong input_width,
    king_inference_cuda_device_ptr *output,
    zend_ulong *rows
) {
    zend_string *weight = NULL;

    *output = 0;
    *rows = 0;
    if (linear_op == NULL
        || input == 0
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            linear_op,
            input_width,
            &weight,
            rows
        ) != SUCCESS
        || *rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(model, (size_t) *rows * sizeof(float), output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_linear_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_quantized_matvec_q8_0(model, weight, input, input_width, *output, *rows) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_quantized_matvec_result,
            model->cuda_quantized_matvec_error[0] != '\0'
                ? model->cuda_quantized_matvec_error
                : "cuda_decoder_graph_kv_linear_execution_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_linear_execution_count++;
    model->cuda_decoder_graph_executor_last_linear_input_width = input_width;
    model->cuda_decoder_graph_executor_last_linear_rows = *rows;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_slice_to_device(
    king_inference_model_object *model,
    zval *slice_op,
    king_inference_cuda_device_ptr input,
    zend_ulong input_length,
    king_inference_cuda_device_ptr *output,
    zend_ulong *offset,
    zend_ulong *length
) {
    *output = 0;
    *offset = 0;
    *length = 0;
    if (slice_op == NULL
        || input == 0
        || king_inference_cuda_decoder_graph_executor_slice_shape(
            model,
            slice_op,
            input_length,
            offset,
            length
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(model, (size_t) *length * sizeof(float), output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_slice_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_vector_slice(model, input, *offset, *output, *length) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_kv_slice_execution_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_slice_execution_count++;
    model->cuda_decoder_graph_executor_last_slice_offset = *offset;
    model->cuda_decoder_graph_executor_last_slice_length = *length;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_kv_head_prepare(
    king_inference_model_object *model,
    zval *graph,
    zend_string *rms_norm_id,
    king_inference_cuda_device_ptr rms_norm_output,
    zend_ulong width,
    zval *kv_probe,
    zend_ulong *executed_device_ops,
    bool *kv_prepared
) {
    zval *key_linear_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, rms_norm_id, "linear", 1);
    zval *value_linear_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, rms_norm_id, "linear", 2);
    zval *key_slice_op = NULL;
    zval *value_slice_op = NULL;
    zval key_rope_probe;
    zend_string *key_linear_id = NULL;
    zend_string *key_slice_id = NULL;
    zend_string *value_linear_id = NULL;
    king_inference_cuda_device_ptr key_linear_output = 0;
    king_inference_cuda_device_ptr value_linear_output = 0;
    king_inference_cuda_device_ptr key_slice_output = 0;
    king_inference_cuda_device_ptr value_slice_output = 0;
    zend_ulong key_rows = 0;
    zend_ulong value_rows = 0;
    zend_ulong key_slice_offset = 0;
    zend_ulong key_slice_length = 0;
    zend_ulong value_slice_offset = 0;
    zend_ulong value_slice_length = 0;
    bool key_rope_executed = false;

    *kv_prepared = false;
    add_assoc_bool(kv_probe, "attempted", key_linear_op != NULL || value_linear_op != NULL);
    if (key_linear_op == NULL && value_linear_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_kv_head_prepare_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_kv_head_prepare_execution_unavailable"
        );
        return FAILURE;
    }
    if (key_linear_op == NULL
        || value_linear_op == NULL
        || king_inference_graph_required_string(key_linear_op, "id", &key_linear_id) != SUCCESS
        || king_inference_graph_required_string(value_linear_op, "id", &value_linear_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_linear_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_linear_to_device(
            model,
            key_linear_op,
            rms_norm_output,
            width,
            &key_linear_output,
            &key_rows
    ) != SUCCESS) {
        return FAILURE;
    }
    (*executed_device_ops)++;
    key_slice_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, key_linear_id, "slice");
    if (key_slice_op == NULL || king_inference_graph_required_string(key_slice_op, "id", &key_slice_id) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_key_slice_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_slice_to_device(
            model,
            key_slice_op,
            key_linear_output,
            key_rows,
            &key_slice_output,
            &key_slice_offset,
            &key_slice_length
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    (*executed_device_ops)++;
    array_init(&key_rope_probe);
    add_assoc_string(&key_rope_probe, "type", "gpu_decoder_key_rope_device_execution");
    add_assoc_bool(&key_rope_probe, "attempted", false);
    add_assoc_bool(&key_rope_probe, "available", model->cuda_decoder_graph_executor_rope_execution_available);
    add_assoc_bool(&key_rope_probe, "executed", false);
    add_assoc_bool(&key_rope_probe, "retained_device_output", false);
    if (king_inference_cuda_decoder_graph_executor_execute_rope_after_slice(
            model,
            graph,
            key_slice_id,
            key_slice_output,
            key_slice_length,
            &key_rope_probe,
            executed_device_ops,
            &key_rope_executed
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        zval_ptr_dtor(&key_rope_probe);
        return FAILURE;
    }
    if (!key_rope_executed) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        zval_ptr_dtor(&key_rope_probe);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_key_rope_missing");
        return FAILURE;
    }
    add_assoc_zval(kv_probe, "key_rope_execution", &key_rope_probe);

    if (king_inference_cuda_decoder_graph_executor_linear_to_device(
            model,
            value_linear_op,
            rms_norm_output,
            width,
            &value_linear_output,
            &value_rows
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    (*executed_device_ops)++;
    value_slice_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, value_linear_id, "slice");
    if (value_slice_op == NULL) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_value_slice_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_slice_to_device(
            model,
            value_slice_op,
            value_linear_output,
            value_rows,
            &value_slice_output,
            &value_slice_offset,
            &value_slice_length
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    (*executed_device_ops)++;

    model->cuda_decoder_graph_executor_kv_head_prepare_execution_count++;
    model->cuda_decoder_graph_executor_last_kv_head_key_length = key_slice_length;
    model->cuda_decoder_graph_executor_last_kv_head_value_length = value_slice_length;
    *kv_prepared = true;

    add_assoc_bool(kv_probe, "executed", true);
    add_assoc_bool(kv_probe, "key_rope_executed", key_rope_executed);
    add_assoc_long(kv_probe, "key_linear_rows", (zend_long) key_rows);
    add_assoc_long(kv_probe, "key_slice_offset", (zend_long) key_slice_offset);
    add_assoc_long(kv_probe, "key_slice_length", (zend_long) key_slice_length);
    add_assoc_long(kv_probe, "value_linear_rows", (zend_long) value_rows);
    add_assoc_long(kv_probe, "value_slice_offset", (zend_long) value_slice_offset);
    add_assoc_long(kv_probe, "value_slice_length", (zend_long) value_slice_length);

    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_head_prepare_free_failed"
        );
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_head_prepare_free_failed"
        );
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_head_prepare_free_failed"
        );
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_head_prepare_free_failed"
        );
        return FAILURE;
    }
    return SUCCESS;
}
