/*
 * CUDA prompt prefill batch path for layers after the already batched layer 0.
 */

static zval *king_inference_cuda_decoder_prompt_loop_prefill_op_by_id(
    zval *graph,
    const char *id
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    size_t id_length = id != NULL ? strlen(id) : 0;

    if (ops == NULL || id_length == 0) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_id;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_id = king_inference_array_find(entry, "id");
        if (op_id != NULL
            && Z_TYPE_P(op_id) == IS_STRING
            && Z_STRLEN_P(op_id) == id_length
            && memcmp(Z_STRVAL_P(op_id), id, id_length) == 0) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_layer_id(
    char *buffer,
    size_t buffer_size,
    const char *format,
    zend_ulong layer
) {
    int written = snprintf(buffer, buffer_size, format, (unsigned long) layer);

    if (written <= 0 || (size_t) written >= buffer_size) {
        return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_copy_context_rows_to_stack(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr context_matrix,
    king_inference_cuda_device_ptr stack_matrix,
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
                stack_matrix,
                target_offset,
                value_length
        ) != SUCCESS) {
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                model->cuda_device_vector_ops_result,
                model->cuda_device_vector_ops_error[0] != '\0'
                    ? model->cuda_device_vector_ops_error
                    : "cuda_decoder_prompt_remaining_prefill_attention_stack_copy_failed"
            );
            return FAILURE;
        }
    }
    return SUCCESS;
}

