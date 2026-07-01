/*
 * CUDA decoder graph helpers for reusing batch-prefilled K/V cache rows.
 */

static bool king_inference_cuda_decoder_graph_executor_prefilled_kv_batch_active(
    king_inference_model_object *model
) {
    return model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_tokens > 0
        && model->cuda_decoder_prompt_prefill_kv_heads > 0
        && model->cuda_decoder_prompt_prefill_kv_cache_writes > 0
        && model->cuda_device_kv_cache_available
        && model->cuda_device_kv_cache_key_length > 0
        && model->cuda_device_kv_cache_value_length > 0;
}

static zend_result king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_attention_stack(
    king_inference_model_object *model,
    zval *graph,
    zend_ulong position,
    zval *slice_probe,
    zval *rope_probe,
    zval *kv_probe,
    king_inference_cuda_device_ptr *attention_stack_output,
    zend_ulong *attention_stack_width,
    zend_string **attention_stack_id,
    zend_ulong *executed_device_ops,
    bool *used_prefilled_attention_stack
) {
    zval *stack_op;
    zend_string *stack_id = NULL;
    size_t source_offset;

    *used_prefilled_attention_stack = false;
    if (model->cuda_decoder_prompt_prefill_layer0_attention_stack == 0
        || model->cuda_decoder_prompt_prefill_layer0_attention_stack_width == 0
        || model->cuda_decoder_prompt_prefill_layer0_attention_heads == 0
        || position >= model->cuda_decoder_prompt_prefill_tokens) {
        return SUCCESS;
    }
    if (*attention_stack_output != 0 || *attention_stack_width != 0 || *attention_stack_id != NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_attention_stack_target_dirty"
        );
        return FAILURE;
    }

    stack_op = king_inference_cuda_decoder_graph_executor_first_op(graph, "stack");
    if (stack_op == NULL || king_inference_graph_required_string(stack_op, "id", &stack_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_attention_stack_id_invalid"
        );
        return FAILURE;
    }
    if (model->cuda_decoder_prompt_prefill_layer0_attention_stack_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || position > (zend_ulong) (SIZE_MAX / (size_t) model->cuda_decoder_prompt_prefill_layer0_attention_stack_width)) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_attention_stack_size_invalid"
        );
        return FAILURE;
    }

    *attention_stack_width = model->cuda_decoder_prompt_prefill_layer0_attention_stack_width;
    if (king_inference_cuda_device_allocator_alloc(
            model,
            (size_t) *attention_stack_width * sizeof(float),
            attention_stack_output
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_prefilled_attention_stack_allocation_failed"
        );
        return FAILURE;
    }

    source_offset = (size_t) position * (size_t) *attention_stack_width;
    if (king_inference_cuda_vector_copy_to_offset(
            model,
            model->cuda_decoder_prompt_prefill_layer0_attention_stack
                + (king_inference_cuda_device_ptr) (source_offset * sizeof(float)),
            *attention_stack_output,
            0,
            *attention_stack_width
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, attention_stack_output);
        *attention_stack_width = 0;
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_prefilled_attention_stack_copy_failed"
        );
        return FAILURE;
    }

    *attention_stack_id = stack_id;
    (*executed_device_ops)++;
    model->cuda_decoder_graph_executor_attention_heads_execution_count++;
    model->cuda_decoder_graph_executor_attention_stack_execution_count++;
    model->cuda_decoder_graph_executor_last_attention_stack_expected_heads =
        model->cuda_decoder_prompt_prefill_layer0_attention_heads;
    model->cuda_decoder_graph_executor_last_attention_stack_prepared_heads =
        model->cuda_decoder_prompt_prefill_layer0_attention_heads;
    model->cuda_decoder_graph_executor_last_attention_stack_width = *attention_stack_width;

    add_assoc_bool(slice_probe, "attempted", true);
    add_assoc_bool(slice_probe, "executed", false);
    add_assoc_bool(slice_probe, "used_prefill_attention_stack", true);
    add_assoc_long(
        slice_probe,
        "query_head_count",
        (zend_long) model->cuda_decoder_prompt_prefill_layer0_attention_heads
    );
    add_assoc_bool(rope_probe, "attempted", true);
    add_assoc_bool(rope_probe, "executed", false);
    add_assoc_bool(rope_probe, "used_prefill_attention_stack", true);
    add_assoc_bool(kv_probe, "attempted", true);
    add_assoc_bool(kv_probe, "available", true);
    add_assoc_bool(kv_probe, "executed", true);
    add_assoc_bool(kv_probe, "retained_device_output", true);
    add_assoc_bool(kv_probe, "used_prefill_attention_stack", true);
    add_assoc_bool(kv_probe, "complete_attention_stack", true);
    add_assoc_bool(kv_probe, "attention_stack_released", false);
    add_assoc_long(kv_probe, "attention_stack_width", (zend_long) *attention_stack_width);
    add_assoc_long(
        kv_probe,
        "expected_heads",
        (zend_long) model->cuda_decoder_prompt_prefill_layer0_attention_heads
    );
    add_assoc_long(
        kv_probe,
        "prepared_heads",
        (zend_long) model->cuda_decoder_prompt_prefill_layer0_attention_heads
    );

    *used_prefilled_attention_stack = true;
    return SUCCESS;
}

