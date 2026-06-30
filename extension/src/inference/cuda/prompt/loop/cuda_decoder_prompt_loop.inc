/*
 * CUDA prompt decoder loop contract for native King GPU inference.
 */

#define KING_INFERENCE_CUDA_PROMPT_BATCH_PREFILL_MIN_TOKENS 16

#include "cuda_decoder_prompt_profile.inc"

static void king_inference_cuda_decoder_prompt_loop_reset_fields(king_inference_model_object *model)
{
    model->cuda_decoder_prompt_loop_result = 0;
    model->cuda_decoder_prompt_loop_error[0] = '\0';
    model->cuda_decoder_prompt_loop_count = 0;
    model->cuda_decoder_prompt_loop_last_prompt_tokens = 0;
    model->cuda_decoder_prompt_loop_last_validated_graphs = 0;
    model->cuda_decoder_prompt_loop_last_result_envelopes = 0;
    model->cuda_decoder_prompt_loop_last_prefill_batches = 0;
    model->cuda_decoder_prompt_loop_last_prefill_tokens = 0;
    model->cuda_decoder_prompt_loop_last_prefill_template_reuses = 0;
    model->cuda_decoder_prompt_loop_last_decoded_tokens = 0;
    model->cuda_decoder_prompt_loop_last_kv_cache_reserve_calls = 0;
    model->cuda_decoder_prompt_loop_last_kv_cache_allocations = 0;
    model->cuda_decoder_prompt_loop_last_kv_cache_writes = 0;
    model->cuda_decoder_prompt_loop_last_kv_cache_batch_writes = 0;
    model->cuda_decoder_prompt_loop_last_graph_constructions = 0;
    model->cuda_decoder_prompt_loop_last_plan_constructions = 0;
    model->cuda_decoder_prompt_loop_last_policy_checks = 0;
    model->cuda_decoder_prompt_loop_last_policy_interval_skips = 0;
    model->cuda_decoder_prompt_loop_last_thermal_runtime_checks = 0;
    model->cuda_decoder_prompt_loop_last_power_runtime_checks = 0;
    model->cuda_decoder_prompt_prefill_embeddings = 0;
    model->cuda_decoder_prompt_prefill_initial_norm = 0;
    model->cuda_decoder_prompt_prefill_initial_linear = 0;
    model->cuda_decoder_prompt_prefill_initial_query_rope = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_stack = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_residual = 0;
    model->cuda_decoder_prompt_prefill_embedding_bytes = 0;
    model->cuda_decoder_prompt_prefill_initial_norm_bytes = 0;
    model->cuda_decoder_prompt_prefill_initial_linear_bytes = 0;
    model->cuda_decoder_prompt_prefill_initial_query_rope_bytes = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_stack_bytes = 0;
    model->cuda_decoder_prompt_prefill_layer0_attention_residual_bytes = 0;
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
    model->cuda_decoder_prompt_loop_last_max_tokens = 0;
    model->cuda_decoder_prompt_loop_last_thermal_interval_seconds = 0;
    model->cuda_decoder_prompt_loop_last_power_interval_seconds = 0;
    king_inference_cuda_decoder_prompt_loop_reset_hot_token_profile(model);
    model->cuda_decoder_prompt_loop_attempted = false;
    model->cuda_decoder_prompt_loop_available = false;
    model->cuda_decoder_prompt_loop_tokenizer_ready = false;
    model->cuda_decoder_prompt_loop_graph_validation_ready = false;
    model->cuda_decoder_prompt_loop_result_contract_ready = false;
    model->cuda_decoder_prompt_loop_batch_prefill_available = false;
    model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
    model->cuda_decoder_prompt_loop_last_reuse_profile_available = false;
    model->cuda_decoder_prompt_prefill_embeddings_active = false;
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

static bool king_inference_cuda_decoder_prompt_loop_batch_prefill_dependencies_ready(
    king_inference_model_object *model
) {
    return model != NULL
        && model->cuda_decoder_prompt_loop_tokenizer_ready
        && model->cuda_decoder_prompt_loop_graph_validation_ready
        && model->cuda_decoder_prompt_loop_result_contract_ready
        && model->cuda_decoder_graph_executor_execution_plan_available
        && model->cuda_decoder_graph_executor_embedding_execution_available
        && model->cuda_decoder_graph_executor_rms_norm_execution_available
        && model->cuda_decoder_graph_executor_linear_execution_available
        && model->cuda_decoder_graph_executor_slice_execution_available
        && model->cuda_decoder_graph_executor_rope_execution_available
        && model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
        && model->cuda_decoder_graph_executor_kv_write_execution_available
        && model->cuda_decoder_graph_executor_kv_attention_execution_available
        && model->cuda_decoder_graph_executor_attention_stack_slot_execution_available
        && model->cuda_decoder_graph_executor_attention_heads_execution_available
        && model->cuda_decoder_graph_executor_attention_stack_execution_available
        && model->cuda_decoder_graph_executor_attention_output_projection_execution_available
        && model->cuda_decoder_graph_executor_attention_residual_execution_available
        && model->cuda_decoder_graph_executor_ffn_norm_execution_available
        && model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available
        && model->cuda_decoder_graph_executor_ffn_swiglu_execution_available
        && model->cuda_decoder_graph_executor_ffn_down_projection_execution_available
        && model->cuda_decoder_graph_executor_ffn_output_residual_execution_available;
}

static const char *king_inference_cuda_decoder_prompt_loop_batch_prefill_admission_reason(
    king_inference_model_object *model
) {
    if (model == NULL) {
        return "model_unavailable";
    }
    if (!model->cuda_decoder_prompt_loop_attempted) {
        return "prompt_loop_not_attempted";
    }
    if (!model->cuda_decoder_prompt_loop_available) {
        return model->cuda_decoder_prompt_loop_error[0] != '\0'
            ? model->cuda_decoder_prompt_loop_error
            : "prompt_loop_unavailable";
    }
    if (!king_inference_cuda_decoder_prompt_loop_batch_prefill_dependencies_ready(model)) {
        return "batch_prefill_decoder_dependencies_unavailable";
    }
    return model->cuda_decoder_prompt_loop_batch_prefill_available
        ? "ready"
        : "batch_prefill_admission_disabled";
}

#include "cuda_decoder_prompt_loop_sampling.inc"
#include "../prefill/query/cuda_decoder_prompt_prefill_query_batch.inc"
#include "../prefill/kv/cuda_decoder_prompt_prefill_kv_batch.inc"
#include "../prefill/ffn/cuda_decoder_prompt_prefill_ffn_batch.inc"
#include "../prefill/attention/cuda_decoder_prompt_prefill_attention_batch.inc"
#include "../prefill/remaining/cuda_decoder_prompt_prefill_remaining_batch.inc"
#include "../prefill/batch/cuda_decoder_prompt_batch_prefill.inc"

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_sequential(
    king_inference_model_object *model,
    zval *tokenized_prompt,
    zend_ulong token_count,
    zval *decode_options,
    size_t *validated_graphs,
    size_t *result_envelopes,
    size_t *prefill_tokens
) {
    zend_ulong position;

    if (token_count <= 1) {
        return SUCCESS;
    }
    for (position = 0; position + 1 < token_count; position++) {
        zval graph;
        zval graph_result;

        ZVAL_UNDEF(&graph);
        ZVAL_UNDEF(&graph_result);
        add_assoc_bool(decode_options, "emit_token", false);
        if (king_inference_token_decode_graph_array(model, tokenized_prompt, position, decode_options, &graph) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_prepare_result(model, &graph, &graph_result) != SUCCESS) {
            if (!Z_ISUNDEF(graph_result)) {
                zval_ptr_dtor(&graph_result);
            }
            if (!Z_ISUNDEF(graph)) {
                zval_ptr_dtor(&graph);
            }
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                -1,
                model->cuda_decoder_graph_executor_error[0] != '\0'
                    ? model->cuda_decoder_graph_executor_error
                    : "cuda_decoder_prompt_loop_sequential_prefill_failed"
            );
            return FAILURE;
        }
        (*validated_graphs)++;
        (*result_envelopes)++;
        (*prefill_tokens)++;
        zval_ptr_dtor(&graph_result);
        zval_ptr_dtor(&graph);
    }
    model->cuda_decoder_prompt_loop_last_used_batch_prefill = false;
    return SUCCESS;
}

