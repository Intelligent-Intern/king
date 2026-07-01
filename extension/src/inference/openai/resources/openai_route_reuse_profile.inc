/*
 * OpenAI route reuse profile projection for native King GPU inference.
 */

static void king_inference_openai_route_reuse_profile_add_status(
    zval *return_value,
    king_inference_model_object *model,
    bool gpu_backend
) {
    zval reuse;
    bool available = gpu_backend
        && model->cuda_decoder_prompt_loop_last_reuse_profile_available;
    bool hot_token_profile = gpu_backend
        && model->cuda_decoder_prompt_loop_last_hot_token_profile_available;
    bool hot_path_reused = hot_token_profile
        && model->cuda_decoder_prompt_loop_last_hot_token_graph_constructions == 0
        && model->cuda_decoder_prompt_loop_last_hot_token_execution_plans == 0;
    bool kv_reused = available
        && model->cuda_device_kv_cache_allocated
        && model->cuda_decoder_prompt_loop_last_kv_cache_allocations <= 1;
    bool interval_gated = available
        && model->cuda_decoder_prompt_loop_last_policy_checks > 0
        && model->cuda_decoder_prompt_loop_last_policy_interval_skips > 0;

    array_init(&reuse);
    add_assoc_bool(&reuse, "available", available);
    if (!available) {
        add_assoc_string(
            &reuse,
            "reason",
            gpu_backend ? "no_completed_gpu_prompt_request_observed" : "non_gpu_backend"
        );
        add_assoc_zval(return_value, "last_request_reuse_profile", &reuse);
        return;
    }

    add_assoc_long(&reuse, "prompt_tokens", (zend_long) model->cuda_decoder_prompt_loop_last_prompt_tokens);
    add_assoc_long(&reuse, "generated_tokens", (zend_long) model->cuda_decoder_prompt_loop_last_decoded_tokens);
    add_assoc_long(&reuse, "accepted_max_tokens", model->cuda_decoder_prompt_loop_last_max_tokens);
    add_assoc_string(&reuse, "kv_cache_scope", "model_resident_lazy_first_use");
    add_assoc_bool(&reuse, "kv_cache_allocated", model->cuda_device_kv_cache_allocated);
    add_assoc_bool(&reuse, "kv_cache_reused_across_generated_tokens", kv_reused);
    add_assoc_bool(
        &reuse,
        "kv_cache_reallocated_per_generated_token",
        model->cuda_decoder_prompt_loop_last_decoded_tokens > 0
            && model->cuda_decoder_prompt_loop_last_kv_cache_allocations
                > model->cuda_decoder_prompt_loop_last_decoded_tokens
    );
    add_assoc_long(
        &reuse,
        "kv_cache_reserve_calls",
        (zend_long) model->cuda_decoder_prompt_loop_last_kv_cache_reserve_calls
    );
    add_assoc_long(
        &reuse,
        "kv_cache_logical_allocations",
        (zend_long) model->cuda_decoder_prompt_loop_last_kv_cache_allocations
    );
    add_assoc_long(
        &reuse,
        "kv_cache_writes",
        (zend_long) model->cuda_decoder_prompt_loop_last_kv_cache_writes
    );
    add_assoc_long(
        &reuse,
        "kv_cache_batch_writes",
        (zend_long) model->cuda_decoder_prompt_loop_last_kv_cache_batch_writes
    );
    add_assoc_long(
        &reuse,
        "prefill_batches",
        (zend_long) model->cuda_decoder_prompt_loop_last_prefill_batches
    );
    add_assoc_long(
        &reuse,
        "prefill_tokens",
        (zend_long) model->cuda_decoder_prompt_loop_last_prefill_tokens
    );
    add_assoc_long(
        &reuse,
        "prefill_template_reuses",
        (zend_long) model->cuda_decoder_prompt_loop_last_prefill_template_reuses
    );
    add_assoc_bool(&reuse, "prefill_runs_once_before_decode", true);
    add_assoc_string(&reuse, "decode_context_advance_mode", "resident_kv_cache_plus_mutated_decode_template");
    add_assoc_long(
        &reuse,
        "request_graph_constructions",
        (zend_long) model->cuda_decoder_prompt_loop_last_graph_constructions
    );
    add_assoc_long(
        &reuse,
        "request_execution_plan_constructions",
        (zend_long) model->cuda_decoder_prompt_loop_last_plan_constructions
    );
    add_assoc_bool(&reuse, "decode_graph_template_reused", hot_path_reused);
    add_assoc_bool(&reuse, "persistent_decode_plan_index_reused", hot_path_reused);
    add_assoc_long(
        &reuse,
        "hot_token_dynamic_op_scans",
        hot_token_profile
            ? (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_op_index_fallback_scans
            : 0
    );
    add_assoc_bool(
        &reuse,
        "hot_token_dynamic_op_scans_eliminated",
        hot_token_profile && model->cuda_decoder_prompt_loop_last_hot_token_op_index_fallback_scans == 0
    );
    add_assoc_bool(
        &reuse,
        "decode_graph_constructed_on_hot_path",
        hot_token_profile && model->cuda_decoder_prompt_loop_last_hot_token_graph_constructions > 0
    );
    add_assoc_bool(
        &reuse,
        "execution_plan_constructed_on_hot_path",
        hot_token_profile && model->cuda_decoder_prompt_loop_last_hot_token_execution_plans > 0
    );
    add_assoc_bool(&reuse, "prompt_recomputed_after_each_generated_token", !hot_path_reused);
    add_assoc_long(
        &reuse,
        "policy_guard_calls",
        (zend_long) model->cuda_decoder_prompt_loop_last_policy_checks
    );
    add_assoc_long(
        &reuse,
        "policy_guard_interval_skips",
        (zend_long) model->cuda_decoder_prompt_loop_last_policy_interval_skips
    );
    add_assoc_long(
        &reuse,
        "thermal_runtime_sensor_reads",
        (zend_long) model->cuda_decoder_prompt_loop_last_thermal_runtime_checks
    );
    add_assoc_long(
        &reuse,
        "power_runtime_sensor_reads",
        (zend_long) model->cuda_decoder_prompt_loop_last_power_runtime_checks
    );
    add_assoc_long(
        &reuse,
        "thermal_check_interval_seconds",
        model->cuda_decoder_prompt_loop_last_thermal_interval_seconds
    );
    add_assoc_long(
        &reuse,
        "power_check_interval_seconds",
        model->cuda_decoder_prompt_loop_last_power_interval_seconds
    );
    add_assoc_bool(&reuse, "thermal_and_power_checks_interval_gated", interval_gated);
    add_assoc_zval(return_value, "last_request_reuse_profile", &reuse);
}
