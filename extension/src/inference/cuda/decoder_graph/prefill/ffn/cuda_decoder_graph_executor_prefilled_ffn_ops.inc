/*
 * CUDA decoder graph helpers for reusing batch-prefilled layer-0 FFN state.
 */

static zend_result king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_ffn_norm(
    king_inference_model_object *model,
    zval *graph,
    zend_string *residual_id,
    zend_ulong residual_width,
    zend_ulong position,
    zval *ffn_norm_probe,
    king_inference_cuda_device_ptr *ffn_norm_output,
    zend_ulong *ffn_norm_width,
    zend_string **ffn_norm_id_out,
    zend_ulong *executed_device_ops,
    bool *used_prefilled_ffn_norm
) {
    zval *ffn_norm_op;
    zend_string *ffn_norm_id = NULL;
    zend_string *weight = NULL;
    double epsilon = 0.000001;
    size_t source_offset;

    *used_prefilled_ffn_norm = false;
    *ffn_norm_output = 0;
    *ffn_norm_width = 0;
    if (ffn_norm_id_out != NULL) {
        *ffn_norm_id_out = NULL;
    }
    if (model->cuda_decoder_prompt_prefill_layer0_ffn_norm == 0
        || model->cuda_decoder_prompt_prefill_layer0_ffn_norm_width == 0
        || position >= model->cuda_decoder_prompt_prefill_tokens) {
        return SUCCESS;
    }
    if (residual_id == NULL
        || residual_width == 0
        || residual_width != model->cuda_decoder_prompt_prefill_layer0_ffn_norm_width
        || residual_width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_norm_shape_invalid"
        );
        return FAILURE;
    }

    ffn_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, residual_id, "rms_norm");
    add_assoc_string(ffn_norm_probe, "type", "gpu_decoder_ffn_norm_device_execution");
    add_assoc_bool(ffn_norm_probe, "attempted", ffn_norm_op != NULL);
    add_assoc_bool(ffn_norm_probe, "available", model->cuda_decoder_graph_executor_ffn_norm_execution_available);
    add_assoc_bool(ffn_norm_probe, "executed", false);
    add_assoc_bool(ffn_norm_probe, "retained_device_output", false);
    if (ffn_norm_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(ffn_norm_op, "id", &ffn_norm_id) != SUCCESS
        || king_inference_graph_required_string(ffn_norm_op, "weight", &weight) != SUCCESS
        || king_inference_graph_finite_double_option(ffn_norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
        || epsilon <= 0.0) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_norm_contract_invalid"
        );
        return FAILURE;
    }
    if (position > (zend_ulong) (SIZE_MAX / (size_t) residual_width)) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_norm_offset_invalid"
        );
        return FAILURE;
    }

    if (king_inference_cuda_device_allocator_alloc(
            model,
            (size_t) residual_width * sizeof(float),
            ffn_norm_output
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_prefilled_ffn_norm_allocation_failed"
        );
        return FAILURE;
    }

    source_offset = (size_t) position * (size_t) residual_width;
    if (king_inference_cuda_vector_copy_to_offset(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_norm
                + (king_inference_cuda_device_ptr) (source_offset * sizeof(float)),
            *ffn_norm_output,
            0,
            residual_width
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, ffn_norm_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_prefilled_ffn_norm_copy_failed"
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
    add_assoc_bool(ffn_norm_probe, "precomputed", true);
    add_assoc_bool(ffn_norm_probe, "used_prefill_ffn_norm", true);
    add_assoc_bool(ffn_norm_probe, "retained_device_output", true);
    add_assoc_long(ffn_norm_probe, "width", (zend_long) residual_width);
    add_assoc_long(ffn_norm_probe, "bytes", (zend_long) ((size_t) residual_width * sizeof(float)));
    add_assoc_double(ffn_norm_probe, "epsilon", epsilon);
    *used_prefilled_ffn_norm = true;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_ffn_gate_up(
    king_inference_model_object *model,
    zval *graph,
    zend_string *ffn_norm_id,
    zend_ulong ffn_norm_width,
    zend_ulong position,
    zval *ffn_gate_up_probe,
    king_inference_cuda_device_ptr *gate_output,
    king_inference_cuda_device_ptr *up_output,
    zend_ulong *gate_rows,
    zend_ulong *up_rows,
    zend_string **gate_id_out,
    zend_string **up_id_out,
    zend_ulong *executed_device_ops,
    bool *used_prefilled_gate_up
) {
    zval *gate_op;
    zval *up_op;
    zend_string *gate_id = NULL;
    zend_string *up_id = NULL;
    zend_string *gate_weight = NULL;
    zend_string *up_weight = NULL;
    zend_ulong gate_shape_rows = 0;
    zend_ulong up_shape_rows = 0;
    size_t gate_source_offset;
    size_t up_source_offset;

    *used_prefilled_gate_up = false;
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
    if (model->cuda_decoder_prompt_prefill_layer0_ffn_gate == 0
        || model->cuda_decoder_prompt_prefill_layer0_ffn_up == 0
        || model->cuda_decoder_prompt_prefill_layer0_ffn_gate_rows == 0
        || model->cuda_decoder_prompt_prefill_layer0_ffn_up_rows == 0
        || position >= model->cuda_decoder_prompt_prefill_tokens) {
        return SUCCESS;
    }
    if (ffn_norm_id == NULL || ffn_norm_width == 0) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_gate_up_input_invalid"
        );
        return FAILURE;
    }

    gate_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, ffn_norm_id, "linear", 0);
    up_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, ffn_norm_id, "linear", 1);
    add_assoc_string(ffn_gate_up_probe, "type", "gpu_decoder_ffn_gate_up_projection_device_execution");
    add_assoc_bool(ffn_gate_up_probe, "attempted", gate_op != NULL || up_op != NULL);
    add_assoc_bool(ffn_gate_up_probe, "available", model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available);
    add_assoc_bool(ffn_gate_up_probe, "executed", false);
    add_assoc_bool(ffn_gate_up_probe, "retained_device_output", false);
    if (gate_op == NULL
        || up_op == NULL
        || king_inference_graph_required_string(gate_op, "id", &gate_id) != SUCCESS
        || king_inference_graph_required_string(up_op, "id", &up_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            gate_op,
            ffn_norm_width,
            &gate_weight,
            &gate_shape_rows
    ) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            up_op,
            ffn_norm_width,
            &up_weight,
            &up_shape_rows
    ) != SUCCESS
        || gate_shape_rows == 0
        || gate_shape_rows != up_shape_rows
        || gate_shape_rows != model->cuda_decoder_prompt_prefill_layer0_ffn_gate_rows
        || up_shape_rows != model->cuda_decoder_prompt_prefill_layer0_ffn_up_rows
        || gate_shape_rows > (zend_ulong) (SIZE_MAX / sizeof(float))
        || up_shape_rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            model->cuda_decoder_graph_executor_error[0] != '\0'
                ? model->cuda_decoder_graph_executor_error
                : "cuda_decoder_graph_prefilled_ffn_gate_up_contract_invalid"
        );
        return FAILURE;
    }
    if (position > (zend_ulong) (SIZE_MAX / (size_t) gate_shape_rows)
        || position > (zend_ulong) (SIZE_MAX / (size_t) up_shape_rows)) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_gate_up_offset_invalid"
        );
        return FAILURE;
    }

    if (king_inference_cuda_device_allocator_alloc(
            model,
            (size_t) gate_shape_rows * sizeof(float),
            gate_output
    ) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(
            model,
            (size_t) up_shape_rows * sizeof(float),
            up_output
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, gate_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_prefilled_ffn_gate_up_allocation_failed"
        );
        return FAILURE;
    }

    gate_source_offset = (size_t) position * (size_t) gate_shape_rows;
    up_source_offset = (size_t) position * (size_t) up_shape_rows;
    if (king_inference_cuda_vector_copy_to_offset(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_gate
                + (king_inference_cuda_device_ptr) (gate_source_offset * sizeof(float)),
            *gate_output,
            0,
            gate_shape_rows
    ) != SUCCESS
        || king_inference_cuda_vector_copy_to_offset(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_up
                + (king_inference_cuda_device_ptr) (up_source_offset * sizeof(float)),
            *up_output,
            0,
            up_shape_rows
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, gate_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_prefilled_ffn_gate_up_copy_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_count++;
    model->cuda_decoder_graph_executor_last_ffn_gate_rows = gate_shape_rows;
    model->cuda_decoder_graph_executor_last_ffn_up_rows = up_shape_rows;
    *gate_rows = gate_shape_rows;
    *up_rows = up_shape_rows;
    if (gate_id_out != NULL) {
        *gate_id_out = gate_id;
    }
    if (up_id_out != NULL) {
        *up_id_out = up_id;
    }
    (*executed_device_ops) += 2;

    add_assoc_str(ffn_gate_up_probe, "gate", zend_string_copy(gate_id));
    add_assoc_str(ffn_gate_up_probe, "up", zend_string_copy(up_id));
    add_assoc_bool(ffn_gate_up_probe, "executed", true);
    add_assoc_bool(ffn_gate_up_probe, "precomputed", true);
    add_assoc_bool(ffn_gate_up_probe, "used_prefill_ffn_gate_up", true);
    add_assoc_bool(ffn_gate_up_probe, "retained_device_output", true);
    add_assoc_long(ffn_gate_up_probe, "input_width", (zend_long) ffn_norm_width);
    add_assoc_long(ffn_gate_up_probe, "gate_rows", (zend_long) gate_shape_rows);
    add_assoc_long(ffn_gate_up_probe, "up_rows", (zend_long) up_shape_rows);
    *used_prefilled_gate_up = true;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_ffn_swiglu(
    king_inference_model_object *model,
    zval *graph,
    zend_string *gate_id,
    king_inference_cuda_device_ptr gate_output,
    zend_ulong gate_rows,
    zend_string *up_id,
    king_inference_cuda_device_ptr up_output,
    zend_ulong up_rows,
    zend_ulong position,
    zval *ffn_swiglu_probe,
    king_inference_cuda_device_ptr *swiglu_output,
    zend_ulong *swiglu_width,
    zend_string **gated_id_out,
    zend_ulong *executed_device_ops,
    bool *used_prefilled_swiglu
) {
    zval *silu_op;
    zval *mul_op;
    zend_string *silu_id = NULL;
    zend_string *gated_id = NULL;
    size_t source_offset;

    *used_prefilled_swiglu = false;
    *swiglu_output = 0;
    *swiglu_width = 0;
    if (gated_id_out != NULL) {
        *gated_id_out = NULL;
    }
    if (model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu == 0
        || model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu_width == 0
        || position >= model->cuda_decoder_prompt_prefill_tokens) {
        return SUCCESS;
    }
    if (gate_id == NULL
        || up_id == NULL
        || gate_output == 0
        || up_output == 0
        || gate_rows == 0
        || gate_rows != up_rows
        || gate_rows != model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu_width
        || gate_rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_swiglu_input_invalid"
        );
        return FAILURE;
    }

    silu_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gate_id, "silu");
    add_assoc_string(ffn_swiglu_probe, "type", "gpu_decoder_ffn_swiglu_device_execution");
    add_assoc_bool(ffn_swiglu_probe, "attempted", silu_op != NULL);
    add_assoc_bool(ffn_swiglu_probe, "available", model->cuda_decoder_graph_executor_ffn_swiglu_execution_available);
    add_assoc_bool(ffn_swiglu_probe, "executed", false);
    add_assoc_bool(ffn_swiglu_probe, "retained_device_output", false);
    if (silu_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(silu_op, "id", &silu_id) != SUCCESS
        || (mul_op = king_inference_cuda_decoder_graph_executor_mul_op_for_inputs(graph, silu_id, up_id)) == NULL
        || king_inference_graph_required_string(mul_op, "id", &gated_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_swiglu_contract_invalid"
        );
        return FAILURE;
    }
    if (position > (zend_ulong) (SIZE_MAX / (size_t) gate_rows)) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_swiglu_offset_invalid"
        );
        return FAILURE;
    }

    if (king_inference_cuda_device_allocator_alloc(
            model,
            (size_t) gate_rows * sizeof(float),
            swiglu_output
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_prefilled_ffn_swiglu_allocation_failed"
        );
        return FAILURE;
    }

    source_offset = (size_t) position * (size_t) gate_rows;
    if (king_inference_cuda_vector_copy_to_offset(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu
                + (king_inference_cuda_device_ptr) (source_offset * sizeof(float)),
            *swiglu_output,
            0,
            gate_rows
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, swiglu_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_prefilled_ffn_swiglu_copy_failed"
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
    add_assoc_bool(ffn_swiglu_probe, "precomputed", true);
    add_assoc_bool(ffn_swiglu_probe, "used_prefill_ffn_swiglu", true);
    add_assoc_bool(ffn_swiglu_probe, "retained_device_output", true);
    add_assoc_long(ffn_swiglu_probe, "width", (zend_long) gate_rows);
    add_assoc_long(ffn_swiglu_probe, "bytes", (zend_long) ((size_t) gate_rows * sizeof(float)));
    *used_prefilled_swiglu = true;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_ffn_down_projection(
    king_inference_model_object *model,
    zval *graph,
    zend_string *gated_id,
    king_inference_cuda_device_ptr swiglu_output,
    zend_ulong swiglu_width,
    zend_ulong position,
    zval *ffn_down_probe,
    king_inference_cuda_device_ptr *down_output,
    zend_ulong *down_width,
    zend_string **down_id_out,
    zend_ulong *executed_device_ops,
    bool *used_prefilled_down
) {
    zval *down_op;
    zend_string *down_id = NULL;
    zend_string *down_weight = NULL;
    zend_ulong down_rows = 0;
    size_t source_offset;

    *used_prefilled_down = false;
    *down_output = 0;
    *down_width = 0;
    if (down_id_out != NULL) {
        *down_id_out = NULL;
    }
    if (model->cuda_decoder_prompt_prefill_layer0_ffn_down == 0
        || model->cuda_decoder_prompt_prefill_layer0_ffn_down_width == 0
        || position >= model->cuda_decoder_prompt_prefill_tokens) {
        return SUCCESS;
    }
    if (gated_id == NULL
        || swiglu_output == 0
        || swiglu_width == 0
        || model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu_width != swiglu_width) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_down_input_invalid"
        );
        return FAILURE;
    }

    down_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gated_id, "linear");
    add_assoc_string(ffn_down_probe, "type", "gpu_decoder_ffn_down_projection_device_execution");
    add_assoc_bool(ffn_down_probe, "attempted", down_op != NULL);
    add_assoc_bool(ffn_down_probe, "available", model->cuda_decoder_graph_executor_ffn_down_projection_execution_available);
    add_assoc_bool(ffn_down_probe, "executed", false);
    add_assoc_bool(ffn_down_probe, "retained_device_output", false);
    if (down_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(down_op, "id", &down_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            down_op,
            swiglu_width,
            &down_weight,
            &down_rows
    ) != SUCCESS
        || down_rows == 0
        || down_rows != model->cuda_decoder_prompt_prefill_layer0_ffn_down_width
        || down_rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            model->cuda_decoder_graph_executor_error[0] != '\0'
                ? model->cuda_decoder_graph_executor_error
                : "cuda_decoder_graph_prefilled_ffn_down_contract_invalid"
        );
        return FAILURE;
    }
    (void) down_weight;
    if (position > (zend_ulong) (SIZE_MAX / (size_t) down_rows)) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_down_offset_invalid"
        );
        return FAILURE;
    }

    if (king_inference_cuda_device_allocator_alloc(
            model,
            (size_t) down_rows * sizeof(float),
            down_output
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_prefilled_ffn_down_allocation_failed"
        );
        return FAILURE;
    }

    source_offset = (size_t) position * (size_t) down_rows;
    if (king_inference_cuda_vector_copy_to_offset(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_down
                + (king_inference_cuda_device_ptr) (source_offset * sizeof(float)),
            *down_output,
            0,
            down_rows
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, down_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_prefilled_ffn_down_copy_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_ffn_down_projection_execution_count++;
    model->cuda_decoder_graph_executor_last_ffn_down_rows = down_rows;
    *down_width = down_rows;
    if (down_id_out != NULL) {
        *down_id_out = down_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(ffn_down_probe, "input", zend_string_copy(gated_id));
    add_assoc_str(ffn_down_probe, "down", zend_string_copy(down_id));
    add_assoc_bool(ffn_down_probe, "executed", true);
    add_assoc_bool(ffn_down_probe, "precomputed", true);
    add_assoc_bool(ffn_down_probe, "used_prefill_ffn_down", true);
    add_assoc_bool(ffn_down_probe, "retained_device_output", true);
    add_assoc_long(ffn_down_probe, "input_width", (zend_long) swiglu_width);
    add_assoc_long(ffn_down_probe, "rows", (zend_long) down_rows);
    *used_prefilled_down = true;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_ffn_output_residual(
    king_inference_model_object *model,
    zval *graph,
    zend_string *residual_id,
    king_inference_cuda_device_ptr residual_output,
    zend_ulong residual_width,
    zend_string *down_id,
    king_inference_cuda_device_ptr down_output,
    zend_ulong down_width,
    zend_ulong position,
    zval *ffn_output_probe,
    king_inference_cuda_device_ptr *output,
    zend_ulong *output_width,
    zend_string **output_id_out,
    zend_ulong *executed_device_ops,
    bool *used_prefilled_output
) {
    zval *output_op;
    zend_string *output_id = NULL;
    size_t source_offset;

    *used_prefilled_output = false;
    *output = 0;
    *output_width = 0;
    if (output_id_out != NULL) {
        *output_id_out = NULL;
    }
    if (model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual == 0
        || model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual_width == 0
        || position >= model->cuda_decoder_prompt_prefill_tokens) {
        return SUCCESS;
    }
    if (residual_id == NULL
        || down_id == NULL
        || residual_output == 0
        || down_output == 0
        || residual_width == 0
        || residual_width != down_width
        || residual_width != model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual_width
        || residual_width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_output_residual_input_invalid"
        );
        return FAILURE;
    }

    output_op = king_inference_cuda_decoder_graph_executor_add_op_for_inputs(graph, residual_id, down_id);
    add_assoc_string(ffn_output_probe, "type", "gpu_decoder_ffn_output_residual_device_execution");
    add_assoc_bool(ffn_output_probe, "attempted", output_op != NULL);
    add_assoc_bool(ffn_output_probe, "available", model->cuda_decoder_graph_executor_ffn_output_residual_execution_available);
    add_assoc_bool(ffn_output_probe, "executed", false);
    add_assoc_bool(ffn_output_probe, "retained_device_output", false);
    if (output_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(output_op, "id", &output_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_output_residual_contract_invalid"
        );
        return FAILURE;
    }
    if (position > (zend_ulong) (SIZE_MAX / (size_t) residual_width)) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_ffn_output_residual_offset_invalid"
        );
        return FAILURE;
    }

    if (king_inference_cuda_device_allocator_alloc(
            model,
            (size_t) residual_width * sizeof(float),
            output
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_prefilled_ffn_output_residual_allocation_failed"
        );
        return FAILURE;
    }

    source_offset = (size_t) position * (size_t) residual_width;
    if (king_inference_cuda_vector_copy_to_offset(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual
                + (king_inference_cuda_device_ptr) (source_offset * sizeof(float)),
            *output,
            0,
            residual_width
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_prefilled_ffn_output_residual_copy_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_ffn_output_residual_execution_count++;
    model->cuda_decoder_graph_executor_last_ffn_output_residual_width = residual_width;
    *output_width = residual_width;
    if (output_id_out != NULL) {
        *output_id_out = output_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(ffn_output_probe, "residual", zend_string_copy(residual_id));
    add_assoc_str(ffn_output_probe, "down", zend_string_copy(down_id));
    add_assoc_str(ffn_output_probe, "output", zend_string_copy(output_id));
    add_assoc_bool(ffn_output_probe, "executed", true);
    add_assoc_bool(ffn_output_probe, "precomputed", true);
    add_assoc_bool(ffn_output_probe, "used_prefill_ffn_output_residual", true);
    add_assoc_bool(ffn_output_probe, "retained_device_output", true);
    add_assoc_long(ffn_output_probe, "width", (zend_long) residual_width);
    add_assoc_long(ffn_output_probe, "bytes", (zend_long) ((size_t) residual_width * sizeof(float)));
    *used_prefilled_output = true;
    return SUCCESS;
}
