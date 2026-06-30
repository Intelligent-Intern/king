#include "stream_process_close.inc"

static bool king_inference_stream_has_native_events(king_inference_stream_object *stream)
{
    return !Z_ISUNDEF(stream->native_events) && Z_TYPE(stream->native_events) == IS_ARRAY;
}

#include "stream_native_lazy.inc"

static zend_long king_inference_runtime_monotonic_ms(void)
{
    struct timespec ts;

    if (clock_gettime(CLOCK_MONOTONIC, &ts) != 0) {
        return (zend_long) time(NULL) * 1000;
    }
    return ((zend_long) ts.tv_sec * 1000) + ((zend_long) ts.tv_nsec / 1000000);
}

#include "stream_hotpath_metrics.inc"

static void king_inference_stream_model_runtime_started(king_inference_stream_object *stream)
{
    king_inference_model_object *model;

    stream->runtime_started_ms = king_inference_runtime_monotonic_ms();
    stream->runtime_first_token_ms = 0;
    stream->runtime_finished_ms = 0;
    stream->runtime_generated_tokens = 0;
    if (Z_ISUNDEF(stream->model) || Z_TYPE(stream->model) != IS_OBJECT) {
        return;
    }

    model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    model->runtime_last_started_ms = stream->runtime_started_ms;
    model->runtime_last_first_token_ms = 0;
    model->runtime_last_finished_ms = 0;
    model->runtime_last_duration_ms = 0;
    model->runtime_last_time_to_first_token_ms = 0;
    model->runtime_last_generated_tokens = 0;
    model->runtime_last_tokens_per_second = 0.0;
    model->runtime_last_timing_available = false;
    model->runtime_last_first_token_available = false;
    model->cuda_decoder_prompt_loop_last_prefill_tokens = 0;
    model->cuda_decoder_prompt_loop_last_prefill_ns = 0;
    king_inference_cuda_decoder_prompt_loop_reset_hot_token_profile(model);
}

static void king_inference_stream_model_runtime_token(king_inference_stream_object *stream)
{
    king_inference_model_object *model;
    zend_long now = king_inference_runtime_monotonic_ms();

    if (stream->runtime_started_ms <= 0) {
        stream->runtime_started_ms = now;
    }
    if (stream->runtime_first_token_ms <= 0) {
        stream->runtime_first_token_ms = now;
    }
    stream->runtime_generated_tokens++;
    if (Z_ISUNDEF(stream->model) || Z_TYPE(stream->model) != IS_OBJECT) {
        return;
    }

    model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    model->runtime_last_started_ms = stream->runtime_started_ms;
    model->runtime_last_first_token_ms = stream->runtime_first_token_ms;
    model->runtime_last_time_to_first_token_ms = stream->runtime_first_token_ms - stream->runtime_started_ms;
    model->runtime_last_generated_tokens = stream->runtime_generated_tokens;
    model->runtime_last_first_token_available = true;
}

static void king_inference_stream_model_runtime_finished(king_inference_stream_object *stream)
{
    king_inference_model_object *model;
    zend_long duration_ms;

    if (stream->runtime_finished_ms <= 0) {
        stream->runtime_finished_ms = king_inference_runtime_monotonic_ms();
    }
    if (stream->runtime_started_ms <= 0) {
        stream->runtime_started_ms = stream->runtime_finished_ms;
    }
    duration_ms = stream->runtime_finished_ms - stream->runtime_started_ms;
    if (duration_ms < 0) {
        duration_ms = 0;
    }
    if (Z_ISUNDEF(stream->model) || Z_TYPE(stream->model) != IS_OBJECT) {
        return;
    }

    model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    model->runtime_last_started_ms = stream->runtime_started_ms;
    model->runtime_last_first_token_ms = stream->runtime_first_token_ms;
    model->runtime_last_finished_ms = stream->runtime_finished_ms;
    model->runtime_last_duration_ms = duration_ms;
    model->runtime_last_time_to_first_token_ms = stream->runtime_first_token_ms > 0
        ? stream->runtime_first_token_ms - stream->runtime_started_ms
        : 0;
    model->runtime_last_generated_tokens = stream->runtime_generated_tokens;
    model->runtime_last_tokens_per_second = duration_ms > 0
        ? ((double) stream->runtime_generated_tokens * 1000.0) / (double) duration_ms
        : 0.0;
    model->runtime_last_timing_available = true;
    model->runtime_last_first_token_available = stream->runtime_first_token_ms > 0;
}

