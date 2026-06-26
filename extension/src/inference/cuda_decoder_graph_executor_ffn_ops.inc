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
    zend_string **gate_id_out,
    zend_string **up_id_out,
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
    if (gate_id_out != NULL) {
        *gate_id_out = NULL;
    }
    if (up_id_out != NULL) {
        *up_id_out = NULL;
    }
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
    if (gate_id_out != NULL) {
        *gate_id_out = gate_id;
    }
    if (up_id_out != NULL) {
        *up_id_out = up_id;
    }

    add_assoc_str(ffn_gate_up_probe, "gate", zend_string_copy(gate_id));
    add_assoc_str(ffn_gate_up_probe, "up", zend_string_copy(up_id));
    add_assoc_bool(ffn_gate_up_probe, "executed", true);
    add_assoc_bool(ffn_gate_up_probe, "retained_device_output", true);
    add_assoc_long(ffn_gate_up_probe, "input_width", (zend_long) ffn_norm_width);
    add_assoc_long(ffn_gate_up_probe, "gate_rows", (zend_long) *gate_rows);
    add_assoc_long(ffn_gate_up_probe, "up_rows", (zend_long) *up_rows);
    return SUCCESS;
}

static zval *king_inference_cuda_decoder_graph_executor_mul_op_for_inputs(
    zval *graph,
    zend_string *left_id,
    zend_string *right_id
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;

    if (ops == NULL || left_id == NULL || right_id == NULL) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *left;
        zval *right;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        left = king_inference_array_find(entry, "left");
        right = king_inference_array_find(entry, "right");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && zend_string_equals_literal(Z_STR_P(op_name), "mul")
            && ((king_inference_cuda_decoder_graph_executor_zval_string_equals(left, left_id)
                    && king_inference_cuda_decoder_graph_executor_zval_string_equals(right, right_id))
                || (king_inference_cuda_decoder_graph_executor_zval_string_equals(left, right_id)
                    && king_inference_cuda_decoder_graph_executor_zval_string_equals(right, left_id)))) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_ffn_swiglu(
    king_inference_model_object *model,
    zval *graph,
    zend_string *gate_id,
    king_inference_cuda_device_ptr gate_output,
    zend_ulong gate_rows,
    zend_string *up_id,
    king_inference_cuda_device_ptr up_output,
    zend_ulong up_rows,
    zval *ffn_swiglu_probe,
    king_inference_cuda_device_ptr *swiglu_output,
    zend_ulong *swiglu_width,
    zend_string **gated_id_out,
    zend_ulong *executed_device_ops
) {
    zval *silu_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gate_id, "silu");
    zval *mul_op = NULL;
    zend_string *silu_id = NULL;
    zend_string *gated_id = NULL;

    *swiglu_output = 0;
    *swiglu_width = 0;
    if (gated_id_out != NULL) {
        *gated_id_out = NULL;
    }
    add_assoc_string(ffn_swiglu_probe, "type", "gpu_decoder_ffn_swiglu_device_execution");
    add_assoc_bool(ffn_swiglu_probe, "attempted", silu_op != NULL);
    add_assoc_bool(ffn_swiglu_probe, "available", model->cuda_decoder_graph_executor_ffn_swiglu_execution_available);
    add_assoc_bool(ffn_swiglu_probe, "executed", false);
    add_assoc_bool(ffn_swiglu_probe, "retained_device_output", false);
    if (silu_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_ffn_swiglu_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_swiglu_execution_unavailable");
        return FAILURE;
    }
    if (gate_output == 0
        || up_output == 0
        || gate_rows == 0
        || gate_rows != up_rows
        || gate_rows > (zend_ulong) (SIZE_MAX / sizeof(float))
        || king_inference_graph_required_string(silu_op, "id", &silu_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_swiglu_input_invalid");
        return FAILURE;
    }
    mul_op = king_inference_cuda_decoder_graph_executor_mul_op_for_inputs(graph, silu_id, up_id);
    if (mul_op == NULL || king_inference_graph_required_string(mul_op, "id", &gated_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_swiglu_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(model, (size_t) gate_rows * sizeof(float), swiglu_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_ffn_swiglu_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_ffn_swiglu_f32(model, gate_output, up_output, *swiglu_output, gate_rows) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, swiglu_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_ffn_swiglu_result,
            model->cuda_ffn_swiglu_error[0] != '\0'
                ? model->cuda_ffn_swiglu_error
                : "cuda_decoder_graph_ffn_swiglu_execution_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_ffn_swiglu_execution_count++;
    model->cuda_decoder_graph_executor_last_ffn_swiglu_width = gate_rows;
    *swiglu_width = gate_rows;
    if (gated_id_out != NULL) {
        *gated_id_out = gated_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(ffn_swiglu_probe, "gate", zend_string_copy(gate_id));
    add_assoc_str(ffn_swiglu_probe, "up", zend_string_copy(up_id));
    add_assoc_str(ffn_swiglu_probe, "silu", zend_string_copy(silu_id));
    add_assoc_str(ffn_swiglu_probe, "gated", zend_string_copy(gated_id));
    add_assoc_bool(ffn_swiglu_probe, "executed", true);
    add_assoc_bool(ffn_swiglu_probe, "retained_device_output", true);
    add_assoc_long(ffn_swiglu_probe, "width", (zend_long) gate_rows);
    add_assoc_long(ffn_swiglu_probe, "bytes", (zend_long) ((size_t) gate_rows * sizeof(float)));
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_ffn_down_projection(
    king_inference_model_object *model,
    zval *graph,
    zend_string *gated_id,
    king_inference_cuda_device_ptr swiglu_output,
    zend_ulong swiglu_width,
    zval *ffn_down_probe,
    king_inference_cuda_device_ptr *down_output,
    zend_ulong *down_width,
    zend_string **down_id_out,
    zend_ulong *executed_device_ops
) {
    zval *down_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gated_id, "linear");
    zend_string *down_id = NULL;

    *down_output = 0;
    *down_width = 0;
    if (down_id_out != NULL) {
        *down_id_out = NULL;
    }
    add_assoc_string(ffn_down_probe, "type", "gpu_decoder_ffn_down_projection_device_execution");
    add_assoc_bool(ffn_down_probe, "attempted", down_op != NULL);
    add_assoc_bool(ffn_down_probe, "available", model->cuda_decoder_graph_executor_ffn_down_projection_execution_available);
    add_assoc_bool(ffn_down_probe, "executed", false);
    add_assoc_bool(ffn_down_probe, "retained_device_output", false);
    if (down_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_ffn_down_projection_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_down_projection_execution_unavailable");
        return FAILURE;
    }
    if (swiglu_output == 0
        || swiglu_width == 0
        || king_inference_graph_required_string(down_op, "id", &down_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_down_projection_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_linear_to_device(
            model,
            down_op,
            swiglu_output,
            swiglu_width,
            down_output,
            down_width
    ) != SUCCESS) {
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_ffn_down_projection_execution_count++;
    model->cuda_decoder_graph_executor_last_ffn_down_rows = *down_width;
    if (down_id_out != NULL) {
        *down_id_out = down_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(ffn_down_probe, "input", zend_string_copy(gated_id));
    add_assoc_str(ffn_down_probe, "down", zend_string_copy(down_id));
    add_assoc_bool(ffn_down_probe, "executed", true);
    add_assoc_bool(ffn_down_probe, "retained_device_output", true);
    add_assoc_long(ffn_down_probe, "input_width", (zend_long) swiglu_width);
    add_assoc_long(ffn_down_probe, "rows", (zend_long) *down_width);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_attention_projection_residual_ffn_norm_gate_up_swiglu_down_and_output(
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
    zval *ffn_swiglu_probe,
    zval *ffn_down_probe,
    zval *ffn_output_probe,
    zval *final_norm_probe,
    zend_ulong *executed_device_ops,
    bool *projection_executed,
    bool *residual_executed,
    bool *ffn_norm_executed,
    bool *ffn_gate_up_executed,
    bool *ffn_swiglu_executed,
    bool *ffn_down_executed,
    bool *ffn_output_executed,
    bool *final_norm_executed
) {
    zend_string *projection_id = NULL;
    zend_string *residual_id = NULL;
    zend_string *ffn_norm_id = NULL;
    zend_string *ffn_gate_id = NULL;
    zend_string *ffn_up_id = NULL;
    zend_string *ffn_gated_id = NULL;
    zend_string *ffn_down_id = NULL;
    zend_string *ffn_output_id = NULL;
    king_inference_cuda_device_ptr projection_output = 0;
    king_inference_cuda_device_ptr residual_output = 0;
    king_inference_cuda_device_ptr ffn_norm_output = 0;
    king_inference_cuda_device_ptr ffn_gate_output = 0;
    king_inference_cuda_device_ptr ffn_up_output = 0;
    king_inference_cuda_device_ptr ffn_swiglu_output = 0;
    king_inference_cuda_device_ptr ffn_down_output = 0;
    king_inference_cuda_device_ptr ffn_output_residual = 0;
    king_inference_cuda_device_ptr final_norm_output = 0;
    zend_ulong projection_width = 0;
    zend_ulong residual_width = 0;
    zend_ulong ffn_norm_width = 0;
    zend_ulong ffn_gate_rows = 0;
    zend_ulong ffn_up_rows = 0;
    zend_ulong ffn_swiglu_width = 0;
    zend_ulong ffn_down_width = 0;
    zend_ulong ffn_output_width = 0;
    zend_ulong final_norm_width = 0;
    zend_result release_result = SUCCESS;

    *projection_executed = false;
    *residual_executed = false;
    *ffn_norm_executed = false;
    *ffn_gate_up_executed = false;
    *ffn_swiglu_executed = false;
    *ffn_down_executed = false;
    *ffn_output_executed = false;
    *final_norm_executed = false;
    add_assoc_string(ffn_gate_up_probe, "type", "gpu_decoder_ffn_gate_up_projection_device_execution");
    add_assoc_bool(ffn_gate_up_probe, "attempted", false);
    add_assoc_bool(ffn_gate_up_probe, "available", model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available);
    add_assoc_bool(ffn_gate_up_probe, "executed", false);
    add_assoc_bool(ffn_gate_up_probe, "retained_device_output", false);
    add_assoc_string(ffn_swiglu_probe, "type", "gpu_decoder_ffn_swiglu_device_execution");
    add_assoc_bool(ffn_swiglu_probe, "attempted", false);
    add_assoc_bool(ffn_swiglu_probe, "available", model->cuda_decoder_graph_executor_ffn_swiglu_execution_available);
    add_assoc_bool(ffn_swiglu_probe, "executed", false);
    add_assoc_bool(ffn_swiglu_probe, "retained_device_output", false);
    add_assoc_string(ffn_down_probe, "type", "gpu_decoder_ffn_down_projection_device_execution");
    add_assoc_bool(ffn_down_probe, "attempted", false);
    add_assoc_bool(ffn_down_probe, "available", model->cuda_decoder_graph_executor_ffn_down_projection_execution_available);
    add_assoc_bool(ffn_down_probe, "executed", false);
    add_assoc_bool(ffn_down_probe, "retained_device_output", false);
    add_assoc_string(ffn_output_probe, "type", "gpu_decoder_ffn_output_residual_device_execution");
    add_assoc_bool(ffn_output_probe, "attempted", false);
    add_assoc_bool(ffn_output_probe, "available", model->cuda_decoder_graph_executor_ffn_output_residual_execution_available);
    add_assoc_bool(ffn_output_probe, "executed", false);
    add_assoc_bool(ffn_output_probe, "retained_device_output", false);
    add_assoc_string(final_norm_probe, "type", "gpu_decoder_final_norm_device_execution");
    add_assoc_bool(final_norm_probe, "attempted", false);
    add_assoc_bool(final_norm_probe, "available", model->cuda_decoder_graph_executor_final_norm_execution_available);
    add_assoc_bool(final_norm_probe, "executed", false);
    add_assoc_bool(final_norm_probe, "retained_device_output", false);
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
            &ffn_gate_id,
            &ffn_up_id,
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
    if (*ffn_gate_up_executed
        && king_inference_cuda_decoder_graph_executor_execute_ffn_swiglu(
            model,
            graph,
            ffn_gate_id,
            ffn_gate_output,
            ffn_gate_rows,
            ffn_up_id,
            ffn_up_output,
            ffn_up_rows,
            ffn_swiglu_probe,
            &ffn_swiglu_output,
            &ffn_swiglu_width,
            &ffn_gated_id,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_swiglu_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &residual_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_output);
        return FAILURE;
    }
    *ffn_swiglu_executed = ffn_swiglu_output != 0 && ffn_swiglu_width > 0;
    if (*ffn_swiglu_executed
        && king_inference_cuda_decoder_graph_executor_execute_ffn_down_projection(
            model,
            graph,
            ffn_gated_id,
            ffn_swiglu_output,
            ffn_swiglu_width,
            ffn_down_probe,
            &ffn_down_output,
            &ffn_down_width,
            &ffn_down_id,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_down_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_swiglu_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &residual_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_output);
        return FAILURE;
    }
    *ffn_down_executed = ffn_down_output != 0 && ffn_down_width > 0;
    if (*ffn_down_executed
        && king_inference_cuda_decoder_graph_executor_execute_ffn_output_residual_add(
            model,
            graph,
            residual_id,
            residual_output,
            residual_width,
            ffn_down_id,
            ffn_down_output,
            ffn_down_width,
            ffn_output_probe,
            &ffn_output_residual,
            &ffn_output_width,
            &ffn_output_id,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output_residual);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_down_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_swiglu_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &residual_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_output);
        return FAILURE;
    }
    *ffn_output_executed = ffn_output_residual != 0 && ffn_output_width > 0;
    if (*ffn_output_executed
        && king_inference_cuda_decoder_graph_executor_execute_final_norm(
            model,
            graph,
            ffn_output_id,
            ffn_output_residual,
            ffn_output_width,
            final_norm_probe,
            &final_norm_output,
            &final_norm_width,
            NULL,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &final_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output_residual);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_down_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_swiglu_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &residual_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_output);
        return FAILURE;
    }
    *final_norm_executed = final_norm_output != 0 && final_norm_width > 0;
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &final_norm_output) != SUCCESS) {
        release_result = FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output_residual) != SUCCESS) {
        release_result = FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_down_output) != SUCCESS) {
        release_result = FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_swiglu_output) != SUCCESS) {
        release_result = FAILURE;
    }
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
                : "cuda_decoder_graph_attention_residual_ffn_gate_up_swiglu_down_output_final_norm_free_failed"
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
    add_assoc_bool(ffn_swiglu_probe, "retained_device_output", false);
    add_assoc_bool(ffn_swiglu_probe, "swiglu_output_released", *ffn_swiglu_executed);
    add_assoc_bool(ffn_down_probe, "retained_device_output", false);
    add_assoc_bool(ffn_down_probe, "down_output_released", *ffn_down_executed);
    add_assoc_bool(ffn_output_probe, "retained_device_output", false);
    add_assoc_bool(ffn_output_probe, "output_residual_released", *ffn_output_executed);
    add_assoc_bool(final_norm_probe, "retained_device_output", false);
    add_assoc_bool(final_norm_probe, "final_norm_output_released", *final_norm_executed);
    add_assoc_long(residual_probe, "projection_width", (zend_long) projection_width);
    add_assoc_long(residual_probe, "residual_width", (zend_long) residual_width);
    add_assoc_long(ffn_norm_probe, "residual_width", (zend_long) residual_width);
    add_assoc_long(ffn_norm_probe, "ffn_norm_width", (zend_long) ffn_norm_width);
    add_assoc_long(ffn_gate_up_probe, "ffn_norm_width", (zend_long) ffn_norm_width);
    add_assoc_long(ffn_swiglu_probe, "gate_rows", (zend_long) ffn_gate_rows);
    add_assoc_long(ffn_swiglu_probe, "up_rows", (zend_long) ffn_up_rows);
    add_assoc_long(ffn_swiglu_probe, "swiglu_width", (zend_long) ffn_swiglu_width);
    add_assoc_long(ffn_down_probe, "swiglu_width", (zend_long) ffn_swiglu_width);
    add_assoc_long(ffn_down_probe, "down_width", (zend_long) ffn_down_width);
    add_assoc_long(ffn_output_probe, "down_width", (zend_long) ffn_down_width);
    add_assoc_long(ffn_output_probe, "output_width", (zend_long) ffn_output_width);
    add_assoc_long(final_norm_probe, "input_width", (zend_long) ffn_output_width);
    add_assoc_long(final_norm_probe, "final_norm_width", (zend_long) final_norm_width);
    return SUCCESS;
}
