/*
 * Lazy CUDA prompt stream state.
 */

typedef struct _king_inference_cuda_decoder_prompt_stream_state {
    zval tokenized_prompt;
    zval decode_options;
    zval stops;
    zval graph;
    zval plan_result;
    king_inference_cuda_decoder_prefill_mutations mutations;
    zend_long max_tokens;
    zend_ulong token_count;
    zend_ulong position;
    zend_ulong decoded_token_id;
    size_t validated_graphs;
    size_t result_envelopes;
    size_t prefill_batches;
    size_t prefill_tokens;
    size_t prefill_template_reuses;
    size_t decoded_tokens;
    size_t kv_cache_reserve_calls_start;
    size_t kv_cache_allocations_start;
    size_t kv_cache_writes_start;
    size_t kv_cache_batch_writes_start;
    size_t graph_constructions_start;
    size_t plan_constructions_start;
    zend_string *decoded_token_text;
    double decoded_probability;
    double decoded_logit;
    double decoded_rank;
    bool initialized;
    bool stopped;
    bool finished;
} king_inference_cuda_decoder_prompt_stream_state;

static king_inference_cuda_decoder_prompt_stream_state *king_inference_cuda_decoder_prompt_stream_state_from(
    king_inference_stream_object *stream
) {
    return (king_inference_cuda_decoder_prompt_stream_state *) stream->native_gpu_stream_state;
}

static void king_inference_cuda_decoder_prompt_stream_release(king_inference_stream_object *stream)
{
    king_inference_cuda_decoder_prompt_stream_state *state;

    if (stream == NULL || stream->native_gpu_stream_state == NULL) {
        return;
    }

    state = king_inference_cuda_decoder_prompt_stream_state_from(stream);
    king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(&state->mutations);
    if (!Z_ISUNDEF(state->plan_result)) {
        zval_ptr_dtor(&state->plan_result);
        ZVAL_UNDEF(&state->plan_result);
    }
    if (!Z_ISUNDEF(state->graph)) {
        zval_ptr_dtor(&state->graph);
        ZVAL_UNDEF(&state->graph);
    }
    if (!Z_ISUNDEF(state->stops)) {
        zval_ptr_dtor(&state->stops);
        ZVAL_UNDEF(&state->stops);
    }
    if (!Z_ISUNDEF(state->decode_options)) {
        zval_ptr_dtor(&state->decode_options);
        ZVAL_UNDEF(&state->decode_options);
    }
    if (!Z_ISUNDEF(state->tokenized_prompt)) {
        zval_ptr_dtor(&state->tokenized_prompt);
        ZVAL_UNDEF(&state->tokenized_prompt);
    }
    if (state->decoded_token_text != NULL) {
        zend_string_release(state->decoded_token_text);
        state->decoded_token_text = NULL;
    }
    efree(state);
    stream->native_gpu_stream_state = NULL;
}

