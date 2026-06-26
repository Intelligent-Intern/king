/*
 * CUDA decoder graph RoPE execution helpers for native King GPU inference.
 */

static zend_result king_inference_cuda_decoder_graph_executor_rope_shape(
    king_inference_model_object *model,
    zval *op,
    zend_ulong input_length,
    zend_ulong *offset,
    zend_ulong *head_dim,
    zend_ulong *position,
    double *position_scale,
    double *rope_base
) {
    zend_ulong rope_offset = king_inference_tensor_option_ulong(op, "offset", 0);
    zend_ulong rope_head_dim = king_inference_tensor_option_ulong(op, "head_dim", input_length - rope_offset);
    zend_ulong rope_position = king_inference_tensor_option_ulong(op, "position", 0);
    double rope_position_scale;
    double rope_base_value;

    if (king_inference_graph_finite_double_option(op, "position_scale", 1.0, &rope_position_scale) != SUCCESS
        || king_inference_graph_finite_double_option(op, "rope_base", 10000.0, &rope_base_value) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_rope_contract_invalid");
        return FAILURE;
    }
    if (input_length == 0
        || input_length > UINT_MAX
        || rope_offset > input_length
        || rope_offset > UINT_MAX
        || rope_head_dim == 0
        || (rope_head_dim % 2) != 0
        || rope_head_dim > input_length - rope_offset
        || rope_head_dim > UINT_MAX
        || !isfinite(rope_position_scale)
        || !isfinite(rope_base_value)
        || rope_base_value <= 0.0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_rope_shape_invalid");
        return FAILURE;
    }

    *offset = rope_offset;
    *head_dim = rope_head_dim;
    *position = rope_position;
    *position_scale = rope_position_scale;
    *rope_base = rope_base_value;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_rope_after_slice(
    king_inference_model_object *model,
    zval *graph,
    zend_string *slice_id,
    king_inference_cuda_device_ptr slice_output,
    zend_ulong slice_length,
    zval *rope_probe,
    zend_ulong *executed_device_ops,
    bool *rope_executed
) {
    zval *rope_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, slice_id, "rope");
    zend_ulong rope_offset = 0;
    zend_ulong rope_head_dim = 0;
    zend_ulong rope_position = 0;
    double rope_position_scale = 1.0;
    double rope_base = 10000.0;
    king_inference_cuda_device_ptr rope_output = 0;

    *rope_executed = false;
    add_assoc_bool(rope_probe, "attempted", rope_op != NULL);
    if (rope_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_rope_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_rope_execution_unavailable"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_rope_shape(
            model,
            rope_op,
            slice_length,
            &rope_offset,
            &rope_head_dim,
            &rope_position,
            &rope_position_scale,
            &rope_base
    ) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(
            model,
            (size_t) slice_length * sizeof(float),
            &rope_output
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_rope_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_rope_f32(
            model,
            slice_output,
            rope_output,
            slice_length,
            rope_offset,
            rope_head_dim,
            rope_position,
            rope_position_scale,
            rope_base
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rope_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_rope_result,
            model->cuda_rope_error[0] != '\0'
                ? model->cuda_rope_error
                : "cuda_decoder_graph_rope_execution_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rope_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_rope_free_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_rope_execution_count++;
    model->cuda_decoder_graph_executor_last_rope_length = slice_length;
    model->cuda_decoder_graph_executor_last_rope_position = rope_position;
    (*executed_device_ops)++;
    *rope_executed = true;

    add_assoc_bool(rope_probe, "executed", true);
    add_assoc_long(rope_probe, "length", (zend_long) slice_length);
    add_assoc_long(rope_probe, "offset", (zend_long) rope_offset);
    add_assoc_long(rope_probe, "head_dim", (zend_long) rope_head_dim);
    add_assoc_long(rope_probe, "position", (zend_long) rope_position);
    add_assoc_double(rope_probe, "position_scale", rope_position_scale);
    add_assoc_double(rope_probe, "rope_base", rope_base);
    add_assoc_long(rope_probe, "bytes", (zend_long) ((size_t) slice_length * sizeof(float)));
    return SUCCESS;
}
