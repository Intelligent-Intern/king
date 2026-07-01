/*
 * GPU runtime refusal reason helpers.
 */

static void king_inference_gpu_status_append_reason(zval *reasons, const char *reason)
{
    zval *entry;

    if (reason == NULL || reason[0] == '\0') {
        return;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(reasons), entry) {
        if (Z_TYPE_P(entry) == IS_STRING && strcmp(Z_STRVAL_P(entry), reason) == 0) {
            return;
        }
    } ZEND_HASH_FOREACH_END();

    add_next_index_string(reasons, reason);
}

static const char *king_inference_gpu_status_first_reason(zval *reasons)
{
    zval *first = zend_hash_index_find(Z_ARRVAL_P(reasons), 0);

    if (first == NULL || Z_TYPE_P(first) != IS_STRING || Z_STRLEN_P(first) == 0) {
        return "gpu_ready";
    }

    return Z_STRVAL_P(first);
}

static void king_inference_gpu_decoder_blockers(king_inference_model_object *model, zval *blockers)
{
    array_init(blockers);
    if (model == NULL || !model->cuda_embedding_row_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_embedding_row_loader_missing");
    }
    if (model == NULL || !model->cuda_device_vector_ops_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_device_vector_ops_missing");
    }
    if (model == NULL || !model->cuda_device_kv_cache_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_device_kv_cache_missing");
    }
    if (model == NULL || !model->cuda_decoder_graph_executor_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_decoder_graph_executor_missing");
    }
    if (model == NULL || !model->cuda_decoder_graph_executor_attention_residual_execution_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_decoder_graph_attention_residual_missing");
    }
    if (model == NULL || !model->cuda_decoder_graph_executor_ffn_norm_execution_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_decoder_graph_ffn_norm_missing");
    }
    if (model == NULL || !model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_decoder_graph_ffn_gate_up_projection_missing");
    }
    if (model == NULL || !model->cuda_decoder_graph_executor_ffn_swiglu_execution_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_decoder_graph_ffn_swiglu_missing");
    }
    if (model == NULL || !model->cuda_decoder_graph_executor_ffn_down_projection_execution_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_decoder_graph_ffn_down_projection_missing");
    }
    if (model == NULL || !model->cuda_decoder_graph_executor_ffn_output_residual_execution_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_decoder_graph_ffn_output_residual_missing");
    }
    if (model == NULL || !model->cuda_decoder_graph_executor_final_norm_execution_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_decoder_graph_final_norm_missing");
    }
    if (model == NULL || !model->cuda_decoder_graph_executor_logits_projection_execution_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_decoder_graph_logits_projection_missing");
    }
    if (model == NULL || !model->cuda_decoder_prompt_loop_available) {
        king_inference_gpu_status_append_reason(blockers, "gpu_prompt_decoder_loop_missing");
    }
}

static void king_inference_gpu_replace_return_decoder_blockers(
    king_inference_model_object *model,
    zval *return_value
) {
    zval blockers;

    king_inference_gpu_decoder_blockers(model, &blockers);
    add_assoc_zval(return_value, "decoder_blockers", &blockers);
}

