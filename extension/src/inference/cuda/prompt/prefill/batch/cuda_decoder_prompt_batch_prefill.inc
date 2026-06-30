/*
 * CUDA prompt batch-prefill helpers for native King GPU inference.
 */

static zend_result king_inference_cuda_decoder_prompt_loop_token_at(
    zval *tokenized_prompt,
    zend_ulong position,
    zend_ulong *token_id_out
) {
    zval *tokens;
    zval *token;

    *token_id_out = 0;
    if (tokenized_prompt == NULL || Z_TYPE_P(tokenized_prompt) != IS_ARRAY) {
        return FAILURE;
    }
    tokens = king_inference_array_find(tokenized_prompt, "tokens");
    if (tokens == NULL || Z_TYPE_P(tokens) != IS_ARRAY) {
        return FAILURE;
    }
    token = zend_hash_index_find(Z_ARRVAL_P(tokens), position);
    if (token == NULL || Z_TYPE_P(token) != IS_LONG || Z_LVAL_P(token) < 0) {
        return FAILURE;
    }

    *token_id_out = (zend_ulong) Z_LVAL_P(token);
    return SUCCESS;
}

static void king_inference_cuda_decoder_prompt_loop_release_prefill_embeddings(
    king_inference_model_object *model
) {
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_embeddings != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_embeddings
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_initial_norm != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_initial_norm
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_initial_linear != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_initial_linear
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_initial_query_rope != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_initial_query_rope
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_layer0_attention_stack != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_layer0_attention_stack
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_layer0_attention_residual != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_layer0_attention_residual
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_layer0_ffn_norm != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_norm
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_layer0_ffn_gate != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_gate
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_layer0_ffn_up != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_up
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_layer0_ffn_down != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_down
        );
    }
    if (model->cuda_decoder_prompt_prefill_embeddings_active
        && model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual != 0) {
        (void) king_inference_cuda_device_allocator_free(
            model,
            model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual
        );
    }
    model->cuda_decoder_prompt_prefill_embeddings = 0;
    model->cuda_decoder_prompt_prefill_initial_norm = 0;
    model->cuda_decoder_prompt_prefill_initial_linear = 0;
    model->cuda_decoder_prompt_prefill_initial_query_rope = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_stack = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_residual = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_norm = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_gate = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_up = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_down = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual = 0;
    model->cuda_decoder_prompt_prefill_embedding_bytes = 0;
    model->cuda_decoder_prompt_prefill_initial_norm_bytes = 0;
    model->cuda_decoder_prompt_prefill_initial_linear_bytes = 0;
    model->cuda_decoder_prompt_prefill_initial_query_rope_bytes = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_stack_bytes = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_residual_bytes = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_norm_bytes = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_gate_bytes = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_up_bytes = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu_bytes = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_down_bytes = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual_bytes = 0;
    model->cuda_decoder_prompt_prefill_kv_cache_writes = 0;
    model->cuda_decoder_prompt_prefill_tokens = 0;
    model->cuda_decoder_prompt_prefill_width = 0;
    model->cuda_decoder_prompt_prefill_initial_linear_rows = 0;
    model->cuda_decoder_prompt_prefill_initial_query_rope_heads = 0;
    model->cuda_decoder_prompt_prefill_initial_query_rope_head_dim = 0;
    model->cuda_decoder_prompt_prefill_kv_heads = 0;
    model->cuda_decoder_prompt_prefill_kv_key_length = 0;
    model->cuda_decoder_prompt_prefill_kv_value_length = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_heads = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_stack_width = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_residual_width = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_norm_width = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_gate_rows = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_up_rows = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_swiglu_width = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_down_width = 0;
    model->cuda_decoder_prompt_prefill_layer0_ffn_output_residual_width = 0;
    model->cuda_decoder_prompt_prefill_embeddings_active = false;
}

typedef struct _king_inference_cuda_decoder_prefill_slot_list {
    zval **items;
    size_t count;
    size_t capacity;
} king_inference_cuda_decoder_prefill_slot_list;

typedef struct _king_inference_cuda_decoder_prefill_mutations {
    zval *graph_token_id;
    zval *graph_position;
    zval *embedding_token_id;
    zval *embedding_prefill_batch_index;
    king_inference_cuda_decoder_prefill_slot_list rope_positions;
    king_inference_cuda_decoder_prefill_slot_list kv_write_slots;
    king_inference_cuda_decoder_prefill_slot_list kv_attention_slot_starts;
    king_inference_cuda_decoder_prefill_slot_list kv_attention_slot_counts;
    king_inference_cuda_decoder_prefill_slot_list sample_indexes;
} king_inference_cuda_decoder_prefill_mutations;

