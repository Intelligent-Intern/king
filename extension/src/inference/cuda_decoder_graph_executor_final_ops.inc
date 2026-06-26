/*
 * CUDA decoder graph final-stage helpers for native King GPU inference.
 */

static zval *king_inference_cuda_decoder_graph_executor_final_norm_op_for_input(
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
        zval *input;
        zval *id;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        input = king_inference_array_find(entry, "input");
        id = king_inference_array_find(entry, "id");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && zend_string_equals_literal(Z_STR_P(op_name), "rms_norm")
            && id != NULL
            && Z_TYPE_P(id) == IS_STRING
            && zend_string_equals_literal(Z_STR_P(id), "final_norm")
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(input, input_id)) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_ffn_output_residual_add(
    king_inference_model_object *model,
    zval *graph,
    zend_string *attention_residual_id,
    king_inference_cuda_device_ptr attention_residual_output,
    zend_ulong attention_residual_width,
    zend_string *down_id,
    king_inference_cuda_device_ptr down_output,
    zend_ulong down_width,
    zval *ffn_output_probe,
    king_inference_cuda_device_ptr *output,
    zend_ulong *output_width,
    zend_string **output_id_out,
    zend_ulong *executed_device_ops
) {
    zval *output_op = king_inference_cuda_decoder_graph_executor_add_op_for_inputs(graph, attention_residual_id, down_id);
    zend_string *output_id = NULL;

    *output = 0;
    *output_width = 0;
    if (output_id_out != NULL) {
        *output_id_out = NULL;
    }
    add_assoc_string(ffn_output_probe, "type", "gpu_decoder_ffn_output_residual_device_execution");
    add_assoc_bool(ffn_output_probe, "attempted", output_op != NULL);
    add_assoc_bool(ffn_output_probe, "available", model->cuda_decoder_graph_executor_ffn_output_residual_execution_available);
    add_assoc_bool(ffn_output_probe, "executed", false);
    add_assoc_bool(ffn_output_probe, "retained_device_output", false);
    if (output_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_ffn_output_residual_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_output_residual_execution_unavailable");
        return FAILURE;
    }
    if (attention_residual_output == 0
        || down_output == 0
        || attention_residual_width == 0
        || attention_residual_width != down_width
        || attention_residual_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || king_inference_graph_required_string(output_op, "id", &output_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ffn_output_residual_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(model, (size_t) attention_residual_width * sizeof(float), output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_ffn_output_residual_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_vector_add(model, attention_residual_output, down_output, *output, attention_residual_width) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_ffn_output_residual_add_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_ffn_output_residual_execution_count++;
    model->cuda_decoder_graph_executor_last_ffn_output_residual_width = attention_residual_width;
    *output_width = attention_residual_width;
    if (output_id_out != NULL) {
        *output_id_out = output_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(ffn_output_probe, "residual", zend_string_copy(attention_residual_id));
    add_assoc_str(ffn_output_probe, "down", zend_string_copy(down_id));
    add_assoc_str(ffn_output_probe, "output", zend_string_copy(output_id));
    add_assoc_bool(ffn_output_probe, "executed", true);
    add_assoc_bool(ffn_output_probe, "retained_device_output", true);
    add_assoc_long(ffn_output_probe, "width", (zend_long) attention_residual_width);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_final_norm(
    king_inference_model_object *model,
    zval *graph,
    zend_string *input_id,
    king_inference_cuda_device_ptr input_output,
    zend_ulong input_width,
    zval *final_norm_probe,
    king_inference_cuda_device_ptr *final_norm_output,
    zend_ulong *final_norm_width,
    zend_string **final_norm_id_out,
    zend_ulong *executed_device_ops
) {
    zval *final_norm_op = king_inference_cuda_decoder_graph_executor_final_norm_op_for_input(graph, input_id);
    zend_string *final_norm_id = NULL;
    zend_string *weight = NULL;
    double epsilon = 0.000001;
    size_t bytes;

    *final_norm_output = 0;
    *final_norm_width = 0;
    if (final_norm_id_out != NULL) {
        *final_norm_id_out = NULL;
    }
    add_assoc_string(final_norm_probe, "type", "gpu_decoder_final_norm_device_execution");
    add_assoc_bool(final_norm_probe, "attempted", final_norm_op != NULL);
    add_assoc_bool(final_norm_probe, "available", model->cuda_decoder_graph_executor_final_norm_execution_available);
    add_assoc_bool(final_norm_probe, "executed", false);
    add_assoc_bool(final_norm_probe, "retained_device_output", false);
    if (final_norm_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_final_norm_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_final_norm_execution_unavailable");
        return FAILURE;
    }
    if (input_output == 0
        || input_width == 0
        || input_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || king_inference_graph_required_string(final_norm_op, "id", &final_norm_id) != SUCCESS
        || king_inference_graph_required_string(final_norm_op, "weight", &weight) != SUCCESS
        || king_inference_graph_finite_double_option(final_norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
        || epsilon <= 0.0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_final_norm_contract_invalid");
        return FAILURE;
    }
    bytes = (size_t) input_width * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(model, bytes, final_norm_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_final_norm_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_rms_norm_f32(model, weight, input_output, *final_norm_output, input_width, epsilon) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, final_norm_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_rms_norm_result,
            model->cuda_rms_norm_error[0] != '\0'
                ? model->cuda_rms_norm_error
                : "cuda_decoder_graph_final_norm_execution_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_rms_norm_execution_count++;
    model->cuda_decoder_graph_executor_final_norm_execution_count++;
    model->cuda_decoder_graph_executor_last_rms_norm_width = input_width;
    model->cuda_decoder_graph_executor_last_final_norm_width = input_width;
    *final_norm_width = input_width;
    if (final_norm_id_out != NULL) {
        *final_norm_id_out = final_norm_id;
    }
    (*executed_device_ops)++;

    add_assoc_str(final_norm_probe, "input", zend_string_copy(input_id));
    add_assoc_str(final_norm_probe, "final_norm", zend_string_copy(final_norm_id));
    add_assoc_bool(final_norm_probe, "executed", true);
    add_assoc_bool(final_norm_probe, "retained_device_output", true);
    add_assoc_long(final_norm_probe, "width", (zend_long) input_width);
    add_assoc_long(final_norm_probe, "bytes", (zend_long) bytes);
    add_assoc_double(final_norm_probe, "epsilon", epsilon);
    return SUCCESS;
}
