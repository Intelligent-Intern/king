static zend_long king_inference_stream_runtime_duration_ms(king_inference_stream_object *stream)
{
    zend_long finished_ms;
    zend_long duration_ms;

    if (stream->runtime_started_ms <= 0) {
        return 0;
    }

    finished_ms = stream->runtime_finished_ms > 0
        ? stream->runtime_finished_ms
        : king_inference_runtime_monotonic_ms();
    duration_ms = finished_ms - stream->runtime_started_ms;
    return duration_ms > 0 ? duration_ms : 0;
}

static double king_inference_stream_rate_per_second(zend_long units, zend_long duration_ms)
{
    if (units <= 0 || duration_ms <= 0) {
        return 0.0;
    }

    return ((double) units * 1000.0) / (double) duration_ms;
}

static double king_inference_stream_rate_per_second_ns(zend_long units, zend_long duration_ns)
{
    if (units <= 0 || duration_ns <= 0) {
        return 0.0;
    }

    return ((double) units * 1000000000.0) / (double) duration_ns;
}

static king_inference_model_object *king_inference_stream_metric_model(king_inference_stream_object *stream)
{
    if (Z_ISUNDEF(stream->model) || Z_TYPE(stream->model) != IS_OBJECT) {
        return NULL;
    }

    return php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
}

static void king_inference_stream_add_hotpath_metrics(
    king_inference_stream_object *stream,
    zval *return_value
) {
    king_inference_model_object *model = king_inference_stream_metric_model(stream);
    zend_long duration_ms = king_inference_stream_runtime_duration_ms(stream);
    zend_long generated_tokens = stream->runtime_generated_tokens;
    zend_long first_token_ms = stream->runtime_first_token_ms > 0 && stream->runtime_started_ms > 0
        ? stream->runtime_first_token_ms - stream->runtime_started_ms
        : 0;
    zend_long prefill_tokens = 0;
    zend_long prefill_ns = 0;
    bool gpu_profile_available = false;
    zval hotpath;
    zval gpu;
    zval readback;

    if (model != NULL) {
        if (model->runtime_last_generated_tokens > generated_tokens) {
            generated_tokens = model->runtime_last_generated_tokens;
        }
        prefill_tokens = (zend_long) model->cuda_decoder_prompt_loop_last_prefill_tokens;
        prefill_ns = model->cuda_decoder_prompt_loop_last_prefill_ns;
        gpu_profile_available = model->cuda_decoder_prompt_loop_last_hot_token_profile_available;
    }

    add_assoc_bool(return_value, "runtime_timing_available", stream->runtime_started_ms > 0);
    add_assoc_bool(return_value, "runtime_first_token_available", stream->runtime_first_token_ms > 0);
    add_assoc_long(return_value, "runtime_started_ms", stream->runtime_started_ms);
    add_assoc_long(return_value, "runtime_first_token_ms", stream->runtime_first_token_ms);
    add_assoc_long(return_value, "runtime_finished_ms", stream->runtime_finished_ms);
    add_assoc_long(return_value, "runtime_duration_ms", duration_ms);
    add_assoc_long(return_value, "runtime_time_to_first_token_ms", first_token_ms);
    add_assoc_long(return_value, "runtime_generated_tokens", generated_tokens);
    add_assoc_double(
        return_value,
        "runtime_tokens_per_second",
        king_inference_stream_rate_per_second(generated_tokens, duration_ms)
    );

    array_init(&hotpath);
    add_assoc_bool(&hotpath, "available", stream->runtime_started_ms > 0 || gpu_profile_available);
    add_assoc_long(&hotpath, "time_to_first_token_ms", first_token_ms);
    add_assoc_long(&hotpath, "duration_ms", duration_ms);
    add_assoc_long(&hotpath, "generated_tokens", generated_tokens);
    add_assoc_double(
        &hotpath,
        "tokens_per_second",
        king_inference_stream_rate_per_second(generated_tokens, duration_ms)
    );
    add_assoc_long(&hotpath, "prefill_tokens", prefill_tokens);
    add_assoc_long(&hotpath, "prefill_duration_ns", prefill_ns);
    add_assoc_double(
        &hotpath,
        "prefill_tokens_per_second",
        king_inference_stream_rate_per_second_ns(prefill_tokens, prefill_ns)
    );

    array_init(&gpu);
    add_assoc_bool(&gpu, "available", gpu_profile_available);
    add_assoc_long(
        &gpu,
        "kernel_launches",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_launches : 0
    );
    add_assoc_long(
        &gpu,
        "host_device_syncs",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_syncs : 0
    );
    add_assoc_long(
        &gpu,
        "device_to_host_copies",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_dtoh_copies : 0
    );

    array_init(&readback);
    add_assoc_long(
        &readback,
        "candidates",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_readback_candidates : 0
    );
    add_assoc_long(
        &readback,
        "bytes",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_readback_bytes : 0
    );
    add_assoc_long(
        &readback,
        "full_logits_bytes",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_full_logits_bytes : 0
    );
    add_assoc_long(
        &readback,
        "saved_bytes",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_hot_token_saved_readback_bytes : 0
    );
    add_assoc_long(
        &readback,
        "request_tokens",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_readback_tokens : 0
    );
    add_assoc_long(
        &readback,
        "request_bytes",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_readback_bytes : 0
    );
    add_assoc_long(
        &readback,
        "request_full_logits_bytes",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_full_logits_bytes : 0
    );
    add_assoc_long(
        &readback,
        "request_saved_bytes",
        model != NULL ? (zend_long) model->cuda_decoder_prompt_loop_last_saved_readback_bytes : 0
    );
    add_assoc_bool(
        &readback,
        "resident_device_buffers",
        model != NULL
            && model->cuda_logits_readback_device_indices != 0
            && model->cuda_logits_readback_device_logits != 0
    );
    add_assoc_long(
        &readback,
        "resident_buffer_reuses",
        model != NULL ? (zend_long) model->cuda_logits_readback_buffer_reuse_count : 0
    );
    add_assoc_bool(&readback, "explicit_pre_readback_sync_removed", true);
    add_assoc_double(
        &readback,
        "bytes_per_token",
        model != NULL && model->cuda_decoder_prompt_loop_last_readback_tokens > 0
            ? (double) model->cuda_decoder_prompt_loop_last_readback_bytes
                / (double) model->cuda_decoder_prompt_loop_last_readback_tokens
            : 0.0
    );
    add_assoc_double(
        &readback,
        "saved_bytes_per_token",
        model != NULL && model->cuda_decoder_prompt_loop_last_readback_tokens > 0
            ? (double) model->cuda_decoder_prompt_loop_last_saved_readback_bytes
                / (double) model->cuda_decoder_prompt_loop_last_readback_tokens
            : 0.0
    );
    add_assoc_double(
        &readback,
        "savings_ratio",
        model != NULL && model->cuda_decoder_prompt_loop_last_full_logits_bytes > 0
            ? (double) model->cuda_decoder_prompt_loop_last_saved_readback_bytes
                / (double) model->cuda_decoder_prompt_loop_last_full_logits_bytes
            : 0.0
    );
    add_assoc_bool(
        &readback,
        "full_logits_readback_avoided",
        model != NULL && model->cuda_decoder_prompt_loop_last_hot_token_full_logits_readback_avoided
    );
    add_assoc_zval(&gpu, "bounded_readback", &readback);
    add_assoc_zval(&hotpath, "gpu", &gpu);
    add_assoc_zval(return_value, "runtime_hot_path", &hotpath);
}
