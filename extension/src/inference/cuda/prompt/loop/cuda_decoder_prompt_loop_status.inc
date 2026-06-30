/*
 * CUDA prompt decoder loop status projection.
 */

static void king_inference_cuda_decoder_prompt_loop_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval loop;
    zval *config_ready = king_inference_array_find(return_value, "config_ready");
    bool runtime_ready = config_ready != NULL && zend_is_true(config_ready);
    bool prompt_generation_ready = runtime_ready && model->cuda_decoder_prompt_loop_available;

    array_init(&loop);
    add_assoc_bool(&loop, "attempted", model->cuda_decoder_prompt_loop_attempted);
    add_assoc_bool(&loop, "available", model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(&loop, "batch_prefill_compiled", true);
    add_assoc_bool(&loop, "batch_prefill_available", model->cuda_decoder_prompt_loop_batch_prefill_available);
    add_assoc_bool(&loop, "batch_prefill_admitted", model->cuda_decoder_prompt_loop_batch_prefill_available);
    add_assoc_string(
        &loop,
        "batch_prefill_admission_reason",
        king_inference_cuda_decoder_prompt_loop_batch_prefill_admission_reason(model)
    );
    add_assoc_bool(&loop, "last_used_batch_prefill", model->cuda_decoder_prompt_loop_last_used_batch_prefill);
    add_assoc_long(
        &loop,
        "batch_prefill_min_prompt_tokens",
        (zend_long) KING_INFERENCE_CUDA_PROMPT_BATCH_PREFILL_MIN_TOKENS
    );
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
    add_assoc_long(&loop, "last_decoded_tokens", (zend_long) model->cuda_decoder_prompt_loop_last_decoded_tokens);
    add_assoc_long(&loop, "last_prefill_batches", (zend_long) model->cuda_decoder_prompt_loop_last_prefill_batches);
    add_assoc_long(&loop, "last_prefill_tokens", (zend_long) model->cuda_decoder_prompt_loop_last_prefill_tokens);
    add_assoc_long(
        &loop,
        "prefill_embedding_bytes",
        (zend_long) model->cuda_decoder_prompt_prefill_embedding_bytes
    );
    add_assoc_long(
        &loop,
        "prefill_initial_norm_bytes",
        (zend_long) model->cuda_decoder_prompt_prefill_initial_norm_bytes
    );
    add_assoc_long(
        &loop,
        "prefill_initial_linear_bytes",
        (zend_long) model->cuda_decoder_prompt_prefill_initial_linear_bytes
    );
    add_assoc_long(
        &loop,
        "prefill_initial_linear_rows",
        (zend_long) model->cuda_decoder_prompt_prefill_initial_linear_rows
    );
    add_assoc_long(
        &loop,
        "prefill_initial_query_rope_bytes",
        (zend_long) model->cuda_decoder_prompt_prefill_initial_query_rope_bytes
    );
    add_assoc_long(
        &loop,
        "prefill_initial_query_rope_heads",
        (zend_long) model->cuda_decoder_prompt_prefill_initial_query_rope_heads
    );
    add_assoc_long(
        &loop,
        "prefill_layer0_attention_stack_bytes",
        (zend_long) model->cuda_decoder_prompt_prefill_layer0_attention_stack_bytes
    );
    add_assoc_long(
        &loop,
        "prefill_layer0_attention_residual_bytes",
        (zend_long) model->cuda_decoder_prompt_prefill_layer0_attention_residual_bytes
    );
    add_assoc_long(&loop, "prefill_kv_heads", (zend_long) model->cuda_decoder_prompt_prefill_kv_heads);
    add_assoc_long(
        &loop,
        "prefill_kv_cache_writes",
        (zend_long) model->cuda_decoder_prompt_prefill_kv_cache_writes
    );
    add_assoc_long(
        &loop,
        "last_prefill_template_reuses",
        (zend_long) model->cuda_decoder_prompt_loop_last_prefill_template_reuses
    );
    add_assoc_bool(
        &loop,
        "last_reuse_profile_available",
        model->cuda_decoder_prompt_loop_last_reuse_profile_available
    );
    add_assoc_long(
        &loop,
        "last_kv_cache_reserve_calls",
        (zend_long) model->cuda_decoder_prompt_loop_last_kv_cache_reserve_calls
    );
    add_assoc_long(
        &loop,
        "last_kv_cache_logical_allocations",
        (zend_long) model->cuda_decoder_prompt_loop_last_kv_cache_allocations
    );
    add_assoc_long(
        &loop,
        "last_request_graph_constructions",
        (zend_long) model->cuda_decoder_prompt_loop_last_graph_constructions
    );
    add_assoc_long(
        &loop,
        "last_request_execution_plan_constructions",
        (zend_long) model->cuda_decoder_prompt_loop_last_plan_constructions
    );
    add_assoc_long(
        &loop,
        "last_policy_guard_calls",
        (zend_long) model->cuda_decoder_prompt_loop_last_policy_checks
    );
    add_assoc_long(
        &loop,
        "last_policy_guard_interval_skips",
        (zend_long) model->cuda_decoder_prompt_loop_last_policy_interval_skips
    );
    add_assoc_long(
        &loop,
        "last_thermal_runtime_sensor_reads",
        (zend_long) model->cuda_decoder_prompt_loop_last_thermal_runtime_checks
    );
    add_assoc_long(
        &loop,
        "last_power_runtime_sensor_reads",
        (zend_long) model->cuda_decoder_prompt_loop_last_power_runtime_checks
    );
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
    add_assoc_bool(return_value, "plain_text_chat_ready", prompt_generation_ready);
    add_assoc_bool(return_value, "decoder_kernel_ready", model->cuda_decoder_prompt_loop_available);
    add_assoc_bool(return_value, "generation_ready", prompt_generation_ready);
    add_assoc_bool(return_value, "token_generation", prompt_generation_ready);
    add_assoc_bool(return_value, "openai_generation", prompt_generation_ready);
    add_assoc_bool(return_value, "gpu_plain_text_chat_generation", prompt_generation_ready);
}
