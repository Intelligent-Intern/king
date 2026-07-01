/*
 * CUDA prompt decoder hot-token profiling.
 */

typedef struct _king_inference_cuda_decoder_prompt_profile_snapshot {
    size_t embedding_launches;
    size_t matvec_launches;
    size_t rms_norm_launches;
    size_t rope_launches;
    size_t rope_syncs;
    size_t attention_launches;
    size_t vector_launches;
    size_t ffn_launches;
    size_t projection_launches;
    size_t readback_launches;
    size_t readback_syncs;
    size_t dtoh_copies;
    size_t allocator_acquisitions;
    size_t graph_validations;
    size_t graph_constructions;
    size_t execution_plans;
    size_t tensor_lookups;
    size_t op_index_lookups;
    size_t op_index_hits;
    size_t op_index_fallback_scans;
    size_t readback_candidates;
    size_t readback_bytes;
    size_t full_logits_bytes;
    size_t saved_readback_bytes;
    zend_long readback_ns;
} king_inference_cuda_decoder_prompt_profile_snapshot;

static uint64_t king_inference_cuda_decoder_prompt_profile_ns(void)
{
    struct timespec ts;

    if (clock_gettime(CLOCK_MONOTONIC, &ts) != 0) {
        return (uint64_t) time(NULL) * 1000000000ULL;
    }
    return ((uint64_t) ts.tv_sec * 1000000000ULL) + (uint64_t) ts.tv_nsec;
}

static size_t king_inference_cuda_decoder_prompt_profile_graph_counter(zval *graph, const char *key)
{
    zval *value;

    if (graph == NULL || Z_TYPE_P(graph) != IS_ARRAY || key == NULL) {
        return 0;
    }
    value = king_inference_array_find(graph, key);
    return value != NULL && Z_TYPE_P(value) == IS_LONG && Z_LVAL_P(value) >= 0
        ? (size_t) Z_LVAL_P(value)
        : 0;
}

static void king_inference_cuda_decoder_prompt_profile_capture(
    king_inference_model_object *model,
    zval *graph,
    king_inference_cuda_decoder_prompt_profile_snapshot *snapshot
) {
    snapshot->embedding_launches =
        model->cuda_embedding_row_launch_count + model->cuda_embedding_rows_launch_count;
    snapshot->matvec_launches =
        model->cuda_quantized_matvec_launch_count + model->cuda_quantized_matvec_batch_launch_count;
    snapshot->rms_norm_launches =
        model->cuda_rms_norm_launch_count + model->cuda_rms_norm_batch_launch_count;
    snapshot->rope_launches = model->cuda_rope_launch_count + model->cuda_rope_batch_launch_count;
    snapshot->rope_syncs = model->cuda_rope_sync_count + model->cuda_rope_batch_sync_count;
    snapshot->attention_launches =
        model->cuda_attention_scores_launch_count
        + model->cuda_attention_scores_batch_launch_count
        + model->cuda_attention_softmax_launch_count
        + model->cuda_attention_softmax_batch_launch_count
        + model->cuda_attention_values_launch_count
        + model->cuda_attention_values_batch_launch_count;
    snapshot->vector_launches = model->cuda_device_vector_ops_launch_count;
    snapshot->ffn_launches = model->cuda_ffn_swiglu_launch_count;
    snapshot->projection_launches = model->cuda_output_projection_launch_count;
    snapshot->readback_launches = model->cuda_logits_readback_launch_count;
    snapshot->readback_syncs = model->cuda_logits_readback_sync_count;
    snapshot->dtoh_copies = model->cuda_logits_readback_dtoh_count;
    snapshot->allocator_acquisitions = model->cuda_device_allocation_count;
    snapshot->graph_validations = model->cuda_decoder_graph_executor_graph_count;
    snapshot->graph_constructions = 0;
    snapshot->execution_plans = model->cuda_decoder_graph_executor_plan_count;
    snapshot->tensor_lookups = model->cuda_decoder_graph_executor_tensor_lookup_count;
    snapshot->op_index_lookups = king_inference_cuda_decoder_prompt_profile_graph_counter(
        graph,
        "_cuda_profile_op_index_lookups"
    );
    snapshot->op_index_hits = king_inference_cuda_decoder_prompt_profile_graph_counter(
        graph,
        "_cuda_profile_op_index_hits"
    );
    snapshot->op_index_fallback_scans = king_inference_cuda_decoder_prompt_profile_graph_counter(
        graph,
        "_cuda_profile_op_index_fallback_scans"
    );
    snapshot->readback_candidates = model->cuda_logits_readback_last_candidate_count;
    snapshot->readback_bytes = model->cuda_logits_readback_last_bytes;
    snapshot->full_logits_bytes = model->cuda_logits_readback_full_bytes;
    snapshot->saved_readback_bytes = model->cuda_logits_readback_saved_bytes;
    snapshot->readback_ns = model->cuda_logits_readback_last_ns;
}

