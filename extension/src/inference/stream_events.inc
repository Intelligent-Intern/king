static void king_inference_stream_close_process(king_inference_stream_object *stream, bool terminate)
{
    if (stream->stdout_fd >= 0) {
        close(stream->stdout_fd);
        stream->stdout_fd = -1;
    }
    if (stream->stderr_fd >= 0) {
        close(stream->stderr_fd);
        stream->stderr_fd = -1;
    }
    if (stream->child_pid > 0) {
        if (terminate && !stream->done) {
            kill((pid_t) stream->child_pid, SIGTERM);
        }
        waitpid((pid_t) stream->child_pid, NULL, WNOHANG);
        stream->child_pid = -1;
    }
}

static bool king_inference_stream_has_native_events(king_inference_stream_object *stream)
{
    return !Z_ISUNDEF(stream->native_events) && Z_TYPE(stream->native_events) == IS_ARRAY;
}

static void king_inference_stream_openai_chunk(
    king_inference_stream_object *stream,
    const char *role,
    const char *content,
    size_t content_len,
    const char *finish_reason,
    zval *return_value
);

static void king_inference_stream_done_event(king_inference_stream_object *stream, zval *return_value);

static zend_result king_inference_stream_next_native_event(
    king_inference_stream_object *stream,
    zval *return_value
) {
    zval *event;

    if (!king_inference_stream_has_native_events(stream)) {
        return FAILURE;
    }

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
        if (stream->openai_compatible) {
            king_inference_stream_openai_chunk(
                stream,
                NULL,
                Z_STRVAL_P(event),
                Z_STRLEN_P(event),
                NULL,
                return_value
            );
            return SUCCESS;
        }

        array_init(return_value);
        add_assoc_string(return_value, "type", "token");
        add_assoc_str(return_value, "text", zend_string_copy(Z_STR_P(event)));
        return SUCCESS;
    }

    ZVAL_COPY(return_value, event);
    return SUCCESS;
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

    king_inference_stream_openai_chunk_base(stream, return_value);
    add_assoc_string(return_value, "object", "error");
    array_init(&error);
    add_assoc_string(&error, "type", "server_error");
    add_assoc_stringl(&error, "message", message, message_len);
    add_assoc_zval(return_value, "error", &error);
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
    add_assoc_bool(return_value, "done", stream->done);
    add_assoc_bool(return_value, "cancelled", stream->cancelled);
    add_assoc_long(return_value, "exit_code", stream->exit_code);
    add_assoc_bool(return_value, "openai_compatible", stream->openai_compatible);
    add_assoc_bool(return_value, "gpu_thermal_preflight_checked", stream->gpu_thermal_preflight_checked);
    add_assoc_long(return_value, "gpu_thermal_preflight_at", stream->gpu_thermal_preflight_at);
    add_assoc_bool(
        return_value,
        "gpu_thermal_preflight_temperature_available",
        stream->gpu_thermal_preflight_temperature_available
    );
    if (stream->gpu_thermal_preflight_temperature_available) {
        add_assoc_double(
            return_value,
            "gpu_thermal_preflight_temperature_c",
            stream->gpu_thermal_preflight_temperature_c
        );
    } else {
        add_assoc_null(return_value, "gpu_thermal_preflight_temperature_c");
    }
    add_assoc_bool(return_value, "native_stream", native_event_count > 0);
    add_assoc_long(return_value, "native_event_count", native_event_count);
    add_assoc_long(return_value, "native_event_index", (zend_long) stream->native_event_index);
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
    if (stream->openai_compatible) {
        king_inference_stream_openai_chunk(stream, NULL, NULL, 0, "stop", return_value);
        return;
    }

    array_init(return_value);
    add_assoc_string(return_value, "type", stream->cancelled ? "cancelled" : "done");
    add_assoc_long(return_value, "exit_code", stream->exit_code);
    add_assoc_long(return_value, "chunks", stream->chunk_count);
    add_assoc_long(return_value, "bytes", stream->bytes_emitted);
}

static zend_result king_inference_stream_next_event(
    king_inference_stream_object *stream,
    zend_long timeout_ms,
    zval *return_value
) {
    king_inference_model_object *model;
    fd_set readfds;
    struct timeval timeout;
    int maxfd = -1;
    int ready;
    char buffer[4096];
    ssize_t read_len;

    if (stream->start_event_pending) {
        stream->start_event_pending = false;
        if (stream->openai_compatible) {
            king_inference_stream_openai_chunk(stream, "assistant", NULL, 0, NULL, return_value);
            return SUCCESS;
        }
        array_init(return_value);
        add_assoc_string(return_value, "type", "start");
        add_assoc_string(
            return_value,
            "backend",
            king_inference_stream_has_native_events(stream) ? "king_native_cpu" : "local"
        );
        add_assoc_bool(return_value, "native_stream", king_inference_stream_has_native_events(stream));
        add_assoc_long(return_value, "pid", stream->child_pid);
        add_assoc_bool(return_value, "gpu_thermal_preflight_checked", stream->gpu_thermal_preflight_checked);
        add_assoc_long(return_value, "gpu_thermal_preflight_at", stream->gpu_thermal_preflight_at);
        if (stream->gpu_thermal_preflight_temperature_available) {
            add_assoc_double(
                return_value,
                "gpu_thermal_preflight_temperature_c",
                stream->gpu_thermal_preflight_temperature_c
            );
        } else {
            add_assoc_null(return_value, "gpu_thermal_preflight_temperature_c");
        }
        return SUCCESS;
    }

    if (stream->done) {
        ZVAL_NULL(return_value);
        return SUCCESS;
    }

    if (!Z_ISUNDEF(stream->model) && Z_TYPE(stream->model) == IS_OBJECT) {
        model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
        if (king_inference_check_gpu_policy(&model->config, "King inference stream") != SUCCESS) {
            stream->cancelled = true;
            stream->done = true;
            king_inference_stream_close_process(stream, true);
            return FAILURE;
        }
    }

    if (king_inference_stream_has_native_events(stream)) {
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
