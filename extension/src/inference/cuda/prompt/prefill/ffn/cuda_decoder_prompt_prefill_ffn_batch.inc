/*
 * CUDA prompt prefill layer-0 FFN batch helpers.
 */

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_layer0_ffn_down_batch(
    king_inference_model_object *model,
    zval *graph,
    zval *residual_op,
    zend_ulong swiglu_width,
    zend_ulong prefill_token_count
) {
    zval *ffn_norm_op;
    zval *gate_op;
    zval *up_op;
    zval *silu_op;
    zval *mul_op;
    zval *down_op;
    zend_string *residual_id = NULL;
    zend_string *ffn_norm_id = NULL;
    zend_string *gate_id = NULL;
    zend_string *up_id = NULL;
    zend_string *silu_id = NULL;
    zend_string *gated_id = NULL;
    zend_string *down_weight = NULL;
    zend_ulong down_rows = 0;
    size_t down_elements;
    size_t down_bytes;

    if (model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu == 0) {
        return SUCCESS;
    }
    if (graph == NULL
        || residual_op == NULL
        || swiglu_width == 0
        || swiglu_width != model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu_width
        || prefill_token_count == 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_down_shape_invalid"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(residual_op, "id", &residual_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_down_residual_id_invalid"
        );
        return FAILURE;
    }

    ffn_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, residual_id, "rms_norm");
    if (ffn_norm_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(ffn_norm_op, "id", &ffn_norm_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_down_norm_id_invalid"
        );
        return FAILURE;
    }
    gate_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, ffn_norm_id, "linear", 0);
    up_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, ffn_norm_id, "linear", 1);
    if (gate_op == NULL && up_op == NULL) {
        return SUCCESS;
    }
    if (gate_op == NULL
        || up_op == NULL
        || king_inference_graph_required_string(gate_op, "id", &gate_id) != SUCCESS
        || king_inference_graph_required_string(up_op, "id", &up_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_down_gate_up_contract_invalid"
        );
        return FAILURE;
    }

    silu_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gate_id, "silu");
    if (silu_op == NULL) {
        silu_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gate_id, "gelu_tanh");
    }
    if (silu_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(silu_op, "id", &silu_id) != SUCCESS
        || (mul_op = king_inference_cuda_decoder_graph_executor_mul_op_for_inputs(graph, silu_id, up_id)) == NULL
        || king_inference_graph_required_string(mul_op, "id", &gated_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_down_swiglu_contract_invalid"
        );
        return FAILURE;
    }

    down_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gated_id, "linear");
    if (down_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            down_op,
            swiglu_width,
            &down_weight,
            &down_rows
    ) != SUCCESS
        || down_rows == 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            model->cuda_decoder_graph_executor_error[0] != '\0'
                ? model->cuda_decoder_graph_executor_error
                : "cuda_decoder_prompt_batch_prefill_ffn_down_contract_invalid"
        );
        return FAILURE;
    }
    if (prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) down_rows)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_down_size_overflow"
        );
        return FAILURE;
    }

    down_elements = (size_t) prefill_token_count * (size_t) down_rows;
    if (down_elements > SIZE_MAX / sizeof(float)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_down_bytes_overflow"
        );
        return FAILURE;
    }
    down_bytes = down_elements * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(
            model,
            down_bytes,
            &model->cuda_decoder_prompt_prefill_layer0_ffn_down
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_ffn_down_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_quantized_matvec_batch(
            model,
            down_weight,
            model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu,
            swiglu_width,
            prefill_token_count,
            model->cuda_decoder_prompt_prefill_layer0_ffn_down,
            down_rows
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_quantized_matvec_result,
            model->cuda_quantized_matvec_error[0] != '\0'
                ? model->cuda_quantized_matvec_error
                : "cuda_decoder_prompt_batch_prefill_ffn_down_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_prompt_prefill_layer0_ffn_down_bytes = down_bytes;
    model->cuda_decoder_prompt_prefill_layer0_ffn_down_width = down_rows;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_layer0_ffn_output_residual_batch(
    king_inference_model_object *model,
    zval *graph,
    zval *residual_op,
    zend_ulong residual_width,
    zend_ulong prefill_token_count
) {
    zval *ffn_norm_op;
    zval *gate_op;
    zval *up_op;
    zval *silu_op;
    zval *mul_op;
    zval *down_op;
    zval *post_ffw_norm_op = NULL;
    zval *output_op;
    zend_string *residual_id = NULL;
    zend_string *ffn_norm_id = NULL;
    zend_string *gate_id = NULL;
    zend_string *up_id = NULL;
    zend_string *silu_id = NULL;
    zend_string *gated_id = NULL;
    zend_string *down_id = NULL;
    zend_string *post_ffw_norm_id = NULL;
    zend_string *post_ffw_norm_weight = NULL;
    zend_string *output_right_id = NULL;
    zend_string *output_id = NULL;
    king_inference_cuda_device_ptr post_ffw_norm_matrix = 0;
    king_inference_cuda_device_ptr output_right_matrix = 0;
    size_t output_elements;
    size_t output_bytes;
    double post_ffw_norm_epsilon = 0.000001;

    if (model->cuda_decoder_prompt_prefill_layer0_ffn_down == 0) {
        return SUCCESS;
    }
    if (graph == NULL
        || residual_op == NULL
        || residual_width == 0
        || residual_width != model->cuda_decoder_prompt_prefill_layer0_attention_residual_width
        || residual_width != model->cuda_decoder_prompt_prefill_layer0_ffn_down_width
        || prefill_token_count == 0
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) residual_width)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_output_residual_shape_invalid"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(residual_op, "id", &residual_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_output_residual_id_invalid"
        );
        return FAILURE;
    }

    ffn_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, residual_id, "rms_norm");
    if (ffn_norm_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(ffn_norm_op, "id", &ffn_norm_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_output_norm_id_invalid"
        );
        return FAILURE;
    }
    gate_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, ffn_norm_id, "linear", 0);
    up_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, ffn_norm_id, "linear", 1);
    if (gate_op == NULL && up_op == NULL) {
        return SUCCESS;
    }
    if (gate_op == NULL
        || up_op == NULL
        || king_inference_graph_required_string(gate_op, "id", &gate_id) != SUCCESS
        || king_inference_graph_required_string(up_op, "id", &up_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_output_gate_up_contract_invalid"
        );
        return FAILURE;
    }

    silu_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gate_id, "silu");
    if (silu_op == NULL) {
        silu_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gate_id, "gelu_tanh");
    }
    if (silu_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(silu_op, "id", &silu_id) != SUCCESS
        || (mul_op = king_inference_cuda_decoder_graph_executor_mul_op_for_inputs(graph, silu_id, up_id)) == NULL
        || king_inference_graph_required_string(mul_op, "id", &gated_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_output_swiglu_contract_invalid"
        );
        return FAILURE;
    }

    down_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gated_id, "linear");
    if (down_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(down_op, "id", &down_id) != SUCCESS) {
        return SUCCESS;
    }
    output_right_id = down_id;
    post_ffw_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, down_id, "rms_norm");
    if (post_ffw_norm_op != NULL) {
        if (king_inference_graph_required_string(post_ffw_norm_op, "id", &post_ffw_norm_id) != SUCCESS
            || king_inference_graph_required_string(post_ffw_norm_op, "weight", &post_ffw_norm_weight) != SUCCESS
            || king_inference_graph_finite_double_option(post_ffw_norm_op, "epsilon", 0.000001, &post_ffw_norm_epsilon) != SUCCESS
            || post_ffw_norm_epsilon <= 0.0) {
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                -1,
                "cuda_decoder_prompt_batch_prefill_post_ffw_norm_contract_invalid"
            );
            return FAILURE;
        }
        output_right_id = post_ffw_norm_id;
    }
    output_op = king_inference_cuda_decoder_graph_executor_add_op_for_inputs(graph, residual_id, output_right_id);
    if (output_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(output_op, "id", &output_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_output_contract_invalid"
        );
        return FAILURE;
    }
    (void) output_id;

    output_elements = (size_t) prefill_token_count * (size_t) residual_width;
    if (output_elements > SIZE_MAX / sizeof(float) || output_elements > UINT_MAX) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_output_size_overflow"
        );
        return FAILURE;
    }
    output_bytes = output_elements * sizeof(float);
    output_right_matrix = model->cuda_decoder_prompt_prefill_layer0_ffn_down;
    if (post_ffw_norm_op != NULL) {
        if (king_inference_cuda_device_allocator_alloc(model, output_bytes, &post_ffw_norm_matrix) != SUCCESS
            || king_inference_cuda_rms_norm_f32_batch(
                model,
                post_ffw_norm_weight,
                model->cuda_decoder_prompt_prefill_layer0_ffn_down,
                post_ffw_norm_matrix,
                residual_width,
                prefill_token_count,
                post_ffw_norm_epsilon
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_ffw_norm_matrix);
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                model->cuda_rms_norm_result != 0
                    ? model->cuda_rms_norm_result
                    : model->cuda_device_allocator_result,
                model->cuda_rms_norm_error[0] != '\0'
                    ? model->cuda_rms_norm_error
                    : (model->cuda_device_allocator_error[0] != '\0'
                        ? model->cuda_device_allocator_error
                        : "cuda_decoder_prompt_batch_prefill_post_ffw_norm_failed")
            );
            return FAILURE;
        }
        output_right_matrix = post_ffw_norm_matrix;
    }
    if (king_inference_cuda_device_allocator_alloc(
            model,
            output_bytes,
            &model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_ffw_norm_matrix);
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_ffn_output_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_vector_add(
            model,
            model->cuda_decoder_prompt_prefill_layer0_attention_residual,
            output_right_matrix,
            model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual,
            (zend_ulong) output_elements
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_ffw_norm_matrix);
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_prompt_batch_prefill_ffn_output_failed"
        );
        return FAILURE;
    }
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_ffw_norm_matrix);

    model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual_bytes = output_bytes;
    model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual_width = residual_width;
    return SUCCESS;
}
