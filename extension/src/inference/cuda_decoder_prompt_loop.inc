/*
 * CUDA prompt decoder loop contract for native King GPU inference.
 */

static void king_inference_cuda_decoder_prompt_loop_reset_fields(king_inference_model_object *model)
{
    model->cuda_decoder_prompt_loop_result = 0;
    model->cuda_decoder_prompt_loop_error[0] = '\0';
    model->cuda_decoder_prompt_loop_count = 0;
    model->cuda_decoder_prompt_loop_last_prompt_tokens = 0;
    model->cuda_decoder_prompt_loop_last_validated_graphs = 0;
    model->cuda_decoder_prompt_loop_last_result_envelopes = 0;
    model->cuda_decoder_prompt_loop_last_max_tokens = 0;
    model->cuda_decoder_prompt_loop_attempted = false;
    model->cuda_decoder_prompt_loop_available = false;
    model->cuda_decoder_prompt_loop_tokenizer_ready = false;
    model->cuda_decoder_prompt_loop_graph_validation_ready = false;
    model->cuda_decoder_prompt_loop_result_contract_ready = false;
}

static void king_inference_cuda_decoder_prompt_loop_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_decoder_prompt_loop_error";
    }

    model->cuda_decoder_prompt_loop_result = result;
    snprintf(
        model->cuda_decoder_prompt_loop_error,
        sizeof(model->cuda_decoder_prompt_loop_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_decoder_prompt_loop_tokenizer_ready(king_inference_model_object *model)
{
    return model->gguf.tokenizer_tokens_loaded
        && model->gguf.tokenizer_lookup_loaded
        && Z_TYPE(model->tokenizer_tokens) == IS_ARRAY
        && Z_TYPE(model->tokenizer_lookup) == IS_ARRAY
        && zend_hash_num_elements(Z_ARRVAL(model->tokenizer_tokens)) > 0
        && zend_hash_num_elements(Z_ARRVAL(model->tokenizer_lookup)) > 0;
}

typedef struct _king_inference_cuda_prompt_candidate {
    zend_ulong token_id;
    double logit;
    double probability;
    double rank;
} king_inference_cuda_prompt_candidate;

static zend_result king_inference_cuda_decoder_prompt_loop_candidate(
    zval *candidate,
    zend_ulong fallback_rank,
    king_inference_cuda_prompt_candidate *out
) {
    zval *token_id = king_inference_array_find(candidate, "token_id");
    zval *logit = king_inference_array_find(candidate, "logit");
    zval *rank = king_inference_array_find(candidate, "rank");

    if (candidate == NULL
        || Z_TYPE_P(candidate) != IS_ARRAY
        || token_id == NULL
        || Z_TYPE_P(token_id) != IS_LONG
        || Z_LVAL_P(token_id) < 0
        || logit == NULL
        || (Z_TYPE_P(logit) != IS_DOUBLE && Z_TYPE_P(logit) != IS_LONG)) {
        return FAILURE;
    }

    out->token_id = (zend_ulong) Z_LVAL_P(token_id);
    out->logit = Z_TYPE_P(logit) == IS_DOUBLE ? Z_DVAL_P(logit) : (double) Z_LVAL_P(logit);
    out->probability = 1.0;
    out->rank = fallback_rank;
    if (rank != NULL && Z_TYPE_P(rank) == IS_LONG && Z_LVAL_P(rank) >= 0) {
        out->rank = (double) Z_LVAL_P(rank);
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_select_token(
    zval *graph,
    zval *candidates,
    king_inference_cuda_prompt_candidate *selected
) {
    zval *op = king_inference_king_native_next_token_op(graph);
    zval *op_name;
    king_inference_cuda_prompt_candidate *items;
    zend_ulong count;
    zend_ulong index;
    zend_ulong top_k = 0;
    zend_ulong active_count;
    zend_ulong selected_rank = 0;
    zend_ulong sample_index;
    double temperature = 0.0;
    double top_p = 1.0;
    double max_score = 0.0;
    double probability_sum = 0.0;
    double active_sum = 0.0;
    bool has_seed = false;
    zend_long seed = 0;

    if (op == NULL
        || candidates == NULL
        || Z_TYPE_P(candidates) != IS_ARRAY
        || zend_hash_num_elements(Z_ARRVAL_P(candidates)) == 0
        || king_inference_graph_finite_double_option(op, "temperature", 0.0, &temperature) != SUCCESS
        || king_inference_graph_finite_double_option(op, "top_p", 1.0, &top_p) != SUCCESS
        || king_inference_graph_sampling_seed(op, &has_seed, &seed) != SUCCESS
        || king_inference_graph_sampling_top_k(op, &top_k) != SUCCESS) {
        return FAILURE;
    }
    if (temperature < 0.0 || top_p <= 0.0 || top_p > 1.0) {
        return FAILURE;
    }

    count = (zend_ulong) zend_hash_num_elements(Z_ARRVAL_P(candidates));
    items = ecalloc((size_t) count, sizeof(*items));
    for (index = 0; index < count; index++) {
        zval *candidate = zend_hash_index_find(Z_ARRVAL_P(candidates), index);

        if (king_inference_cuda_decoder_prompt_loop_candidate(candidate, index, &items[index]) != SUCCESS) {
            efree(items);
            return FAILURE;
        }
    }

    op_name = king_inference_array_find(op, "op");
    if (op_name != NULL
        && Z_TYPE_P(op_name) == IS_STRING
        && zend_string_equals_literal(Z_STR_P(op_name), "argmax_token")) {
        *selected = items[0];
        selected->probability = 1.0;
        efree(items);
        return SUCCESS;
    }
    if (temperature == 0.0) {
        *selected = items[0];
        selected->probability = 1.0;
        efree(items);
        return SUCCESS;
    }

    for (index = 0; index < count; index++) {
        double score = items[index].logit / temperature;

        items[index].probability = score;
        if (index == 0 || score > max_score) {
            max_score = score;
        }
    }
    for (index = 0; index < count; index++) {
        items[index].probability = king_inference_graph_exp(items[index].probability - max_score);
        probability_sum += items[index].probability;
    }
    if (probability_sum <= 0.0) {
        efree(items);
        return FAILURE;
    }
    for (index = 0; index < count; index++) {
        items[index].probability /= probability_sum;
    }

    active_count = top_k == 0 || top_k > count ? count : top_k;
    probability_sum = 0.0;
    for (index = 0; index < active_count; index++) {
        probability_sum += items[index].probability;
        if (probability_sum >= top_p) {
            active_count = index + 1;
            break;
        }
    }
    for (index = 0; index < active_count; index++) {
        active_sum += items[index].probability;
    }
    if (active_sum <= 0.0) {
        efree(items);
        return FAILURE;
    }
    if (has_seed) {
        double target;
        double cumulative = 0.0;

        sample_index = king_inference_tensor_option_ulong(op, "sample_index", 0);
        target = king_inference_graph_sampling_unit(seed, count ^ (active_count << 7) ^ sample_index) * active_sum;
        selected_rank = active_count - 1;
        for (index = 0; index < active_count; index++) {
            cumulative += items[index].probability;
            if (target <= cumulative) {
                selected_rank = index;
                break;
            }
        }
    }

    *selected = items[selected_rank];
    selected->probability = selected->probability / active_sum;
    selected->rank = (double) selected_rank;
    efree(items);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_write_token_result(
    zval *graph_result,
    king_inference_cuda_prompt_candidate *selected
) {
    zval final;
    zval payload;
    zval values;

    array_init(&values);
    add_next_index_long(&values, (zend_long) selected->token_id);
    add_next_index_double(&values, selected->probability);
    add_next_index_double(&values, selected->logit);
    add_next_index_double(&values, selected->rank);

    array_init(&payload);
    add_assoc_string(&payload, "type", "vector");
    add_assoc_long(&payload, "length", 4);
    add_assoc_zval(&payload, "values", &values);

    array_init(&final);
    add_assoc_zval(&final, "next_token", &payload);
    add_assoc_zval(graph_result, "final", &final);
    add_assoc_bool(graph_result, "token_result_ready", true);
    add_assoc_bool(graph_result, "decoded_token_ready", true);
    add_assoc_long(graph_result, "selected_token_id", (zend_long) selected->token_id);
    add_assoc_double(graph_result, "selected_token_probability", selected->probability);
    add_assoc_double(graph_result, "selected_token_logit", selected->logit);
    add_assoc_double(graph_result, "selected_token_rank", selected->rank);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_consume_bounded_logits(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph,
    zval *graph_result,
    zend_ulong *token_id,
    double *probability,
    double *logit,
    double *rank
) {
    zval *logits_probe = king_inference_array_find(graph_result, "logits_projection_device_execution");
    zval *candidates = logits_probe != NULL && Z_TYPE_P(logits_probe) == IS_ARRAY
        ? king_inference_array_find(logits_probe, "candidates")
        : NULL;
    king_inference_cuda_prompt_candidate selected;

    if (king_inference_cuda_decoder_prompt_loop_select_token(graph, candidates, &selected) != SUCCESS
        || king_inference_cuda_decoder_prompt_loop_write_token_result(graph_result, &selected) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_bounded_logits_sample_failed");
        return FAILURE;
    }

    king_inference_king_native_update_decoder_metrics(stream, graph_result, graph, selected.token_id);
    *token_id = selected.token_id;
    *probability = selected.probability;
    *logit = selected.logit;
    *rank = selected.rank;
    return SUCCESS;
}

static void king_inference_cuda_decoder_prompt_loop_release(king_inference_model_object *model)
{
    king_inference_cuda_decoder_prompt_loop_reset_fields(model);
}

static void king_inference_cuda_decoder_prompt_loop_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_decoder_prompt_loop_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_decoder_prompt_loop_attempted = true;
    model->cuda_decoder_prompt_loop_tokenizer_ready =
        king_inference_cuda_decoder_prompt_loop_tokenizer_ready(model);
    model->cuda_decoder_prompt_loop_graph_validation_ready =
        model->cuda_decoder_graph_executor_available;
    model->cuda_decoder_prompt_loop_result_contract_ready =
        model->cuda_decoder_graph_executor_result_contract_available;

    if (!model->cuda_decoder_prompt_loop_tokenizer_ready) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_loop_tokenizer_unavailable"
        );
        return;
    }
    if (!model->cuda_decoder_prompt_loop_graph_validation_ready) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_loop_graph_executor_unavailable"
        );
        return;
    }
    if (!model->cuda_decoder_prompt_loop_result_contract_ready) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_loop_result_contract_unavailable"
        );
        return;
    }

    model->cuda_decoder_prompt_loop_available = true;
    model->cuda_decoder_prompt_loop_result = 0;
    model->cuda_decoder_prompt_loop_error[0] = '\0';
}

