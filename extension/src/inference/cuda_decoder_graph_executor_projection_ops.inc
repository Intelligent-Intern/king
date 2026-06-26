/*
 * CUDA decoder graph attention output-projection helpers for native King GPU inference.
 */

static zend_result king_inference_cuda_decoder_graph_executor_execute_attention_output_projection(
    king_inference_model_object *model,
    zval *graph,
    zend_string *stack_id,
    king_inference_cuda_device_ptr stack_output,
    zend_ulong stack_width,
    zval *projection_probe,
    king_inference_cuda_device_ptr *projection_output,
    zend_ulong *projection_width,
    zend_ulong *executed_device_ops
) {
    zval *projection_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, stack_id, "linear");
    zend_string *projection_id = NULL;

    *projection_output = 0;
    *projection_width = 0;
    add_assoc_string(projection_probe, "type", "gpu_decoder_attention_output_projection_device_execution");
    add_assoc_bool(projection_probe, "attempted", projection_op != NULL);
    add_assoc_bool(
        projection_probe,
        "available",
        model->cuda_decoder_graph_executor_attention_output_projection_execution_available
    );
    add_assoc_bool(projection_probe, "executed", false);
    add_assoc_bool(projection_probe, "retained_device_output", false);
    if (projection_op == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_attention_output_projection_missing"
        );
        return FAILURE;
    }
    if (stack_output == 0 || stack_width == 0 || stack_id == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_attention_output_projection_input_invalid"
        );
        return FAILURE;
    }
    if (!model->cuda_decoder_graph_executor_attention_output_projection_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_attention_output_projection_execution_unavailable"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(projection_op, "id", &projection_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_to_device(
            model,
            projection_op,
            stack_output,
            stack_width,
            projection_output,
            projection_width
    ) != SUCCESS) {
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_attention_output_projection_execution_count++;
    model->cuda_decoder_graph_executor_last_attention_output_projection_width = *projection_width;
    (*executed_device_ops)++;

    add_assoc_str(projection_probe, "input", zend_string_copy(stack_id));
    add_assoc_str(projection_probe, "projection", zend_string_copy(projection_id));
    add_assoc_bool(projection_probe, "executed", true);
    add_assoc_bool(projection_probe, "retained_device_output", true);
    add_assoc_long(projection_probe, "stack_width", (zend_long) stack_width);
    add_assoc_long(projection_probe, "projection_width", (zend_long) *projection_width);
    return SUCCESS;
}