static zval *king_inference_cuda_decoder_graph_executor_prefilled_add_op_for_inputs(
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
            && zend_string_equals_literal(Z_STR_P(op_name), "add")
            && ((king_inference_cuda_decoder_graph_executor_zval_string_equals(left, left_id)
                    && king_inference_cuda_decoder_graph_executor_zval_string_equals(right, right_id))
                || (king_inference_cuda_decoder_graph_executor_zval_string_equals(left, right_id)
                    && king_inference_cuda_decoder_graph_executor_zval_string_equals(right, left_id)))) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_attention_residual(
    king_inference_model_object *model,
    zval *graph,
    zend_string *hidden_id,
    zend_ulong hidden_width,
    zend_ulong position,
    zval *slice_probe,
    zval *rope_probe,
    zval *kv_probe,
    zval *projection_probe,
    zval *residual_probe,
    king_inference_cuda_device_ptr *residual_output,
    zend_ulong *residual_width,
    zend_string **residual_id_out,
    zend_ulong *executed_device_ops,
    bool *used_prefilled_attention_residual
) {
    zval *stack_op;
    zval *projection_op;
    zval *residual_op;
    zend_string *stack_id = NULL;
    zend_string *projection_id = NULL;
    zend_string *residual_id = NULL;
    size_t source_offset;

    *used_prefilled_attention_residual = false;
    if (model->cuda_decoder_prompt_prefill_layer0_attention_residual == 0
        || model->cuda_decoder_prompt_prefill_layer0_attention_residual_width == 0
        || position >= model->cuda_decoder_prompt_prefill_tokens) {
        return SUCCESS;
    }
    if (*residual_output != 0 || *residual_width != 0 || *residual_id_out != NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_attention_residual_target_dirty"
        );
        return FAILURE;
    }
    if (hidden_id == NULL
        || hidden_width == 0
        || hidden_width != model->cuda_decoder_prompt_prefill_layer0_attention_residual_width
        || hidden_width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_attention_residual_shape_invalid"
        );
        return FAILURE;
    }

    stack_op = king_inference_cuda_decoder_graph_executor_first_op(graph, "stack");
    if (stack_op == NULL || king_inference_graph_required_string(stack_op, "id", &stack_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_attention_residual_stack_id_invalid"
        );
        return FAILURE;
    }
    projection_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, stack_id, "linear");
    if (projection_op == NULL || king_inference_graph_required_string(projection_op, "id", &projection_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_attention_residual_projection_id_invalid"
        );
        return FAILURE;
    }
    residual_op = king_inference_cuda_decoder_graph_executor_prefilled_add_op_for_inputs(graph, hidden_id, projection_id);
    if (residual_op == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_required_string(residual_op, "id", &residual_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_attention_residual_id_invalid"
        );
        return FAILURE;
    }
    if (position > (zend_ulong) (SIZE_MAX / (size_t) hidden_width)) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_attention_residual_offset_invalid"
        );
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
                : "cuda_decoder_graph_prefilled_attention_residual_allocation_failed"
        );
        return FAILURE;
    }

    source_offset = (size_t) position * (size_t) hidden_width;
    if (king_inference_cuda_vector_copy_to_offset(
            model,
            model->cuda_decoder_prompt_prefill_layer0_attention_residual
                + (king_inference_cuda_device_ptr) (source_offset * sizeof(float)),
            *residual_output,
            0,
            hidden_width
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, residual_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_prefilled_attention_residual_copy_failed"
        );
        return FAILURE;
    }

    *residual_width = hidden_width;
    *residual_id_out = residual_id;
    (*executed_device_ops)++;
    model->cuda_decoder_graph_executor_attention_residual_execution_count++;
    model->cuda_decoder_graph_executor_last_attention_residual_width = hidden_width;

    add_assoc_bool(slice_probe, "attempted", true);
    add_assoc_bool(slice_probe, "executed", false);
    add_assoc_bool(slice_probe, "used_prefill_attention_residual", true);
    add_assoc_bool(rope_probe, "attempted", true);
    add_assoc_bool(rope_probe, "executed", false);
    add_assoc_bool(rope_probe, "used_prefill_attention_residual", true);
    add_assoc_bool(kv_probe, "attempted", true);
    add_assoc_bool(kv_probe, "available", true);
    add_assoc_bool(kv_probe, "executed", true);
    add_assoc_bool(kv_probe, "retained_device_output", false);
    add_assoc_bool(kv_probe, "used_prefill_attention_residual", true);
    add_assoc_bool(kv_probe, "complete_attention_stack", true);
    add_assoc_long(kv_probe, "attention_stack_width", (zend_long) model->cuda_decoder_prompt_prefill_layer0_attention_stack_width);

    add_assoc_string(projection_probe, "type", "gpu_decoder_attention_output_projection_device_execution");
    add_assoc_bool(projection_probe, "attempted", true);
    add_assoc_bool(projection_probe, "available", model->cuda_decoder_graph_executor_attention_output_projection_execution_available);
    add_assoc_bool(projection_probe, "executed", false);
    add_assoc_bool(projection_probe, "precomputed", true);
    add_assoc_bool(projection_probe, "used_prefill_attention_residual", true);
    add_assoc_bool(projection_probe, "retained_device_output", false);
    add_assoc_bool(projection_probe, "projection_output_released", true);
    add_assoc_str(projection_probe, "input", zend_string_copy(stack_id));
    add_assoc_str(projection_probe, "projection", zend_string_copy(projection_id));
    add_assoc_long(projection_probe, "projection_width", (zend_long) hidden_width);

    add_assoc_string(residual_probe, "type", "gpu_decoder_attention_residual_device_execution");
    add_assoc_bool(residual_probe, "attempted", true);
    add_assoc_bool(residual_probe, "available", model->cuda_decoder_graph_executor_attention_residual_execution_available);
    add_assoc_bool(residual_probe, "executed", true);
    add_assoc_bool(residual_probe, "precomputed", true);
    add_assoc_bool(residual_probe, "used_prefill_attention_residual", true);
    add_assoc_bool(residual_probe, "retained_device_output", true);
    add_assoc_str(residual_probe, "residual", zend_string_copy(residual_id));
    add_assoc_long(residual_probe, "width", (zend_long) hidden_width);
    add_assoc_long(residual_probe, "bytes", (zend_long) ((size_t) hidden_width * sizeof(float)));

    *used_prefilled_attention_residual = true;
    return SUCCESS;
}