static zend_result king_inference_cuda_decoder_prompt_loop_validate_prompt(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *prompt_text,
    zend_long max_tokens
) {
    zval encoded;
    zval tokenized_prompt;
    zval decode_options;
    zval event;
    zend_ulong token_count = 0;
    zend_ulong position;
    zend_ulong decoded_token_id = 0;
    size_t validated_graphs = 0;
    size_t result_envelopes = 0;
    size_t decoded_tokens = 0;
    zend_string *decoded_token_text = NULL;
    double decoded_probability = 0.0;
    double decoded_logit = 0.0;
    double decoded_rank = 0.0;

    ZVAL_UNDEF(&encoded);
    ZVAL_UNDEF(&tokenized_prompt);
    ZVAL_UNDEF(&decode_options);

    if (!model->cuda_decoder_prompt_loop_available) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_loop_unavailable"
        );
        return FAILURE;
    }
    if (prompt_text == NULL || Z_TYPE_P(prompt_text) != IS_STRING || Z_STRLEN_P(prompt_text) == 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_prompt_invalid");
        return FAILURE;
    }
    if (max_tokens <= 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_max_tokens_invalid");
        return FAILURE;
    }

    if (king_inference_tokenizer_encode(model, Z_STR_P(prompt_text), &encoded) != SUCCESS
        || king_inference_king_native_prompt_tokens(model, &encoded, &tokenized_prompt, &token_count) != SUCCESS) {
        if (!Z_ISUNDEF(encoded)) {
            zval_ptr_dtor(&encoded);
        }
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_tokenize_failed");
        return FAILURE;
    }
    if (token_count == 0) {
        zval_ptr_dtor(&tokenized_prompt);
        zval_ptr_dtor(&encoded);
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_tokenize_empty");
        return FAILURE;
    }

    king_inference_king_native_prompt_decode_options(stream, &decode_options);
    for (position = 0; position < token_count; position++) {
        zval graph;
        zval graph_result;

        ZVAL_UNDEF(&graph);
        ZVAL_UNDEF(&graph_result);
        add_assoc_bool(&decode_options, "emit_token", position + 1 == token_count);
        if (king_inference_token_decode_graph_array(model, &tokenized_prompt, position, &decode_options, &graph) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_prepare_result(model, &graph, &graph_result) != SUCCESS) {
            if (!Z_ISUNDEF(graph_result)) {
                zval_ptr_dtor(&graph_result);
            }
            if (!Z_ISUNDEF(graph)) {
                zval_ptr_dtor(&graph);
            }
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                -1,
                "cuda_decoder_prompt_loop_graph_validation_failed"
            );
            return FAILURE;
        }
        if (position + 1 == token_count) {
            if (king_inference_cuda_decoder_prompt_loop_consume_bounded_logits(
                    model,
                    stream,
                    &graph,
                    &graph_result,
                    &decoded_token_id,
                    &decoded_probability,
                    &decoded_logit,
                    &decoded_rank
            ) != SUCCESS) {
                zval_ptr_dtor(&graph_result);
                zval_ptr_dtor(&graph);
                zval_ptr_dtor(&decode_options);
                zval_ptr_dtor(&tokenized_prompt);
                zval_ptr_dtor(&encoded);
                return FAILURE;
            }
            decoded_token_text = king_inference_tokenizer_decode_token(model, decoded_token_id);
            if (decoded_token_text == NULL) {
                zval_ptr_dtor(&graph_result);
                zval_ptr_dtor(&graph);
                zval_ptr_dtor(&decode_options);
                zval_ptr_dtor(&tokenized_prompt);
                zval_ptr_dtor(&encoded);
                king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_decode_token_failed");
                return FAILURE;
            }
            decoded_tokens++;
        }
        validated_graphs++;
        result_envelopes++;
        zval_ptr_dtor(&graph_result);
        zval_ptr_dtor(&graph);
    }

    model->cuda_decoder_prompt_loop_count++;
    model->cuda_decoder_prompt_loop_last_prompt_tokens = token_count;
    model->cuda_decoder_prompt_loop_last_validated_graphs = validated_graphs;
    model->cuda_decoder_prompt_loop_last_result_envelopes = result_envelopes;
    model->cuda_decoder_prompt_loop_last_max_tokens = max_tokens;
    model->cuda_decoder_prompt_loop_result = 0;
    model->cuda_decoder_prompt_loop_error[0] = '\0';

    array_init(&event);
    add_assoc_string(&event, "type", "gpu_prompt_decoder_loop_contract");
    add_assoc_string(&event, "backend", "king_native_gpu");
    add_assoc_bool(&event, "prompt_loop_ready", true);
    add_assoc_bool(&event, "prompt_graphs_validated", true);
    add_assoc_bool(&event, "graph_result_contract_ready", true);
    add_assoc_bool(&event, "graph_execution_plan_ready", true);
    add_assoc_bool(
        &event,
        "embedding_device_execution_ready",
        model->cuda_decoder_graph_executor_embedding_execution_available
    );
    add_assoc_bool(
        &event,
        "rms_norm_device_execution_ready",
        model->cuda_decoder_graph_executor_rms_norm_execution_available
    );
    add_assoc_bool(
        &event,
        "linear_device_execution_ready",
        model->cuda_decoder_graph_executor_linear_execution_available
    );
    add_assoc_bool(
        &event,
        "slice_device_execution_ready",
        model->cuda_decoder_graph_executor_slice_execution_available
    );
    add_assoc_bool(
        &event,
        "rope_device_execution_ready",
        model->cuda_decoder_graph_executor_rope_execution_available
    );
    add_assoc_bool(
        &event,
        "kv_head_prepare_device_execution_ready",
        model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
    );
    add_assoc_bool(
        &event,
        "kv_write_device_execution_ready",
        model->cuda_decoder_graph_executor_kv_write_execution_available
    );
    add_assoc_bool(
        &event,
        "kv_attention_device_execution_ready",
        model->cuda_decoder_graph_executor_kv_attention_execution_available
    );
    add_assoc_bool(
        &event,
        "attention_stack_slot_device_execution_ready",
        model->cuda_decoder_graph_executor_attention_stack_slot_execution_available
    );
    add_assoc_bool(
        &event,
        "attention_heads_device_execution_ready",
        model->cuda_decoder_graph_executor_attention_heads_execution_available
    );
    add_assoc_bool(
        &event,
        "attention_stack_device_execution_ready",
        model->cuda_decoder_graph_executor_attention_stack_execution_available
    );
    add_assoc_bool(
        &event,
        "attention_output_projection_device_execution_ready",
        model->cuda_decoder_graph_executor_attention_output_projection_execution_available
    );
    add_assoc_bool(
        &event,
        "attention_residual_device_execution_ready",
        model->cuda_decoder_graph_executor_attention_residual_execution_available
    );
    add_assoc_bool(
        &event,
        "ffn_norm_device_execution_ready",
        model->cuda_decoder_graph_executor_ffn_norm_execution_available
    );
    add_assoc_bool(
        &event,
        "ffn_gate_up_projection_device_execution_ready",
        model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available
    );
    add_assoc_bool(
        &event,
        "ffn_swiglu_device_execution_ready",
        model->cuda_decoder_graph_executor_ffn_swiglu_execution_available
    );
    add_assoc_bool(
        &event,
        "ffn_down_projection_device_execution_ready",
        model->cuda_decoder_graph_executor_ffn_down_projection_execution_available
    );
    add_assoc_bool(
        &event,
        "ffn_output_residual_device_execution_ready",
        model->cuda_decoder_graph_executor_ffn_output_residual_execution_available
    );
    add_assoc_bool(
        &event,
        "final_norm_device_execution_ready",
        model->cuda_decoder_graph_executor_final_norm_execution_available
    );
    add_assoc_bool(
        &event,
        "logits_projection_device_execution_ready",
        model->cuda_decoder_graph_executor_logits_projection_execution_available
    );
    add_assoc_bool(&event, "device_execution_result_ready", decoded_tokens > 0);
    add_assoc_bool(&event, "generation_ready", false);
    add_assoc_long(&event, "prompt_tokens", (zend_long) token_count);
    add_assoc_long(&event, "validated_graphs", (zend_long) validated_graphs);
    add_assoc_long(&event, "result_envelopes", (zend_long) result_envelopes);
    add_assoc_long(&event, "decoded_tokens", (zend_long) decoded_tokens);
    add_assoc_long(&event, "accepted_max_tokens", max_tokens);
    add_assoc_bool(&event, "bounded_logits_consumed", decoded_tokens > 0);
    add_assoc_bool(&event, "token_result_ready", decoded_tokens > 0);
    add_assoc_bool(&event, "decoded_token_ready", decoded_tokens > 0);
    if (decoded_tokens > 0) {
        add_assoc_long(&event, "decoded_token_id", (zend_long) decoded_token_id);
        add_assoc_str(&event, "decoded_token_text", zend_string_copy(decoded_token_text));
        add_assoc_double(&event, "decoded_token_probability", decoded_probability);
        add_assoc_double(&event, "decoded_token_logit", decoded_logit);
        add_assoc_double(&event, "decoded_token_rank", decoded_rank);
    }
    add_next_index_zval(&stream->native_events, &event);
    if (decoded_token_text != NULL) {
        add_next_index_str(&stream->native_events, decoded_token_text);
    }

    zval_ptr_dtor(&decode_options);
    zval_ptr_dtor(&tokenized_prompt);
    zval_ptr_dtor(&encoded);
    return SUCCESS;
}

