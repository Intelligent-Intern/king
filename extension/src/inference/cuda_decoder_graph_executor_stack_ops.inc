/*
 * CUDA decoder graph attention stack-slot helpers for native King GPU inference.
 */

static zval *king_inference_cuda_decoder_graph_executor_stack_op_for_input(
    zval *graph,
    zend_string *input_id
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;

    if (ops == NULL || input_id == NULL) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *inputs;
        zval *input;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        inputs = king_inference_array_find(entry, "inputs");
        if (op_name == NULL
            || Z_TYPE_P(op_name) != IS_STRING
            || !zend_string_equals_literal(Z_STR_P(op_name), "stack")
            || inputs == NULL
            || Z_TYPE_P(inputs) != IS_ARRAY) {
            continue;
        }
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(inputs), input) {
            if (king_inference_cuda_decoder_graph_executor_zval_string_equals(input, input_id)) {
                return entry;
            }
        } ZEND_HASH_FOREACH_END();
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_cuda_decoder_graph_executor_attention_stack_shape(
    king_inference_model_object *model,
    zval *stack_op,
    zend_string *context_id,
    zend_ulong context_length,
    zend_ulong *input_index,
    zend_ulong *input_count,
    zend_ulong *stack_width
) {
    zval *inputs;
    zval *input;
    zend_ulong index = 0;
    bool found = false;

    *input_index = 0;
    *input_count = 0;
    *stack_width = 0;
    inputs = stack_op != NULL ? king_inference_array_find(stack_op, "inputs") : NULL;
    if (inputs == NULL || Z_TYPE_P(inputs) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(inputs)) == 0) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_attention_stack_inputs_invalid"
        );
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(inputs), input) {
        if (input == NULL || Z_TYPE_P(input) != IS_STRING) {
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                -1,
                "cuda_decoder_graph_attention_stack_input_invalid"
            );
            return FAILURE;
        }
        if (king_inference_cuda_decoder_graph_executor_zval_string_equals(input, context_id)) {
            *input_index = index;
            found = true;
        }
        index++;
    } ZEND_HASH_FOREACH_END();

    if (!found
        || context_length == 0
        || context_length > UINT_MAX
        || index == 0
        || context_length > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_attention_stack_shape_invalid"
        );
        return FAILURE;
    }
    if (index > (zend_ulong) (SIZE_MAX / ((size_t) context_length * sizeof(float)))) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_attention_stack_width_overflow"
        );
        return FAILURE;
    }

    *input_count = index;
    *stack_width = index * context_length;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_prepare_attention_stack_slot(
    king_inference_model_object *model,
    zval *graph,
    zend_string *context_id,
    king_inference_cuda_device_ptr context_output,
    zend_ulong context_length,
    zval *stack_probe,
    king_inference_cuda_device_ptr *stack_output,
    zend_ulong *stack_width,
    zend_ulong *executed_device_ops
) {
    zval *stack_op = king_inference_cuda_decoder_graph_executor_stack_op_for_input(graph, context_id);
    zend_string *stack_id = NULL;
    zend_ulong input_index = 0;
    zend_ulong input_count = 0;
    zend_ulong output_offset = 0;

    *stack_output = 0;
    *stack_width = 0;
    add_assoc_bool(stack_probe, "attempted", stack_op != NULL);
    add_assoc_bool(
        stack_probe,
        "available",
        model->cuda_decoder_graph_executor_attention_stack_slot_execution_available
    );
    add_assoc_bool(stack_probe, "executed", false);
    add_assoc_bool(stack_probe, "retained_device_output", false);
    if (stack_op == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_attention_stack_missing");
        return FAILURE;
    }
    if (context_output == 0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_attention_stack_context_invalid");
        return FAILURE;
    }
    if (!model->cuda_decoder_graph_executor_attention_stack_slot_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_attention_stack_slot_execution_unavailable"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(stack_op, "id", &stack_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_attention_stack_id_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_attention_stack_shape(
            model,
            stack_op,
            context_id,
            context_length,
            &input_index,
            &input_count,
            stack_width
    ) != SUCCESS) {
        return FAILURE;
    }

    output_offset = input_index * context_length;
    if (output_offset > UINT_MAX) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_attention_stack_offset_invalid"
        );
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(
            model,
            (size_t) *stack_width * sizeof(float),
            stack_output
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_attention_stack_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_vector_copy_to_offset(
            model,
            context_output,
            *stack_output,
            output_offset,
            context_length
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, stack_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_attention_stack_slot_copy_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_attention_stack_slot_execution_count++;
    model->cuda_decoder_graph_executor_last_attention_stack_width = *stack_width;
    model->cuda_decoder_graph_executor_last_attention_stack_input_index = input_index;
    model->cuda_decoder_graph_executor_last_attention_stack_input_count = input_count;
    (*executed_device_ops)++;

    add_assoc_str(stack_probe, "stack", zend_string_copy(stack_id));
    add_assoc_str(stack_probe, "context", zend_string_copy(context_id));
    add_assoc_bool(stack_probe, "executed", true);
    add_assoc_bool(stack_probe, "retained_device_output", true);
    add_assoc_long(stack_probe, "input_index", (zend_long) input_index);
    add_assoc_long(stack_probe, "input_count", (zend_long) input_count);
    add_assoc_long(stack_probe, "context_length", (zend_long) context_length);
    add_assoc_long(stack_probe, "stack_width", (zend_long) *stack_width);
    add_assoc_long(stack_probe, "output_offset", (zend_long) output_offset);
    return SUCCESS;
}
