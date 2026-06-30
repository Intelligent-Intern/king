PHP_METHOD(King_Inference_Stream, __construct)
{
    zval *model;
    zval *request;
    zval *options = NULL;
    zval empty_options;
    zval *validation_options;
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_ARRAY(request)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (options != NULL) {
        validation_options = options;
    } else {
        ZVAL_UNDEF(&empty_options);
        array_init(&empty_options);
        validation_options = &empty_options;
    }
    if (king_inference_stream_validate_request_options(
        request,
        validation_options,
        "King\\Inference\\Stream::__construct"
    ) != SUCCESS) {
        if (options == NULL) {
            zval_ptr_dtor(&empty_options);
        }
        RETURN_THROWS();
    }
    if (options == NULL) {
        zval_ptr_dtor(&empty_options);
    }

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    king_inference_stream_release_owned_state(stream);
    ZVAL_COPY(&stream->model, model);
    ZVAL_COPY(&stream->request, request);
    if (options != NULL) {
        ZVAL_COPY(&stream->options, options);
    } else {
        array_init(&stream->options);
    }
    stream->start_event_pending = true;
    stream->done = false;
    stream->cancelled = false;
    stream->exit_code = -1;
    stream->chunk_count = 0;
    stream->stderr_count = 0;
    stream->bytes_emitted = 0;
    king_inference_stream_reset_decoder_metrics(stream);
    stream->native_event_index = 0;
    stream->gpu_thermal_preflight_at = 0;
    stream->gpu_thermal_last_check_at = 0;
    stream->gpu_thermal_abort_at = 0;
    stream->gpu_power_preflight_at = 0;
    stream->gpu_power_last_check_at = 0;
    stream->gpu_power_abort_at = 0;
    stream->gpu_policy_during_run_checks = 0;
    stream->gpu_policy_interval_skips = 0;
    stream->gpu_thermal_runtime_checks = 0;
    stream->gpu_power_runtime_checks = 0;
    stream->gpu_thermal_check_interval_seconds = 0;
    stream->gpu_power_check_interval_seconds = 0;
    stream->gpu_thermal_preflight_temperature_c = 0.0;
    stream->gpu_thermal_abort_temperature_c = 0.0;
    stream->gpu_thermal_abort_ceiling_c = 0.0;
    stream->gpu_power_preflight_watts = 0.0;
    stream->gpu_power_abort_watts = 0.0;
    stream->gpu_power_abort_ceiling_watts = 0.0;
    stream->gpu_thermal_preflight_checked = false;
    stream->gpu_thermal_preflight_temperature_available = false;
    stream->gpu_thermal_aborted = false;
    stream->gpu_power_preflight_checked = false;
    stream->gpu_power_preflight_available = false;
    stream->gpu_power_aborted = false;
    stream->openai_compatible = king_inference_stream_openai_requested(&stream->request, &stream->options);
    stream->created_at = (zend_long) time(NULL);
    if (stream->openai_compatible) {
        king_inference_stream_prepare_openai(stream);
    } else if (stream->response_id != NULL) {
        zend_string_release(stream->response_id);
        stream->response_id = NULL;
    }

    if (king_inference_stream_start(stream, "King\\Inference\\Stream::__construct") != SUCCESS) {
        king_inference_stream_throw_start_failure(stream, "King\\Inference\\Stream::__construct");
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Stream, next)
{
    zend_long timeout_ms = 0;
    bool timeout_is_null = true;
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_is_null) {
        timeout_ms = 0;
    }

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (king_inference_stream_next_event(stream, timeout_ms, return_value) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Stream, nextAsync)
{
    zend_long timeout_ms = 0;
    bool timeout_is_null = true;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_is_null) {
        timeout_ms = 0;
    }

    if (king_inference_next_async_create(
        return_value,
        ZEND_THIS,
        timeout_ms,
        "King\\Inference\\Stream::nextAsync",
        sizeof("King\\Inference\\Stream::nextAsync") - 1
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Stream, cancel)
{
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_NONE();

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    king_inference_stream_cancel_state(stream);
    RETURN_TRUE;
}

PHP_METHOD(King_Inference_Stream, isDone)
{
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_NONE();

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    RETURN_BOOL(stream->done);
}

PHP_METHOD(King_Inference_Stream, getMetrics)
{
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_NONE();

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    king_inference_stream_metrics(stream, return_value);
}