static bool king_inference_cuda_decoder_graph_executor_prefilled_query_rope_vector(
    king_inference_model_object *model,
    zend_string *query_slice_id,
    zend_ulong position,
    zend_ulong query_slice_length,
    king_inference_cuda_device_ptr *query_rope_output,
    zend_ulong *query_head
) {
    unsigned long parsed_layer = 0;
    unsigned long parsed_head = 0;
    int consumed = 0;
    size_t offset_elements;

    *query_rope_output = 0;
    *query_head = 0;
    if (query_slice_id == NULL
        || model->cuda_decoder_prompt_prefill_initial_query_rope == 0
        || model->cuda_decoder_prompt_prefill_initial_query_rope_heads == 0
        || model->cuda_decoder_prompt_prefill_initial_query_rope_head_dim == 0
        || query_slice_length != model->cuda_decoder_prompt_prefill_initial_query_rope_head_dim
        || position >= model->cuda_decoder_prompt_prefill_tokens
        || ZSTR_LEN(query_slice_id) > INT_MAX
        || sscanf(ZSTR_VAL(query_slice_id), "l%lu_h%lu_q_slice%n", &parsed_layer, &parsed_head, &consumed) != 2
        || consumed != (int) ZSTR_LEN(query_slice_id)
        || parsed_layer != 0
        || (zend_ulong) parsed_head >= model->cuda_decoder_prompt_prefill_initial_query_rope_heads) {
        return false;
    }
    if ((size_t) parsed_head > (SIZE_MAX / (size_t) model->cuda_decoder_prompt_prefill_tokens)
        || ((size_t) parsed_head * (size_t) model->cuda_decoder_prompt_prefill_tokens) > SIZE_MAX - (size_t) position) {
        return false;
    }
    offset_elements = ((size_t) parsed_head * (size_t) model->cuda_decoder_prompt_prefill_tokens) + (size_t) position;
    if (offset_elements > SIZE_MAX / (size_t) model->cuda_decoder_prompt_prefill_initial_query_rope_head_dim
        || offset_elements * (size_t) model->cuda_decoder_prompt_prefill_initial_query_rope_head_dim > SIZE_MAX / sizeof(float)) {
        return false;
    }

    offset_elements *= (size_t) model->cuda_decoder_prompt_prefill_initial_query_rope_head_dim;
    *query_rope_output = model->cuda_decoder_prompt_prefill_initial_query_rope
        + (king_inference_cuda_device_ptr) (offset_elements * sizeof(float));
    *query_head = (zend_ulong) parsed_head;
    return true;
}

