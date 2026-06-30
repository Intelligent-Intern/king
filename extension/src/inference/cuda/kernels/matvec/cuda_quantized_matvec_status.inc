/*
 * CUDA quantized matrix/vector status helpers.
 */

static void king_inference_cuda_quantized_matvec_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval matvec;
    zval supported_types;

    array_init(&matvec);
    add_assoc_bool(&matvec, "attempted", model->cuda_quantized_matvec_attempted);
    add_assoc_bool(&matvec, "available", model->cuda_quantized_matvec_available);
    add_assoc_bool(&matvec, "nvrtc_available", model->cuda_quantized_matvec_nvrtc_available);
    add_assoc_bool(&matvec, "module_loaded", model->cuda_quantized_matvec_module_loaded);
    add_assoc_bool(&matvec, "q8_0_available", model->cuda_quantized_matvec_q8_0_available);
    add_assoc_bool(&matvec, "batch_available", model->cuda_quantized_matvec_batch_available);
    add_assoc_long(&matvec, "launches", (zend_long) model->cuda_quantized_matvec_launch_count);
    add_assoc_long(&matvec, "batch_launches", (zend_long) model->cuda_quantized_matvec_batch_launch_count);
    add_assoc_long(&matvec, "result", model->cuda_quantized_matvec_result);
    add_assoc_string(&matvec, "error", model->cuda_quantized_matvec_error);

    array_init(&supported_types);
    add_next_index_string(&supported_types, "Q8_0");
    add_next_index_string(&supported_types, "Q5_0");
    add_next_index_string(&supported_types, "Q4_K");
    add_next_index_string(&supported_types, "Q6_K");
    add_assoc_zval(&matvec, "supported_types", &supported_types);
    add_assoc_zval(return_value, "quantized_matvec", &matvec);
}

static void king_inference_cuda_quantized_matvec_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_matvec;

    king_inference_cuda_quantized_matvec_add_status(model, return_value);
    if (!model->cuda_quantized_matvec_attempted
        || model->cuda_quantized_matvec_available
        || !model->cuda_weight_upload_complete) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_matvec = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_matvec) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_quantized_matvec_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_quantized_matvec_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_quantized_matvec_unavailable");
    }
}
