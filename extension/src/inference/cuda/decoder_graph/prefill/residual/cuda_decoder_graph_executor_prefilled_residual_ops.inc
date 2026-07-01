/*
 * CUDA decoder graph helpers for continuing from a batch-prefilled attention residual.
 */

static zend_result king_inference_cuda_decoder_graph_executor_execute_prefilled_attention_residual_ffn_norm_gate_up_swiglu_down_and_output(
    king_inference_model_object *model,
    zval *graph,
    zend_string *residual_id,
    king_inference_cuda_device_ptr *residual_output,
    zend_ulong residual_width,
    zend_ulong prefill_batch_index,
    zval *residual_probe,
    zval *ffn_norm_probe,
    zval *ffn_gate_up_probe,
    zval *ffn_swiglu_probe,
    zval *ffn_down_probe,
    zval *ffn_output_probe,
    zval *final_norm_probe,
    zval *logits_probe,
    zend_ulong *executed_device_ops,
    bool *ffn_norm_executed,
    bool *ffn_gate_up_executed,
    bool *ffn_swiglu_executed,
    bool *ffn_down_executed,
    bool *ffn_output_executed,
    bool *final_norm_executed,
    bool *logits_executed,
    zend_string **next_hidden_id_out,
    king_inference_cuda_device_ptr *next_hidden_output,
    zend_ulong *next_hidden_width
) {
    zend_string *ffn_norm_id = NULL;
    zend_string *ffn_gate_id = NULL;
    zend_string *ffn_up_id = NULL;
    zend_string *ffn_gated_id = NULL;
    zend_string *ffn_down_id = NULL;
    zend_string *ffn_output_id = NULL;
    zend_string *final_norm_id = NULL;
    zend_string *logits_id = NULL;
    king_inference_cuda_device_ptr ffn_norm_output = 0;
    king_inference_cuda_device_ptr ffn_gate_output = 0;
    king_inference_cuda_device_ptr ffn_up_output = 0;
    king_inference_cuda_device_ptr ffn_swiglu_output = 0;
    king_inference_cuda_device_ptr ffn_down_output = 0;
    king_inference_cuda_device_ptr ffn_output_residual = 0;
    king_inference_cuda_device_ptr final_norm_output = 0;
    king_inference_cuda_device_ptr logits_output = 0;
    zend_ulong ffn_norm_width = 0;
    zend_ulong ffn_gate_rows = 0;
    zend_ulong ffn_up_rows = 0;
    zend_ulong ffn_swiglu_width = 0;
    zend_ulong ffn_down_width = 0;
    zend_ulong ffn_output_width = 0;
    zend_ulong final_norm_width = 0;
    zend_ulong logits_rows = 0;
    zend_result release_result = SUCCESS;
    bool used_prefill_ffn_norm = false;
    bool used_prefill_ffn_gate_up = false;
    bool used_prefill_ffn_swiglu = false;
    bool used_prefill_ffn_down = false;
    bool used_prefill_ffn_output = false;

    if (next_hidden_id_out != NULL) {
        *next_hidden_id_out = NULL;
    }
    if (next_hidden_output != NULL) {
        *next_hidden_output = 0;
    }
    if (next_hidden_width != NULL) {
        *next_hidden_width = 0;
    }
    *ffn_norm_executed = false;
    *ffn_gate_up_executed = false;
    *ffn_swiglu_executed = false;
    *ffn_down_executed = false;
    *ffn_output_executed = false;
    *final_norm_executed = false;
    *logits_executed = false;
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
    add_assoc_string(logits_probe, "type", "gpu_decoder_logits_projection_device_execution");
    add_assoc_bool(logits_probe, "attempted", false);
    add_assoc_bool(logits_probe, "available", model->cuda_decoder_graph_executor_logits_projection_execution_available);
    add_assoc_bool(logits_probe, "executed", false);
    add_assoc_bool(logits_probe, "retained_device_output", false);

    if (residual_output == NULL || *residual_output == 0 || residual_id == NULL || residual_width == 0) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_attention_residual_input_invalid"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_ffn_norm(
            model,
            graph,
            residual_id,
            residual_width,
            prefill_batch_index,
            ffn_norm_probe,
            &ffn_norm_output,
            &ffn_norm_width,
            &ffn_norm_id,
            executed_device_ops,
            &used_prefill_ffn_norm
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    if (!used_prefill_ffn_norm
        && king_inference_cuda_decoder_graph_executor_execute_ffn_norm(
            model,
            graph,
            residual_id,
            *residual_output,
            residual_width,
            ffn_norm_probe,
            &ffn_norm_output,
            &ffn_norm_width,
            &ffn_norm_id,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    *ffn_norm_executed = ffn_norm_output != 0 && ffn_norm_width > 0;
    if (*ffn_norm_executed
        && king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_ffn_gate_up(
            model,
            graph,
            ffn_norm_id,
            ffn_norm_width,
            prefill_batch_index,
            ffn_gate_up_probe,
            &ffn_gate_output,
            &ffn_up_output,
            &ffn_gate_rows,
            &ffn_up_rows,
            &ffn_gate_id,
            &ffn_up_id,
            executed_device_ops,
            &used_prefill_ffn_gate_up
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    if (*ffn_norm_executed
        && !used_prefill_ffn_gate_up
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
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    *ffn_gate_up_executed = ffn_gate_output != 0 && ffn_up_output != 0 && ffn_gate_rows == ffn_up_rows;
    if (*ffn_gate_up_executed
        && king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_ffn_swiglu(
            model,
            graph,
            ffn_gate_id,
            ffn_gate_output,
            ffn_gate_rows,
            ffn_up_id,
            ffn_up_output,
            ffn_up_rows,
            prefill_batch_index,
            ffn_swiglu_probe,
            &ffn_swiglu_output,
            &ffn_swiglu_width,
            &ffn_gated_id,
            executed_device_ops,
            &used_prefill_ffn_swiglu
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_swiglu_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    if (*ffn_gate_up_executed
        && !used_prefill_ffn_swiglu
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
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    *ffn_swiglu_executed = ffn_swiglu_output != 0 && ffn_swiglu_width > 0;
    if (*ffn_swiglu_executed
        && king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_ffn_down_projection(
            model,
            graph,
            ffn_gated_id,
            ffn_swiglu_output,
            ffn_swiglu_width,
            prefill_batch_index,
            ffn_down_probe,
            &ffn_down_output,
            &ffn_down_width,
            &ffn_down_id,
            executed_device_ops,
            &used_prefill_ffn_down
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_down_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_swiglu_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    if (*ffn_swiglu_executed
        && !used_prefill_ffn_down
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
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    *ffn_down_executed = ffn_down_output != 0 && ffn_down_width > 0;
    if (*ffn_down_executed
        && king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_ffn_output_residual(
            model,
            graph,
            residual_id,
            *residual_output,
            residual_width,
            ffn_down_id,
            ffn_down_output,
            ffn_down_width,
            prefill_batch_index,
            ffn_output_probe,
            &ffn_output_residual,
            &ffn_output_width,
            &ffn_output_id,
            executed_device_ops,
            &used_prefill_ffn_output
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output_residual);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_down_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_swiglu_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    if (*ffn_down_executed
        && !used_prefill_ffn_output
        && king_inference_cuda_decoder_graph_executor_execute_ffn_output_residual_add(
            model,
            graph,
            residual_id,
            *residual_output,
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
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
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
            &final_norm_id,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &final_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output_residual);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_down_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_swiglu_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    *final_norm_executed = final_norm_output != 0 && final_norm_width > 0;
    if (!*final_norm_executed
        && ffn_output_residual != 0
        && ffn_output_width > 0
        && ffn_output_id != NULL
        && next_hidden_id_out != NULL
        && next_hidden_output != NULL
        && next_hidden_width != NULL) {
        *next_hidden_id_out = ffn_output_id;
        *next_hidden_output = ffn_output_residual;
        *next_hidden_width = ffn_output_width;
        ffn_output_residual = 0;
    }
    if (*final_norm_executed
        && king_inference_cuda_decoder_graph_executor_execute_logits_projection(
            model,
            graph,
            final_norm_id,
            final_norm_output,
            final_norm_width,
            logits_probe,
            &logits_output,
            &logits_rows,
            &logits_id,
            executed_device_ops
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &logits_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &final_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output_residual);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_down_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_swiglu_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_up_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_gate_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        return FAILURE;
    }
    *logits_executed = logits_output != 0 && logits_rows > 0 && logits_id != NULL;
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &logits_output) != SUCCESS) {
        release_result = FAILURE;
    }
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
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output) != SUCCESS) {
        release_result = FAILURE;
    }
    if (release_result != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_prefilled_attention_residual_tail_free_failed"
        );
        return FAILURE;
    }
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
    add_assoc_bool(logits_probe, "retained_device_output", false);
    add_assoc_bool(logits_probe, "logits_output_released", *logits_executed);
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
    add_assoc_long(logits_probe, "final_norm_width", (zend_long) final_norm_width);
    add_assoc_long(logits_probe, "logits_rows", (zend_long) logits_rows);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_try_execute_prefilled_layer0_attention_residual(
    king_inference_model_object *model,
    zval *graph,
    zend_string *embedding_id,
    zend_ulong width,
    zend_ulong prefill_batch_index,
    zval *slice_probe,
    zval *rope_probe,
    zval *kv_probe,
    zval *attention_projection_probe,
    zval *attention_residual_probe,
    zval *ffn_norm_probe,
    zval *ffn_gate_up_probe,
    zval *ffn_swiglu_probe,
    zval *ffn_down_probe,
    zval *ffn_output_probe,
    zval *final_norm_probe,
    zval *logits_probe,
    bool *attention_projection_probe_initialized,
    bool *attention_residual_probe_initialized,
    bool *ffn_norm_probe_initialized,
    bool *ffn_gate_up_probe_initialized,
    bool *ffn_swiglu_probe_initialized,
    bool *ffn_down_probe_initialized,
    bool *ffn_output_probe_initialized,
    bool *final_norm_probe_initialized,
    bool *logits_probe_initialized,
    zend_ulong *executed_device_ops,
    bool *used_prefill_attention_residual,
    bool *attention_projection_executed,
    bool *attention_residual_executed,
    bool *ffn_norm_executed,
    bool *ffn_gate_up_executed,
    bool *ffn_swiglu_executed,
    bool *ffn_down_executed,
    bool *ffn_output_executed,
    bool *final_norm_executed,
    bool *logits_executed,
    zend_string **next_hidden_id,
    king_inference_cuda_device_ptr *next_hidden_output,
    zend_ulong *next_hidden_width
) {
    king_inference_cuda_device_ptr residual_output = 0;
    zend_string *residual_id = NULL;
    zend_ulong residual_width = 0;

    *used_prefill_attention_residual = false;
    if (model->cuda_decoder_prompt_prefill_layer0_attention_residual == 0
        || model->cuda_decoder_prompt_prefill_layer0_attention_residual_width == 0
        || prefill_batch_index >= model->cuda_decoder_prompt_prefill_tokens) {
        return SUCCESS;
    }

    array_init(attention_projection_probe);
    array_init(attention_residual_probe);
    array_init(ffn_norm_probe);
    array_init(ffn_gate_up_probe);
    array_init(ffn_swiglu_probe);
    array_init(ffn_down_probe);
    array_init(ffn_output_probe);
    array_init(final_norm_probe);
    array_init(logits_probe);
    *attention_projection_probe_initialized = true;
    *attention_residual_probe_initialized = true;
    *ffn_norm_probe_initialized = true;
    *ffn_gate_up_probe_initialized = true;
    *ffn_swiglu_probe_initialized = true;
    *ffn_down_probe_initialized = true;
    *ffn_output_probe_initialized = true;
    *final_norm_probe_initialized = true;
    *logits_probe_initialized = true;

    if (king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_attention_residual(
            model,
            graph,
            embedding_id,
            width,
            prefill_batch_index,
            slice_probe,
            rope_probe,
            kv_probe,
            attention_projection_probe,
            attention_residual_probe,
            &residual_output,
            &residual_width,
            &residual_id,
            executed_device_ops,
            used_prefill_attention_residual
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &residual_output);
        return FAILURE;
    }
    if (!*used_prefill_attention_residual) {
        king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(
            true,
            attention_projection_probe,
            true,
            attention_residual_probe,
            true,
            ffn_norm_probe,
            true,
            ffn_gate_up_probe,
            true,
            ffn_swiglu_probe,
            true,
            ffn_down_probe,
            true,
            ffn_output_probe,
            true,
            final_norm_probe,
            true,
            logits_probe
        );
        *attention_projection_probe_initialized = false;
        *attention_residual_probe_initialized = false;
        *ffn_norm_probe_initialized = false;
        *ffn_gate_up_probe_initialized = false;
        *ffn_swiglu_probe_initialized = false;
        *ffn_down_probe_initialized = false;
        *ffn_output_probe_initialized = false;
        *final_norm_probe_initialized = false;
        *logits_probe_initialized = false;
        return SUCCESS;
    }

    *attention_projection_executed = true;
    *attention_residual_executed = true;
    if (king_inference_cuda_decoder_graph_executor_execute_prefilled_attention_residual_ffn_norm_gate_up_swiglu_down_and_output(
            model,
            graph,
            residual_id,
            &residual_output,
            residual_width,
            prefill_batch_index,
            attention_residual_probe,
            ffn_norm_probe,
            ffn_gate_up_probe,
            ffn_swiglu_probe,
            ffn_down_probe,
            ffn_output_probe,
            final_norm_probe,
            logits_probe,
            executed_device_ops,
            ffn_norm_executed,
            ffn_gate_up_executed,
            ffn_swiglu_executed,
            ffn_down_executed,
            ffn_output_executed,
            final_norm_executed,
            logits_executed,
            next_hidden_id,
            next_hidden_output,
            next_hidden_width
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &residual_output);
        return FAILURE;
    }
    if (!*logits_executed
        && *next_hidden_output != 0
        && king_inference_cuda_decoder_graph_executor_execute_remaining_blocks(
            model,
            graph,
            next_hidden_id,
            next_hidden_output,
            next_hidden_width,
            slice_probe,
            rope_probe,
            kv_probe,
            attention_projection_probe,
            attention_residual_probe,
            ffn_norm_probe,
            ffn_gate_up_probe,
            ffn_swiglu_probe,
            ffn_down_probe,
            ffn_output_probe,
            final_norm_probe,
            logits_probe,
            executed_device_ops,
            attention_projection_executed,
            attention_residual_executed,
            ffn_norm_executed,
            ffn_gate_up_executed,
            ffn_swiglu_executed,
            ffn_down_executed,
            ffn_output_executed,
            final_norm_executed,
            logits_executed
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, next_hidden_output);
        return FAILURE;
    }
    return SUCCESS;
}