#include "cuda_decoder_prompt_stream.inc"

static void king_inference_cuda_decoder_prompt_loop_release(king_inference_model_object *model)
{
    king_inference_cuda_decoder_prompt_loop_release_prefill_embeddings(model);
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
    model->cuda_decoder_prompt_loop_batch_prefill_available = false;

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
    /*
     * Batch prefill is a performance optimization, not a correctness boundary.
     * The current batch path can produce invalid KV state for normal Gemma chat
     * prompts at the 16-token admission threshold, which corrupts the first
     * decoded tokens. Keep generation on the validated sequential GPU prefill
     * path until the batch mutation/template path is hardened.
     */
    model->cuda_decoder_prompt_loop_batch_prefill_available = false;
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
    zval stops;
    zval event;
    zend_ulong token_count = 0;
    zend_ulong position;
    zend_ulong decoded_token_id = 0;
    size_t validated_graphs = 0;
    size_t result_envelopes = 0;
    size_t prefill_batches = 0;
    size_t prefill_tokens = 0;
    size_t prefill_template_reuses = 0;
    size_t decoded_tokens = 0;
    zend_string *decoded_token_text = NULL;
    double decoded_probability = 0.0;
    double decoded_logit = 0.0;
    double decoded_rank = 0.0;
    bool stopped = false;

    ZVAL_UNDEF(&encoded);
    ZVAL_UNDEF(&tokenized_prompt);
    ZVAL_UNDEF(&decode_options);
    ZVAL_UNDEF(&stops);

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

    if (king_inference_king_native_prompt_stop_list(&stream->request, &stops) != SUCCESS) {
        zval_ptr_dtor(&tokenized_prompt);
        zval_ptr_dtor(&encoded);
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_stop_list_invalid");
        return FAILURE;
    }
    king_inference_king_native_prompt_add_default_stops(model, &stops);
    king_inference_king_native_prompt_decode_options(stream, &decode_options);
    if (token_count >= KING_INFERENCE_CUDA_PROMPT_BATCH_PREFILL_MIN_TOKENS
        && model->cuda_decoder_prompt_loop_batch_prefill_available) {
        if (king_inference_cuda_decoder_prompt_loop_prefill_batch_template(
                model,
                &tokenized_prompt,
                token_count,
                &decode_options,
                &validated_graphs,
                &result_envelopes,
                &prefill_batches,
                &prefill_tokens,
                &prefill_template_reuses
        ) != SUCCESS) {
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            return FAILURE;
        }
    } else if (king_inference_cuda_decoder_prompt_loop_prefill_sequential(
                   model,
                   &tokenized_prompt,
                   token_count,
                   &decode_options,
                   &validated_graphs,
                   &result_envelopes,
                   &prefill_tokens
    ) != SUCCESS) {
        zval_ptr_dtor(&decode_options);
        zval_ptr_dtor(&stops);
        zval_ptr_dtor(&tokenized_prompt);
        zval_ptr_dtor(&encoded);
        return FAILURE;
    }

    position = token_count - 1;
    {
        zval graph;
        zval plan_result;
        king_inference_cuda_decoder_prefill_mutations mutations;
        zend_ulong initial_token_id = 0;

        ZVAL_UNDEF(&graph);
        ZVAL_UNDEF(&plan_result);
        memset(&mutations, 0, sizeof(mutations));
        add_assoc_bool(&decode_options, "emit_token", true);
        if (king_inference_cuda_decoder_prompt_loop_token_at(&tokenized_prompt, position, &initial_token_id) != SUCCESS
            || king_inference_token_decode_graph_array(model, &tokenized_prompt, position, &decode_options, &graph) != SUCCESS
            || king_inference_cuda_decoder_prompt_loop_prepare_decode_template(model, &graph, &plan_result) != SUCCESS
            || king_inference_cuda_decoder_prompt_loop_build_decode_mutations(model, &graph, &mutations) != SUCCESS) {
            king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(&mutations);
            if (!Z_ISUNDEF(plan_result)) {
                zval_ptr_dtor(&plan_result);
            }
            if (!Z_ISUNDEF(graph)) {
                zval_ptr_dtor(&graph);
            }
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                -1,
                model->cuda_decoder_graph_executor_error[0] != '\0'
                    ? model->cuda_decoder_graph_executor_error
                    : "cuda_decoder_prompt_loop_graph_validation_failed"
            );
            return FAILURE;
        }
        validated_graphs++;
        if (king_inference_check_gpu_policy_during_run(stream, "King GPU decoder loop") != SUCCESS) {
            king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(&mutations);
            zval_ptr_dtor(&plan_result);
            zval_ptr_dtor(&graph);
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_guardrail_failed");
            return FAILURE;
        }
        if (king_inference_cuda_decoder_prompt_loop_execute_decode_template(
                model,
                stream,
                &graph,
                &mutations,
                initial_token_id,
                position,
                &decoded_token_id,
                &decoded_token_text,
                &decoded_probability,
                &decoded_logit,
                &decoded_rank,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL
        ) != SUCCESS) {
            king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(&mutations);
            zval_ptr_dtor(&plan_result);
            zval_ptr_dtor(&graph);
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            return FAILURE;
        }
        if (king_inference_cuda_decoder_prompt_loop_emit_decoded_text(
                model,
                stream,
                decoded_token_id,
                decoded_token_text,
                &stops,
                &stopped
        ) != SUCCESS) {
            king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(&mutations);
            zval_ptr_dtor(&plan_result);
            zval_ptr_dtor(&graph);
            zval_ptr_dtor(&decode_options);
            zval_ptr_dtor(&stops);
            zval_ptr_dtor(&tokenized_prompt);
            zval_ptr_dtor(&encoded);
            if (decoded_token_text != NULL) {
                zend_string_release(decoded_token_text);
            }
            return FAILURE;
        }
        decoded_tokens++;
        result_envelopes++;

        while (decoded_tokens < (size_t) max_tokens && !stopped) {
            zend_ulong source_token_id;

            if (position >= (zend_ulong) ZEND_LONG_MAX || decoded_token_id > (zend_ulong) ZEND_LONG_MAX) {
                king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(&mutations);
                zval_ptr_dtor(&plan_result);
                zval_ptr_dtor(&graph);
                zval_ptr_dtor(&decode_options);
                zval_ptr_dtor(&stops);
                zval_ptr_dtor(&tokenized_prompt);
                zval_ptr_dtor(&encoded);
                if (decoded_token_text != NULL) {
                    zend_string_release(decoded_token_text);
                }
                king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_position_limit_exceeded");
                return FAILURE;
            }

            source_token_id = decoded_token_id;
            position++;
            if (king_inference_check_gpu_policy_during_run(stream, "King GPU decoder loop") != SUCCESS) {
                king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(&mutations);
                zval_ptr_dtor(&plan_result);
                zval_ptr_dtor(&graph);
                zval_ptr_dtor(&decode_options);
                zval_ptr_dtor(&stops);
                zval_ptr_dtor(&tokenized_prompt);
                zval_ptr_dtor(&encoded);
                if (decoded_token_text != NULL) {
                    zend_string_release(decoded_token_text);
                }
                king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_loop_guardrail_failed");
                return FAILURE;
            }
            if (king_inference_cuda_decoder_prompt_loop_execute_decode_template(
                    model,
                    stream,
                    &graph,
                    &mutations,
                    source_token_id,
                    position,
                    &decoded_token_id,
                    &decoded_token_text,
                    &decoded_probability,
                    &decoded_logit,
                    &decoded_rank,
                    NULL,
                    NULL,
                    NULL,
                    NULL,
                    NULL
            ) != SUCCESS) {
                king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(&mutations);
                zval_ptr_dtor(&plan_result);
                zval_ptr_dtor(&graph);
                zval_ptr_dtor(&decode_options);
                zval_ptr_dtor(&stops);
                zval_ptr_dtor(&tokenized_prompt);
                zval_ptr_dtor(&encoded);
                if (decoded_token_text != NULL) {
                    zend_string_release(decoded_token_text);
                }
                return FAILURE;
            }
            if (king_inference_cuda_decoder_prompt_loop_emit_decoded_text(
                    model,
                    stream,
                    decoded_token_id,
                    decoded_token_text,
                    &stops,
                    &stopped
            ) != SUCCESS) {
                king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(&mutations);
                zval_ptr_dtor(&plan_result);
                zval_ptr_dtor(&graph);
                zval_ptr_dtor(&decode_options);
                zval_ptr_dtor(&stops);
                zval_ptr_dtor(&tokenized_prompt);
                zval_ptr_dtor(&encoded);
                if (decoded_token_text != NULL) {
                    zend_string_release(decoded_token_text);
                }
                return FAILURE;
            }
            decoded_tokens++;
            result_envelopes++;
        }

        king_inference_cuda_decoder_prompt_loop_prefill_mutations_release(&mutations);
        zval_ptr_dtor(&plan_result);
        zval_ptr_dtor(&graph);
    }
    zval_ptr_dtor(&stops);
    if (!Z_ISUNDEF(stream->native_events)
        && Z_TYPE(stream->native_events) == IS_ARRAY
        && zend_hash_num_elements(Z_ARRVAL(stream->native_events)) == 0) {
        add_next_index_stringl(&stream->native_events, "", 0);
    }

    model->cuda_decoder_prompt_loop_count++;
    model->cuda_decoder_prompt_loop_last_prompt_tokens = token_count;
    model->cuda_decoder_prompt_loop_last_validated_graphs = validated_graphs;
    model->cuda_decoder_prompt_loop_last_result_envelopes = result_envelopes;
    model->cuda_decoder_prompt_loop_last_prefill_batches = prefill_batches;
    model->cuda_decoder_prompt_loop_last_prefill_tokens = prefill_tokens;
    model->cuda_decoder_prompt_loop_last_prefill_template_reuses = prefill_template_reuses;
    model->cuda_decoder_prompt_loop_last_max_tokens = max_tokens;
    model->cuda_decoder_prompt_loop_result = 0;
    model->cuda_decoder_prompt_loop_error[0] = '\0';

    if (king_inference_king_native_stream_diagnostics_enabled(stream)) {
        array_init(&event);
        add_assoc_string(&event, "type", "gpu_prompt_decoder_loop_contract");
        add_assoc_string(&event, "backend", "king_native_gpu");
        add_assoc_bool(&event, "prompt_loop_ready", true);
        add_assoc_bool(&event, "prompt_batch_prefill_available", model->cuda_decoder_prompt_loop_batch_prefill_available);
        add_assoc_bool(&event, "prompt_batch_prefill_used", model->cuda_decoder_prompt_loop_last_used_batch_prefill);
        add_assoc_bool(&event, "decode_graph_template_resident", true);
        add_assoc_bool(&event, "decode_graph_constructed_on_hot_path", false);
        add_assoc_bool(&event, "token_events_streamed_immediately", true);
        add_assoc_bool(&event, "stop_condition_reached", stopped);
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
        add_assoc_bool(&event, "prompt_generation_ready", decoded_tokens == (size_t) max_tokens);
        add_assoc_long(&event, "prompt_tokens", (zend_long) token_count);
        add_assoc_long(&event, "prefill_batches", (zend_long) prefill_batches);
        add_assoc_long(&event, "prefill_tokens", (zend_long) prefill_tokens);
        add_assoc_long(&event, "prefill_template_reuses", (zend_long) prefill_template_reuses);
        add_assoc_long(&event, "prefill_embedding_bytes", (zend_long) model->cuda_decoder_prompt_prefill_embedding_bytes);
        add_assoc_long(&event, "prefill_initial_norm_bytes", (zend_long) model->cuda_decoder_prompt_prefill_initial_norm_bytes);
        add_assoc_long(&event, "prefill_initial_linear_bytes", (zend_long) model->cuda_decoder_prompt_prefill_initial_linear_bytes);
        add_assoc_long(
            &event,
            "prefill_initial_linear_rows",
            (zend_long) model->cuda_decoder_prompt_prefill_initial_linear_rows
        );
        add_assoc_long(
            &event,
            "prefill_initial_query_rope_bytes",
            (zend_long) model->cuda_decoder_prompt_prefill_initial_query_rope_bytes
        );
        add_assoc_long(
            &event,
            "prefill_initial_query_rope_heads",
            (zend_long) model->cuda_decoder_prompt_prefill_initial_query_rope_heads
        );
        add_assoc_long(
            &event,
            "prefill_layer0_attention_stack_bytes",
            (zend_long) model->cuda_decoder_prompt_prefill_layer0_attention_stack_bytes
        );
        add_assoc_long(
            &event,
            "prefill_layer0_attention_residual_bytes",
            (zend_long) model->cuda_decoder_prompt_prefill_layer0_attention_residual_bytes
        );
        add_assoc_long(&event, "prefill_kv_heads", (zend_long) model->cuda_decoder_prompt_prefill_kv_heads);
        add_assoc_long(
            &event,
            "prefill_kv_cache_writes",
            (zend_long) model->cuda_decoder_prompt_prefill_kv_cache_writes
        );
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
    }
    if (decoded_token_text != NULL) {
        zend_string_release(decoded_token_text);
    }

    zval_ptr_dtor(&decode_options);
    zval_ptr_dtor(&tokenized_prompt);
    zval_ptr_dtor(&encoded);
    return SUCCESS;
}

#include "cuda_decoder_prompt_loop_status.inc"

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