static void king_inference_cuda_decoder_prompt_loop_prefill_slot_list_release(
    king_inference_cuda_decoder_prefill_slot_list *slots
) {
    if (slots->items != NULL) {
        efree(slots->items);
    }
    slots->items = NULL;
    slots->count = 0;
    slots->capacity = 0;
}

static void king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(
    king_inference_cuda_decoder_prefill_mutations *mutations
) {
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_release(&mutations->rope_positions);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_release(&mutations->kv_write_slots);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_release(&mutations->kv_attention_slot_starts);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_release(&mutations->kv_attention_slot_counts);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_release(&mutations->sample_indexes);
    memset(mutations, 0, sizeof(*mutations));
}

static zval *king_inference_cuda_decoder_prompt_loop_prefill_long_slot(
    zval *array,
    const char *key,
    zend_long fallback
) {
    zval *slot;
    zval inserted;

    if (array == NULL || Z_TYPE_P(array) != IS_ARRAY || key == NULL) {
        return NULL;
    }
    slot = king_inference_array_find(array, key);
    if (slot == NULL) {
        ZVAL_LONG(&inserted, fallback);
        slot = zend_hash_str_update(Z_ARRVAL_P(array), key, strlen(key), &inserted);
    }
    return slot != NULL && Z_TYPE_P(slot) == IS_LONG ? slot : NULL;
}

static void king_inference_cuda_decoder_prompt_loop_prefill_slot_list_add(
    king_inference_cuda_decoder_prefill_slot_list *slots,
    zval *slot
) {
    size_t new_capacity;

    if (slot == NULL) {
        return;
    }
    if (slots->count == slots->capacity) {
        new_capacity = slots->capacity == 0 ? 8 : slots->capacity * 2;
        slots->items = slots->items == NULL
            ? emalloc(new_capacity * sizeof(*slots->items))
            : erealloc(slots->items, new_capacity * sizeof(*slots->items));
        slots->capacity = new_capacity;
    }
    slots->items[slots->count++] = slot;
}

static void king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(
    king_inference_cuda_decoder_prefill_slot_list *slots,
    zend_long value
) {
    size_t index;

    for (index = 0; index < slots->count; index++) {
        ZVAL_LONG(slots->items[index], value);
    }
}

