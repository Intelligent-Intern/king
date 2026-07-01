/*
 * CUDA final output projection path for native King GPU inference.
 */

static void king_inference_cuda_output_projection_reset_fields(king_inference_model_object *model)
{
    model->cuda_output_projection_result = 0;
    model->cuda_output_projection_error[0] = '\0';
    model->cuda_output_projection_launch_count = 0;
    model->cuda_output_projection_attempted = false;
    model->cuda_output_projection_available = false;
    model->cuda_output_projection_resolved = false;
    model->cuda_output_projection_uploaded = false;
    model->cuda_output_projection_tied_token_embedding = false;
    model->cuda_output_projection_q8_0_available = false;
}

static void king_inference_cuda_output_projection_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_output_projection_error";
    }

    model->cuda_output_projection_result = result;
    snprintf(
        model->cuda_output_projection_error,
        sizeof(model->cuda_output_projection_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_output_projection_resolve(
    king_inference_model_object *model,
    zend_string **name_out,
    const char **source_out,
    const char **status_out,
    bool *tied_embedding_out
) {
    zend_result resolved;

    *name_out = NULL;
    if (source_out != NULL) {
        *source_out = NULL;
    }
    if (status_out != NULL) {
        *status_out = NULL;
    }
    if (tied_embedding_out != NULL) {
        *tied_embedding_out = false;
    }

    resolved = king_inference_resolve_output_projection_tensor_name(
        model,
        NULL,
        name_out,
        source_out,
        status_out,
        tied_embedding_out
    );
    if (resolved != SUCCESS || *name_out == NULL) {
        king_inference_cuda_output_projection_set_error(
            model,
            -1,
            status_out != NULL && *status_out != NULL ? *status_out : "output_projection_not_resolved"
        );
        return false;
    }

    return true;
}

static void king_inference_cuda_output_projection_release(king_inference_model_object *model)
{
    king_inference_cuda_output_projection_reset_fields(model);
}

static void king_inference_cuda_output_projection_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    zend_string *name = NULL;
    const char *source = NULL;
    const char *status = NULL;
    bool tied_embedding = false;
    king_inference_cuda_weight_upload *upload;

    king_inference_cuda_output_projection_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_output_projection_attempted = true;
    model->cuda_output_projection_q8_0_available = model->cuda_quantized_matvec_available;
    if (!king_inference_cuda_output_projection_resolve(
            model,
            &name,
            &source,
            &status,
            &tied_embedding
        )) {
        return;
    }

    (void) source;
    (void) status;
    model->cuda_output_projection_resolved = true;
    model->cuda_output_projection_tied_token_embedding = tied_embedding;
    upload = king_inference_cuda_weight_cache_find(model, name, false);
    model->cuda_output_projection_uploaded = upload != NULL && upload->device_ptr != 0;
    if (!model->cuda_output_projection_uploaded) {
        zend_string_release(name);
        king_inference_cuda_output_projection_set_error(model, -1, "cuda_output_projection_weight_not_uploaded");
        return;
    }
    zend_string_release(name);

    if (!model->cuda_quantized_matvec_available) {
        king_inference_cuda_output_projection_set_error(model, -1, "cuda_quantized_matvec_dependency_unavailable");
        return;
    }

    model->cuda_output_projection_available = true;
    model->cuda_output_projection_result = 0;
    model->cuda_output_projection_error[0] = '\0';
}

static zend_result king_inference_cuda_output_projection_q8_0(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr input_vector,
    zend_ulong input_length,
    king_inference_cuda_device_ptr output_logits,
    zend_ulong vocab_rows
) {
    zend_string *name = NULL;
    const char *status = NULL;
    bool tied_embedding = false;
    zend_result result;

    if (!model->cuda_output_projection_available) {
        king_inference_cuda_output_projection_set_error(model, -1, "cuda_output_projection_unavailable");
        return FAILURE;
    }
    if (input_vector == 0 || output_logits == 0) {
        king_inference_cuda_output_projection_set_error(model, -1, "cuda_output_projection_argument_invalid");
        return FAILURE;
    }
    if (input_length == 0 || input_length > UINT_MAX || vocab_rows == 0 || vocab_rows > UINT_MAX) {
        king_inference_cuda_output_projection_set_error(model, -1, "cuda_output_projection_shape_invalid");
        return FAILURE;
    }
    if (!king_inference_cuda_output_projection_resolve(model, &name, NULL, &status, &tied_embedding)) {
        return FAILURE;
    }

    model->cuda_output_projection_tied_token_embedding = tied_embedding;
    result = king_inference_cuda_quantized_matvec(
        model,
        name,
        input_vector,
        input_length,
        output_logits,
        vocab_rows
    );
    zend_string_release(name);
    if (result != SUCCESS) {
        king_inference_cuda_output_projection_set_error(
            model,
            model->cuda_quantized_matvec_result,
            model->cuda_quantized_matvec_error[0] != '\0'
                ? model->cuda_quantized_matvec_error
                : "cuda_output_projection_matvec_failed"
        );
        return FAILURE;
    }

    model->cuda_output_projection_launch_count++;
    model->cuda_output_projection_result = 0;
    model->cuda_output_projection_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_output_projection_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zend_string *name = NULL;
    const char *source = NULL;
    const char *status = NULL;
    bool tied_embedding = false;
    zval output_projection;

    array_init(&output_projection);
    add_assoc_bool(&output_projection, "attempted", model->cuda_output_projection_attempted);
    add_assoc_bool(&output_projection, "available", model->cuda_output_projection_available);
    add_assoc_bool(&output_projection, "resolved", model->cuda_output_projection_resolved);
    add_assoc_bool(&output_projection, "uploaded", model->cuda_output_projection_uploaded);
    add_assoc_bool(&output_projection, "q8_0_available", model->cuda_output_projection_q8_0_available);
    add_assoc_bool(
        &output_projection,
        "tied_token_embedding",
        model->cuda_output_projection_tied_token_embedding
    );
    add_assoc_long(&output_projection, "launches", (zend_long) model->cuda_output_projection_launch_count);
    add_assoc_long(&output_projection, "result", model->cuda_output_projection_result);
    add_assoc_string(&output_projection, "error", model->cuda_output_projection_error);

    if (king_inference_cuda_output_projection_resolve(
            model,
            &name,
            &source,
            &status,
            &tied_embedding
        )) {
        if (source != NULL) {
            add_assoc_string(&output_projection, "source", source);
        }
        if (status != NULL) {
            add_assoc_string(&output_projection, "status", status);
        }
        add_assoc_stringl(&output_projection, "name", ZSTR_VAL(name), ZSTR_LEN(name));
        add_assoc_bool(&output_projection, "tied_token_embedding", tied_embedding);
        zend_string_release(name);
    } else if (status != NULL) {
        add_assoc_string(&output_projection, "status", status);
    }

    add_assoc_zval(return_value, "output_projection_path", &output_projection);
}

static void king_inference_cuda_output_projection_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_output_projection;

    king_inference_cuda_output_projection_add_status(model, return_value);
    if (!model->cuda_output_projection_attempted
        || model->cuda_output_projection_available
        || !model->cuda_context_available) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_output_projection = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_output_projection) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_output_projection_path_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_output_projection_path_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(
            return_value,
            "gpu_output_projection_path_unavailable"
        );
    }
}