static void king_inference_cuda_decoder_prompt_loop_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval loop;

    array_init(&loop);
    add_assoc_bool(&loop, "attempted", model->cuda_decoder_prompt_loop_attempted);
    add_assoc_bool(&loop, "available", model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(&loop, "tokenizer_ready", model->cuda_decoder_prompt_loop_tokenizer_ready);
    add_assoc_bool(
        &loop,
        "graph_validation_ready",
        model->cuda_decoder_prompt_loop_graph_validation_ready
    );
    add_assoc_bool(
        &loop,
        "result_contract_ready",
        model->cuda_decoder_prompt_loop_result_contract_ready
    );
    add_assoc_bool(
        &loop,
        "execution_plan_ready",
        model->cuda_decoder_graph_executor_execution_plan_available
    );
    add_assoc_bool(
        &loop,
        "embedding_device_execution_ready",
        model->cuda_decoder_graph_executor_embedding_execution_available
    );
    add_assoc_bool(
        &loop,
        "rms_norm_device_execution_ready",
        model->cuda_decoder_graph_executor_rms_norm_execution_available
    );
    add_assoc_bool(
        &loop,
        "linear_device_execution_ready",
        model->cuda_decoder_graph_executor_linear_execution_available
    );
    add_assoc_bool(
        &loop,
        "slice_device_execution_ready",
        model->cuda_decoder_graph_executor_slice_execution_available
    );
    add_assoc_bool(
        &loop,
        "rope_device_execution_ready",
        model->cuda_decoder_graph_executor_rope_execution_available
    );
    add_assoc_bool(
        &loop,
        "kv_head_prepare_device_execution_ready",
        model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
    );
    add_assoc_bool(
        &loop,
        "kv_write_device_execution_ready",
        model->cuda_decoder_graph_executor_kv_write_execution_available
    );
    add_assoc_bool(
        &loop,
        "kv_attention_device_execution_ready",
        model->cuda_decoder_graph_executor_kv_attention_execution_available
    );
    add_assoc_bool(
        &loop,
        "attention_stack_slot_device_execution_ready",
        model->cuda_decoder_graph_executor_attention_stack_slot_execution_available
    );
    add_assoc_bool(
        &loop,
        "attention_heads_device_execution_ready",
        model->cuda_decoder_graph_executor_attention_heads_execution_available
    );
    add_assoc_bool(
        &loop,
        "attention_stack_device_execution_ready",
        model->cuda_decoder_graph_executor_attention_stack_execution_available
    );
    add_assoc_bool(
        &loop,
        "attention_output_projection_device_execution_ready",
        model->cuda_decoder_graph_executor_attention_output_projection_execution_available
    );
    add_assoc_bool(
        &loop,
        "attention_residual_device_execution_ready",
        model->cuda_decoder_graph_executor_attention_residual_execution_available
    );
    add_assoc_bool(
        &loop,
        "ffn_norm_device_execution_ready",
        model->cuda_decoder_graph_executor_ffn_norm_execution_available
    );
    add_assoc_bool(
        &loop,
        "ffn_gate_up_projection_device_execution_ready",
        model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available
    );
    add_assoc_bool(
        &loop,
        "ffn_swiglu_device_execution_ready",
        model->cuda_decoder_graph_executor_ffn_swiglu_execution_available
    );
    add_assoc_bool(
        &loop,
        "ffn_down_projection_device_execution_ready",
        model->cuda_decoder_graph_executor_ffn_down_projection_execution_available
    );
    add_assoc_bool(
        &loop,
        "ffn_output_residual_device_execution_ready",
        model->cuda_decoder_graph_executor_ffn_output_residual_execution_available
    );
    add_assoc_bool(
        &loop,
        "final_norm_device_execution_ready",
        model->cuda_decoder_graph_executor_final_norm_execution_available
    );
    add_assoc_bool(
        &loop,
        "logits_projection_device_execution_ready",
        model->cuda_decoder_graph_executor_logits_projection_execution_available
    );
    add_assoc_bool(&loop, "device_execution_result_ready", model->cuda_decoder_prompt_loop_count > 0);
    add_assoc_bool(&loop, "token_result_ready", model->cuda_decoder_prompt_loop_count > 0);
    add_assoc_bool(&loop, "decoded_token_ready", model->cuda_decoder_prompt_loop_count > 0);
    add_assoc_long(&loop, "runs", (zend_long) model->cuda_decoder_prompt_loop_count);
    add_assoc_long(&loop, "last_prompt_tokens", (zend_long) model->cuda_decoder_prompt_loop_last_prompt_tokens);
    add_assoc_long(
        &loop,
        "last_validated_graphs",
        (zend_long) model->cuda_decoder_prompt_loop_last_validated_graphs
    );
    add_assoc_long(
        &loop,
        "last_result_envelopes",
        (zend_long) model->cuda_decoder_prompt_loop_last_result_envelopes
    );
    add_assoc_long(&loop, "last_max_tokens", model->cuda_decoder_prompt_loop_last_max_tokens);
    add_assoc_long(&loop, "result", model->cuda_decoder_prompt_loop_result);
    add_assoc_string(&loop, "error", model->cuda_decoder_prompt_loop_error);
    add_assoc_zval(return_value, "decoder_prompt_loop", &loop);
    add_assoc_bool(return_value, "decoder_prompt_loop_ready", model->cuda_decoder_prompt_loop_available);
}

static void king_inference_cuda_decoder_prompt_loop_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_loop;

    king_inference_cuda_decoder_prompt_loop_add_status(model, return_value);
    if (!model->cuda_decoder_prompt_loop_attempted
        || model->cuda_decoder_prompt_loop_available
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_loop = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_loop) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_decoder_prompt_loop_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_decoder_prompt_loop_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_decoder_prompt_loop_unavailable");
    }
}
