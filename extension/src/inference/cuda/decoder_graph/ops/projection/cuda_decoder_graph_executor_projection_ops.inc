/*
 * CUDA decoder graph attention output-projection helpers for native King GPU inference.
 */

#include "../../device/compare/attention/cuda_decoder_graph_executor_attention_residual_compare.inc"

static zend_result king_inference_cuda_decoder_graph_executor_execute_attention_output_projection(
    king_inference_model_object *model,
    zval *graph,
    zend_string *stack_id,
    king_inference_cuda_device_ptr stack_output,
    zend_ulong stack_width,
    zval *projection_probe,
    king_inference_cuda_device_ptr *projection_output,
    zend_ulong *projection_width,
    zend_string **projection_id_out,
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
    if (king_inference_graph_required_string(projection_op, "id", &projection_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_attention_output_projection_id_invalid"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_linear_to_device(
            model,
            projection_op,
            stack_output,
            stack_width,
            projection_output,
            projection_width
    ) != SUCCESS) {
        return FAILURE;
    }
    if (projection_id_out != NULL) {
        *projection_id_out = projection_id;
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

static zend_result king_inference_cuda_decoder_graph_executor_execute_attention_residual_add(
    king_inference_model_object *model,
    zval *graph,
    zend_string *hidden_id,
    king_inference_cuda_device_ptr hidden_output,
    zend_ulong hidden_width,
    zend_string *projection_id,
    king_inference_cuda_device_ptr projection_output,
    zend_ulong projection_width,
    zval *residual_probe,
    king_inference_cuda_device_ptr *residual_output,
    zend_ulong *residual_width,
    zend_string **residual_id_out,
    zend_ulong *executed_device_ops
) {
    zval *residual_op = king_inference_cuda_decoder_graph_executor_add_op_for_inputs(graph, hidden_id, projection_id);
    zend_string *residual_id = NULL;

    *residual_output = 0;
    *residual_width = 0;
    add_assoc_string(residual_probe, "type", "gpu_decoder_attention_residual_device_execution");
    add_assoc_bool(residual_probe, "attempted", residual_op != NULL);
    add_assoc_bool(residual_probe, "available", model->cuda_decoder_graph_executor_attention_residual_execution_available);
    add_assoc_bool(residual_probe, "executed", false);
    add_assoc_bool(residual_probe, "retained_device_output", false);
    if (residual_op == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_attention_residual_missing");
        return FAILURE;
    }
    if (!model->cuda_decoder_graph_executor_attention_residual_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_attention_residual_execution_unavailable"
        );
        return FAILURE;
    }
    if (hidden_output == 0
        || projection_output == 0
        || hidden_width == 0
        || hidden_width != projection_width
        || hidden_width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_attention_residual_input_invalid");
        return FAILURE;
    }
    if (king_inference_graph_required_string(residual_op, "id", &residual_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_attention_residual_id_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(
            model,
            (size_t) hidden_width * sizeof(float),
            residual_output
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_attention_residual_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_vector_add(model, hidden_output, projection_output, *residual_output, hidden_width) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_attention_residual_add_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_compare_attention_residual_add(
            model,
            hidden_output,
            hidden_width,
            projection_output,
            projection_width,
            *residual_output,
            hidden_width,
            residual_probe
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_attention_residual_execution_count++;
    model->cuda_decoder_graph_executor_last_attention_residual_width = hidden_width;
    *residual_width = hidden_width;
    if (residual_id_out != NULL) {
        *residual_id_out = residual_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(residual_probe, "residual", zend_string_copy(residual_id));
    add_assoc_bool(residual_probe, "executed", true);
    add_assoc_bool(residual_probe, "retained_device_output", true);
    add_assoc_long(residual_probe, "width", (zend_long) hidden_width);
    return SUCCESS;
}