static void king_inference_gpu_status_refusal_reasons(
    zval *reasons,
    bool gpu_enabled,
    bool process_bindings_enabled,
    bool config_bindings_enabled,
    bool backend_supported,
    bool artifact_configured,
    bool artifact_readable,
    zend_long max_gpu_layers,
    bool thermal_monitored,
    bool allow_unmonitored,
    bool temperature_available,
    bool temperature_ok,
    bool power_configured,
    bool power_sensor_configured,
    bool power_available,
    bool power_ok,
    bool driver_visible,
    bool vram_admission_checked,
    bool free_vram_floor_met,
    bool model_vram_fits_after_reserve,
    bool model_vram_fits_without_reserve,
    bool system_ram_offload_required,
    bool system_ram_offload_allowed,
    bool system_ram_offload_limit_configured,
    bool system_ram_offload_limit_met,
    bool system_ram_available,
    bool system_ram_floor_met,
    bool system_ram_offload_supported,
    bool system_ram_offload_admitted
) {
    array_init(reasons);

    if (!gpu_enabled) {
        king_inference_gpu_status_append_reason(reasons, "gpu_disabled");
    }
    if (!process_bindings_enabled) {
        king_inference_gpu_status_append_reason(reasons, "process_gpu_bindings_disabled");
    }
    if (!config_bindings_enabled) {
        king_inference_gpu_status_append_reason(reasons, "config_gpu_bindings_disabled");
    }
    if (!backend_supported) {
        king_inference_gpu_status_append_reason(reasons, "unsupported_gpu_backend");
    }
    if (!artifact_configured) {
        king_inference_gpu_status_append_reason(reasons, "gpu_model_artifact_missing");
    } else if (!artifact_readable) {
        king_inference_gpu_status_append_reason(reasons, "gpu_model_artifact_not_readable");
    }
    if (max_gpu_layers <= 0) {
        king_inference_gpu_status_append_reason(reasons, "gpu_layers_disabled");
    }
    if (!vram_admission_checked) {
        king_inference_gpu_status_append_reason(reasons, "gpu_vram_status_unavailable");
    } else {
        if (!free_vram_floor_met) {
            king_inference_gpu_status_append_reason(reasons, "gpu_free_vram_below_configured_floor");
        }
        if (!model_vram_fits_after_reserve) {
            king_inference_gpu_status_append_reason(
                reasons,
                model_vram_fits_without_reserve
                    ? "gpu_model_artifact_exceeds_free_vram_after_reserve"
                    : "gpu_model_artifact_exceeds_free_vram"
            );
            if (system_ram_offload_required && !system_ram_offload_allowed) {
                king_inference_gpu_status_append_reason(reasons, "gpu_system_ram_offload_disabled");
            } else if (system_ram_offload_required && !system_ram_offload_limit_configured) {
                king_inference_gpu_status_append_reason(reasons, "gpu_system_ram_offload_limit_missing");
            } else if (system_ram_offload_required && !system_ram_offload_limit_met) {
                king_inference_gpu_status_append_reason(reasons, "gpu_system_ram_offload_exceeds_limit");
            } else if (system_ram_offload_required && !system_ram_available) {
                king_inference_gpu_status_append_reason(reasons, "gpu_system_ram_unavailable");
            } else if (system_ram_offload_required && !system_ram_floor_met) {
                king_inference_gpu_status_append_reason(reasons, "gpu_system_ram_offload_floor_not_met");
            } else if (system_ram_offload_required && !system_ram_offload_supported) {
                king_inference_gpu_status_append_reason(reasons, "gpu_system_ram_offload_not_supported");
            } else if (system_ram_offload_required && !system_ram_offload_admitted) {
                king_inference_gpu_status_append_reason(reasons, "gpu_system_ram_offload_not_admitted");
            }
        }
    }
    if (!driver_visible && !temperature_available) {
        king_inference_gpu_status_append_reason(reasons, "gpu_driver_not_visible");
    }
    if (!thermal_monitored && !allow_unmonitored) {
        king_inference_gpu_status_append_reason(reasons, "gpu_thermal_monitor_missing");
    }
    if (thermal_monitored && !temperature_available) {
        king_inference_gpu_status_append_reason(reasons, "gpu_temperature_unavailable");
    }
    if (temperature_available && !temperature_ok) {
        king_inference_gpu_status_append_reason(reasons, "gpu_temperature_limit_reached");
    }
    if (power_configured && !power_sensor_configured) {
        king_inference_gpu_status_append_reason(reasons, "gpu_power_sensor_missing");
    }
    if (power_configured && power_sensor_configured && !power_available) {
        king_inference_gpu_status_append_reason(reasons, "gpu_power_unavailable");
    }
    if (power_configured && power_available && !power_ok) {
        king_inference_gpu_status_append_reason(reasons, "gpu_power_limit_reached");
    }
    if (zend_hash_num_elements(Z_ARRVAL_P(reasons)) == 0) {
        king_inference_gpu_status_append_reason(reasons, "gpu_decoder_kernel_not_implemented");
    }
}

static void king_inference_gpu_status_append_return_reason(zval *return_value, const char *reason)
{
    zval fresh;
    zval *reasons = zend_hash_str_find(
        Z_ARRVAL_P(return_value),
        "refusal_reasons",
        sizeof("refusal_reasons") - 1
    );

    if (reasons != NULL && Z_TYPE_P(reasons) == IS_ARRAY) {
        king_inference_gpu_status_append_reason(reasons, reason);
        return;
    }

    array_init(&fresh);
    king_inference_gpu_status_append_reason(&fresh, reason);
    add_assoc_zval(return_value, "refusal_reasons", &fresh);
}