static size_t king_inference_cuda_decoder_prompt_profile_delta(size_t after, size_t before)
{
    return after >= before ? after - before : 0;
}

static zend_long king_inference_cuda_decoder_prompt_profile_elapsed(uint64_t end, uint64_t start)
{
    return end >= start && end - start <= (uint64_t) ZEND_LONG_MAX
        ? (zend_long) (end - start)
        : 0;
}

static void king_inference_cuda_decoder_prompt_loop_reset_hot_token_profile(
    king_inference_model_object *model
) {
    model->cuda_decoder_prompt_loop_last_hot_token_total_ns = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_policy_ns = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_mutation_ns = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_device_execute_ns = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_bounded_emit_ns = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_logits_readback_ns = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_sampler_ns = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_token_decode_ns = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_text_emit_ns = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_event_shape_ns = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_launches = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_syncs = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_dtoh_copies = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_allocator_acquisitions = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_graph_validations = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_graph_constructions = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_execution_plans = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_tensor_lookups = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_op_index_lookups = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_op_index_hits = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_op_index_fallback_scans = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_embedding_launches = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_matvec_launches = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_rms_norm_launches = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_rope_launches = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_attention_launches = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_vector_launches = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_ffn_launches = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_projection_launches = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_readback_launches = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_readback_candidates = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_readback_bytes = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_full_logits_bytes = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_saved_readback_bytes = 0;
    model->cuda_decoder_prompt_loop_last_readback_tokens = 0;
    model->cuda_decoder_prompt_loop_last_readback_bytes = 0;
    model->cuda_decoder_prompt_loop_last_full_logits_bytes = 0;
    model->cuda_decoder_prompt_loop_last_saved_readback_bytes = 0;
    model->cuda_decoder_prompt_loop_last_hot_token_full_logits_readback_avoided = false;
    model->cuda_decoder_prompt_loop_last_hot_token_profile_available = false;
}