static void king_inference_stream_reset_decoder_metrics(king_inference_stream_object *stream)
{
    stream->native_decoder_token_count = 0;
    stream->native_decoder_last_token_id = 0;
    stream->native_decoder_last_probability = 0.0;
    stream->native_decoder_last_logit = 0.0;
    stream->native_decoder_last_rank = 0.0;
    stream->native_decoder_last_token_available = false;
    stream->native_decoder_last_score_available = false;
}

static void king_inference_stream_abort_state(king_inference_stream_object *stream)
{
    stream->start_event_pending = false;
    if (king_inference_stream_has_native_events(stream)) {
        stream->native_event_index = (zend_ulong) zend_hash_num_elements(Z_ARRVAL(stream->native_events));
    }
    king_inference_cuda_decoder_prompt_stream_release(stream);
    king_inference_stream_close_process(stream, true);
    stream->done = true;
    king_inference_stream_model_runtime_finished(stream);
}

static void king_inference_stream_cancel_state(king_inference_stream_object *stream)
{
    stream->cancelled = true;
    stream->timed_out = false;
    king_inference_stream_abort_state(stream);
}

static void king_inference_stream_timeout_state(king_inference_stream_object *stream)
{
    stream->cancelled = true;
    stream->timed_out = true;
    king_inference_stream_abort_state(stream);
}

#include "stream_gpu_policy_status.inc"

static void king_inference_stream_openai_chunk(
    king_inference_stream_object *stream,
    const char *role,
    const char *content,
    size_t content_len,
    const char *finish_reason,
    zval *return_value
);

static void king_inference_stream_openai_error(
    king_inference_stream_object *stream,
    const char *message,
    size_t message_len,
    zval *return_value
);

static void king_inference_stream_done_event(king_inference_stream_object *stream, zval *return_value);

static bool king_inference_stream_openai_array_event(
    king_inference_stream_object *stream,
    zval *event,
    zval *return_value
) {
    zval *type = king_inference_array_find(event, "type");
    zval *text = king_inference_array_find(event, "text");
    zval *message = king_inference_array_find(event, "message");
    const char *type_name = type != NULL && Z_TYPE_P(type) == IS_STRING ? Z_STRVAL_P(type) : "";

    if (strcmp(type_name, "token") == 0 && text != NULL && Z_TYPE_P(text) == IS_STRING) {
        stream->chunk_count++;
        stream->bytes_emitted += (zend_long) Z_STRLEN_P(text);
        king_inference_stream_model_runtime_token(stream);
        king_inference_stream_openai_chunk(stream, NULL, Z_STRVAL_P(text), Z_STRLEN_P(text), NULL, return_value);
        return true;
    }
    if ((strcmp(type_name, "stderr") == 0 || strcmp(type_name, "error") == 0)
        && ((text != NULL && Z_TYPE_P(text) == IS_STRING) || (message != NULL && Z_TYPE_P(message) == IS_STRING))) {
        zval *error_text = text != NULL && Z_TYPE_P(text) == IS_STRING ? text : message;
        king_inference_stream_openai_error(stream, Z_STRVAL_P(error_text), Z_STRLEN_P(error_text), return_value);
        return true;
    }
    if (strcmp(type_name, "done") == 0 || strcmp(type_name, "cancelled") == 0) {
        king_inference_stream_done_event(stream, return_value);
        return true;
    }

    return false;
}

static zend_result king_inference_stream_next_native_event(
    king_inference_stream_object *stream,
    zval *return_value
) {
    zval *event;

    if (!king_inference_stream_has_native_events(stream)) {
        return FAILURE;
    }

    while (true) {
        event = zend_hash_index_find(Z_ARRVAL(stream->native_events), stream->native_event_index);
        if (event == NULL) {
            stream->exit_code = 0;
            king_inference_stream_done_event(stream, return_value);
            return SUCCESS;
        }

        stream->native_event_index++;
        if (Z_TYPE_P(event) == IS_STRING) {
            stream->chunk_count++;
            stream->bytes_emitted += (zend_long) Z_STRLEN_P(event);
            king_inference_stream_model_runtime_token(stream);
            if (stream->openai_compatible) {
                king_inference_stream_openai_chunk(stream, NULL, Z_STRVAL_P(event), Z_STRLEN_P(event), NULL, return_value);
                return SUCCESS;
            }

            array_init(return_value);
            add_assoc_string(return_value, "type", "token");
            add_assoc_str(return_value, "text", zend_string_copy(Z_STR_P(event)));
            return SUCCESS;
        }
        if (stream->openai_compatible) {
            if (Z_TYPE_P(event) == IS_ARRAY && king_inference_stream_openai_array_event(stream, event, return_value)) {
                return SUCCESS;
            }
            continue;
        }

        ZVAL_COPY(return_value, event);
        return SUCCESS;
    }
}