static zend_result king_inference_cuda_decoder_prompt_stream_start(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zend_long max_tokens
) {
    king_inference_cuda_decoder_prompt_stream_state *state;

    king_inference_cuda_decoder_prompt_stream_release(stream);
    if (!model->cuda_decoder_prompt_loop_available) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_unavailable");
        return FAILURE;
    }
    if (max_tokens <= 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_max_tokens_invalid");
        return FAILURE;
    }

    state = ecalloc(1, sizeof(*state));
    ZVAL_UNDEF(&state->tokenized_prompt);
    ZVAL_UNDEF(&state->decode_options);
    ZVAL_UNDEF(&state->stops);
    ZVAL_UNDEF(&state->graph);
    ZVAL_UNDEF(&state->plan_result);
    memset(&state->mutations, 0, sizeof(state->mutations));
    state->max_tokens = max_tokens;
    stream->native_gpu_stream_state = state;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_prompt_stream_initialize(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    king_inference_cuda_decoder_prompt_stream_state *state
) {
    zval encoded;
    zval *prompt_text = king_inference_array_find(&stream->request, "native_prompt_text");
    zend_ulong initial_token_id = 0;

    ZVAL_UNDEF(&encoded);
    if (prompt_text == NULL || Z_TYPE_P(prompt_text) != IS_STRING || Z_STRLEN_P(prompt_text) == 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_prompt_invalid");
        return FAILURE;
    }

    if (king_inference_tokenizer_encode(model, Z_STR_P(prompt_text), &encoded) != SUCCESS
        || king_inference_king_native_prompt_tokens(model, &encoded, &state->tokenized_prompt, &state->token_count) != SUCCESS) {
        if (!Z_ISUNDEF(encoded)) {
            zval_ptr_dtor(&encoded);
        }
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_tokenize_failed");
        return FAILURE;
    }
    zval_ptr_dtor(&encoded);
    if (state->token_count == 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_tokenize_empty");
        return FAILURE;
    }

    if (king_inference_king_native_prompt_stop_list(&stream->request, &state->stops) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_stop_list_invalid");
        return FAILURE;
    }
    king_inference_king_native_prompt_add_default_stops(model, &state->stops);
    king_inference_king_native_prompt_decode_options(stream, &state->decode_options);

    state->kv_cache_reserve_calls_start = model->cuda_device_kv_cache_reserve_call_count;
    state->kv_cache_allocations_start = model->cuda_device_kv_cache_allocation_count;
    state->kv_cache_writes_start = model->cuda_device_kv_cache_write_count;
    state->kv_cache_batch_writes_start = model->cuda_device_kv_cache_batch_write_count;
    state->graph_constructions_start = model->cuda_decoder_graph_executor_graph_count;
    state->plan_constructions_start = model->cuda_decoder_graph_executor_plan_count;

    if (state->token_count >= KING_INFERENCE_CUDA_PROMPT_BATCH_PREFILL_MIN_TOKENS
        && king_inference_cuda_decoder_prompt_loop_batch_prefill_admitted(model)) {
        if (king_inference_cuda_decoder_prompt_loop_prefill_batch_template(
                model,
                &state->tokenized_prompt,
                state->token_count,
                &state->decode_options,
                &state->validated_graphs,
                &state->result_envelopes,
                &state->prefill_batches,
                &state->prefill_tokens,
                &state->prefill_template_reuses
        ) != SUCCESS) {
            return FAILURE;
        }
    } else if (king_inference_cuda_decoder_prompt_loop_prefill_sequential(
                   model,
                   &state->tokenized_prompt,
                   state->token_count,
                   &state->decode_options,
                   &state->validated_graphs,
                   &state->result_envelopes,
                   &state->prefill_tokens
    ) != SUCCESS) {
        return FAILURE;
    }

    state->position = state->token_count - 1;
    add_assoc_bool(&state->decode_options, "emit_token", true);
    if (king_inference_cuda_decoder_prompt_loop_token_at(
            &state->tokenized_prompt,
            state->position,
            &initial_token_id
    ) != SUCCESS
        || king_inference_token_decode_graph_array(
            model,
            &state->tokenized_prompt,
            state->position,
            &state->decode_options,
            &state->graph
        ) != SUCCESS
        || king_inference_cuda_decoder_prompt_loop_prepare_decode_template(
            model,
            &state->graph,
            &state->plan_result
        ) != SUCCESS
        || king_inference_cuda_decoder_prompt_loop_build_decode_mutations(
            model,
            &state->graph,
            &state->mutations
        ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            model->cuda_decoder_graph_executor_error[0] != '\0'
                ? model->cuda_decoder_graph_executor_error
                : "cuda_decoder_prompt_loop_graph_validation_failed"
        );
        return FAILURE;
    }

    state->decoded_token_id = initial_token_id;
    state->validated_graphs++;
    state->initialized = true;
    return SUCCESS;
}

