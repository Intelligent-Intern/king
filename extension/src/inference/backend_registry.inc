static zend_result king_inference_backend_validate_model_config(zval *config, const char *function_name)
{
    king_inference_backend_kind kind;

    if (king_inference_backend_kind_from_config(config, &kind, function_name) != SUCCESS) {
        return FAILURE;
    }

    if (!king_inference_backend_kind_is_available(kind)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() backend '%s' is part of the King inference contract but is not implemented in this build.",
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

    switch (kind) {
        case KING_INFERENCE_BACKEND_LOCAL:
            return king_inference_king_local_stream_start(stream, function_name);

        case KING_INFERENCE_BACKEND_KING_NATIVE_CPU:
            return king_inference_king_native_cpu_stream_start(stream, function_name);

        case KING_INFERENCE_BACKEND_KING_NATIVE_GPU:
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() backend '%s' is part of the King inference contract but is not implemented in this build.",
                function_name,
                king_inference_backend_kind_name(kind)
            );
            return FAILURE;
    }

    zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s() resolved an unknown inference backend.", function_name);
    return FAILURE;
}

static void king_inference_backend_model_info(king_inference_model_object *intern, zval *return_value)
{
    king_inference_backend_kind kind;
    zval capabilities;

    if (king_inference_backend_kind_from_config(&intern->config, &kind, "King\\Inference\\Model::info") != SUCCESS) {
        kind = KING_INFERENCE_BACKEND_LOCAL;
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
    }

    king_inference_backend_capabilities_array(kind, &capabilities);
    add_assoc_zval(return_value, "backend_capabilities", &capabilities);
}