static bool king_inference_stream_openai_requested(zval *request, zval *options)
{
    zval *flag = king_inference_array_find(options, "openai_compatible");
    zval *format;

    if (flag != NULL && zend_is_true(flag)) {
        return true;
    }
    flag = king_inference_array_find(request, "openai_compatible");
    if (flag != NULL && zend_is_true(flag)) {
        return true;
    }

    format = king_inference_array_find(options, "format");
    if (format == NULL) {
        format = king_inference_array_find(request, "format");
    }
    if (format != NULL && Z_TYPE_P(format) == IS_STRING) {
        return zend_string_equals_literal(Z_STR_P(format), "openai")
            || zend_string_equals_literal(Z_STR_P(format), "openai_chat")
            || zend_string_equals_literal(Z_STR_P(format), "openai_chat_completions");
    }

    return false;
}

static zend_result king_inference_stream_validate_openai_flag(
    zval *source,
    const char *source_name,
    const char *function_name
) {
    zval *flag = king_inference_array_find(source, "openai_compatible");

    if (flag == NULL || Z_TYPE_P(flag) == IS_NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(flag) == IS_TRUE || Z_TYPE_P(flag) == IS_FALSE) {
        return SUCCESS;
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "%s() %s.openai_compatible must be a boolean when provided.",
        function_name,
        source_name
    );
    return FAILURE;
}

static zend_result king_inference_stream_validate_format(
    zval *source,
    const char *source_name,
    const char *function_name
) {
    zval *format = king_inference_array_find(source, "format");

    if (format == NULL || Z_TYPE_P(format) == IS_NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(format) != IS_STRING || Z_STRLEN_P(format) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s.format must be a non-empty string when provided.",
            function_name,
            source_name
        );
        return FAILURE;
    }
    if (zend_string_equals_literal(Z_STR_P(format), "openai")
        || zend_string_equals_literal(Z_STR_P(format), "openai_chat")
        || zend_string_equals_literal(Z_STR_P(format), "openai_chat_completions")) {
        return SUCCESS;
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "%s() %s.format is not a supported inference stream format.",
        function_name,
        source_name
    );
    return FAILURE;
}