static void king_inference_cuda_decoder_prompt_stream_finish(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    king_inference_cuda_decoder_prompt_stream_state *state
) {
    zval event;

    model->cuda_decoder_prompt_loop_count++;
    model->cuda_decoder_prompt_loop_last_prompt_tokens = state->token_count;
    model->cuda_decoder_prompt_loop_last_validated_graphs = state->validated_graphs;
    model->cuda_decoder_prompt_loop_last_result_envelopes = state->result_envelopes;
    model->cuda_decoder_prompt_loop_last_prefill_batches = state->prefill_batches;
    model->cuda_decoder_prompt_loop_last_prefill_tokens = state->prefill_tokens;
    model->cuda_decoder_prompt_loop_last_prefill_template_reuses = state->prefill_template_reuses;
    model->cuda_decoder_prompt_loop_last_decoded_tokens = state->decoded_tokens;
    model->cuda_decoder_prompt_loop_last_kv_cache_reserve_calls =
        model->cuda_device_kv_cache_reserve_call_count - state->kv_cache_reserve_calls_start;
    model->cuda_decoder_prompt_loop_last_kv_cache_allocations =
        model->cuda_device_kv_cache_allocation_count - state->kv_cache_allocations_start;
    model->cuda_decoder_prompt_loop_last_kv_cache_writes =
        model->cuda_device_kv_cache_write_count - state->kv_cache_writes_start;
    model->cuda_decoder_prompt_loop_last_kv_cache_batch_writes =
        model->cuda_device_kv_cache_batch_write_count - state->kv_cache_batch_writes_start;
    model->cuda_decoder_prompt_loop_last_graph_constructions =
        model->cuda_decoder_graph_executor_graph_count - state->graph_constructions_start;
    model->cuda_decoder_prompt_loop_last_plan_constructions =
        model->cuda_decoder_graph_executor_plan_count - state->plan_constructions_start;
    model->cuda_decoder_prompt_loop_last_policy_checks = (size_t) stream->gpu_policy_during_run_checks;
    model->cuda_decoder_prompt_loop_last_policy_interval_skips = (size_t) stream->gpu_policy_interval_skips;
    model->cuda_decoder_prompt_loop_last_thermal_runtime_checks = (size_t) stream->gpu_thermal_runtime_checks;
    model->cuda_decoder_prompt_loop_last_power_runtime_checks = (size_t) stream->gpu_power_runtime_checks;
    model->cuda_decoder_prompt_loop_last_max_tokens = state->max_tokens;
    model->cuda_decoder_prompt_loop_last_thermal_interval_seconds =
        king_inference_gpu_thermal_check_interval_seconds(&model->config);
    model->cuda_decoder_prompt_loop_last_power_interval_seconds =
        king_inference_gpu_power_check_interval_seconds(&model->config);
    model->cuda_decoder_prompt_loop_last_reuse_profile_available = true;
    model->cuda_decoder_prompt_loop_result = 0;
    model->cuda_decoder_prompt_loop_error[0] = '\0';

    if (king_inference_king_native_stream_diagnostics_enabled(stream)) {
        array_init(&event);
        add_assoc_string(&event, "type", "gpu_prompt_decoder_loop_contract");
        add_assoc_string(&event, "backend", "king_native_gpu");
        add_assoc_bool(&event, "prompt_loop_ready", true);
        add_assoc_bool(&event, "lazy_native_token_stream", true);
        add_assoc_bool(&event, "prompt_batch_prefill_available", model->cuda_decoder_prompt_loop_batch_prefill_available);
        add_assoc_bool(&event, "prompt_batch_prefill_admitted", king_inference_cuda_decoder_prompt_loop_batch_prefill_admitted(model));
        add_assoc_string(&event, "prompt_batch_prefill_status", king_inference_cuda_decoder_prompt_loop_batch_prefill_status(model));
        add_assoc_string(&event, "prompt_batch_prefill_admission_reason", king_inference_cuda_decoder_prompt_loop_batch_prefill_admission_reason(model));
        add_assoc_bool(&event, "prompt_batch_prefill_used", model->cuda_decoder_prompt_loop_last_used_batch_prefill);
        add_assoc_bool(&event, "decode_graph_template_resident", true);
        add_assoc_bool(&event, "decode_graph_constructed_on_hot_path", false);
        add_assoc_bool(&event, "token_events_streamed_immediately", true);
        add_assoc_bool(&event, "stop_condition_reached", state->stopped);
        add_assoc_bool(&event, "prompt_graphs_validated", true);
        add_assoc_bool(&event, "graph_result_contract_ready", true);
        add_assoc_bool(&event, "graph_execution_plan_ready", true);
        add_assoc_long(&event, "prompt_tokens", (zend_long) state->token_count);
        add_assoc_long(&event, "prefill_batches", (zend_long) state->prefill_batches);
        add_assoc_long(&event, "prefill_tokens", (zend_long) state->prefill_tokens);
        add_assoc_long(&event, "prefill_template_reuses", (zend_long) state->prefill_template_reuses);
        add_assoc_long(&event, "validated_graphs", (zend_long) state->validated_graphs);
        add_assoc_long(&event, "result_envelopes", (zend_long) state->result_envelopes);
        add_assoc_long(&event, "decoded_tokens", (zend_long) state->decoded_tokens);
        add_assoc_long(&event, "accepted_max_tokens", state->max_tokens);
        add_assoc_bool(&event, "bounded_logits_consumed", state->decoded_tokens > 0);
        add_assoc_bool(&event, "token_result_ready", state->decoded_tokens > 0);
        add_assoc_bool(&event, "decoded_token_ready", state->decoded_tokens > 0);
        add_next_index_zval(&stream->native_events, &event);
    }

    state->finished = true;
}

