/*
 * CUDA decoder graph FFN execution helpers for native King GPU inference.
 */

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
    if (king_inference_cuda_rms_norm_f32(
            model,
            weight,
            residual_output,
            *ffn_norm_output,
            residual_width,
            epsilon
    ) != SUCCESS) {
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

static zend_result king_inference_cuda_decoder_graph_executor_execute_ffn_gate_up_projections(
    king_inference_model_object *model,
    zval *graph,
    zend_string *ffn_norm_id,
    king_inference_cuda_device_ptr ffn_norm_output,
    zend_ulong ffn_norm_width,
    zval *ffn_gate_up_probe,
    king_inference_cuda_device_ptr *gate_output,
    king_inference_cuda_device_ptr *up_output,
    zend_ulong *gate_rows,
    zend_ulong *up_rows,
    zend_ulong *executed_device_ops
) {
    zval *gate_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, ffn_norm_id, "linear", 0);
    zval *up_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, ffn_norm_id, "linear", 1);
    zend_string *gate_id = NULL;
    zend_string *up_id = NULL;

    *gate_output = 0;
    *up_output = 0;
    *gate_rows = 0;
    *up_rows = 0;
    add_assoc_string(ffn_gate_up_probe, "type", "gpu_decoder_ffn_gate_up_projection_device_execution");
    add_assoc_bool(ffn_gate_up_probe, "attempted", gate_op != NULL || up_op != NULL);
    add_assoc_bool(ffn_gate_up_probe, "available", model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available);
    add_assoc_bool(ffn_gate_up_probe, "executed", false);
    add_assoc_bool(ffn_gate_up_probe, "retained_device_output", false);
    if (gate_op == NULL && up_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_gate_up_projection_execution_unavailable");
        return FAILURE;
    }
    if (gate_op == NULL
        || up_op == NULL
        || ffn_norm_output == 0
        || ffn_norm_width == 0
        || king_inference_graph_required_string(gate_op, "id", &gate_id) != SUCCESS
        || king_inference_graph_required_string(up_op, "id", &up_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_gate_up_projection_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_linear_to_device(
            model,
            gate_op,
            ffn_norm_output,
            ffn_norm_width,
            gate_output,
            gate_rows
    ) != SUCCESS) {
        return FAILURE;
    }
    (*executed_device_ops)++;
    if (king_inference_cuda_decoder_graph_executor_linear_to_device(
            model,
            up_op,
            ffn_norm_output,
            ffn_norm_width,
            up_output,
            up_rows
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, gate_output);
        return FAILURE;
    }
    (*executed_device_ops)++;
    if (*gate_rows == 0 || *gate_rows != *up_rows) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, gate_output);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_gate_up_projection_shape_invalid");
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_count++;
    model->cuda_decoder_graph_executor_last_ffn_gate_rows = *gate_rows;
    model->cuda_decoder_graph_executor_last_ffn_up_rows = *up_rows;

    add_assoc_str(ffn_gate_up_probe, "gate", zend_string_copy(gate_id));
    add_assoc_str(ffn_gate_up_probe, "up", zend_string_copy(up_id));
    add_assoc_bool(ffn_gate_up_probe, "executed", true);
    add_assoc_bool(ffn_gate_up_probe, "retained_device_output", true);
    add_assoc_long(ffn_gate_up_probe, "input_width", (zend_long) ffn_norm_width);
    add_assoc_long(ffn_gate_up_probe, "gate_rows", (zend_long) *gate_rows);
    add_assoc_long(ffn_gate_up_probe, "up_rows", (zend_long) *up_rows);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_attention_projection_residual_ffn_norm_and_gate_up(
    king_inference_model_object *model,
    zval *graph,
    zend_string *hidden_id,
    king_inference_cuda_device_ptr hidden_output,
    zend_ulong hidden_width,
    zend_string *stack_id,
    king_inference_cuda_device_ptr stack_output,
    zend_ulong stack_width,
    zval *projection_probe,
    zval *residual_probe,
    zval *ffn_norm_probe,
    zval *ffn_gate_up_probe,
    zend_ulong *executed_device_ops,
    bool *projection_executed,
    bool *residual_executed,
    bool *ffn_norm_executed,
    bool *ffn_gate_up_executed
) {
    zend_string *projection_id = NULL;
    zend_string *residual_id = NULL;
    zend_string *ffn_norm_id = NULL;
    king_inference_cuda_device_ptr projection_output = 0;
    king_inference_cuda_device_ptr residual_output = 0;
    king_inference_cuda_device_ptr ffn_norm_output = 0;
    king_inference_cuda_device_ptr ffn_gate_output = 0;
    king_inference_cuda_device_ptr ffn_up_output = 0;
    zend_ulong projection_width = 0;
    zend_ulong residual_width = 0;
    zend_ulong ffn_norm_width = 0;
    zend_ulong ffn_gate_rows = 0;
    zend_ulong ffn_up_rows = 0;
    zend_result release_result = SUCCESS;

    *projection_executed = false;
    *residual_executed = false;
    *ffn_norm_executed = false;
    *ffn_gate_up_executed = false;
    add_assoc_string(ffn_gate_up_probe, "type", "gpu_decoder_ffn_gate_up_projection_device_execution");
    add_assoc_bool(ffn_gate_up_probe, "attempted", false);
    add_assoc_bool(ffn_gate_up_probe, "available", model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available);
    add_assoc_bool(ffn_gate_up_probe, "executed", false);
    add_assoc_bool(ffn_gate_up_probe, "retained_device_output", false);
    if (king_inference_cuda_decoder_graph_executor_execute_attention_output_projection(
            model,
            graph,
            stack_id,
            stack_output,
            stack_width,
            projection_probe,
            &projection_output,
            &projection_width,
            &projection_id,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_output);
        return FAILURE;
    }
    *projection_executed = true;
    if (king_inference_cuda_decoder_graph_executor_execute_attention_residual_add(
            model,
            graph,
            hidden_id,
            hidden_output,
            hidden_width,
            projection_id,
            projection_output,
            projection_width,
            residual_probe,
            &residual_output,
            &residual_width,
            &residual_id,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &residual_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_output);
        return FAILURE;
    }
    *residual_executed = true;
    if (king_inference_cuda_decoder_graph_executor_execute_ffn_norm(
            model,
            graph,
            residual_id,
            residual_output,
            residual_width,
            ffn_norm_probe,
            &ffn_norm_output,
            &ffn_norm_width,
            &ffn_norm_id,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &residual_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_output);
        return FAILURE;
    }
    *ffn_norm_executed = ffn_norm_output != 0 && ffn_norm_width > 0;
    if (*ffn_norm_executed
        && king_inference_cuda_decoder_graph_executor_execute_ffn_gate_up_projections(
            model,
            graph,
            ffn_norm_id,
            ffn_norm_output,
            ffn_norm_width,
            ffn_gate_up_probe,
            &ffn_gate_output,
            &ffn_up_output,
            &ffn_gate_rows,
            &ffn_up_rows,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &residual_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_output);
        return FAILURE;
    }
    *ffn_gate_up_executed = ffn_gate_output != 0 && ffn_up_output != 0 && ffn_gate_rows == ffn_up_rows;
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output) != SUCCESS) {
        release_result = FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output) != SUCCESS) {
        release_result = FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output) != SUCCESS) {
        release_result = FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &residual_output) != SUCCESS) {
        release_result = FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_output) != SUCCESS) {
        release_result = FAILURE;
    }
    if (release_result != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_attention_residual_ffn_gate_up_free_failed"
        );
        return FAILURE;
    }
    add_assoc_bool(projection_probe, "retained_device_output", false);
    add_assoc_bool(projection_probe, "projection_output_released", true);
    add_assoc_bool(residual_probe, "retained_device_output", false);
    add_assoc_bool(residual_probe, "residual_output_released", true);
    add_assoc_bool(ffn_norm_probe, "retained_device_output", false);
    add_assoc_bool(ffn_norm_probe, "ffn_norm_output_released", *ffn_norm_executed);
    add_assoc_bool(ffn_gate_up_probe, "retained_device_output", false);
    add_assoc_bool(ffn_gate_up_probe, "gate_up_output_released", *ffn_gate_up_executed);
    add_assoc_long(residual_probe, "projection_width", (zend_long) projection_width);
    add_assoc_long(residual_probe, "residual_width", (zend_long) residual_width);
    add_assoc_long(ffn_norm_probe, "residual_width", (zend_long) residual_width);
    add_assoc_long(ffn_norm_probe, "ffn_norm_width", (zend_long) ffn_norm_width);
    add_assoc_long(ffn_gate_up_probe, "ffn_norm_width", (zend_long) ffn_norm_width);
    return SUCCESS;
}
