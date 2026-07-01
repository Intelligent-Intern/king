/*
 * CUDA prompt decode template reuse for native King GPU inference.
 */

static zend_result king_inference_cuda_decoder_prompt_loop_build_decode_mutations(
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
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_decode_template_mutation_plan_invalid");
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
        || mutations->rope_positions.count == 0
        || mutations->kv_write_slots.count == 0
        || mutations->kv_attention_slot_starts.count == 0
        || mutations->kv_attention_slot_starts.count != mutations->kv_attention_slot_counts.count) {
        king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(mutations);
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_decode_template_mutation_slots_missing");
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_apply_decode_mutations(
    king_inference_model_object *model,
    king_inference_cuda_decoder_prefill_mutations *mutations,
    zend_ulong token_id,
    zend_ulong position
) {
    zend_ulong sliding_window = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_SLIDING_WINDOW];
    zend_ulong slot_start;
    zend_ulong slot_count;

    king_inference_decode_graph_attention_window(
        model,
        position,
        sliding_window,
        &slot_start,
        &slot_count
    );

    if (token_id > (zend_ulong) ZEND_LONG_MAX
        || position > (zend_ulong) ZEND_LONG_MAX
        || slot_start > (zend_ulong) ZEND_LONG_MAX
        || slot_count > (zend_ulong) ZEND_LONG_MAX) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_decode_template_mutation_value_invalid");
        return FAILURE;
    }

    ZVAL_LONG(mutations->graph_token_id, (zend_long) token_id);
    ZVAL_LONG(mutations->graph_position, (zend_long) position);
    ZVAL_LONG(mutations->embedding_token_id, (zend_long) token_id);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(&mutations->rope_positions, (zend_long) position);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(&mutations->kv_write_slots, (zend_long) position);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(&mutations->kv_attention_slot_starts, (zend_long) slot_start);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(&mutations->kv_attention_slot_counts, (zend_long) slot_count);
    king_inference_cuda_decoder_prompt_loop_prefill_slot_list_set(&mutations->sample_indexes, (zend_long) position);
    return SUCCESS;
}

static void king_inference_cuda_decoder_prompt_loop_disable_candidate_materialization(
    zval *graph
) {
    zval *ops = king_inference_array_find(graph, "ops");
    zval *entry;

    if (ops == NULL) {
        ops = king_inference_array_find(graph, "steps");
    }
    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY) {
        return;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && (zend_string_equals_literal(Z_STR_P(op_name), "argmax_token")
                || zend_string_equals_literal(Z_STR_P(op_name), "sample_token"))) {
            add_assoc_bool(entry, "materialize_candidates", false);
        }
    } ZEND_HASH_FOREACH_END();
}

static zend_result king_inference_cuda_decoder_prompt_loop_prepare_decode_template(
    king_inference_model_object *model,
    zval *graph,
    zval *plan_result
) {
    array_init(plan_result);
    add_assoc_string(plan_result, "type", "gpu_decoder_prompt_decode_template_plan");
    add_assoc_string(plan_result, "backend", "king_native_gpu");
    add_assoc_bool(plan_result, "result_contract_ready", true);
    add_assoc_bool(plan_result, "graph_validated", false);
    add_assoc_bool(plan_result, "execution_plan_ready", false);
    add_assoc_bool(plan_result, "device_execution_result_ready", false);
    add_assoc_bool(plan_result, "graph_template_resident", true);
    add_assoc_bool(plan_result, "silent_cpu_fallback", false);

    king_inference_cuda_decoder_prompt_loop_disable_candidate_materialization(graph);
    if (king_inference_cuda_decoder_graph_executor_validate_graph(model, graph) != SUCCESS) {
        zval_ptr_dtor(plan_result);
        ZVAL_UNDEF(plan_result);
        return FAILURE;
    }
    add_assoc_bool(plan_result, "graph_validated", true);
    if (king_inference_cuda_decoder_graph_executor_prepare_op_index(model, graph) != SUCCESS) {
        zval_ptr_dtor(plan_result);
        ZVAL_UNDEF(plan_result);
        return FAILURE;
    }
    add_assoc_bool(plan_result, "graph_op_index_ready", true);
    if (king_inference_cuda_decoder_graph_executor_prepare_plan(model, graph, plan_result) != SUCCESS) {
        zval_ptr_dtor(plan_result);
        ZVAL_UNDEF(plan_result);
        return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_execute_decode_template(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph,
    king_inference_cuda_decoder_prefill_mutations *mutations,
    zend_ulong token_id,
    zend_ulong position,
    zend_ulong *decoded_token_id,
    zend_string **decoded_token_text,
    double *decoded_probability,
    double *decoded_logit,
    double *decoded_rank,
    zend_long *mutation_ns,
    zend_long *device_execute_ns,
    zend_long *bounded_emit_ns,
    zend_long *sampler_ns,
    zend_long *token_decode_ns
) {
    zval graph_result;
    uint64_t start_ns;
    uint64_t end_ns;

    start_ns = king_inference_cuda_decoder_prompt_profile_ns();
    if (king_inference_cuda_decoder_prompt_loop_apply_decode_mutations(
            model,
            mutations,
            token_id,
            position
    ) != SUCCESS) {
        return FAILURE;
    }
    end_ns = king_inference_cuda_decoder_prompt_profile_ns();
    if (mutation_ns != NULL) {
        *mutation_ns = king_inference_cuda_decoder_prompt_profile_elapsed(end_ns, start_ns);
    }

    array_init(&graph_result);
    add_assoc_string(&graph_result, "type", "gpu_decoder_prompt_decode_template_execution");
    add_assoc_string(&graph_result, "backend", "king_native_gpu");
    add_assoc_bool(&graph_result, "result_contract_ready", true);
    add_assoc_bool(&graph_result, "graph_validated", true);
    add_assoc_bool(&graph_result, "execution_plan_ready", true);
    add_assoc_bool(&graph_result, "graph_template_reused", true);
    add_assoc_bool(&graph_result, "graph_constructed_on_hot_path", false);
    add_assoc_bool(&graph_result, "compact_decode_result", true);
    add_assoc_bool(&graph_result, "silent_cpu_fallback", false);
    add_assoc_long(&graph_result, "token_id", (zend_long) token_id);
    add_assoc_long(&graph_result, "position", (zend_long) position);
    start_ns = king_inference_cuda_decoder_prompt_profile_ns();
    if (king_inference_cuda_decoder_graph_executor_execute_initial_device_ops(
            model,
            graph,
            &graph_result
    ) != SUCCESS) {
        zval_ptr_dtor(&graph_result);
        return FAILURE;
    }
    end_ns = king_inference_cuda_decoder_prompt_profile_ns();
    if (device_execute_ns != NULL) {
        *device_execute_ns = king_inference_cuda_decoder_prompt_profile_elapsed(end_ns, start_ns);
    }
    start_ns = king_inference_cuda_decoder_prompt_profile_ns();
    if (king_inference_cuda_decoder_prompt_loop_emit_bounded_token(
            model,
            stream,
            graph,
            &graph_result,
            decoded_token_id,
            decoded_token_text,
            decoded_probability,
            decoded_logit,
            decoded_rank,
            sampler_ns,
            token_decode_ns
    ) != SUCCESS) {
        zval_ptr_dtor(&graph_result);
        return FAILURE;
    }
    end_ns = king_inference_cuda_decoder_prompt_profile_ns();
    if (bounded_emit_ns != NULL) {
        *bounded_emit_ns = king_inference_cuda_decoder_prompt_profile_elapsed(end_ns, start_ns);
    }
    zval_ptr_dtor(&graph_result);
    return SUCCESS;
}