static void king_inference_cuda_decoder_prompt_profile_record(
    king_inference_model_object *model,
    king_inference_cuda_decoder_prompt_profile_snapshot *before,
    king_inference_cuda_decoder_prompt_profile_snapshot *after,
    zend_long total_ns,
    zend_long policy_ns,
    zend_long mutation_ns,
    zend_long device_execute_ns,
    zend_long bounded_emit_ns,
    zend_long sampler_ns,
    zend_long token_decode_ns,
    zend_long text_emit_ns
) {
    size_t syncs;

    model->cuda_decoder_prompt_loop_last_hot_token_embedding_launches =
        king_inference_cuda_decoder_prompt_profile_delta(after->embedding_launches, before->embedding_launches);
    model->cuda_decoder_prompt_loop_last_hot_token_matvec_launches =
        king_inference_cuda_decoder_prompt_profile_delta(after->matvec_launches, before->matvec_launches);
    model->cuda_decoder_prompt_loop_last_hot_token_rms_norm_launches =
        king_inference_cuda_decoder_prompt_profile_delta(after->rms_norm_launches, before->rms_norm_launches);
    model->cuda_decoder_prompt_loop_last_hot_token_rope_launches =
        king_inference_cuda_decoder_prompt_profile_delta(after->rope_launches, before->rope_launches);
    model->cuda_decoder_prompt_loop_last_hot_token_attention_launches =
        king_inference_cuda_decoder_prompt_profile_delta(after->attention_launches, before->attention_launches);
    model->cuda_decoder_prompt_loop_last_hot_token_vector_launches =
        king_inference_cuda_decoder_prompt_profile_delta(after->vector_launches, before->vector_launches);
    model->cuda_decoder_prompt_loop_last_hot_token_ffn_launches =
        king_inference_cuda_decoder_prompt_profile_delta(after->ffn_launches, before->ffn_launches);
    model->cuda_decoder_prompt_loop_last_hot_token_projection_launches =
        king_inference_cuda_decoder_prompt_profile_delta(after->projection_launches, before->projection_launches);
    model->cuda_decoder_prompt_loop_last_hot_token_readback_launches =
        king_inference_cuda_decoder_prompt_profile_delta(after->readback_launches, before->readback_launches);
    model->cuda_decoder_prompt_loop_last_hot_token_launches =
        model->cuda_decoder_prompt_loop_last_hot_token_embedding_launches
        + model->cuda_decoder_prompt_loop_last_hot_token_matvec_launches
        + model->cuda_decoder_prompt_loop_last_hot_token_rms_norm_launches
        + model->cuda_decoder_prompt_loop_last_hot_token_rope_launches
        + model->cuda_decoder_prompt_loop_last_hot_token_attention_launches
        + model->cuda_decoder_prompt_loop_last_hot_token_vector_launches
        + model->cuda_decoder_prompt_loop_last_hot_token_ffn_launches
        + model->cuda_decoder_prompt_loop_last_hot_token_projection_launches
        + model->cuda_decoder_prompt_loop_last_hot_token_readback_launches;
    syncs = king_inference_cuda_decoder_prompt_profile_delta(after->rope_syncs, before->rope_syncs)
        + king_inference_cuda_decoder_prompt_profile_delta(after->readback_syncs, before->readback_syncs);
    model->cuda_decoder_prompt_loop_last_hot_token_syncs = syncs;
    model->cuda_decoder_prompt_loop_last_hot_token_dtoh_copies =
        king_inference_cuda_decoder_prompt_profile_delta(after->dtoh_copies, before->dtoh_copies);
    model->cuda_decoder_prompt_loop_last_hot_token_allocator_acquisitions =
        king_inference_cuda_decoder_prompt_profile_delta(after->allocator_acquisitions, before->allocator_acquisitions);
    model->cuda_decoder_prompt_loop_last_hot_token_graph_validations =
        king_inference_cuda_decoder_prompt_profile_delta(after->graph_validations, before->graph_validations);
    model->cuda_decoder_prompt_loop_last_hot_token_graph_constructions =
        king_inference_cuda_decoder_prompt_profile_delta(after->graph_constructions, before->graph_constructions);
    model->cuda_decoder_prompt_loop_last_hot_token_execution_plans =
        king_inference_cuda_decoder_prompt_profile_delta(after->execution_plans, before->execution_plans);
    model->cuda_decoder_prompt_loop_last_hot_token_tensor_lookups =
        king_inference_cuda_decoder_prompt_profile_delta(after->tensor_lookups, before->tensor_lookups);
    model->cuda_decoder_prompt_loop_last_hot_token_op_index_lookups =
        king_inference_cuda_decoder_prompt_profile_delta(after->op_index_lookups, before->op_index_lookups);
    model->cuda_decoder_prompt_loop_last_hot_token_op_index_hits =
        king_inference_cuda_decoder_prompt_profile_delta(after->op_index_hits, before->op_index_hits);
    model->cuda_decoder_prompt_loop_last_hot_token_op_index_fallback_scans =
        king_inference_cuda_decoder_prompt_profile_delta(
            after->op_index_fallback_scans,
            before->op_index_fallback_scans
        );
    model->cuda_decoder_prompt_loop_last_hot_token_total_ns = total_ns;
    model->cuda_decoder_prompt_loop_last_hot_token_policy_ns = policy_ns;
    model->cuda_decoder_prompt_loop_last_hot_token_mutation_ns = mutation_ns;
    model->cuda_decoder_prompt_loop_last_hot_token_device_execute_ns = device_execute_ns;
    model->cuda_decoder_prompt_loop_last_hot_token_bounded_emit_ns = bounded_emit_ns;
    model->cuda_decoder_prompt_loop_last_hot_token_logits_readback_ns = after->readback_ns;
    model->cuda_decoder_prompt_loop_last_hot_token_sampler_ns = sampler_ns;
    model->cuda_decoder_prompt_loop_last_hot_token_token_decode_ns = token_decode_ns;
    model->cuda_decoder_prompt_loop_last_hot_token_text_emit_ns = text_emit_ns;
    model->cuda_decoder_prompt_loop_last_hot_token_event_shape_ns = text_emit_ns;
    model->cuda_decoder_prompt_loop_last_hot_token_readback_candidates = after->readback_candidates;
    model->cuda_decoder_prompt_loop_last_hot_token_readback_bytes = after->readback_bytes;
    model->cuda_decoder_prompt_loop_last_hot_token_full_logits_bytes = after->full_logits_bytes;
    model->cuda_decoder_prompt_loop_last_hot_token_saved_readback_bytes = after->saved_readback_bytes;
    if (after->readback_bytes > 0 || after->full_logits_bytes > 0) {
        model->cuda_decoder_prompt_loop_last_readback_tokens++;
        model->cuda_decoder_prompt_loop_last_readback_bytes += after->readback_bytes;
        model->cuda_decoder_prompt_loop_last_full_logits_bytes += after->full_logits_bytes;
        model->cuda_decoder_prompt_loop_last_saved_readback_bytes += after->saved_readback_bytes;
    }
    model->cuda_decoder_prompt_loop_last_hot_token_full_logits_readback_avoided =
        after->full_logits_bytes > 0 && after->readback_bytes < after->full_logits_bytes;
    model->cuda_decoder_prompt_loop_last_hot_token_profile_available = true;
}

