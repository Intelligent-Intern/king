/*
 * CUDA decoder graph probe lifecycle helpers for native King GPU inference.
 */

static void king_inference_cuda_decoder_graph_executor_destroy_initial_probes(
    zval *embedding_probe,
    zval *rms_norm_probe,
    zval *linear_probe,
    zval *slice_probe,
    zval *rope_probe,
    zval *kv_head_prepare_probe
) {
    zval_ptr_dtor(embedding_probe);
    zval_ptr_dtor(rms_norm_probe);
    zval_ptr_dtor(linear_probe);
    zval_ptr_dtor(slice_probe);
    zval_ptr_dtor(rope_probe);
    zval_ptr_dtor(kv_head_prepare_probe);
}

static void king_inference_cuda_decoder_graph_executor_add_empty_initial_result(
    zval *result,
    zval *embedding_probe,
    zval *rms_norm_probe,
    zval *linear_probe,
    zval *slice_probe,
    zval *rope_probe,
    zval *kv_head_prepare_probe
) {
    static const char *ready_keys[] = {
        "embedding_device_execution_ready",
        "rms_norm_device_execution_ready",
        "linear_device_execution_ready",
        "slice_device_execution_ready",
        "rope_device_execution_ready",
        "kv_head_prepare_device_execution_ready",
        "kv_write_device_execution_ready",
        "kv_attention_device_execution_ready",
        "attention_stack_slot_device_execution_ready",
        "attention_heads_device_execution_ready",
        "attention_stack_device_execution_ready",
        "attention_output_projection_device_execution_ready",
        "attention_residual_device_execution_ready",
        "ffn_norm_device_execution_ready",
        "ffn_gate_up_projection_device_execution_ready",
        "ffn_swiglu_device_execution_ready",
        "ffn_down_projection_device_execution_ready",
        "ffn_output_residual_device_execution_ready",
        "final_norm_device_execution_ready",
        "logits_projection_device_execution_ready"
    };
    size_t index;

    add_assoc_zval(result, "embedding_device_execution", embedding_probe);
    add_assoc_zval(result, "rms_norm_device_execution", rms_norm_probe);
    add_assoc_zval(result, "linear_device_execution", linear_probe);
    add_assoc_zval(result, "slice_device_execution", slice_probe);
    add_assoc_zval(result, "rope_device_execution", rope_probe);
    add_assoc_zval(result, "kv_head_prepare_device_execution", kv_head_prepare_probe);
    for (index = 0; index < sizeof(ready_keys) / sizeof(ready_keys[0]); index++) {
        add_assoc_bool(result, ready_keys[index], false);
    }
    add_assoc_long(result, "executed_device_ops", 0);
}

static void king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(
    bool projection_initialized,
    zval *projection_probe,
    bool residual_initialized,
    zval *residual_probe,
    bool ffn_norm_initialized,
    zval *ffn_norm_probe,
    bool ffn_gate_up_initialized,
    zval *ffn_gate_up_probe,
    bool ffn_swiglu_initialized,
    zval *ffn_swiglu_probe,
    bool ffn_down_initialized,
    zval *ffn_down_probe,
    bool ffn_output_initialized,
    zval *ffn_output_probe,
    bool final_norm_initialized,
    zval *final_norm_probe,
    bool logits_initialized,
    zval *logits_probe
) {
    if (projection_initialized) {
        zval_ptr_dtor(projection_probe);
    }
    if (residual_initialized) {
        zval_ptr_dtor(residual_probe);
    }
    if (ffn_norm_initialized) {
        zval_ptr_dtor(ffn_norm_probe);
    }
    if (ffn_gate_up_initialized) {
        zval_ptr_dtor(ffn_gate_up_probe);
    }
    if (ffn_swiglu_initialized) {
        zval_ptr_dtor(ffn_swiglu_probe);
    }
    if (ffn_down_initialized) {
        zval_ptr_dtor(ffn_down_probe);
    }
    if (ffn_output_initialized) {
        zval_ptr_dtor(ffn_output_probe);
    }
    if (final_norm_initialized) {
        zval_ptr_dtor(final_norm_probe);
    }
    if (logits_initialized) {
        zval_ptr_dtor(logits_probe);
    }
}

static void king_inference_cuda_decoder_graph_executor_add_downstream_probes(
    zval *result,
    bool projection_initialized,
    zval *projection_probe,
    bool residual_initialized,
    zval *residual_probe,
    bool ffn_norm_initialized,
    zval *ffn_norm_probe,
    bool ffn_gate_up_initialized,
    zval *ffn_gate_up_probe,
    bool ffn_swiglu_initialized,
    zval *ffn_swiglu_probe,
    bool ffn_down_initialized,
    zval *ffn_down_probe,
    bool ffn_output_initialized,
    zval *ffn_output_probe,
    bool final_norm_initialized,
    zval *final_norm_probe,
    bool logits_initialized,
    zval *logits_probe
) {
    if (projection_initialized) {
        add_assoc_zval(result, "attention_output_projection_device_execution", projection_probe);
    }
    if (residual_initialized) {
        add_assoc_zval(result, "attention_residual_device_execution", residual_probe);
    }
    if (ffn_norm_initialized) {
        add_assoc_zval(result, "ffn_norm_device_execution", ffn_norm_probe);
    }
    if (ffn_gate_up_initialized) {
        add_assoc_zval(result, "ffn_gate_up_projection_device_execution", ffn_gate_up_probe);
    }
    if (ffn_swiglu_initialized) {
        add_assoc_zval(result, "ffn_swiglu_device_execution", ffn_swiglu_probe);
    }
    if (ffn_down_initialized) {
        add_assoc_zval(result, "ffn_down_projection_device_execution", ffn_down_probe);
    }
    if (ffn_output_initialized) {
        add_assoc_zval(result, "ffn_output_residual_device_execution", ffn_output_probe);
    }
    if (final_norm_initialized) {
        add_assoc_zval(result, "final_norm_device_execution", final_norm_probe);
    }
    if (logits_initialized) {
        add_assoc_zval(result, "logits_projection_device_execution", logits_probe);
    }
}