static zend_result king_inference_cuda_decoder_prompt_loop_build_prefill_mutations(
    king_inference_model_object *model,
    zval *graph,
    king_inference_cuda_decoder_prefill_mutations *mutations
) {
    zval *ops;
    zval *entry;

    memset(mutations, 0, sizeof(*mutations));
    mutations->graph_token_id = king_inference_cuda_decoder_prompt_loop_prefill_long_slot(graph, "token_id", 0);
    mutations->graph_position = king_inference_cuda_decoder_prompt_loop_prefill_long_slot(graph, "position", 0);
    ops = king_inference_array_find(graph, "ops");
    if (ops == NULL) {
        ops = king_inference_array_find(graph, "steps");
    }
    if (mutations->graph_token_id == NULL
        || mutations->graph_position == NULL
        || ops == NULL
        || Z_TYPE_P(ops) != IS_ARRAY) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_mutation_plan_invalid");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        if (op_name == NULL || Z_TYPE_P(op_name) != IS_STRING) {
            continue;
        }
        if (zend_string_equals_literal(Z_STR_P(op_name), "embedding")) {
            mutations->embedding_prefill_batch_index =
                king_inference_cuda_decoder_prompt_loop_prefill_long_slot(entry, "prefill_batch_index", 0);
            mutations->embedding_token_id =
                king_inference_cuda_decoder_prompt_loop_prefill_long_slot(entry, "token_id", 0);
        } else if (zend_string_equals_literal(Z_STR_P(op_name), "rope")) {
            king_inference_cuda_decoder_prompt_loop_prefill_slot_list_add(
                &mutations->rope_positions,
                king_inference_cuda_decoder_prompt_loop_prefill_long_slot(entry, "position", 0)
            );
        } else if (zend_string_equals_literal(Z_STR_P(op_name), "kv_write")) {
            king_inference_cuda_decoder_prompt_loop_prefill_slot_list_add(
                &mutations->kv_write_slots,
                king_inference_cuda_decoder_prompt_loop_prefill_long_slot(entry, "slot", 0)
            );
        } else if (zend_string_equals_literal(Z_STR_P(op_name), "kv_attention")) {
            king_inference_cuda_decoder_prompt_loop_prefill_slot_list_add(
                &mutations->kv_attention_slot_starts,
                king_inference_cuda_decoder_prompt_loop_prefill_long_slot(entry, "slot_start", 0)
            );
            king_inference_cuda_decoder_prompt_loop_prefill_slot_list_add(
                &mutations->kv_attention_slot_counts,
                king_inference_cuda_decoder_prompt_loop_prefill_long_slot(entry, "slot_count", 1)
            );
        } else if (zend_string_equals_literal(Z_STR_P(op_name), "sample_token")) {
            king_inference_cuda_decoder_prompt_loop_prefill_slot_list_add(
                &mutations->sample_indexes,
                king_inference_cuda_decoder_prompt_loop_prefill_long_slot(entry, "sample_index", 0)
            );
        }
    } ZEND_HASH_FOREACH_END();

    if (mutations->embedding_token_id == NULL
        || mutations->embedding_prefill_batch_index == NULL
        || mutations->rope_positions.count == 0
        || mutations->kv_write_slots.count == 0
        || mutations->kv_attention_slot_starts.count == 0
        || mutations->kv_attention_slot_starts.count != mutations->kv_attention_slot_counts.count) {
        king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(mutations);
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_mutation_slots_missing");
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_apply_prefill_mutations(
    king_inference_model_object *model,
    king_inference_cuda_decoder_prefill_mutations *mutations,
    zval *tokenized_prompt,
    zend_ulong position
) {
    zend_ulong token_id;
    zend_ulong sliding_window = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_SLIDING_WINDOW];
    zend_ulong slot_start = sliding_window > 0 && position >= sliding_window ? position - sliding_window + 1 : 0;
    zend_ulong slot_count = position - slot_start + 1;

    if (king_inference_cuda_decoder_prompt_loop_token_at(tokenized_prompt, position, &token_id) != SUCCESS
        || token_id > (zend_ulong) ZEND_LONG_MAX
        || position > (zend_ulong) ZEND_LONG_MAX
        || slot_start > (zend_ulong) ZEND_LONG_MAX
        || slot_count > (zend_ulong) ZEND_LONG_MAX) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_mutation_value_invalid");
        return FAILURE;
    }

    ZVAL_LONG(mutations->graph_token_id, (zend_long) token_id);
    ZVAL_LONG(mutations->graph_position, (zend_long) position);
    ZVAL_LONG(mutations->embedding_token_id, (zend_long) token_id);
    ZVAL_LONG(mutations->embedding_prefill_batch_index, (zend_long) position);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(&mutations->rope_positions, (zend_long) position);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(&mutations->kv_write_slots, (zend_long) position);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(&mutations->kv_attention_slot_starts, (zend_long) slot_start);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(&mutations->kv_attention_slot_counts, (zend_long) slot_count);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(&mutations->sample_indexes, (zend_long) position);
    return SUCCESS;
}

#include "cuda_decoder_prompt_decode_template.inc"