static void king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr *norm_matrix,
    king_inference_cuda_device_ptr *query_matrix,
    king_inference_cuda_device_ptr *key_matrix,
    king_inference_cuda_device_ptr *value_matrix,
    king_inference_cuda_device_ptr *query_rope_matrix,
    king_inference_cuda_device_ptr *scores,
    king_inference_cuda_device_ptr *probabilities,
    king_inference_cuda_device_ptr *contexts,
    king_inference_cuda_device_ptr *attention_stack,
    king_inference_cuda_device_ptr *projection_matrix,
    king_inference_cuda_device_ptr *attention_residual,
    king_inference_cuda_device_ptr *ffn_norm,
    king_inference_cuda_device_ptr *ffn_gate,
    king_inference_cuda_device_ptr *ffn_up,
    king_inference_cuda_device_ptr *ffn_swiglu,
    king_inference_cuda_device_ptr *ffn_down
) {
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, ffn_down);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, ffn_swiglu);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, ffn_up);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, ffn_gate);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, ffn_norm);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, attention_residual);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, projection_matrix);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, attention_stack);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, contexts);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, probabilities);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, scores);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, query_rope_matrix);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, value_matrix);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, key_matrix);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, query_matrix);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, norm_matrix);
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_remaining_layer_batch(
    king_inference_model_object *model,
    zval *graph,
    zend_ulong layer,
    zend_ulong prefill_token_count,
    zend_string *current_id,
    king_inference_cuda_device_ptr current_matrix,
    zend_ulong current_width,
    zend_string **next_id_out,
    king_inference_cuda_device_ptr *next_matrix_out,
    zend_ulong *next_width_out
) {
    zval *norm_op;
    zval *query_op;
    zval *key_op;
    zval *value_op;
    zval *query_slice_op;
    zval *query_rope_op = NULL;
    zval *stack_op;
    zval *projection_op;
    zval *post_attention_norm_op = NULL;
    zval *residual_op;
    zval *ffn_norm_op;
    zval *gate_op;
    zval *up_op;
    zval *silu_op;
    zval *mul_op;
    zval *down_op;
    zval *post_ffw_norm_op = NULL;
    zval *output_op;
    zend_string *norm_id = NULL;
    zend_string *norm_weight = NULL;
    zend_string *query_id = NULL;
    zend_string *query_slice_id = NULL;
    zend_string *query_weight = NULL;
    zend_string *key_weight = NULL;
    zend_string *value_weight = NULL;
    zend_string *stack_id = NULL;
    zend_string *projection_id = NULL;
    zend_string *projection_weight = NULL;
    zend_string *post_attention_norm_id = NULL;
    zend_string *post_attention_norm_weight = NULL;
    zend_string *projection_residual_id = NULL;
    zend_string *residual_id = NULL;
    zend_string *ffn_norm_id = NULL;
    zend_string *ffn_norm_weight = NULL;
    zend_string *gate_id = NULL;
    zend_string *up_id = NULL;
    zend_string *gate_weight = NULL;
    zend_string *up_weight = NULL;
    zend_string *silu_id = NULL;
    zend_string *gated_id = NULL;
    zend_string *down_id = NULL;
    zend_string *down_weight = NULL;
    zend_string *post_ffw_norm_id = NULL;
    zend_string *post_ffw_norm_weight = NULL;
    zend_string *output_right_id = NULL;
    zend_string *output_id = NULL;
    zend_ulong query_rows = 0;
    zend_ulong key_rows = 0;
    zend_ulong value_rows = 0;
    zend_ulong projection_rows = 0;
    zend_ulong gate_rows = 0;
    zend_ulong up_rows = 0;
    zend_ulong down_rows = 0;
    zend_ulong query_heads = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT];
    zend_ulong kv_heads = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV];
    zend_ulong key_length = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH];
    zend_ulong value_length = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH];
    zend_ulong sliding_window = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_SLIDING_WINDOW];
    zend_ulong max_slots;
    zend_ulong stack_width;
    zend_ulong query_head;
    zend_ulong kv_head;
    double epsilon = 0.000001;
    double ffn_epsilon = 0.000001;
    double post_attention_norm_epsilon = 0.000001;
    double post_ffw_norm_epsilon = 0.000001;
    double rope_base = 10000.0;
    double rope_position_scale = 1.0;
    zend_ulong rope_pairing = 0;
    zend_ulong ffn_activation = king_inference_decode_graph_gemma_profile(model) ? 1 : 0;
    double scale;
    char stack_id_buffer[64];
    king_inference_cuda_device_ptr norm_matrix = 0;
    king_inference_cuda_device_ptr query_matrix = 0;
    king_inference_cuda_device_ptr key_matrix = 0;
    king_inference_cuda_device_ptr value_matrix = 0;
    king_inference_cuda_device_ptr query_rope_matrix = 0;
    king_inference_cuda_device_ptr key_rope_matrix = 0;
    king_inference_cuda_device_ptr scores = 0;
    king_inference_cuda_device_ptr probabilities = 0;
    king_inference_cuda_device_ptr contexts = 0;
    king_inference_cuda_device_ptr attention_stack = 0;
    king_inference_cuda_device_ptr projection_matrix = 0;
    king_inference_cuda_device_ptr post_attention_norm_matrix = 0;
    king_inference_cuda_device_ptr projection_residual_matrix = 0;
    king_inference_cuda_device_ptr attention_residual = 0;
    king_inference_cuda_device_ptr ffn_norm = 0;
    king_inference_cuda_device_ptr ffn_gate = 0;
    king_inference_cuda_device_ptr ffn_up = 0;
    king_inference_cuda_device_ptr ffn_swiglu = 0;
    king_inference_cuda_device_ptr ffn_down = 0;
    king_inference_cuda_device_ptr post_ffw_norm_matrix = 0;
    king_inference_cuda_device_ptr ffn_output_right_matrix = 0;
    king_inference_cuda_device_ptr ffn_output = 0;
    size_t current_elements;
    size_t q_elements;
    size_t k_elements;
    size_t v_elements;
    size_t query_rope_elements;
    size_t score_elements;
    size_t context_elements;
    size_t stack_elements;
    size_t projection_elements;
    size_t gate_elements;
    size_t up_elements;
    size_t swiglu_elements;
    size_t down_elements;

    *next_id_out = NULL;
    *next_matrix_out = 0;
    *next_width_out = 0;
    if (graph == NULL
        || current_id == NULL
        || current_matrix == 0
        || current_width == 0
        || prefill_token_count == 0
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) current_width)) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_input_invalid");
        return FAILURE;
    }
    king_inference_attention_refine_runtime_shape_from_tensors_for_layer(
        model,
        layer,
        &query_heads,
        &kv_heads,
        &key_length,
        &value_length
    );
    if (kv_heads == 0) {
        kv_heads = query_heads;
    }
    max_slots = sliding_window > 0 && sliding_window < prefill_token_count ? sliding_window : prefill_token_count;
    if (query_heads == 0
        || kv_heads == 0
        || query_heads < kv_heads
        || key_length == 0
        || value_length == 0
        || max_slots == 0
        || query_heads > (zend_ulong) (SIZE_MAX / (size_t) value_length)) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_attention_shape_invalid");
        return FAILURE;
    }

    norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, current_id, "rms_norm");
    if (norm_op == NULL
        || king_inference_graph_required_string(norm_op, "id", &norm_id) != SUCCESS
        || king_inference_graph_required_string(norm_op, "weight", &norm_weight) != SUCCESS
        || king_inference_graph_finite_double_option(norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
        || epsilon <= 0.0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_norm_contract_invalid");
        return FAILURE;
    }
    query_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, norm_id, "linear", 0);
    key_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, norm_id, "linear", 1);
    value_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, norm_id, "linear", 2);
    if (query_op == NULL
        || key_op == NULL
        || value_op == NULL
        || king_inference_graph_required_string(query_op, "id", &query_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(model, query_op, current_width, &query_weight, &query_rows) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(model, key_op, current_width, &key_weight, &key_rows) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(model, value_op, current_width, &value_weight, &value_rows) != SUCCESS
        || query_rows != query_heads * key_length
        || key_rows < kv_heads * key_length
        || value_rows < kv_heads * value_length) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_qkv_contract_invalid");
        return FAILURE;
    }
    query_slice_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, query_id, "slice");
    if (query_slice_op != NULL
        && king_inference_graph_required_string(query_slice_op, "id", &query_slice_id) == SUCCESS) {
        query_rope_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, query_slice_id, "rope");
    }
    if (query_rope_op != NULL
        && (king_inference_graph_finite_double_option(query_rope_op, "position_scale", 1.0, &rope_position_scale) != SUCCESS
            || king_inference_graph_finite_double_option(query_rope_op, "rope_base", 10000.0, &rope_base) != SUCCESS)) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_rope_options_invalid");
        return FAILURE;
    }
    if (query_rope_op != NULL) {
        rope_pairing = king_inference_tensor_option_ulong(query_rope_op, "pairing", 0);
    }
    if (king_inference_cuda_decoder_prompt_loop_prefill_layer_id(
            stack_id_buffer,
            sizeof(stack_id_buffer),
            "l%lu_context",
            layer
    ) != SUCCESS
        || (stack_op = king_inference_cuda_decoder_prompt_loop_prefill_op_by_id(graph, stack_id_buffer)) == NULL
        || king_inference_graph_required_string(stack_op, "id", &stack_id) != SUCCESS
        || (projection_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, stack_id, "linear")) == NULL
        || king_inference_graph_required_string(projection_op, "id", &projection_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            projection_op,
            query_heads * value_length,
            &projection_weight,
            &projection_rows
    ) != SUCCESS
        || projection_rows != current_width) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_attention_contract_invalid");
        return FAILURE;
    }
    projection_residual_id = projection_id;
    post_attention_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, projection_id, "rms_norm");
    if (post_attention_norm_op != NULL) {
        if (king_inference_graph_required_string(post_attention_norm_op, "id", &post_attention_norm_id) != SUCCESS
            || king_inference_graph_required_string(post_attention_norm_op, "weight", &post_attention_norm_weight) != SUCCESS
            || king_inference_graph_finite_double_option(post_attention_norm_op, "epsilon", 0.000001, &post_attention_norm_epsilon) != SUCCESS
            || post_attention_norm_epsilon <= 0.0) {
            king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_post_attention_norm_contract_invalid");
            return FAILURE;
        }
        projection_residual_id = post_attention_norm_id;
    }
    residual_op = king_inference_cuda_decoder_graph_executor_add_op_for_inputs(graph, current_id, projection_residual_id);
    if (residual_op == NULL
        || king_inference_graph_required_string(residual_op, "id", &residual_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_attention_contract_invalid");
        return FAILURE;
    }
    ffn_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, residual_id, "rms_norm");
    if (ffn_norm_op == NULL
        || king_inference_graph_required_string(ffn_norm_op, "id", &ffn_norm_id) != SUCCESS
        || king_inference_graph_required_string(ffn_norm_op, "weight", &ffn_norm_weight) != SUCCESS
        || king_inference_graph_finite_double_option(ffn_norm_op, "epsilon", epsilon, &ffn_epsilon) != SUCCESS
        || ffn_epsilon <= 0.0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_ffn_norm_contract_invalid");
        return FAILURE;
    }
    gate_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, ffn_norm_id, "linear", 0);
    up_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, ffn_norm_id, "linear", 1);
    if (gate_op == NULL
        || up_op == NULL
        || king_inference_graph_required_string(gate_op, "id", &gate_id) != SUCCESS
        || king_inference_graph_required_string(up_op, "id", &up_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(model, gate_op, current_width, &gate_weight, &gate_rows) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(model, up_op, current_width, &up_weight, &up_rows) != SUCCESS
        || gate_rows == 0
        || gate_rows != up_rows) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_gate_up_contract_invalid");
        return FAILURE;
    }
    silu_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gate_id, "silu");
    if (silu_op == NULL) {
        silu_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gate_id, "gelu_tanh");
    }
    if (silu_op == NULL
        || king_inference_graph_required_string(silu_op, "id", &silu_id) != SUCCESS
        || (mul_op = king_inference_cuda_decoder_graph_executor_mul_op_for_inputs(graph, silu_id, up_id)) == NULL
        || king_inference_graph_required_string(mul_op, "id", &gated_id) != SUCCESS
        || (down_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, gated_id, "linear")) == NULL
        || king_inference_graph_required_string(down_op, "id", &down_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(model, down_op, gate_rows, &down_weight, &down_rows) != SUCCESS
        || down_rows != current_width) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_ffn_output_contract_invalid");
        return FAILURE;
    }
    output_right_id = down_id;
    post_ffw_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, down_id, "rms_norm");
    if (post_ffw_norm_op != NULL) {
        if (king_inference_graph_required_string(post_ffw_norm_op, "id", &post_ffw_norm_id) != SUCCESS
            || king_inference_graph_required_string(post_ffw_norm_op, "weight", &post_ffw_norm_weight) != SUCCESS
            || king_inference_graph_finite_double_option(post_ffw_norm_op, "epsilon", 0.000001, &post_ffw_norm_epsilon) != SUCCESS
            || post_ffw_norm_epsilon <= 0.0) {
            king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_post_ffw_norm_contract_invalid");
            return FAILURE;
        }
        output_right_id = post_ffw_norm_id;
    }
    output_op = king_inference_cuda_decoder_graph_executor_add_op_for_inputs(graph, residual_id, output_right_id);
    if (output_op == NULL
        || king_inference_graph_required_string(output_op, "id", &output_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_ffn_output_contract_invalid");
        return FAILURE;
    }

    stack_width = query_heads * value_length;
    current_elements = (size_t) prefill_token_count * (size_t) current_width;
    q_elements = (size_t) prefill_token_count * (size_t) query_rows;
    k_elements = (size_t) prefill_token_count * (size_t) key_rows;
    v_elements = (size_t) prefill_token_count * (size_t) value_rows;
    query_rope_elements = q_elements;
    score_elements = (size_t) prefill_token_count * (size_t) max_slots;
    context_elements = (size_t) prefill_token_count * (size_t) value_length;
    stack_elements = (size_t) prefill_token_count * (size_t) stack_width;
    projection_elements = current_elements;
    gate_elements = (size_t) prefill_token_count * (size_t) gate_rows;
    up_elements = (size_t) prefill_token_count * (size_t) up_rows;
    swiglu_elements = gate_elements;
    down_elements = current_elements;
    if (current_elements > SIZE_MAX / sizeof(float)
        || q_elements > SIZE_MAX / sizeof(float)
        || k_elements > SIZE_MAX / sizeof(float)
        || v_elements > SIZE_MAX / sizeof(float)
        || query_rope_elements > SIZE_MAX / sizeof(float)
        || score_elements > SIZE_MAX / sizeof(float)
        || context_elements > SIZE_MAX / sizeof(float)
        || stack_elements > SIZE_MAX / sizeof(float)
        || projection_elements > SIZE_MAX / sizeof(float)
        || gate_elements > SIZE_MAX / sizeof(float)
        || up_elements > SIZE_MAX / sizeof(float)
        || swiglu_elements > SIZE_MAX / sizeof(float)
        || down_elements > SIZE_MAX / sizeof(float)
        || current_elements > UINT_MAX
        || gate_elements > UINT_MAX) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_size_overflow");
        return FAILURE;
    }

    if (king_inference_cuda_device_allocator_alloc(model, current_elements * sizeof(float), &norm_matrix) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, q_elements * sizeof(float), &query_matrix) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, k_elements * sizeof(float), &key_matrix) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, v_elements * sizeof(float), &value_matrix) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, query_rope_elements * sizeof(float), &query_rope_matrix) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, score_elements * sizeof(float), &scores) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, score_elements * sizeof(float), &probabilities) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, context_elements * sizeof(float), &contexts) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, stack_elements * sizeof(float), &attention_stack) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, projection_elements * sizeof(float), &projection_matrix) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, projection_elements * sizeof(float), &attention_residual) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, projection_elements * sizeof(float), &ffn_norm) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, gate_elements * sizeof(float), &ffn_gate) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, up_elements * sizeof(float), &ffn_up) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, swiglu_elements * sizeof(float), &ffn_swiglu) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, down_elements * sizeof(float), &ffn_down) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, down_elements * sizeof(float), &ffn_output) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(
            model,
            &norm_matrix,
            &query_matrix,
            &key_matrix,
            &value_matrix,
            &query_rope_matrix,
            &scores,
            &probabilities,
            &contexts,
            &attention_stack,
            &projection_matrix,
            &attention_residual,
            &ffn_norm,
            &ffn_gate,
            &ffn_up,
            &ffn_swiglu,
            &ffn_down
        );
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output);
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_remaining_prefill_allocation_failed"
        );
        return FAILURE;
    }

    if (king_inference_cuda_rms_norm_f32_batch(model, norm_weight, current_matrix, norm_matrix, current_width, prefill_token_count, epsilon) != SUCCESS
        || king_inference_cuda_quantized_matvec_batch(model, query_weight, norm_matrix, current_width, prefill_token_count, query_matrix, query_rows) != SUCCESS
        || king_inference_cuda_quantized_matvec_batch(model, key_weight, norm_matrix, current_width, prefill_token_count, key_matrix, key_rows) != SUCCESS
        || king_inference_cuda_quantized_matvec_batch(model, value_weight, norm_matrix, current_width, prefill_token_count, value_matrix, value_rows) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(model, &norm_matrix, &query_matrix, &key_matrix, &value_matrix, &query_rope_matrix, &scores, &probabilities, &contexts, &attention_stack, &projection_matrix, &attention_residual, &ffn_norm, &ffn_gate, &ffn_up, &ffn_swiglu, &ffn_down);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output);
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_rms_norm_result != 0 ? model->cuda_rms_norm_result : model->cuda_quantized_matvec_result,
            model->cuda_rms_norm_error[0] != '\0'
                ? model->cuda_rms_norm_error
                : (model->cuda_quantized_matvec_error[0] != '\0'
                    ? model->cuda_quantized_matvec_error
                    : "cuda_decoder_prompt_remaining_prefill_qkv_failed")
        );
        return FAILURE;
    }

    for (query_head = 0; query_head < query_heads; query_head++) {
        king_inference_cuda_device_ptr query_output = query_rope_matrix
            + (king_inference_cuda_device_ptr) ((size_t) query_head * (size_t) prefill_token_count * (size_t) key_length * sizeof(float));

        if (king_inference_cuda_rope_f32_batch_slice(
                model,
                query_matrix,
                query_output,
                query_rows,
                query_head * key_length,
                key_length,
                prefill_token_count,
                0,
                rope_position_scale,
                rope_base,
                rope_pairing
        ) != SUCCESS) {
            king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(model, &norm_matrix, &query_matrix, &key_matrix, &value_matrix, &query_rope_matrix, &scores, &probabilities, &contexts, &attention_stack, &projection_matrix, &attention_residual, &ffn_norm, &ffn_gate, &ffn_up, &ffn_swiglu, &ffn_down);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output);
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                model->cuda_rope_result,
                model->cuda_rope_error[0] != '\0'
                    ? model->cuda_rope_error
                    : "cuda_decoder_prompt_remaining_prefill_query_rope_failed"
            );
            return FAILURE;
        }
    }

    for (kv_head = 0; kv_head < kv_heads; kv_head++) {
        if (king_inference_cuda_device_allocator_alloc(
                model,
                (size_t) prefill_token_count * (size_t) key_length * sizeof(float),
                &key_rope_matrix
        ) != SUCCESS
            || king_inference_cuda_rope_f32_batch_slice(
                model,
                key_matrix,
                key_rope_matrix,
                key_rows,
                kv_head * key_length,
                key_length,
                prefill_token_count,
                0,
                rope_position_scale,
                rope_base,
                rope_pairing
        ) != SUCCESS
            || king_inference_cuda_device_kv_cache_write_batch_strided(
                model,
                layer,
                kv_head,
                0,
                prefill_token_count,
                key_rope_matrix,
                key_length,
                0,
                key_length,
                value_matrix,
                value_rows,
                kv_head * value_length,
                value_length
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_matrix);
            king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(model, &norm_matrix, &query_matrix, &key_matrix, &value_matrix, &query_rope_matrix, &scores, &probabilities, &contexts, &attention_stack, &projection_matrix, &attention_residual, &ffn_norm, &ffn_gate, &ffn_up, &ffn_swiglu, &ffn_down);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output);
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                model->cuda_rope_result != 0 ? model->cuda_rope_result : model->cuda_device_kv_cache_result,
                model->cuda_rope_error[0] != '\0'
                    ? model->cuda_rope_error
                    : (model->cuda_device_kv_cache_error[0] != '\0'
                        ? model->cuda_device_kv_cache_error
                        : "cuda_decoder_prompt_remaining_prefill_kv_write_failed")
            );
            return FAILURE;
        }
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_matrix);
        model->cuda_decoder_prompt_prefill_kv_cache_writes++;
    }

    scale = 1.0 / sqrt((double) key_length);
    for (query_head = 0; query_head < query_heads; query_head++) {
        king_inference_cuda_device_ptr key_cache = 0;
        king_inference_cuda_device_ptr value_cache = 0;
        king_inference_cuda_device_ptr query_base = query_rope_matrix
            + (king_inference_cuda_device_ptr) ((size_t) query_head * (size_t) prefill_token_count * (size_t) key_length * sizeof(float));
        zend_ulong key_stride = 0;
        zend_ulong value_stride = 0;
        zend_ulong mapped_kv_head = king_inference_cuda_decoder_prompt_loop_prefill_kv_head_for_query(
            query_head,
            query_heads,
            kv_heads
        );

        if (king_inference_cuda_device_kv_cache_head(model, false, layer, mapped_kv_head, &key_cache, &key_stride) != SUCCESS
            || king_inference_cuda_device_kv_cache_head(model, true, layer, mapped_kv_head, &value_cache, &value_stride) != SUCCESS
            || king_inference_cuda_attention_scores_f32_batch(model, query_base, key_cache, scores, key_length, key_length, key_stride, 0, max_slots, prefill_token_count, sliding_window, scale) != SUCCESS
            || king_inference_cuda_attention_softmax_f32_batch(model, scores, probabilities, 0, 0, max_slots, prefill_token_count, sliding_window, 1.0, 1.0) != SUCCESS
            || king_inference_cuda_attention_values_f32_batch(model, probabilities, value_cache, contexts, value_length, value_stride, 0, max_slots, prefill_token_count, sliding_window) != SUCCESS
            || king_inference_cuda_decoder_prompt_loop_prefill_copy_context_rows_to_stack(
                model,
                contexts,
                attention_stack,
                prefill_token_count,
                value_length,
                stack_width,
                query_head
        ) != SUCCESS) {
            king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(model, &norm_matrix, &query_matrix, &key_matrix, &value_matrix, &query_rope_matrix, &scores, &probabilities, &contexts, &attention_stack, &projection_matrix, &attention_residual, &ffn_norm, &ffn_gate, &ffn_up, &ffn_swiglu, &ffn_down);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output);
            return FAILURE;
        }
    }

    if (king_inference_cuda_quantized_matvec_batch(model, projection_weight, attention_stack, stack_width, prefill_token_count, projection_matrix, projection_rows) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(model, &norm_matrix, &query_matrix, &key_matrix, &value_matrix, &query_rope_matrix, &scores, &probabilities, &contexts, &attention_stack, &projection_matrix, &attention_residual, &ffn_norm, &ffn_gate, &ffn_up, &ffn_swiglu, &ffn_down);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output);
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_projection_failed");
        return FAILURE;
    }
    projection_residual_matrix = projection_matrix;
    if (post_attention_norm_op != NULL) {
        if (king_inference_cuda_device_allocator_alloc(model, projection_elements * sizeof(float), &post_attention_norm_matrix) != SUCCESS
            || king_inference_cuda_rms_norm_f32_batch(
                model,
                post_attention_norm_weight,
                projection_matrix,
                post_attention_norm_matrix,
                current_width,
                prefill_token_count,
                post_attention_norm_epsilon
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
            king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(model, &norm_matrix, &query_matrix, &key_matrix, &value_matrix, &query_rope_matrix, &scores, &probabilities, &contexts, &attention_stack, &projection_matrix, &attention_residual, &ffn_norm, &ffn_gate, &ffn_up, &ffn_swiglu, &ffn_down);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output);
            king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_post_attention_norm_failed");
            return FAILURE;
        }
        projection_residual_matrix = post_attention_norm_matrix;
    }
    if (king_inference_cuda_vector_add(model, current_matrix, projection_residual_matrix, attention_residual, (zend_ulong) projection_elements) != SUCCESS
        || king_inference_cuda_rms_norm_f32_batch(model, ffn_norm_weight, attention_residual, ffn_norm, current_width, prefill_token_count, ffn_epsilon) != SUCCESS
        || king_inference_cuda_quantized_matvec_batch(model, gate_weight, ffn_norm, current_width, prefill_token_count, ffn_gate, gate_rows) != SUCCESS
        || king_inference_cuda_quantized_matvec_batch(model, up_weight, ffn_norm, current_width, prefill_token_count, ffn_up, up_rows) != SUCCESS
        || king_inference_cuda_ffn_swiglu_f32(model, ffn_gate, ffn_up, ffn_swiglu, (zend_ulong) swiglu_elements, ffn_activation) != SUCCESS
        || king_inference_cuda_quantized_matvec_batch(model, down_weight, ffn_swiglu, gate_rows, prefill_token_count, ffn_down, down_rows) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
        king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(model, &norm_matrix, &query_matrix, &key_matrix, &value_matrix, &query_rope_matrix, &scores, &probabilities, &contexts, &attention_stack, &projection_matrix, &attention_residual, &ffn_norm, &ffn_gate, &ffn_up, &ffn_swiglu, &ffn_down);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output);
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_tail_failed");
        return FAILURE;
    }
    ffn_output_right_matrix = ffn_down;
    if (post_ffw_norm_op != NULL) {
        if (king_inference_cuda_device_allocator_alloc(model, down_elements * sizeof(float), &post_ffw_norm_matrix) != SUCCESS
            || king_inference_cuda_rms_norm_f32_batch(
                model,
                post_ffw_norm_weight,
                ffn_down,
                post_ffw_norm_matrix,
                current_width,
                prefill_token_count,
                post_ffw_norm_epsilon
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_ffw_norm_matrix);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
            king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(model, &norm_matrix, &query_matrix, &key_matrix, &value_matrix, &query_rope_matrix, &scores, &probabilities, &contexts, &attention_stack, &projection_matrix, &attention_residual, &ffn_norm, &ffn_gate, &ffn_up, &ffn_swiglu, &ffn_down);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output);
            king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_post_ffw_norm_failed");
            return FAILURE;
        }
        ffn_output_right_matrix = post_ffw_norm_matrix;
    }
    if (king_inference_cuda_vector_add(model, attention_residual, ffn_output_right_matrix, ffn_output, (zend_ulong) down_elements) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_ffw_norm_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
        king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(model, &norm_matrix, &query_matrix, &key_matrix, &value_matrix, &query_rope_matrix, &scores, &probabilities, &contexts, &attention_stack, &projection_matrix, &attention_residual, &ffn_norm, &ffn_gate, &ffn_up, &ffn_swiglu, &ffn_down);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &ffn_output);
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_output_failed");
        return FAILURE;
    }

    *next_id_out = zend_string_copy(output_id);
    *next_matrix_out = ffn_output;
    *next_width_out = current_width;
    ffn_output = 0;
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_ffw_norm_matrix);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &post_attention_norm_matrix);
    king_inference_cuda_decoder_prompt_loop_prefill_release_layer_buffers(model, &norm_matrix, &query_matrix, &key_matrix, &value_matrix, &query_rope_matrix, &scores, &probabilities, &contexts, &attention_stack, &projection_matrix, &attention_residual, &ffn_norm, &ffn_gate, &ffn_up, &ffn_swiglu, &ffn_down);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_remaining_layers_batch(
    king_inference_model_object *model,
    zval *graph,
    zend_ulong prefill_token_count,
    zend_ulong width
) {
    zend_ulong layer;
    zend_ulong layers = model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT];
    zend_string *current_id;
    king_inference_cuda_device_ptr current_matrix = model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual;
    zend_ulong current_width = model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual_width;
    bool current_owned = false;

    if (layers <= 1) {
        return SUCCESS;
    }
    if (current_matrix == 0 || current_width == 0 || current_width != width) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_remaining_prefill_start_invalid");
        return FAILURE;
    }
    current_id = zend_string_init("l0_output", sizeof("l0_output") - 1, false);
    for (layer = 1; layer < layers; layer++) {
        zend_string *next_id = NULL;
        king_inference_cuda_device_ptr next_matrix = 0;
        zend_ulong next_width = 0;

        if (king_inference_cuda_decoder_prompt_loop_prefill_remaining_layer_batch(
                model,
                graph,
                layer,
                prefill_token_count,
                current_id,
                current_matrix,
                current_width,
                &next_id,
                &next_matrix,
                &next_width
        ) != SUCCESS) {
            if (current_owned) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &current_matrix);
            }
            zend_string_release(current_id);
            return FAILURE;
        }
        if (current_owned) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &current_matrix);
        }
        zend_string_release(current_id);
        current_id = next_id;
        current_matrix = next_matrix;
        current_width = next_width;
        current_owned = true;
    }

    if (current_owned) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &current_matrix);
    }
    zend_string_release(current_id);
    return SUCCESS;
}
