/*
 * CUDA prompt prefill layer-0 attention stack batch path.
 */

static zend_ulong king_inference_cuda_decoder_prompt_loop_prefill_kv_head_for_query(
    zend_ulong query_head,
    zend_ulong query_heads,
    zend_ulong kv_heads
) {
    zend_ulong kv_head;

    if (query_heads == kv_heads) {
        return query_head;
    }
    kv_head = (query_head * kv_heads) / query_heads;
    return kv_head >= kv_heads ? kv_heads - 1 : kv_head;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_copy_attention_context_rows(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr context_matrix,
    zend_ulong prefill_token_count,
    zend_ulong value_length,
    zend_ulong stack_width,
    zend_ulong query_head
) {
    zend_ulong position;

    for (position = 0; position < prefill_token_count; position++) {
        size_t source_offset = (size_t) position * (size_t) value_length;
        zend_ulong target_offset = position * stack_width + query_head * value_length;

        if (target_offset > UINT_MAX
            || king_inference_cuda_vector_copy_to_offset(
                model,
                context_matrix + (king_inference_cuda_device_ptr) (source_offset * sizeof(float)),
                model->cuda_decoder_prompt_prefill_layer0_attention_stack,
                target_offset,
                value_length
        ) != SUCCESS) {
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                model->cuda_device_vector_ops_result,
                model->cuda_device_vector_ops_error[0] != '\0'
                    ? model->cuda_device_vector_ops_error
                    : "cuda_decoder_prompt_batch_prefill_attention_stack_copy_failed"
            );
            return FAILURE;
        }
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_layer0_ffn_norm_batch(
    king_inference_model_object *model,
    zval *graph,
    zval *residual_op,
    zend_ulong residual_width,
    zend_ulong prefill_token_count
) {
    zval *ffn_norm_op;
    zend_string *residual_id = NULL;
    zend_string *ffn_norm_weight = NULL;
    double epsilon = 0.000001;
    size_t ffn_norm_elements;
    size_t ffn_norm_bytes;

    if (residual_op == NULL
        || residual_width == 0
        || prefill_token_count == 0
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) residual_width)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_norm_shape_invalid"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(residual_op, "id", &residual_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_norm_residual_id_invalid"
        );
        return FAILURE;
    }

    ffn_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, residual_id, "rms_norm");
    if (ffn_norm_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(ffn_norm_op, "weight", &ffn_norm_weight) != SUCCESS
        || king_inference_graph_finite_double_option(ffn_norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
        || epsilon <= 0.0) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_norm_contract_invalid"
        );
        return FAILURE;
    }

    ffn_norm_elements = (size_t) prefill_token_count * (size_t) residual_width;
    if (ffn_norm_elements > SIZE_MAX / sizeof(float)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_norm_size_overflow"
        );
        return FAILURE;
    }
    ffn_norm_bytes = ffn_norm_elements * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(
            model,
            ffn_norm_bytes,
            &model->cuda_decoder_prompt_prefill_layer0_ffn_norm
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_ffn_norm_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_rms_norm_f32_batch(
            model,
            ffn_norm_weight,
            model->cuda_decoder_prompt_prefill_layer0_attention_residual,
            model->cuda_decoder_prompt_prefill_layer0_ffn_norm,
            residual_width,
            prefill_token_count,
            epsilon
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_rms_norm_result,
            model->cuda_rms_norm_error[0] != '\0'
                ? model->cuda_rms_norm_error
                : "cuda_decoder_prompt_batch_prefill_ffn_norm_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_prompt_prefill_layer0_ffn_norm_bytes = ffn_norm_bytes;
    model->cuda_decoder_prompt_prefill_layer0_ffn_norm_width = residual_width;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_layer0_ffn_gate_up_batch(
    king_inference_model_object *model,
    zval *graph,
    zval *residual_op,
    zend_ulong ffn_norm_width,
    zend_ulong prefill_token_count
) {
    zval *ffn_norm_op;
    zval *gate_op;
    zval *up_op;
    zend_string *residual_id = NULL;
    zend_string *ffn_norm_id = NULL;
    zend_string *gate_weight = NULL;
    zend_string *up_weight = NULL;
    zend_ulong gate_rows = 0;
    zend_ulong up_rows = 0;
    size_t gate_elements;
    size_t up_elements;
    size_t gate_bytes;
    size_t up_bytes;

    if (model->cuda_decoder_prompt_prefill_layer0_ffn_norm == 0) {
        return SUCCESS;
    }
    if (residual_op == NULL
        || graph == NULL
        || ffn_norm_width == 0
        || ffn_norm_width != model->cuda_decoder_prompt_prefill_layer0_ffn_norm_width
        || prefill_token_count == 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_gate_up_shape_invalid"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(residual_op, "id", &residual_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_gate_up_residual_id_invalid"
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
            "cuda_decoder_prompt_batch_prefill_ffn_gate_up_norm_id_invalid"
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
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            gate_op,
            ffn_norm_width,
            &gate_weight,
            &gate_rows
    ) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            up_op,
            ffn_norm_width,
            &up_weight,
            &up_rows
    ) != SUCCESS
        || gate_rows == 0
        || gate_rows != up_rows) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            model->cuda_decoder_graph_executor_error[0] != '\0'
                ? model->cuda_decoder_graph_executor_error
                : "cuda_decoder_prompt_batch_prefill_ffn_gate_up_contract_invalid"
        );
        return FAILURE;
    }
    if (prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) gate_rows)
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) up_rows)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_gate_up_size_overflow"
        );
        return FAILURE;
    }

    gate_elements = (size_t) prefill_token_count * (size_t) gate_rows;
    up_elements = (size_t) prefill_token_count * (size_t) up_rows;
    if (gate_elements > SIZE_MAX / sizeof(float)
        || up_elements > SIZE_MAX / sizeof(float)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_gate_up_bytes_overflow"
        );
        return FAILURE;
    }
    gate_bytes = gate_elements * sizeof(float);
    up_bytes = up_elements * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(
            model,
            gate_bytes,
            &model->cuda_decoder_prompt_prefill_layer0_ffn_gate
    ) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(
            model,
            up_bytes,
            &model->cuda_decoder_prompt_prefill_layer0_ffn_up
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_ffn_gate_up_allocation_failed"
        );
        return FAILURE;
    }

    if (king_inference_cuda_quantized_matvec_batch(
            model,
            gate_weight,
            model->cuda_decoder_prompt_prefill_layer0_ffn_norm,
            ffn_norm_width,
            prefill_token_count,
            model->cuda_decoder_prompt_prefill_layer0_ffn_gate,
            gate_rows
    ) != SUCCESS
        || king_inference_cuda_quantized_matvec_batch(
            model,
            up_weight,
            model->cuda_decoder_prompt_prefill_layer0_ffn_norm,
            ffn_norm_width,
            prefill_token_count,
            model->cuda_decoder_prompt_prefill_layer0_ffn_up,
            up_rows
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_quantized_matvec_result,
            model->cuda_quantized_matvec_error[0] != '\0'
                ? model->cuda_quantized_matvec_error
                : "cuda_decoder_prompt_batch_prefill_ffn_gate_up_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_prompt_prefill_layer0_ffn_gate_bytes = gate_bytes;
    model->cuda_decoder_prompt_prefill_layer0_ffn_up_bytes = up_bytes;
    model->cuda_decoder_prompt_prefill_layer0_ffn_gate_rows = gate_rows;
    model->cuda_decoder_prompt_prefill_layer0_ffn_up_rows = up_rows;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_layer0_ffn_swiglu_batch(
    king_inference_model_object *model,
    zval *graph,
    zval *residual_op,
    zend_ulong ffn_width,
    zend_ulong prefill_token_count
) {
    zval *ffn_norm_op;
    zval *gate_op;
    zval *up_op;
    zval *silu_op;
    zval *mul_op;
    zend_string *residual_id = NULL;
    zend_string *ffn_norm_id = NULL;
    zend_string *gate_id = NULL;
    zend_string *up_id = NULL;
    zend_string *silu_id = NULL;
    zend_string *gated_id = NULL;
    zend_ulong activation = 0;
    size_t swiglu_elements;
    size_t swiglu_bytes;

    if (model->cuda_decoder_prompt_prefill_layer0_ffn_gate == 0
        || model->cuda_decoder_prompt_prefill_layer0_ffn_up == 0) {
        return SUCCESS;
    }
    if (graph == NULL
        || residual_op == NULL
        || ffn_width == 0
        || ffn_width != model->cuda_decoder_prompt_prefill_layer0_ffn_gate_rows
        || ffn_width != model->cuda_decoder_prompt_prefill_layer0_ffn_up_rows
        || prefill_token_count == 0
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) ffn_width)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_swiglu_shape_invalid"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(residual_op, "id", &residual_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_swiglu_residual_id_invalid"
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
            "cuda_decoder_prompt_batch_prefill_ffn_swiglu_norm_id_invalid"
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
            "cuda_decoder_prompt_batch_prefill_ffn_swiglu_gate_up_contract_invalid"
        );
        return FAILURE;
    }
    silu_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gate_id, "silu");
    if (silu_op == NULL) {
        silu_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gate_id, "gelu_tanh");
        activation = silu_op != NULL ? 1 : 0;
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
            "cuda_decoder_prompt_batch_prefill_ffn_swiglu_contract_invalid"
        );
        return FAILURE;
    }

    swiglu_elements = (size_t) prefill_token_count * (size_t) ffn_width;
    if (swiglu_elements > SIZE_MAX / sizeof(float) || swiglu_elements > UINT_MAX) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_ffn_swiglu_size_overflow"
        );
        return FAILURE;
    }
    swiglu_bytes = swiglu_elements * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(
            model,
            swiglu_bytes,
            &model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_ffn_swiglu_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_ffn_swiglu_f32(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_gate,
            model->cuda_decoder_prompt_prefill_layer0_ffn_up,
            model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu,
            (zend_ulong) swiglu_elements,
            activation
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_ffn_swiglu_result,
            model->cuda_ffn_swiglu_error[0] != '\0'
                ? model->cuda_ffn_swiglu_error
                : "cuda_decoder_prompt_batch_prefill_ffn_swiglu_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu_bytes = swiglu_bytes;
    model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu_width = ffn_width;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_layer0_attention_stack_batch(
    king_inference_model_object *model,
    zval *graph,
    zend_ulong width,
    zend_ulong prefill_token_count
) {
    zval *stack_op;
    zval *projection_op;
    zval *post_attention_norm_op = NULL;
    zval *residual_op;
    zend_string *stack_id = NULL;
    zend_string *projection_weight = NULL;
    zend_string *projection_id = NULL;
    zend_string *post_attention_norm_id = NULL;
    zend_string *post_attention_norm_weight = NULL;
    zend_string *projection_residual_id = NULL;
    zend_string *hidden_id = NULL;
    zend_ulong query_heads = model->cuda_decoder_prompt_prefill_initial_query_rope_heads;
    zend_ulong kv_heads = model->cuda_decoder_prompt_prefill_kv_heads;
    zend_ulong key_length = model->cuda_decoder_prompt_prefill_initial_query_rope_head_dim;
    zend_ulong value_length = model->cuda_decoder_prompt_prefill_kv_value_length;
    zend_ulong sliding_window = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_SLIDING_WINDOW];
    zend_ulong max_slots = sliding_window > 0 && sliding_window < prefill_token_count
        ? sliding_window
        : prefill_token_count;
    zend_ulong stack_width;
    size_t score_elements;
    size_t context_elements;
    size_t stack_elements;
    size_t score_bytes;
    size_t context_bytes;
    size_t stack_bytes;
    size_t projection_elements;
    size_t projection_bytes;
    size_t residual_bytes;
    king_inference_cuda_device_ptr scores = 0;
    king_inference_cuda_device_ptr probabilities = 0;
    king_inference_cuda_device_ptr contexts = 0;
    king_inference_cuda_device_ptr projection_matrix = 0;
    king_inference_cuda_device_ptr post_attention_norm_matrix = 0;
    king_inference_cuda_device_ptr projection_residual_matrix = 0;
    zend_ulong projection_rows = 0;
    zend_ulong query_head;
    double post_attention_norm_epsilon = 0.000001;
    double scale;

    if (graph == NULL
        || model->cuda_decoder_prompt_prefill_initial_query_rope == 0
        || !model->cuda_device_kv_cache_available
        || query_heads == 0
        || kv_heads == 0
        || key_length == 0
        || value_length == 0
        || width == 0
        || key_length > model->cuda_device_kv_cache_key_length
        || value_length > model->cuda_device_kv_cache_value_length
        || prefill_token_count == 0
        || max_slots == 0
        || query_heads < kv_heads
        || query_heads > (zend_ulong) (SIZE_MAX / (size_t) value_length)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_attention_shape_invalid"
        );
        return FAILURE;
    }

    stack_width = query_heads * value_length;
    stack_op = king_inference_cuda_decoder_graph_executor_first_op(graph, "stack");
    projection_op = stack_op != NULL
        && king_inference_graph_required_string(stack_op, "id", &stack_id) == SUCCESS
        ? king_inference_cuda_decoder_graph_executor_op_for_input(graph, stack_id, "linear")
        : NULL;
    if (projection_op == NULL
        || king_inference_graph_required_string(projection_op, "id", &projection_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            projection_op,
            stack_width,
            &projection_weight,
            &projection_rows
    ) != SUCCESS
        || projection_rows != width) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_attention_projection_shape_invalid"
        );
        return FAILURE;
    }
    projection_residual_id = projection_id;
    post_attention_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, projection_id, "rms_norm");
    if (post_attention_norm_op != NULL) {
        if (king_inference_graph_required_string(post_attention_norm_op, "id", &post_attention_norm_id) != SUCCESS
            || king_inference_graph_required_string(post_attention_norm_op, "weight", &post_attention_norm_weight) != SUCCESS
            || king_inference_graph_finite_double_option(post_attention_norm_op, "epsilon", 0.000001, &post_attention_norm_epsilon) != SUCCESS
            || post_attention_norm_epsilon <= 0.0) {
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                -1,
                "cuda_decoder_prompt_batch_prefill_post_attention_norm_contract_invalid"
            );
            return FAILURE;
        }
        projection_residual_id = post_attention_norm_id;
    }
    hidden_id = zend_string_init("x", sizeof("x") - 1, false);
    residual_op = king_inference_cuda_decoder_graph_executor_add_op_for_inputs(graph, hidden_id, projection_residual_id);
    zend_string_release(hidden_id);
    if (residual_op == NULL) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_attention_residual_contract_invalid"
        );
        return FAILURE;
    }

    if (prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) max_slots)
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) value_length)
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) stack_width)
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) projection_rows)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_attention_size_overflow"
        );
        return FAILURE;
    }

    score_elements = (size_t) prefill_token_count * (size_t) max_slots;
    context_elements = (size_t) prefill_token_count * (size_t) value_length;
    stack_elements = (size_t) prefill_token_count * (size_t) stack_width;
    projection_elements = (size_t) prefill_token_count * (size_t) projection_rows;
    if (score_elements > SIZE_MAX / sizeof(float)
        || context_elements > SIZE_MAX / sizeof(float)
        || stack_elements > SIZE_MAX / sizeof(float)
        || projection_elements > SIZE_MAX / sizeof(float)
        || projection_elements > UINT_MAX) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_attention_bytes_overflow"
        );
        return FAILURE;
    }

    score_bytes = score_elements * sizeof(float);
    context_bytes = context_elements * sizeof(float);
    stack_bytes = stack_elements * sizeof(float);
    projection_bytes = projection_elements * sizeof(float);
    residual_bytes = projection_bytes;
    if (king_inference_cuda_device_allocator_alloc(model, score_bytes, &scores) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, score_bytes, &probabilities) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, context_bytes, &contexts) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(
            model,
            stack_bytes,
            &model->cuda_decoder_prompt_prefill_layer0_attention_stack
        ) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, projection_bytes, &projection_matrix) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(
            model,
            residual_bytes,
            &model->cuda_decoder_prompt_prefill_layer0_attention_residual
        ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_attention_allocation_failed"
        );
        return FAILURE;
    }

    scale = 1.0 / sqrt((double) key_length);
    for (query_head = 0; query_head < query_heads; query_head++) {
        zend_ulong kv_head = king_inference_cuda_decoder_prompt_loop_prefill_kv_head_for_query(
            query_head,
            query_heads,
            kv_heads
        );
        king_inference_cuda_device_ptr key_cache = 0;
        king_inference_cuda_device_ptr value_cache = 0;
        king_inference_cuda_device_ptr query_base = model->cuda_decoder_prompt_prefill_initial_query_rope
            + (king_inference_cuda_device_ptr) ((size_t) query_head * (size_t) prefill_token_count * (size_t) key_length * sizeof(float));
        zend_ulong key_stride = 0;
        zend_ulong value_stride = 0;

        if (king_inference_cuda_device_kv_cache_head(model, false, 0, kv_head, &key_cache, &key_stride) != SUCCESS
            || king_inference_cuda_device_kv_cache_head(model, true, 0, kv_head, &value_cache, &value_stride) != SUCCESS
            || king_inference_cuda_attention_scores_f32_batch(
                model,
                query_base,
                key_cache,
                scores,
                key_length,
                key_length,
                key_stride,
                0,
                max_slots,
                prefill_token_count,
                sliding_window,
                scale
        ) != SUCCESS
            || king_inference_cuda_attention_softmax_f32_batch(
                model,
                scores,
                probabilities,
                0,
                0,
                max_slots,
                prefill_token_count,
                sliding_window,
                1.0,
                1.0
        ) != SUCCESS
            || king_inference_cuda_attention_values_f32_batch(
                model,
                probabilities,
                value_cache,
                contexts,
                value_length,
                value_stride,
                0,
                max_slots,
                prefill_token_count,
                sliding_window
        ) != SUCCESS
            || king_inference_cuda_decoder_prompt_loop_prefill_copy_attention_context_rows(
                model,
                contexts,
                prefill_token_count,
                value_length,
                stack_width,
                query_head
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
            return FAILURE;
        }
    }

    if (king_inference_cuda_quantized_matvec_batch(
            model,
            projection_weight,
            model->cuda_decoder_prompt_prefill_layer0_attention_stack,
            stack_width,
            prefill_token_count,
            projection_matrix,
            projection_rows
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_quantized_matvec_result,
            model->cuda_quantized_matvec_error[0] != '\0'
                ? model->cuda_quantized_matvec_error
                : "cuda_decoder_prompt_batch_prefill_attention_projection_failed"
        );
        return FAILURE;
    }
    projection_residual_matrix = projection_matrix;
    if (post_attention_norm_op != NULL) {
        if (king_inference_cuda_device_allocator_alloc(model, projection_bytes, &post_attention_norm_matrix) != SUCCESS
            || king_inference_cuda_rms_norm_f32_batch(
                model,
                post_attention_norm_weight,
                projection_matrix,
                post_attention_norm_matrix,
                projection_rows,
                prefill_token_count,
                post_attention_norm_epsilon
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_matrix);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                model->cuda_rms_norm_result != 0
                    ? model->cuda_rms_norm_result
                    : model->cuda_device_allocator_result,
                model->cuda_rms_norm_error[0] != '\0'
                    ? model->cuda_rms_norm_error
                    : (model->cuda_device_allocator_error[0] != '\0'
                        ? model->cuda_device_allocator_error
                        : "cuda_decoder_prompt_batch_prefill_post_attention_norm_failed")
            );
            return FAILURE;
        }
        projection_residual_matrix = post_attention_norm_matrix;
    }
    if (king_inference_cuda_vector_add(
            model,
            model->cuda_decoder_prompt_prefill_embeddings,
            projection_residual_matrix,
            model->cuda_decoder_prompt_prefill_layer0_attention_residual,
            (zend_ulong) projection_elements
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_quantized_matvec_result != 0
                ? model->cuda_quantized_matvec_result
                : model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_prompt_batch_prefill_attention_residual_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_prompt_prefill_layer0_attention_stack_bytes = stack_bytes;
    model->cuda_decoder_prompt_prefill_layer0_attention_heads = query_heads;
    model->cuda_decoder_prompt_prefill_layer0_attention_stack_width = stack_width;
    model->cuda_decoder_prompt_prefill_layer0_attention_residual_bytes = residual_bytes;
    model->cuda_decoder_prompt_prefill_layer0_attention_residual_width = projection_rows;
    if (king_inference_cuda_decoder_prompt_loop_prefill_layer0_ffn_norm_batch(
            model,
            graph,
            residual_op,
            projection_rows,
            prefill_token_count
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_prompt_loop_prefill_layer0_ffn_gate_up_batch(
            model,
            graph,
            residual_op,
            projection_rows,
            prefill_token_count
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_prompt_loop_prefill_layer0_ffn_swiglu_batch(
            model,
            graph,
            residual_op,
            model->cuda_decoder_prompt_prefill_layer0_ffn_gate_rows,
            prefill_token_count
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_prompt_loop_prefill_layer0_ffn_down_batch(
            model,
            graph,
            residual_op,
            model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu_width,
            prefill_token_count
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_prompt_loop_prefill_layer0_ffn_output_residual_batch(
            model,
            graph,
            residual_op,
            projection_rows,
            prefill_token_count
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
        return FAILURE;
    }
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &projection_matrix);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &contexts);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores);
    return SUCCESS;
}