static zend_result king_inference_cuda_decoder_prompt_stream_step(
    king_inference_model_object *model,
    king_inference_stream_object *stream
) {
    king_inference_cuda_decoder_prompt_stream_state *state =
        king_inference_cuda_decoder_prompt_stream_state_from(stream);
    king_inference_cuda_decoder_prompt_profile_snapshot before;
    king_inference_cuda_decoder_prompt_profile_snapshot after;
    zend_ulong source_token_id;
    uint64_t total_start_ns;
    uint64_t policy_start_ns;
    uint64_t text_emit_start_ns;
    zend_long policy_ns = 0;
    zend_long mutation_ns = 0;
    zend_long device_execute_ns = 0;
    zend_long bounded_emit_ns = 0;
    zend_long sampler_ns = 0;
    zend_long token_decode_ns = 0;
    zend_long text_emit_ns = 0;

    if (state == NULL) {
        return SUCCESS;
    }
    if (!state->initialized
        && king_inference_cuda_decoder_prompt_stream_initialize(model, stream, state) != SUCCESS) {
        king_inference_cuda_decoder_prompt_stream_release(stream);
        return FAILURE;
    }
    if (state->finished) {
        king_inference_cuda_decoder_prompt_stream_release(stream);
        return SUCCESS;
    }
    if (state->decoded_tokens >= (size_t) state->max_tokens || state->stopped) {
        king_inference_cuda_decoder_prompt_stream_finish(model, stream, state);
        king_inference_cuda_decoder_prompt_stream_release(stream);
        return SUCCESS;
    }
    if (state->decoded_tokens > 0) {
        if (state->position >= (zend_ulong) ZEND_LONG_MAX
            || state->decoded_token_id > (zend_ulong) ZEND_LONG_MAX) {
            king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_position_limit_exceeded");
            king_inference_cuda_decoder_prompt_stream_release(stream);
            return FAILURE;
        }
        state->position++;
    }

    source_token_id = state->decoded_token_id;
    total_start_ns = king_inference_cuda_decoder_prompt_profile_ns();
    policy_start_ns = total_start_ns;
    if (king_inference_check_gpu_policy_during_run(stream, "King GPU decoder loop") != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_guardrail_failed");
        king_inference_cuda_decoder_prompt_stream_release(stream);
        return FAILURE;
    }
    policy_ns = king_inference_cuda_decoder_prompt_profile_elapsed(
        king_inference_cuda_decoder_prompt_profile_ns(),
        policy_start_ns
    );
    king_inference_cuda_decoder_prompt_profile_capture(model, &state->graph, &before);
    if (king_inference_cuda_decoder_prompt_loop_execute_decode_template(
            model,
            stream,
            &state->graph,
            &state->mutations,
            source_token_id,
            state->position,
            &state->decoded_token_id,
            &state->decoded_token_text,
            &state->decoded_probability,
            &state->decoded_logit,
            &state->decoded_rank,
            &mutation_ns,
            &device_execute_ns,
            &bounded_emit_ns,
            &sampler_ns,
            &token_decode_ns
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_stream_release(stream);
        return FAILURE;
    }
    text_emit_start_ns = king_inference_cuda_decoder_prompt_profile_ns();
    if (king_inference_cuda_decoder_prompt_loop_emit_decoded_text(
            model,
            stream,
            state->decoded_token_id,
            state->decoded_token_text,
            &state->stops,
            &state->stopped
        ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_stream_release(stream);
        return FAILURE;
    }
    text_emit_ns = king_inference_cuda_decoder_prompt_profile_elapsed(
        king_inference_cuda_decoder_prompt_profile_ns(),
        text_emit_start_ns
    );
    king_inference_cuda_decoder_prompt_profile_capture(model, &state->graph, &after);
    king_inference_cuda_decoder_prompt_profile_record(
        model,
        &before,
        &after,
        king_inference_cuda_decoder_prompt_profile_elapsed(
            king_inference_cuda_decoder_prompt_profile_ns(),
            total_start_ns
        ),
        policy_ns,
        mutation_ns,
        device_execute_ns,
        bounded_emit_ns,
        sampler_ns,
        token_decode_ns,
        text_emit_ns
    );

    state->decoded_tokens++;
    state->result_envelopes++;
    if (state->decoded_tokens >= (size_t) state->max_tokens || state->stopped) {
        king_inference_cuda_decoder_prompt_stream_finish(model, stream, state);
        king_inference_cuda_decoder_prompt_stream_release(stream);
    }
    return SUCCESS;
}