static zend_result king_inference_cuda_decoder_graph_executor_try_prefilled_kv_head_prepare(
    king_inference_model_object *model,
    zval *graph,
    zend_string *key_linear_id,
    zend_string *value_linear_id,
    zend_string *query_slice_id,
    king_inference_cuda_device_ptr query_slice_output,
    zend_ulong query_slice_length,
    zval *query_rope_probe,
    zval *kv_probe,
    king_inference_cuda_device_ptr *attention_stack_output,
    zend_ulong *attention_stack_width,
    zend_string **attention_stack_id,
    zend_ulong *executed_device_ops,
    bool *kv_prepared,
    bool *used_prefilled_kv
) {
    zval *key_slice_op = NULL;
    zval *key_rope_op = NULL;
    zval *value_slice_op = NULL;
    zval *kv_write_op = NULL;
    zval *query_rope_op = NULL;
    zval *kv_attention_op = NULL;
    zval key_rope_probe;
    zval kv_write_probe;
    zval kv_attention_probe;
    zval attention_stack_probe;
    zend_string *key_slice_id = NULL;
    zend_string *key_rope_id = NULL;
    zend_string *value_slice_id = NULL;
    zend_string *query_rope_id = NULL;
    zend_string *kv_attention_id = NULL;
    king_inference_cuda_device_ptr query_rope_output = 0;
    king_inference_cuda_device_ptr attention_context_output = 0;
    zend_ulong layer = 0;
    zend_ulong head = 0;
    zend_ulong position = 0;
    zend_ulong query_rope_length = 0;
    zend_ulong attention_context_length = 0;
    zend_ulong query_head = 0;
    bool query_rope_executed = false;
    bool used_prefilled_query_rope = false;

    *used_prefilled_kv = false;
    if (!king_inference_cuda_decoder_graph_executor_prefilled_kv_batch_active(model)) {
        return SUCCESS;
    }

    key_slice_op = king_inference_cuda_decoder_graph_executor_kv_slice_op_for_query(
        graph,
        key_linear_id,
        query_slice_id,
        "k"
    );
    if (key_slice_op == NULL || king_inference_graph_required_string(key_slice_op, "id", &key_slice_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_kv_key_slice_contract_invalid"
        );
        return FAILURE;
    }
    key_rope_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, key_slice_id, "rope");
    if (key_rope_op == NULL || king_inference_graph_required_string(key_rope_op, "id", &key_rope_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_kv_key_rope_contract_invalid"
        );
        return FAILURE;
    }
    value_slice_op = king_inference_cuda_decoder_graph_executor_kv_slice_op_for_query(
        graph,
        value_linear_id,
        query_slice_id,
        "v"
    );
    if (value_slice_op == NULL || king_inference_graph_required_string(value_slice_op, "id", &value_slice_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_kv_value_slice_contract_invalid"
        );
        return FAILURE;
    }

    kv_write_op = king_inference_cuda_decoder_graph_executor_kv_write_op_for_vectors(
        graph,
        key_rope_id,
        value_slice_id
    );
    if (kv_write_op == NULL
        || king_inference_cuda_decoder_graph_executor_kv_write_shape(
            model,
            kv_write_op,
            model->cuda_device_kv_cache_key_length,
            model->cuda_device_kv_cache_value_length,
            &layer,
            &head,
            &position
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_kv_write_contract_invalid"
        );
        return FAILURE;
    }
    if (layer != 0
        || head >= model->cuda_decoder_prompt_prefill_kv_heads
        || position >= model->cuda_decoder_prompt_prefill_tokens) {
        return SUCCESS;
    }

    query_rope_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, query_slice_id, "rope");
    if (query_slice_id == NULL
        || query_rope_op == NULL
        || king_inference_graph_required_string(query_rope_op, "id", &query_rope_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_kv_query_rope_contract_invalid"
        );
        return FAILURE;
    }

    used_prefilled_query_rope = king_inference_cuda_decoder_graph_executor_prefilled_query_rope_vector(
        model,
        query_slice_id,
        position,
        query_slice_length,
        &query_rope_output,
        &query_head
    );
    if (!used_prefilled_query_rope && query_slice_output == 0) {
        return SUCCESS;
    }

    array_init(&key_rope_probe);
    add_assoc_string(&key_rope_probe, "type", "gpu_decoder_prefilled_key_rope_device_execution");
    add_assoc_bool(&key_rope_probe, "attempted", true);
    add_assoc_bool(&key_rope_probe, "available", true);
    add_assoc_bool(&key_rope_probe, "executed", true);
    add_assoc_bool(&key_rope_probe, "retained_device_output", false);
    add_assoc_bool(&key_rope_probe, "used_prefill_kv_cache", true);
    add_assoc_long(&key_rope_probe, "length", (zend_long) model->cuda_device_kv_cache_key_length);
    add_assoc_long(&key_rope_probe, "position", (zend_long) position);

    array_init(&kv_write_probe);
    add_assoc_string(&kv_write_probe, "type", "gpu_decoder_prefilled_kv_write_device_execution");
    add_assoc_bool(&kv_write_probe, "attempted", true);
    add_assoc_bool(&kv_write_probe, "available", true);
    add_assoc_bool(&kv_write_probe, "executed", true);
    add_assoc_bool(&kv_write_probe, "used_prefill_kv_cache", true);
    add_assoc_long(&kv_write_probe, "layer", (zend_long) layer);
    add_assoc_long(&kv_write_probe, "head", (zend_long) head);
    add_assoc_long(&kv_write_probe, "position", (zend_long) position);
    add_assoc_long(&kv_write_probe, "key_length", (zend_long) model->cuda_device_kv_cache_key_length);
    add_assoc_long(&kv_write_probe, "value_length", (zend_long) model->cuda_device_kv_cache_value_length);

    if (used_prefilled_query_rope) {
        query_rope_length = query_slice_length;
        query_rope_executed = true;
        add_assoc_bool(query_rope_probe, "attempted", true);
        add_assoc_bool(query_rope_probe, "available", true);
        add_assoc_bool(query_rope_probe, "executed", true);
        add_assoc_bool(query_rope_probe, "retained_device_output", false);
        add_assoc_bool(query_rope_probe, "used_prefill_query_rope", true);
        add_assoc_long(query_rope_probe, "head", (zend_long) query_head);
        add_assoc_long(query_rope_probe, "position", (zend_long) position);
        add_assoc_long(query_rope_probe, "length", (zend_long) query_rope_length);
    } else if (king_inference_cuda_decoder_graph_executor_execute_rope_after_slice_to_device(
            model,
            graph,
            query_slice_id,
            query_slice_output,
            query_slice_length,
            query_rope_probe,
            executed_device_ops,
            &query_rope_output,
            &query_rope_length,
            NULL,
            &query_rope_executed
    ) != SUCCESS) {
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_rope_output);
        return FAILURE;
    }
    if (!query_rope_executed) {
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_rope_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_kv_query_rope_missing"
        );
        return FAILURE;
    }

    kv_attention_op = king_inference_cuda_decoder_graph_executor_kv_attention_op_for_query(graph, query_rope_id);
    array_init(&kv_attention_probe);
    add_assoc_string(&kv_attention_probe, "type", "gpu_decoder_prefilled_kv_attention_device_execution");
    if (king_inference_cuda_decoder_graph_executor_execute_kv_attention(
            model,
            kv_attention_op,
            query_rope_output,
            query_rope_length,
            &kv_attention_probe,
            &attention_context_output,
            &attention_context_length,
            executed_device_ops
    ) != SUCCESS) {
        zval_ptr_dtor(&kv_attention_probe);
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        if (!used_prefilled_query_rope) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_rope_output);
        }
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_context_output);
        return FAILURE;
    }
    if (kv_attention_op == NULL
        || king_inference_graph_required_string(kv_attention_op, "id", &kv_attention_id) != SUCCESS) {
        zval_ptr_dtor(&kv_attention_probe);
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        if (!used_prefilled_query_rope) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_rope_output);
        }
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_context_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_kv_attention_id_invalid"
        );
        return FAILURE;
    }
    if (!used_prefilled_query_rope
        && king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_rope_output) != SUCCESS) {
        zval_ptr_dtor(&kv_attention_probe);
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_context_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_prefilled_kv_query_rope_free_failed"
        );
        return FAILURE;
    }
    add_assoc_bool(query_rope_probe, "retained_device_output", false);

    array_init(&attention_stack_probe);
    add_assoc_string(&attention_stack_probe, "type", "gpu_decoder_prefilled_kv_attention_stack_slot_device_execution");
    if (king_inference_cuda_decoder_graph_executor_prepare_attention_stack_slot(
            model,
            graph,
            kv_attention_id,
            attention_context_output,
            attention_context_length,
            &attention_stack_probe,
            attention_stack_output,
            attention_stack_width,
            attention_stack_id,
            executed_device_ops
    ) != SUCCESS) {
        zval_ptr_dtor(&attention_stack_probe);
        zval_ptr_dtor(&kv_attention_probe);
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_context_output);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_context_output) != SUCCESS) {
        zval_ptr_dtor(&attention_stack_probe);
        zval_ptr_dtor(&kv_attention_probe);
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_prefilled_kv_attention_context_free_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_kv_head_prepare_execution_count++;
    model->cuda_decoder_graph_executor_last_kv_head_key_length = model->cuda_device_kv_cache_key_length;
    model->cuda_decoder_graph_executor_last_kv_head_value_length = model->cuda_device_kv_cache_value_length;
    add_assoc_bool(&kv_attention_probe, "retained_device_output", false);
    add_assoc_bool(kv_probe, "executed", true);
    add_assoc_bool(kv_probe, "used_prefill_kv_cache", true);
    add_assoc_bool(kv_probe, "used_prefill_query_rope", used_prefilled_query_rope);
    add_assoc_bool(kv_probe, "query_rope_executed", query_rope_executed);
    add_assoc_long(kv_probe, "key_slice_length", (zend_long) model->cuda_device_kv_cache_key_length);
    add_assoc_long(kv_probe, "key_rope_length", (zend_long) model->cuda_device_kv_cache_key_length);
    add_assoc_long(kv_probe, "value_slice_length", (zend_long) model->cuda_device_kv_cache_value_length);
    add_assoc_long(kv_probe, "query_rope_length", (zend_long) query_rope_length);
    add_assoc_long(kv_probe, "attention_context_length", (zend_long) attention_context_length);
    add_assoc_long(kv_probe, "attention_stack_width", (zend_long) *attention_stack_width);
    add_assoc_bool(kv_probe, "attention_context_released", true);
    add_assoc_bool(kv_probe, "attention_stack_released", false);
    add_assoc_zval(kv_probe, "key_rope_execution", &key_rope_probe);
    add_assoc_zval(kv_probe, "kv_write_execution", &kv_write_probe);
    add_assoc_zval(kv_probe, "kv_attention_execution", &kv_attention_probe);
    add_assoc_zval(kv_probe, "attention_stack_slot_execution", &attention_stack_probe);
    *kv_prepared = true;
    *used_prefilled_kv = true;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_try_prefilled_kv_head_prepare_for_norm(
    king_inference_model_object *model,
    zval *graph,
    zend_string *rms_norm_id,
    zend_string *query_slice_id,
    zend_ulong query_slice_length,
    zval *query_rope_probe,
    zval *kv_probe,
    king_inference_cuda_device_ptr *attention_stack_output,
    zend_ulong *attention_stack_width,
    zend_string **attention_stack_id,
    zend_ulong *executed_device_ops,
    bool *kv_prepared,
    bool *used_prefilled_kv
) {
    zval *key_linear_op;
    zval *value_linear_op;
    zend_string *key_linear_id = NULL;
    zend_string *value_linear_id = NULL;

    *used_prefilled_kv = false;
    if (!king_inference_cuda_decoder_graph_executor_prefilled_kv_batch_active(model)) {
        return SUCCESS;
    }
    key_linear_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, rms_norm_id, "linear", 1);
    value_linear_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, rms_norm_id, "linear", 2);
    if (key_linear_op == NULL
        || value_linear_op == NULL
        || king_inference_graph_required_string(key_linear_op, "id", &key_linear_id) != SUCCESS
        || king_inference_graph_required_string(value_linear_op, "id", &value_linear_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_prefilled_kv_linear_contract_invalid"
        );
        return FAILURE;
    }

    return king_inference_cuda_decoder_graph_executor_try_prefilled_kv_head_prepare(
        model,
        graph,
        key_linear_id,
        value_linear_id,
        query_slice_id,
        0,
        query_slice_length,
        query_rope_probe,
        kv_probe,
        attention_stack_output,
        attention_stack_width,
        attention_stack_id,
        executed_device_ops,
        kv_prepared,
        used_prefilled_kv
    );
}
