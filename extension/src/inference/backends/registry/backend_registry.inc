static zend_result king_inference_backend_validate_model_config(zval *config, const char *function_name)
{
    king_inference_backend_kind kind;

    if (king_inference_backend_kind_from_config(config, &kind, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (!king_inference_backend_kind_allows_model_registration(kind)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() backend '%s' cannot register King inference models in this build.",
            function_name,
            king_inference_backend_kind_name(kind)
        );
        return FAILURE;
    }

    if (kind == KING_INFERENCE_BACKEND_LOCAL
        && king_inference_king_local_validate_model_config(config, function_name) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_stream_start(king_inference_stream_object *stream, const char *function_name)
{
    king_inference_model_object *model;
    king_inference_backend_kind kind;

    model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    if (king_inference_backend_kind_from_config(&model->config, &kind, function_name) != SUCCESS) {
        return FAILURE;
    }

    king_inference_stream_model_runtime_started(stream);
    switch (kind) {
        case KING_INFERENCE_BACKEND_LOCAL:
            return king_inference_king_local_stream_start(stream, function_name);

        case KING_INFERENCE_BACKEND_KING_NATIVE_CPU:
            return king_inference_king_native_cpu_stream_start(stream, function_name);

        case KING_INFERENCE_BACKEND_KING_NATIVE_GPU:
            return king_inference_king_native_gpu_stream_start(stream, function_name);
    }

    zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s() resolved an unknown inference backend.", function_name);
    return FAILURE;
}

static const char *king_inference_model_last_start_error(king_inference_model_object *model)
{
    const char *last_error = king_get_error();

    if (last_error != NULL && last_error[0] != '\0') {
        return last_error;
    }
    if (model == NULL) {
        return NULL;
    }
    if (model->cuda_decoder_graph_executor_error[0] != '\0') {
        return model->cuda_decoder_graph_executor_error;
    }
    if (model->cuda_decoder_prompt_loop_error[0] != '\0') {
        return model->cuda_decoder_prompt_loop_error;
    }
    if (model->cuda_rope_error[0] != '\0') {
        return model->cuda_rope_error;
    }
    if (model->cuda_device_allocator_error[0] != '\0') {
        return model->cuda_device_allocator_error;
    }
    if (model->cuda_context_error[0] != '\0') {
        return model->cuda_context_error;
    }
    if (model->cuda_weight_upload_error[0] != '\0') {
        return model->cuda_weight_upload_error;
    }
    if (model->cuda_quantized_matvec_error[0] != '\0') {
        return model->cuda_quantized_matvec_error;
    }
    if (model->cuda_rms_norm_error[0] != '\0') {
        return model->cuda_rms_norm_error;
    }
    if (model->cuda_embedding_row_error[0] != '\0') {
        return model->cuda_embedding_row_error;
    }
    if (model->cuda_device_vector_ops_error[0] != '\0') {
        return model->cuda_device_vector_ops_error;
    }
    if (model->cuda_device_kv_cache_error[0] != '\0') {
        return model->cuda_device_kv_cache_error;
    }
    if (model->cuda_attention_scores_error[0] != '\0') {
        return model->cuda_attention_scores_error;
    }
    if (model->cuda_attention_softmax_error[0] != '\0') {
        return model->cuda_attention_softmax_error;
    }
    if (model->cuda_attention_values_error[0] != '\0') {
        return model->cuda_attention_values_error;
    }
    if (model->cuda_ffn_swiglu_error[0] != '\0') {
        return model->cuda_ffn_swiglu_error;
    }
    if (model->cuda_output_projection_error[0] != '\0') {
        return model->cuda_output_projection_error;
    }
    if (model->cuda_logits_readback_error[0] != '\0') {
        return model->cuda_logits_readback_error;
    }

    return NULL;
}

static void king_inference_stream_throw_start_failure(
    king_inference_stream_object *stream,
    const char *function_name
) {
    king_inference_model_object *model = NULL;
    const char *message;

    if (EG(exception)) {
        return;
    }
    if (stream != NULL
        && !Z_ISUNDEF(stream->model)
        && Z_TYPE(stream->model) == IS_OBJECT
        && Z_OBJCE(stream->model) == king_ce_inference_model) {
        model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    }

    message = king_inference_model_last_start_error(model);
    if (message == NULL || message[0] == '\0') {
        message = "King inference stream start failed.";
    }
    king_set_error(message);
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "%s() failed to start King inference stream: %s",
        function_name,
        message
    );
}

static void king_inference_backend_model_info(king_inference_model_object *intern, zval *return_value)
{
    king_inference_backend_kind kind;
    zval capabilities;

    if (king_inference_backend_kind_from_config(&intern->config, &kind, "King\\Inference\\Model::info") != SUCCESS) {
        kind = KING_INFERENCE_BACKEND_KING_NATIVE_CPU;
    }

    add_assoc_string(return_value, "backend", king_inference_backend_kind_name(kind));

    if (kind == KING_INFERENCE_BACKEND_LOCAL) {
        zend_string *runner = king_inference_king_local_runner_path(intern);
        bool runner_executable = king_inference_king_local_runner_path_is_executable(runner);
        add_assoc_str(return_value, "runner_path", runner);
        add_assoc_string(return_value, "engine", "king_local_process");
        add_assoc_string(return_value, "runner_protocol", "king-local-argv");
        add_assoc_bool(return_value, "runner_executable", runner_executable);
    } else if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU) {
        king_inference_king_native_model_info(intern, return_value);
    } else if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU) {
        king_inference_king_native_gpu_model_info(intern, return_value);
    }

    king_inference_backend_capabilities_array(kind, &capabilities);
    add_assoc_zval(return_value, "backend_capabilities", &capabilities);
}