static zend_result king_inference_cuda_decoder_prompt_loop_prepare_prefill_template(
    king_inference_model_object *model,
    zval *graph,
    zval *plan_result
) {
    array_init(plan_result);
    add_assoc_string(plan_result, "type", "gpu_decoder_prompt_batch_prefill_plan");
    add_assoc_string(plan_result, "backend", "king_native_gpu");
    add_assoc_bool(plan_result, "result_contract_ready", true);
    add_assoc_bool(plan_result, "graph_validated", false);
    add_assoc_bool(plan_result, "device_execution_result_ready", false);
    add_assoc_bool(plan_result, "token_result_ready", false);
    add_assoc_bool(plan_result, "decoded_token_ready", false);
    add_assoc_bool(plan_result, "execution_plan_ready", false);
    add_assoc_bool(plan_result, "silent_cpu_fallback", false);

    if (king_inference_cuda_decoder_graph_executor_validate_graph(model, graph) != SUCCESS) {
        zval_ptr_dtor(plan_result);
        ZVAL_UNDEF(plan_result);
        return FAILURE;
    }
    add_assoc_bool(plan_result, "graph_validated", true);
    if (king_inference_cuda_decoder_graph_executor_prepare_plan(model, graph, plan_result) != SUCCESS) {
        zval_ptr_dtor(plan_result);
        ZVAL_UNDEF(plan_result);
        return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_initial_batch(
    king_inference_model_object *model,
    zval *graph,
    zend_ulong prefill_token_count,
    zend_ulong width
) {
    zval *embedding_op;
    zval *rms_norm_op;
    zval *linear_op;
    zend_string *embedding_id = NULL;
    zend_string *rms_norm_id = NULL;
    zend_string *rms_weight = NULL;
    zend_string *linear_weight = NULL;
    zend_ulong linear_rows = 0;
    size_t linear_elements;
    size_t linear_bytes;
    double epsilon = 0.000001;

    embedding_op = king_inference_cuda_decoder_graph_executor_first_op(graph, "embedding");
    if (embedding_op == NULL
        || king_inference_graph_required_string(embedding_op, "id", &embedding_id) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_embedding_template_invalid");
        return FAILURE;
    }
    rms_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, embedding_id, "rms_norm");
    if (rms_norm_op == NULL
        || king_inference_graph_required_string(rms_norm_op, "id", &rms_norm_id) != SUCCESS
        || king_inference_graph_required_string(rms_norm_op, "weight", &rms_weight) != SUCCESS
        || king_inference_graph_finite_double_option(rms_norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
        || epsilon <= 0.0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_initial_norm_template_invalid");
        return FAILURE;
    }
    linear_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, rms_norm_id, "linear");
    if (linear_op == NULL
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            linear_op,
            width,
            &linear_weight,
            &linear_rows
    ) != SUCCESS
        || linear_rows == 0
        || linear_rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_initial_linear_template_invalid");
        return FAILURE;
    }
    if (prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) linear_rows)
        || (size_t) prefill_token_count * (size_t) linear_rows > SIZE_MAX / sizeof(float)) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_initial_linear_size_overflow");
        return FAILURE;
    }

    if (king_inference_cuda_device_allocator_alloc(
            model,
            model->cuda_decoder_prompt_prefill_embedding_bytes,
            &model->cuda_decoder_prompt_prefill_initial_norm
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_initial_norm_allocation_failed"
        );
        return FAILURE;
    }
    model->cuda_decoder_prompt_prefill_initial_norm_bytes =
        model->cuda_decoder_prompt_prefill_embedding_bytes;
    if (king_inference_cuda_rms_norm_f32_batch(
            model,
            rms_weight,
            model->cuda_decoder_prompt_prefill_embeddings,
            model->cuda_decoder_prompt_prefill_initial_norm,
            width,
            prefill_token_count,
            epsilon
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_rms_norm_result,
            model->cuda_rms_norm_error[0] != '\0'
                ? model->cuda_rms_norm_error
                : "cuda_decoder_prompt_batch_prefill_initial_norm_failed"
        );
        return FAILURE;
    }

    linear_elements = (size_t) prefill_token_count * (size_t) linear_rows;
    linear_bytes = linear_elements * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(
            model,
            linear_bytes,
            &model->cuda_decoder_prompt_prefill_initial_linear
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_initial_linear_allocation_failed"
        );
        return FAILURE;
    }
    model->cuda_decoder_prompt_prefill_initial_linear_bytes = linear_bytes;
    model->cuda_decoder_prompt_prefill_initial_linear_rows = linear_rows;
    if (king_inference_cuda_quantized_matvec_batch(
            model,
            linear_weight,
            model->cuda_decoder_prompt_prefill_initial_norm,
            width,
            prefill_token_count,
            model->cuda_decoder_prompt_prefill_initial_linear,
            linear_rows
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_quantized_matvec_result,
            model->cuda_quantized_matvec_error[0] != '\0'
                ? model->cuda_quantized_matvec_error
                : "cuda_decoder_prompt_batch_prefill_initial_linear_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_prompt_loop_prefill_layer0_query_rope_batch(
            model,
            graph,
            rms_norm_id,
            prefill_token_count,
            width
    ) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_decoder_prompt_loop_prefill_layer0_kv_batch(
            model,
            graph,
            rms_norm_id,
            prefill_token_count,
            width
    ) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_decoder_prompt_loop_prefill_layer0_attention_stack_batch(
            model,
            graph,
            width,
            prefill_token_count
    ) != SUCCESS) {
        return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_execute_prefill_position(
    king_inference_model_object *model,
    zval *graph,
    zend_ulong position
) {
    zval graph_result;

    array_init(&graph_result);
    add_assoc_string(&graph_result, "type", "gpu_decoder_prompt_batch_prefill_position");
    add_assoc_string(&graph_result, "backend", "king_native_gpu");
    add_assoc_bool(&graph_result, "result_contract_ready", true);
    add_assoc_bool(&graph_result, "graph_template_reused", true);
    add_assoc_long(&graph_result, "position", (zend_long) position);
    if (king_inference_cuda_decoder_graph_executor_execute_initial_device_ops(
            model,
            graph,
            &graph_result
    ) != SUCCESS) {
        zval_ptr_dtor(&graph_result);
        return FAILURE;
    }
    zval_ptr_dtor(&graph_result);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_batch_template(
    king_inference_model_object *model,
    zval *tokenized_prompt,
    zend_ulong token_count,
    zval *decode_options,
    size_t *validated_graphs,
    size_t *result_envelopes,
    size_t *prefill_batches,
    size_t *prefill_tokens,
    size_t *prefill_template_reuses
) {
    zval graph;
    zval plan_result;
    zend_ulong width = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong prefill_token_count;
    double embedding_scale = king_inference_decode_graph_embedding_scale(model);
    size_t prefill_elements;
    size_t prefill_bytes;

    *prefill_batches = 0;
    *prefill_tokens = 0;
    *prefill_template_reuses = 0;
    king_inference_cuda_decoder_prompt_loop_release_prefill_embeddings(model);
    if (token_count <= 1) {
        model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
        return SUCCESS;
    }
    if (!model->cuda_decoder_prompt_loop_batch_prefill_available) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_unavailable");
        model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
        return FAILURE;
    }
    prefill_token_count = token_count - 1;
    if (width == 0
        || width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) width)) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_shape_invalid");
        model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
        return FAILURE;
    }
    prefill_elements = (size_t) prefill_token_count * (size_t) width;
    if (prefill_elements > SIZE_MAX / sizeof(float)) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_size_overflow");
        model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
        return FAILURE;
    }
    prefill_bytes = prefill_elements * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(
            model,
            prefill_bytes,
            &model->cuda_decoder_prompt_prefill_embeddings
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_embedding_matrix_allocation_failed"
        );
        model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
        return FAILURE;
    }
    model->cuda_decoder_prompt_prefill_embeddings_active = true;
    model->cuda_decoder_prompt_prefill_embedding_bytes = prefill_bytes;
    model->cuda_decoder_prompt_prefill_tokens = prefill_token_count;
    model->cuda_decoder_prompt_prefill_width = width;
    if (king_inference_cuda_embedding_rows_load(
            model,
            tokenized_prompt,
            prefill_token_count,
            model->cuda_decoder_prompt_prefill_embeddings,
            width,
            embedding_scale
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_embedding_row_result,
            model->cuda_embedding_row_error[0] != '\0'
                ? model->cuda_embedding_row_error
                : "cuda_decoder_prompt_batch_prefill_embedding_rows_load_failed"
        );
        king_inference_cuda_decoder_prompt_loop_release_prefill_embeddings(model);
        model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
        return FAILURE;
    }

    ZVAL_UNDEF(&graph);
    ZVAL_UNDEF(&plan_result);
    add_assoc_bool(decode_options, "emit_token", false);
    if (king_inference_token_decode_graph_array(model, tokenized_prompt, 0, decode_options, &graph) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_template_build_failed");
        king_inference_cuda_decoder_prompt_loop_release_prefill_embeddings(model);
        model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
        return FAILURE;
    }
    if (king_inference_cuda_decoder_prompt_loop_prepare_prefill_template(
            model,
            &graph,
            &plan_result
    ) != SUCCESS) {
        zval_ptr_dtor(&graph);
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            model->cuda_decoder_graph_executor_error[0] != '\0'
                ? model->cuda_decoder_graph_executor_error
                : "cuda_decoder_prompt_batch_prefill_template_plan_failed"
        );
        king_inference_cuda_decoder_prompt_loop_release_prefill_embeddings(model);
        model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
        return FAILURE;
    }
    if (king_inference_cuda_decoder_prompt_loop_prefill_initial_batch(
            model,
            &graph,
            prefill_token_count,
            width
    ) != SUCCESS) {
        zval_ptr_dtor(&plan_result);
        zval_ptr_dtor(&graph);
        king_inference_cuda_decoder_prompt_loop_release_prefill_embeddings(model);
        model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
        return FAILURE;
    }
    if (king_inference_cuda_decoder_prompt_loop_prefill_remaining_layers_batch(
            model,
            &graph,
            prefill_token_count,
            width
    ) != SUCCESS) {
        zval_ptr_dtor(&plan_result);
        zval_ptr_dtor(&graph);
        king_inference_cuda_decoder_prompt_loop_release_prefill_embeddings(model);
        model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
        return FAILURE;
    }
    (*validated_graphs)++;
    (*result_envelopes)++;
    *prefill_tokens = prefill_token_count;
    zval_ptr_dtor(&plan_result);
    zval_ptr_dtor(&graph);
    king_inference_cuda_decoder_prompt_loop_release_prefill_embeddings(model);
    *prefill_batches = 1;
    model->cuda_decoder_prompt_loop_last_used_batch_prefill = true;
    return SUCCESS;
}