static void king_inference_cuda_decoder_prompt_profile_add_status(
    zval *return_value,
    king_inference_model_object *model
) {
    zval profile;
    zval launches;
    zval timings;
    zval graph;
    zval readback;
    bool tokenizer_dominates = model->cuda_decoder_prompt_loop_last_hot_token_token_decode_ns > 0
        && model->cuda_decoder_prompt_loop_last_hot_token_total_ns > 0
        && model->cuda_decoder_prompt_loop_last_hot_token_token_decode_ns
            > model->cuda_decoder_prompt_loop_last_hot_token_total_ns / 2;

    array_init(&profile);
    add_assoc_bool(&profile, "available", model->cuda_decoder_prompt_loop_last_hot_token_profile_available);
    add_assoc_long(&profile, "cuda_launches", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_launches);
    add_assoc_long(&profile, "host_device_syncs", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_syncs);
    add_assoc_long(&profile, "device_to_host_copies", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_dtoh_copies);
    add_assoc_long(&profile, "allocator_acquisitions", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_allocator_acquisitions);
    add_assoc_string(&profile, "sync_sources", "none_on_bounded_logits_readback_hotpath");
    add_assoc_string(&profile, "deferred_sync_sources", "rope_default_stream_ordering");
    add_assoc_string(&profile, "dtoh_sources", "blocking_bounded_top_k_indices_and_logits");

    array_init(&launches);
    add_assoc_long(&launches, "embedding", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_embedding_launches);
    add_assoc_long(&launches, "matvec", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_matvec_launches);
    add_assoc_long(&launches, "rms_norm", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_rms_norm_launches);
    add_assoc_long(&launches, "rope", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_rope_launches);
    add_assoc_long(&launches, "attention", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_attention_launches);
    add_assoc_long(&launches, "vector", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_vector_launches);
    add_assoc_long(&launches, "ffn", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_ffn_launches);
    add_assoc_long(&launches, "projection", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_projection_launches);
    add_assoc_long(&launches, "readback", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_readback_launches);
    add_assoc_zval(&profile, "launch_breakdown", &launches);

    array_init(&timings);
    add_assoc_long(&timings, "total_ns", model->cuda_decoder_prompt_loop_last_hot_token_total_ns);
    add_assoc_long(&timings, "policy_guard_ns", model->cuda_decoder_prompt_loop_last_hot_token_policy_ns);
    add_assoc_long(&timings, "graph_mutation_ns", model->cuda_decoder_prompt_loop_last_hot_token_mutation_ns);
    add_assoc_long(&timings, "device_execute_ns", model->cuda_decoder_prompt_loop_last_hot_token_device_execute_ns);
    add_assoc_long(&timings, "bounded_sampler_and_token_decode_ns", model->cuda_decoder_prompt_loop_last_hot_token_bounded_emit_ns);
    add_assoc_long(&timings, "bounded_logits_top_k_readback_ns", model->cuda_decoder_prompt_loop_last_hot_token_logits_readback_ns);
    add_assoc_long(&timings, "cpu_sampler_ns", model->cuda_decoder_prompt_loop_last_hot_token_sampler_ns);
    add_assoc_long(&timings, "tokenizer_piece_decode_ns", model->cuda_decoder_prompt_loop_last_hot_token_token_decode_ns);
    add_assoc_long(&timings, "native_event_text_emit_ns", model->cuda_decoder_prompt_loop_last_hot_token_text_emit_ns);
    add_assoc_long(&timings, "http_event_shaping_visible_ns", model->cuda_decoder_prompt_loop_last_hot_token_event_shape_ns);
    add_assoc_zval(&profile, "timings", &timings);

    array_init(&readback);
    add_assoc_bool(&readback, "bounded_top_k_only", true);
    add_assoc_long(&readback, "max_candidates", (zend_long) model->cuda_logits_readback_candidate_limit);
    add_assoc_long(
        &readback,
        "candidate_count",
        (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_readback_candidates
    );
    add_assoc_long(
        &readback,
        "readback_bytes",
        (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_readback_bytes
    );
    add_assoc_long(
        &readback,
        "full_logits_bytes",
        (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_full_logits_bytes
    );
    add_assoc_long(
        &readback,
        "saved_readback_bytes",
        (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_saved_readback_bytes
    );
    add_assoc_long(
        &readback,
        "request_readback_tokens",
        (zend_long) model->cuda_decoder_prompt_loop_last_readback_tokens
    );
    add_assoc_long(
        &readback,
        "request_readback_bytes",
        (zend_long) model->cuda_decoder_prompt_loop_last_readback_bytes
    );
    add_assoc_long(
        &readback,
        "request_full_logits_bytes",
        (zend_long) model->cuda_decoder_prompt_loop_last_full_logits_bytes
    );
    add_assoc_long(
        &readback,
        "request_saved_readback_bytes",
        (zend_long) model->cuda_decoder_prompt_loop_last_saved_readback_bytes
    );
    add_assoc_double(
        &readback,
        "readback_bytes_per_token",
        model->cuda_decoder_prompt_loop_last_readback_tokens > 0
            ? (double) model->cuda_decoder_prompt_loop_last_readback_bytes
                / (double) model->cuda_decoder_prompt_loop_last_readback_tokens
            : 0.0
    );
    add_assoc_double(
        &readback,
        "saved_readback_bytes_per_token",
        model->cuda_decoder_prompt_loop_last_readback_tokens > 0
            ? (double) model->cuda_decoder_prompt_loop_last_saved_readback_bytes
                / (double) model->cuda_decoder_prompt_loop_last_readback_tokens
            : 0.0
    );
    add_assoc_double(
        &readback,
        "readback_savings_ratio",
        model->cuda_decoder_prompt_loop_last_full_logits_bytes > 0
            ? (double) model->cuda_decoder_prompt_loop_last_saved_readback_bytes
                / (double) model->cuda_decoder_prompt_loop_last_full_logits_bytes
            : 0.0
    );
    add_assoc_bool(
        &readback,
        "full_logits_cpu_readback_avoided",
        model->cuda_decoder_prompt_loop_last_hot_token_full_logits_readback_avoided
    );
    add_assoc_bool(&readback, "tokenizer_piece_decode_dominates_hot_token", tokenizer_dominates);
    add_assoc_zval(&profile, "bounded_logits_readback", &readback);

    array_init(&graph);
    add_assoc_long(&graph, "graph_validations", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_graph_validations);
    add_assoc_long(&graph, "graph_constructions", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_graph_constructions);
    add_assoc_long(&graph, "execution_plans", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_execution_plans);
    add_assoc_long(&graph, "tensor_descriptor_lookups", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_tensor_lookups);
    add_assoc_long(&graph, "op_index_lookups", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_op_index_lookups);
    add_assoc_long(&graph, "op_index_hits", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_op_index_hits);
    add_assoc_long(&graph, "op_index_fallback_scans", (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_op_index_fallback_scans);
    add_assoc_bool(&graph, "decode_graph_constructed_on_hot_path", false);
    add_assoc_bool(&graph, "decode_graph_template_reused", true);
    add_assoc_bool(
        &graph,
        "persistent_decode_plan_index_reused",
        model->cuda_decoder_prompt_loop_last_hot_token_graph_constructions == 0
            && model->cuda_decoder_prompt_loop_last_hot_token_execution_plans == 0
    );
    add_assoc_bool(
        &graph,
        "dynamic_op_scan_eliminated",
        model->cuda_decoder_prompt_loop_last_hot_token_op_index_fallback_scans == 0
    );
    add_assoc_zval(&profile, "graph_and_lookup", &graph);

    add_assoc_zval(return_value, "last_hot_token_profile", &profile);
}
