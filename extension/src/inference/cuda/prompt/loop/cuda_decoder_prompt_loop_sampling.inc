/*
 * CUDA prompt decoder loop sampling helpers.
 */

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

static void king_inference_cuda_decoder_prompt_loop_write_selected_metrics(
    king_inference_stream_object *stream,
    king_inference_cuda_prompt_candidate *selected
) {
    stream->native_decoder_token_count++;
    stream->native_decoder_last_token_id = selected->token_id;
    stream->native_decoder_last_token_available = true;
    stream->native_decoder_last_probability = selected->probability;
    stream->native_decoder_last_logit = selected->logit;
    stream->native_decoder_last_rank = selected->rank;
    stream->native_decoder_last_score_available = true;
}

static zend_result king_inference_cuda_decoder_prompt_loop_write_compact_token_result(
    zval *graph_result,
    king_inference_cuda_prompt_candidate *selected
) {
    add_assoc_bool(graph_result, "token_result_ready", true);
    add_assoc_bool(graph_result, "decoded_token_ready", true);
    add_assoc_long(graph_result, "selected_token_id", (zend_long) selected->token_id);
    add_assoc_double(graph_result, "selected_token_probability", selected->probability);
    add_assoc_double(graph_result, "selected_token_logit", selected->logit);
    add_assoc_double(graph_result, "selected_token_rank", selected->rank);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_selected_probe_candidate(
    zval *logits_probe,
    king_inference_cuda_prompt_candidate *selected
) {
    zval *ready;
    zval *token_id;
    zval *probability;
    zval *logit;
    zval *rank;

    if (logits_probe == NULL || Z_TYPE_P(logits_probe) != IS_ARRAY) {
        return FAILURE;
    }
    ready = king_inference_array_find(logits_probe, "selected_token_ready");
    token_id = king_inference_array_find(logits_probe, "selected_token_id");
    probability = king_inference_array_find(logits_probe, "selected_token_probability");
    logit = king_inference_array_find(logits_probe, "selected_token_logit");
    rank = king_inference_array_find(logits_probe, "selected_token_rank");
    if (ready == NULL
        || !zend_is_true(ready)
        || token_id == NULL
        || Z_TYPE_P(token_id) != IS_LONG
        || Z_LVAL_P(token_id) < 0
        || probability == NULL
        || (Z_TYPE_P(probability) != IS_DOUBLE && Z_TYPE_P(probability) != IS_LONG)
        || logit == NULL
        || (Z_TYPE_P(logit) != IS_DOUBLE && Z_TYPE_P(logit) != IS_LONG)
        || rank == NULL
        || (Z_TYPE_P(rank) != IS_DOUBLE && Z_TYPE_P(rank) != IS_LONG)) {
        return FAILURE;
    }

    selected->token_id = (zend_ulong) Z_LVAL_P(token_id);
    selected->probability = Z_TYPE_P(probability) == IS_DOUBLE ? Z_DVAL_P(probability) : (double) Z_LVAL_P(probability);
    selected->logit = Z_TYPE_P(logit) == IS_DOUBLE ? Z_DVAL_P(logit) : (double) Z_LVAL_P(logit);
    selected->rank = Z_TYPE_P(rank) == IS_DOUBLE ? Z_DVAL_P(rank) : (double) Z_LVAL_P(rank);
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
    zval *compact_decode_result = king_inference_array_find(graph_result, "compact_decode_result");
    king_inference_cuda_prompt_candidate selected;

    if (king_inference_cuda_decoder_prompt_loop_selected_probe_candidate(logits_probe, &selected) != SUCCESS
        && king_inference_cuda_decoder_prompt_loop_select_token(graph, candidates, &selected) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_bounded_logits_sample_failed");
        return FAILURE;
    }
    if (compact_decode_result != NULL && zend_is_true(compact_decode_result)) {
        if (king_inference_cuda_decoder_prompt_loop_write_compact_token_result(graph_result, &selected) != SUCCESS) {
            king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_bounded_logits_sample_failed");
            return FAILURE;
        }
        king_inference_cuda_decoder_prompt_loop_write_selected_metrics(stream, &selected);
    } else if (king_inference_cuda_decoder_prompt_loop_write_token_result(graph_result, &selected) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_bounded_logits_sample_failed");
        return FAILURE;
    } else {
        king_inference_king_native_update_decoder_metrics(stream, graph_result, graph, selected.token_id);
    }

    *token_id = selected.token_id;
    *probability = selected.probability;
    *logit = selected.logit;
    *rank = selected.rank;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_emit_bounded_token(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph,
    zval *graph_result,
    zend_ulong *token_id,
    zend_string **last_text,
    double *probability,
    double *logit,
    double *rank,
    zend_long *sampler_ns,
    zend_long *token_decode_ns
) {
    zend_string *piece;
    uint64_t start_ns;
    uint64_t end_ns;

    start_ns = king_inference_cuda_decoder_prompt_profile_ns();
    if (king_inference_cuda_decoder_prompt_loop_consume_bounded_logits(
            model,
            stream,
            graph,
            graph_result,
            token_id,
            probability,
            logit,
            rank
    ) != SUCCESS) {
        return FAILURE;
    }
    end_ns = king_inference_cuda_decoder_prompt_profile_ns();
    if (sampler_ns != NULL) {
        *sampler_ns = king_inference_cuda_decoder_prompt_profile_elapsed(end_ns, start_ns);
    }

    start_ns = king_inference_cuda_decoder_prompt_profile_ns();
    piece = king_inference_tokenizer_decode_token(model, *token_id);
    end_ns = king_inference_cuda_decoder_prompt_profile_ns();
    if (token_decode_ns != NULL) {
        *token_decode_ns = king_inference_cuda_decoder_prompt_profile_elapsed(end_ns, start_ns);
    }
    if (piece == NULL) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_decode_token_failed");
        return FAILURE;
    }
    if (*last_text != NULL) {
        zend_string_release(*last_text);
    }
    *last_text = zend_string_copy(piece);
    zend_string_release(piece);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_loop_emit_decoded_text(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zend_ulong token_id,
    zend_string *decoded_text,
    zval *stops,
    bool *stopped
) {
    *stopped = false;
    if (king_inference_king_native_prompt_should_stop_token(model, token_id)
        || king_inference_king_native_prompt_piece_matches_stop(decoded_text, stops)) {
        *stopped = true;
        return SUCCESS;
    }
    if (decoded_text == NULL || ZSTR_LEN(decoded_text) == 0) {
        return SUCCESS;
    }
    if (Z_ISUNDEF(stream->native_events) || Z_TYPE(stream->native_events) != IS_ARRAY) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_native_stream_unavailable");
        return FAILURE;
    }
    add_next_index_str(&stream->native_events, zend_string_copy(decoded_text));
    return SUCCESS;
}