static zend_result king_inference_stream_validate_native_graph_shapes(
    zval *source,
    const char *source_name,
    const char *function_name
) {
    zval *graph = king_inference_array_find(source, "graph");
    zval *graphs = king_inference_array_find(source, "graphs");
    zval *graph_options = king_inference_array_find(source, "graph_options");

    if (graph != NULL && !king_inference_openai_array_is_object(graph)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s.graph must be an object array when provided.",
            function_name,
            source_name
        );
        return FAILURE;
    }
    if (graphs != NULL && !king_inference_openai_array_is_list(graphs)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s.graphs must be a list array when provided.",
            function_name,
            source_name
        );
        return FAILURE;
    }
    if (graph_options != NULL && !king_inference_openai_array_is_object(graph_options)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s.graph_options must be an object array when provided.",
            function_name,
            source_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_stream_validate_request_options(
    zval *request,
    zval *options,
    const char *function_name
) {
    if (king_inference_stream_validate_openai_flag(request, "request", function_name) != SUCCESS
        || king_inference_stream_validate_openai_flag(options, "options", function_name) != SUCCESS
        || king_inference_stream_validate_format(request, "request", function_name) != SUCCESS
        || king_inference_stream_validate_format(options, "options", function_name) != SUCCESS
        || king_inference_stream_validate_native_graph_shapes(request, "request", function_name) != SUCCESS
        || king_inference_stream_validate_native_graph_shapes(options, "options", function_name) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static void king_inference_stream_prepare_openai(king_inference_stream_object *stream)
{
    if (stream->response_id != NULL) {
        zend_string_release(stream->response_id);
        stream->response_id = NULL;
    }

    stream->created_at = (zend_long) time(NULL);
    stream->response_id = strpprintf(
        0,
        "chatcmpl-king-%lu-" ZEND_LONG_FMT,
        (zend_ulong) stream->std.handle,
        stream->created_at
    );
}

static zend_string *king_inference_stream_openai_model_name(king_inference_stream_object *stream)
{
    zval *request_model = king_inference_array_find(&stream->request, "model");

    if (request_model != NULL && Z_TYPE_P(request_model) == IS_STRING && Z_STRLEN_P(request_model) > 0) {
        return zend_string_copy(Z_STR_P(request_model));
    }

    if (!Z_ISUNDEF(stream->model) && Z_TYPE(stream->model) == IS_OBJECT) {
        king_inference_model_object *model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
        if (model->name != NULL) {
            return zend_string_copy(model->name);
        }
    }

    return zend_string_init("king-native", sizeof("king-native") - 1, 0);
}

static void king_inference_stream_openai_chunk_base(
    king_inference_stream_object *stream,
    zval *return_value
) {
    zend_string *model_name = king_inference_stream_openai_model_name(stream);

    if (stream->response_id == NULL) {
        king_inference_stream_prepare_openai(stream);
    }

    array_init(return_value);
    add_assoc_str(return_value, "id", zend_string_copy(stream->response_id));
    add_assoc_string(return_value, "object", "chat.completion.chunk");
    add_assoc_long(return_value, "created", stream->created_at);
    add_assoc_str(return_value, "model", model_name);
}

static void king_inference_stream_openai_chunk(
    king_inference_stream_object *stream,
    const char *role,
    const char *content,
    size_t content_len,
    const char *finish_reason,
    zval *return_value
) {
    zval choices;
    zval choice;
    zval delta;

    king_inference_stream_openai_chunk_base(stream, return_value);
    array_init(&choices);
    array_init(&choice);
    add_assoc_long(&choice, "index", 0);
    array_init(&delta);
    if (role != NULL) {
        add_assoc_string(&delta, "role", role);
    }
    if (content != NULL) {
        add_assoc_stringl(&delta, "content", content, content_len);
    } else if (role == NULL && finish_reason != NULL) {
        add_assoc_stringl(&delta, "content", "", 0);
    }
    add_assoc_zval(&choice, "delta", &delta);
    if (finish_reason != NULL) {
        add_assoc_string(&choice, "finish_reason", finish_reason);
    } else {
        add_assoc_null(&choice, "finish_reason");
    }
    add_next_index_zval(&choices, &choice);
    add_assoc_zval(return_value, "choices", &choices);
    if (king_inference_openai_stream_usage_requested(&stream->request)) {
        add_assoc_null(return_value, "usage");
    }
}

static void king_inference_stream_openai_error(
    king_inference_stream_object *stream,
    const char *message,
    size_t message_len,
    zval *return_value
) {
    zval error;
    zend_string *safe_message = zend_string_init(message != NULL ? message : "", message != NULL ? message_len : 0, 0);

    king_inference_stream_openai_chunk_base(stream, return_value);
    add_assoc_string(return_value, "object", "error");
    king_inference_openai_error_object(
        &error,
        500,
        ZSTR_VAL(safe_message),
        "server_error",
        NULL
    );
    add_assoc_zval(return_value, "error", &error);
    zend_string_release(safe_message);
}

static void king_inference_stream_metrics(king_inference_stream_object *stream, zval *return_value)
{
    zend_long native_event_count = king_inference_stream_has_native_events(stream)
        ? (zend_long) zend_hash_num_elements(Z_ARRVAL(stream->native_events))
        : 0;

    array_init(return_value);
    add_assoc_long(return_value, "chunks", stream->chunk_count);
    add_assoc_long(return_value, "stderr_chunks", stream->stderr_count);
    add_assoc_long(return_value, "bytes", stream->bytes_emitted);
    king_inference_stream_add_hotpath_metrics(stream, return_value);
    add_assoc_bool(return_value, "done", stream->done);
    add_assoc_bool(return_value, "cancelled", stream->cancelled);
    add_assoc_bool(return_value, "timed_out", stream->timed_out);
    king_inference_stream_add_gpu_policy_metrics(stream, return_value);
    add_assoc_long(return_value, "exit_code", stream->exit_code);
    add_assoc_bool(return_value, "openai_compatible", stream->openai_compatible);
    add_assoc_bool(return_value, "native_stream", native_event_count > 0);
    add_assoc_long(return_value, "native_event_count", native_event_count);
    add_assoc_long(return_value, "native_event_index", (zend_long) stream->native_event_index);
    add_assoc_long(return_value, "native_decoder_tokens", stream->native_decoder_token_count);
    if (stream->native_decoder_last_token_available) {
        if (stream->native_decoder_last_token_id <= (zend_ulong) ZEND_LONG_MAX) {
            add_assoc_long(return_value, "native_decoder_last_token_id", (zend_long) stream->native_decoder_last_token_id);
        } else {
            add_assoc_double(return_value, "native_decoder_last_token_id", (double) stream->native_decoder_last_token_id);
        }
    } else {
        add_assoc_null(return_value, "native_decoder_last_token_id");
    }
    if (stream->native_decoder_last_score_available) {
        add_assoc_double(return_value, "native_decoder_last_probability", stream->native_decoder_last_probability);
        add_assoc_double(return_value, "native_decoder_last_logit", stream->native_decoder_last_logit);
        add_assoc_double(return_value, "native_decoder_last_rank", stream->native_decoder_last_rank);
    } else {
        add_assoc_null(return_value, "native_decoder_last_probability");
        add_assoc_null(return_value, "native_decoder_last_logit");
        add_assoc_null(return_value, "native_decoder_last_rank");
    }
}

static const char *king_inference_stream_openai_finish_reason(king_inference_stream_object *stream)
{
    zval *max_tokens;

    if (stream->cancelled || stream->gpu_thermal_aborted || stream->gpu_power_aborted) {
        return "stop";
    }

    max_tokens = king_inference_array_find(&stream->request, "max_tokens");
    if (max_tokens != NULL
        && Z_TYPE_P(max_tokens) == IS_LONG
        && Z_LVAL_P(max_tokens) > 0
        && stream->runtime_generated_tokens >= Z_LVAL_P(max_tokens)) {
        return "length";
    }

    return "stop";
}

static void king_inference_stream_done_event(king_inference_stream_object *stream, zval *return_value)
{
    int status = 0;
    pid_t waited;

    if (stream->child_pid > 0) {
        waited = waitpid((pid_t) stream->child_pid, &status, WNOHANG);
        if (waited == (pid_t) stream->child_pid) {
            stream->exit_code = WIFEXITED(status) ? WEXITSTATUS(status) : -1;
            stream->child_pid = -1;
        }
    }

    stream->done = true;
    king_inference_stream_model_runtime_finished(stream);
    if (stream->openai_compatible) {
        king_inference_stream_openai_chunk(
            stream,
            NULL,
            NULL,
            0,
            king_inference_stream_openai_finish_reason(stream),
            return_value
        );
        return;
    }

    array_init(return_value);
    add_assoc_string(return_value, "type", stream->cancelled ? "cancelled" : "done");
    add_assoc_long(return_value, "exit_code", stream->exit_code);
    add_assoc_long(return_value, "chunks", stream->chunk_count);
    add_assoc_long(return_value, "bytes", stream->bytes_emitted);
    add_assoc_bool(return_value, "timed_out", stream->timed_out);
    king_inference_stream_add_gpu_policy_runtime_metrics(stream, return_value);
    king_inference_stream_add_gpu_policy_abort_metrics(stream, return_value);
}

static zend_result king_inference_stream_next_event(
    king_inference_stream_object *stream,
    zend_long timeout_ms,
    zval *return_value
) {
    king_inference_model_object *model = NULL;
    fd_set readfds;
    struct timeval timeout;
    int maxfd = -1;
    int ready;
    char buffer[4096];
    ssize_t read_len;

    if (stream->start_event_pending) {
        king_inference_backend_kind kind = KING_INFERENCE_BACKEND_LOCAL;

        stream->start_event_pending = false;
        if (!Z_ISUNDEF(stream->model) && Z_TYPE(stream->model) == IS_OBJECT) {
            model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
            if (king_inference_backend_kind_from_config(&model->config, &kind, "King inference stream") != SUCCESS) {
                return FAILURE;
            }
        }
        if (stream->openai_compatible) {
            if (king_inference_stream_has_native_events(stream)) {
                if (model != NULL
                    && king_inference_check_gpu_policy_during_run(stream, "King inference stream") != SUCCESS) {
                    king_inference_stream_gpu_policy_abort_state(stream);
                    return FAILURE;
                }
                if (king_inference_stream_ensure_native_event_available(stream) != SUCCESS) {
                    if (king_inference_stream_gpu_policy_abort_code(stream) != NULL) {
                        king_inference_stream_gpu_policy_abort_state(stream);
                    }
                    return FAILURE;
                }
                return king_inference_stream_next_native_event(stream, return_value);
            }
            king_inference_stream_openai_chunk(stream, "assistant", NULL, 0, NULL, return_value);
            return SUCCESS;
        }
        array_init(return_value);
        add_assoc_string(return_value, "type", "start");
        add_assoc_string(
            return_value,
            "backend",
            king_inference_backend_kind_name(kind)
        );
        add_assoc_bool(return_value, "native_stream", king_inference_stream_has_native_events(stream));
        add_assoc_long(return_value, "pid", stream->child_pid);
        king_inference_stream_add_gpu_policy_start_status(stream, return_value);
        return SUCCESS;
    }

    if (stream->done) {
        ZVAL_NULL(return_value);
        return SUCCESS;
    }

    if (!Z_ISUNDEF(stream->model) && Z_TYPE(stream->model) == IS_OBJECT) {
        model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
        if (king_inference_check_gpu_policy_during_run(stream, "King inference stream") != SUCCESS) {
            king_inference_stream_gpu_policy_abort_state(stream);
            return FAILURE;
        }
    }

    if (king_inference_stream_has_native_events(stream)) {
        if (king_inference_stream_ensure_native_event_available(stream) != SUCCESS) {
            if (king_inference_stream_gpu_policy_abort_code(stream) != NULL) {
                king_inference_stream_gpu_policy_abort_state(stream);
            }
            return FAILURE;
        }
        return king_inference_stream_next_native_event(stream, return_value);
    }

    if (stream->stdout_fd < 0 && stream->stderr_fd < 0) {
        king_inference_stream_done_event(stream, return_value);
        return SUCCESS;
    }

    FD_ZERO(&readfds);
    if (stream->stdout_fd >= 0) {
        FD_SET(stream->stdout_fd, &readfds);
        maxfd = stream->stdout_fd;
    }
    if (stream->stderr_fd >= 0) {
        FD_SET(stream->stderr_fd, &readfds);
        if (stream->stderr_fd > maxfd) {
            maxfd = stream->stderr_fd;
        }
    }

    timeout.tv_sec = timeout_ms < 0 ? 0 : timeout_ms / 1000;
    timeout.tv_usec = timeout_ms < 0 ? 0 : (timeout_ms % 1000) * 1000;
    ready = select(maxfd + 1, &readfds, NULL, NULL, timeout_ms < 0 ? NULL : &timeout);
    if (ready < 0) {
        if (errno == EINTR) {
            ZVAL_NULL(return_value);
            return SUCCESS;
        }
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "Inference stream select failed.");
        return FAILURE;
    }
    if (ready == 0) {
        ZVAL_NULL(return_value);
        return SUCCESS;
    }

    if (stream->stderr_fd >= 0 && FD_ISSET(stream->stderr_fd, &readfds)) {
        read_len = read(stream->stderr_fd, buffer, sizeof(buffer));
        if (read_len > 0) {
            stream->stderr_count++;
            if (stream->openai_compatible) {
                king_inference_stream_openai_error(stream, buffer, (size_t) read_len, return_value);
                return SUCCESS;
            }
            array_init(return_value);
            add_assoc_string(return_value, "type", "stderr");
            add_assoc_stringl(return_value, "text", buffer, (size_t) read_len);
            return SUCCESS;
        }
        close(stream->stderr_fd);
        stream->stderr_fd = -1;
    }

    if (stream->stdout_fd >= 0 && FD_ISSET(stream->stdout_fd, &readfds)) {
        read_len = read(stream->stdout_fd, buffer, sizeof(buffer));
        if (read_len > 0) {
            stream->chunk_count++;
            stream->bytes_emitted += read_len;
            king_inference_stream_model_runtime_token(stream);
            if (stream->openai_compatible) {
                king_inference_stream_openai_chunk(stream, NULL, buffer, (size_t) read_len, NULL, return_value);
                return SUCCESS;
            }
            array_init(return_value);
            add_assoc_string(return_value, "type", "token");
            add_assoc_stringl(return_value, "text", buffer, (size_t) read_len);
            return SUCCESS;
        }
        close(stream->stdout_fd);
        stream->stdout_fd = -1;
    }

    ZVAL_NULL(return_value);
    return SUCCESS;
}
