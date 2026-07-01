/*
 * CUDA decoder graph RMSNorm helpers for native King GPU inference.
 */

#include "../../device/compare/norm/cuda_decoder_graph_executor_ffn_norm_compare.inc"

static zend_result king_inference_cuda_decoder_graph_executor_execute_post_rms_norm(
    king_inference_model_object *model,
    zval *graph,
    zend_string *input_id,
    king_inference_cuda_device_ptr input_output,
    zend_ulong input_width,
    zval *probe,
    const char *probe_prefix,
    king_inference_cuda_device_ptr *norm_output,
    zend_ulong *norm_width,
    zend_string **norm_id_out,
    zend_ulong *executed_device_ops
) {
    zval *norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, input_id, "rms_norm");
    zend_string *norm_id = NULL;
    zend_string *weight = NULL;
    double epsilon = 0.000001;
    size_t bytes;

    *norm_output = 0;
    *norm_width = 0;
    if (norm_id_out != NULL) {
        *norm_id_out = NULL;
    }
    add_assoc_bool(probe, "post_rms_norm_attempted", norm_op != NULL);
    add_assoc_bool(probe, "post_rms_norm_available", model->cuda_rms_norm_available);
    add_assoc_bool(probe, "post_rms_norm_executed", false);
    add_assoc_bool(probe, "post_rms_norm_retained_device_output", false);
    if (probe_prefix != NULL) {
        add_assoc_string(probe, "post_rms_norm_scope", probe_prefix);
    }
    if (norm_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_rms_norm_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_post_rms_norm_unavailable");
        return FAILURE;
    }
    if (input_output == 0
        || input_width == 0
        || input_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || king_inference_graph_required_string(norm_op, "id", &norm_id) != SUCCESS
        || king_inference_graph_required_string(norm_op, "weight", &weight) != SUCCESS
        || king_inference_graph_finite_double_option(norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
        || epsilon <= 0.0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_post_rms_norm_contract_invalid");
        return FAILURE;
    }

    bytes = (size_t) input_width * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(model, bytes, norm_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_post_rms_norm_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_rms_norm_f32(model, weight, input_output, *norm_output, input_width, epsilon) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, norm_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_rms_norm_result,
            model->cuda_rms_norm_error[0] != '\0'
                ? model->cuda_rms_norm_error
                : "cuda_decoder_graph_post_rms_norm_execution_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_rms_norm_execution_count++;
    model->cuda_decoder_graph_executor_last_rms_norm_width = input_width;
    *norm_width = input_width;
    if (norm_id_out != NULL) {
        *norm_id_out = norm_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(probe, "post_rms_norm_input", zend_string_copy(input_id));
    add_assoc_str(probe, "post_rms_norm", zend_string_copy(norm_id));
    add_assoc_str(probe, "post_rms_norm_weight", zend_string_copy(weight));
    add_assoc_bool(probe, "post_rms_norm_executed", true);
    add_assoc_bool(probe, "post_rms_norm_retained_device_output", true);
    add_assoc_long(probe, "post_rms_norm_width", (zend_long) input_width);
    add_assoc_long(probe, "post_rms_norm_bytes", (zend_long) bytes);
    add_assoc_double(probe, "post_rms_norm_epsilon", epsilon);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_ffn_norm(
    king_inference_model_object *model,
    zval *graph,
    zend_string *residual_id,
    king_inference_cuda_device_ptr residual_output,
    zend_ulong residual_width,
    zval *ffn_norm_probe,
    king_inference_cuda_device_ptr *ffn_norm_output,
    zend_ulong *ffn_norm_width,
    zend_string **ffn_norm_id_out,
    zend_ulong *executed_device_ops
) {
    zval *ffn_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, residual_id, "rms_norm");
    zend_string *ffn_norm_id = NULL;
    zend_string *weight = NULL;
    double epsilon = 0.000001;
    size_t bytes;

    *ffn_norm_output = 0;
    *ffn_norm_width = 0;
    add_assoc_string(ffn_norm_probe, "type", "gpu_decoder_ffn_norm_device_execution");
    add_assoc_bool(ffn_norm_probe, "attempted", ffn_norm_op != NULL);
    add_assoc_bool(ffn_norm_probe, "available", model->cuda_decoder_graph_executor_ffn_norm_execution_available);
    add_assoc_bool(ffn_norm_probe, "executed", false);
    add_assoc_bool(ffn_norm_probe, "retained_device_output", false);
    if (ffn_norm_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_ffn_norm_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_norm_execution_unavailable");
        return FAILURE;
    }
    if (residual_output == 0 || residual_width == 0 || residual_width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_norm_input_invalid");
        return FAILURE;
    }
    if (king_inference_graph_required_string(ffn_norm_op, "id", &ffn_norm_id) != SUCCESS
        || king_inference_graph_required_string(ffn_norm_op, "weight", &weight) != SUCCESS
        || king_inference_graph_finite_double_option(ffn_norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
        || epsilon <= 0.0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_norm_contract_invalid");
        return FAILURE;
    }

    bytes = (size_t) residual_width * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(model, bytes, ffn_norm_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_ffn_norm_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_rms_norm_f32(model, weight, residual_output, *ffn_norm_output, residual_width, epsilon) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, ffn_norm_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_rms_norm_result,
            model->cuda_rms_norm_error[0] != '\0'
                ? model->cuda_rms_norm_error
                : "cuda_decoder_graph_ffn_norm_execution_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_compare_block0_ffn_norm(
            model,
            weight,
            residual_output,
            residual_width,
            *ffn_norm_output,
            residual_width,
            epsilon,
            ffn_norm_probe
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, ffn_norm_output);
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_rms_norm_execution_count++;
    model->cuda_decoder_graph_executor_ffn_norm_execution_count++;
    model->cuda_decoder_graph_executor_last_rms_norm_width = residual_width;
    model->cuda_decoder_graph_executor_last_ffn_norm_width = residual_width;
    *ffn_norm_width = residual_width;
    if (ffn_norm_id_out != NULL) {
        *ffn_norm_id_out = ffn_norm_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(ffn_norm_probe, "input", zend_string_copy(residual_id));
    add_assoc_str(ffn_norm_probe, "ffn_norm", zend_string_copy(ffn_norm_id));
    add_assoc_bool(ffn_norm_probe, "executed", true);
    add_assoc_bool(ffn_norm_probe, "retained_device_output", true);
    add_assoc_long(ffn_norm_probe, "width", (zend_long) residual_width);
    add_assoc_long(ffn_norm_probe, "bytes", (zend_long) bytes);
    add_assoc_double(ffn_norm_probe, "epsilon", epsilon);
    return SUCCESS;
}
