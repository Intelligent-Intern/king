static zend_string *king_inference_king_local_runner_path(king_inference_model_object *model)
{
    zval *backend = king_inference_array_find(&model->config, "backend");
    const char *env_path;
    zval *runner;

    if (backend != NULL && Z_TYPE_P(backend) == IS_ARRAY) {
        zval *runner_config = king_inference_array_find(backend, "runner");
        runner = runner_config != NULL && Z_TYPE_P(runner_config) == IS_ARRAY
            ? king_inference_array_find(runner_config, "path")
            : NULL;
        if (runner != NULL && Z_TYPE_P(runner) == IS_STRING && Z_STRLEN_P(runner) > 0) {
            return zend_string_copy(Z_STR_P(runner));
        }

        runner = king_inference_array_find(backend, "runner_path");
        if (runner != NULL && Z_TYPE_P(runner) == IS_STRING && Z_STRLEN_P(runner) > 0) {
            return zend_string_copy(Z_STR_P(runner));
        }
    }

    env_path = getenv("KING_INFERENCE_RUNNER");
    if (env_path != NULL && env_path[0] != '\0') {
        return zend_string_init(env_path, strlen(env_path), 0);
    }

    return zend_string_init("king-local-infer", sizeof("king-local-infer") - 1, 0);
}

static zend_result king_inference_king_local_validate_model_config(
    zval *config,
    const char *function_name
) {
    zval *backend = king_inference_array_find(config, "backend");
    zval *runner_config;
    zval *runner_path;

    if (backend == NULL || Z_TYPE_P(backend) != IS_ARRAY) {
        return SUCCESS;
    }

    runner_config = king_inference_array_find(backend, "runner");
    if (runner_config != NULL && Z_TYPE_P(runner_config) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() local inference backend.runner must be an array when provided.",
            function_name
        );
        return FAILURE;
    }

    if (runner_config != NULL) {
        runner_path = king_inference_array_find(runner_config, "path");
        if (runner_path != NULL
            && (Z_TYPE_P(runner_path) != IS_STRING || Z_STRLEN_P(runner_path) == 0)) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() local inference backend.runner.path must be a non-empty string when provided.",
                function_name
            );
            return FAILURE;
        }
    }

    runner_path = king_inference_array_find(backend, "runner_path");
    if (runner_path != NULL
        && (Z_TYPE_P(runner_path) != IS_STRING || Z_STRLEN_P(runner_path) == 0)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() local inference backend.runner_path must be a non-empty string when provided.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static bool king_inference_king_local_runner_has_slash(zend_string *runner)
{
    return memchr(ZSTR_VAL(runner), '/', ZSTR_LEN(runner)) != NULL;
}

static bool king_inference_king_local_runner_path_is_executable(zend_string *runner)
{
    if (ZSTR_LEN(runner) == 0) {
        return false;
    }

    if (king_inference_king_local_runner_has_slash(runner)) {
        return access(ZSTR_VAL(runner), X_OK) == 0;
    }

    {
        const char *path_env = getenv("PATH");
        char *paths;
        char *saveptr = NULL;
        char *entry;
        bool found = false;

        if (path_env == NULL || path_env[0] == '\0') {
            return false;
        }

        paths = estrdup(path_env);
        entry = strtok_r(paths, ":", &saveptr);
        while (entry != NULL) {
            zend_string *candidate = strpprintf(
                0,
                "%s/%s",
                entry[0] != '\0' ? entry : ".",
                ZSTR_VAL(runner)
            );

            if (access(ZSTR_VAL(candidate), X_OK) == 0) {
                found = true;
                zend_string_release(candidate);
                break;
            }

            zend_string_release(candidate);
            entry = strtok_r(NULL, ":", &saveptr);
        }
        efree(paths);
        return found;
    }
}

static zend_result king_inference_king_local_require_runner(
    zend_string *runner,
    const char *function_name
) {
    if (king_inference_king_local_runner_path_is_executable(runner)) {
        return SUCCESS;
    }

    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "%s() local inference runner '%s' is not executable or could not be found in PATH.",
        function_name,
        ZSTR_VAL(runner)
    );
    return FAILURE;
}

static zend_string *king_inference_king_local_prompt_from_request(zval *request)
{
    zval *prompt = king_inference_array_find(request, "prompt");
    zval *messages;
    zval *message;
    smart_str buffer = {0};

    if (prompt != NULL && Z_TYPE_P(prompt) == IS_STRING) {
        return zend_string_copy(Z_STR_P(prompt));
    }

    messages = king_inference_array_find(request, "messages");
    if (messages == NULL || Z_TYPE_P(messages) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Inference request requires prompt or messages.");
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(messages), message) {
        zval *role;
        zval *content;
        smart_str text = {0};

        if (Z_TYPE_P(message) != IS_ARRAY) {
            continue;
        }
        role = king_inference_array_find(message, "role");
        content = king_inference_array_find(message, "content");
        if (king_inference_openai_append_content_text(content, &text) != SUCCESS) {
            if (!king_inference_openai_message_has_tool_calls(message)
                || king_inference_openai_append_message_tool_calls_text(message, &text) != SUCCESS) {
                smart_str_free(&text);
                continue;
            }
        }
        smart_str_0(&text);

        if (role != NULL && Z_TYPE_P(role) == IS_STRING && Z_STRLEN_P(role) > 0) {
            smart_str_append(&buffer, Z_STR_P(role));
            smart_str_appends(&buffer, ": ");
        }
        if (text.s != NULL) {
            smart_str_append(&buffer, text.s);
        }
        smart_str_appendc(&buffer, '\n');
        smart_str_free(&text);
    } ZEND_HASH_FOREACH_END();

    king_inference_openai_append_tool_prompt(request, &buffer);
    smart_str_appends(&buffer, "assistant: ");
    smart_str_0(&buffer);
    return buffer.s;
}

static zend_result king_inference_king_local_spawn_argv(
    king_inference_stream_object *stream,
    zend_string **argv,
    size_t argc
) {
    int stdout_pipe[2];
    int stderr_pipe[2];
    pid_t pid;
    char **cargv;
    size_t i;

    if (pipe(stdout_pipe) != 0) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "Could not create inference stdout pipe.");
        return FAILURE;
    }
    if (pipe(stderr_pipe) != 0) {
        close(stdout_pipe[0]);
        close(stdout_pipe[1]);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "Could not create inference stderr pipe.");
        return FAILURE;
    }

    cargv = ecalloc(argc + 1, sizeof(char *));
    for (i = 0; i < argc; i++) {
        cargv[i] = ZSTR_VAL(argv[i]);
    }

    pid = fork();
    if (pid < 0) {
        close(stdout_pipe[0]);
        close(stdout_pipe[1]);
        close(stderr_pipe[0]);
        close(stderr_pipe[1]);
        efree(cargv);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "Could not fork inference runner.");
        return FAILURE;
    }

    if (pid == 0) {
        dup2(stdout_pipe[1], STDOUT_FILENO);
        dup2(stderr_pipe[1], STDERR_FILENO);
        close(stdout_pipe[0]);
        close(stdout_pipe[1]);
        close(stderr_pipe[0]);
        close(stderr_pipe[1]);
        execvp(cargv[0], cargv);
        _exit(127);
    }

    close(stdout_pipe[1]);
    close(stderr_pipe[1]);
    fcntl(stdout_pipe[0], F_SETFL, fcntl(stdout_pipe[0], F_GETFL, 0) | O_NONBLOCK);
    fcntl(stderr_pipe[0], F_SETFL, fcntl(stderr_pipe[0], F_GETFL, 0) | O_NONBLOCK);

    stream->stdout_fd = stdout_pipe[0];
    stream->stderr_fd = stderr_pipe[0];
    stream->child_pid = (zend_long) pid;
    efree(cargv);
    return SUCCESS;
}

static void king_inference_king_local_argv_release(zend_string **argv, size_t argc)
{
    size_t i;

    for (i = 0; i < argc; i++) {
        zend_string_release(argv[i]);
    }
}

static void king_inference_king_local_append_long_arg(
    zend_string **argv,
    size_t *argc,
    zval *request,
    const char *request_key,
    const char *arg_name
) {
    zval *value = king_inference_array_find(request, request_key);

    if (value == NULL || Z_TYPE_P(value) != IS_LONG) {
        return;
    }

    argv[(*argc)++] = zend_string_init(arg_name, strlen(arg_name), 0);
    argv[(*argc)++] = strpprintf(0, ZEND_LONG_FMT, Z_LVAL_P(value));
}

static void king_inference_king_local_append_double_arg(
    zend_string **argv,
    size_t *argc,
    zval *request,
    const char *request_key,
    const char *arg_name
) {
    zval *value = king_inference_array_find(request, request_key);
    double numeric;

    if (value == NULL) {
        return;
    }
    if (Z_TYPE_P(value) == IS_DOUBLE) {
        numeric = Z_DVAL_P(value);
    } else if (Z_TYPE_P(value) == IS_LONG) {
        numeric = (double) Z_LVAL_P(value);
    } else {
        return;
    }

    argv[(*argc)++] = zend_string_init(arg_name, strlen(arg_name), 0);
    argv[(*argc)++] = strpprintf(0, "%.6f", numeric);
}

static void king_inference_king_local_append_string_arg(
    zend_string **argv,
    size_t *argc,
    zend_string *value,
    const char *arg_name)
{
    if (value == NULL || ZSTR_LEN(value) == 0) {
        return;
    }

    argv[(*argc)++] = zend_string_init(arg_name, strlen(arg_name), 0);
    argv[(*argc)++] = zend_string_copy(value);
}

static void king_inference_king_local_append_stop_args(
    zend_string **argv,
    size_t *argc,
    zval *request)
{
    zval *stop = king_inference_array_find(request, "stop");
    zval *entry;

    if (stop == NULL) {
        return;
    }
    if (Z_TYPE_P(stop) == IS_STRING) {
        king_inference_king_local_append_string_arg(argv, argc, Z_STR_P(stop), "--reverse-prompt");
        return;
    }
    if (!king_inference_openai_array_is_list(stop)) {
        return;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(stop), entry) {
        if (Z_TYPE_P(entry) == IS_STRING) {
            king_inference_king_local_append_string_arg(argv, argc, Z_STR_P(entry), "--reverse-prompt");
        }
    } ZEND_HASH_FOREACH_END();
}

static zend_result king_inference_king_local_stream_start(
    king_inference_stream_object *stream,
    const char *function_name
) {
    king_inference_model_object *model;
    zend_string *runner;
    zend_string *prompt;
    zend_string *max_tokens;
    zend_string *temperature;
    zend_string *context_tokens = NULL;
    zend_string *gpu_layers;
    zend_string *argv[56];
    size_t argc = 0;
    const char *generation_error = NULL;
    const char *stop_error = NULL;

    model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    if (!king_inference_openai_stream_generation_options_valid(&stream->request, &generation_error)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s",
            function_name,
            generation_error != NULL ? generation_error : "generation options are invalid."
        );
        return FAILURE;
    }
    if (!king_inference_openai_stop_option_valid(&stream->request, &stop_error)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() %s",
            function_name,
            stop_error != NULL ? stop_error : "stop options are invalid."
        );
        return FAILURE;
    }

    runner = king_inference_king_local_runner_path(model);
    if (king_inference_king_local_require_runner(runner, function_name) != SUCCESS) {
        zend_string_release(runner);
        return FAILURE;
    }

    prompt = king_inference_king_local_prompt_from_request(&stream->request);
    if (prompt == NULL) {
        zend_string_release(runner);
        return FAILURE;
    }

    max_tokens = strpprintf(0, ZEND_LONG_FMT, king_inference_long_from_array(&stream->request, "max_tokens", 256));
    temperature = strpprintf(0, "%.17g", king_inference_double_from_array(&stream->request, "temperature", 0.8));
    gpu_layers = strpprintf(0, ZEND_LONG_FMT, king_inference_max_gpu_layers(&model->config));

    argv[argc++] = runner;
    argv[argc++] = zend_string_init("-m", sizeof("-m") - 1, 0);
    argv[argc++] = zend_string_copy(model->artifact_path);
    argv[argc++] = zend_string_init("-p", sizeof("-p") - 1, 0);
    argv[argc++] = prompt;
    argv[argc++] = zend_string_init("-n", sizeof("-n") - 1, 0);
    argv[argc++] = max_tokens;
    argv[argc++] = zend_string_init("--temp", sizeof("--temp") - 1, 0);
    argv[argc++] = temperature;
    argv[argc++] = zend_string_init("-ngl", sizeof("-ngl") - 1, 0);
    argv[argc++] = gpu_layers;
    argv[argc++] = zend_string_init("--no-display-prompt", sizeof("--no-display-prompt") - 1, 0);
    argv[argc++] = zend_string_init("--log-disable", sizeof("--log-disable") - 1, 0);

    if (king_inference_long_from_array(&model->config, "context_tokens", 0) > 0) {
        context_tokens = strpprintf(0, ZEND_LONG_FMT, king_inference_long_from_array(&model->config, "context_tokens", 0));
        argv[argc++] = zend_string_init("-c", sizeof("-c") - 1, 0);
        argv[argc++] = context_tokens;
    }

    king_inference_king_local_append_long_arg(argv, &argc, &stream->request, "seed", "--seed");
    king_inference_king_local_append_long_arg(argv, &argc, &stream->request, "top_k", "--top-k");
    king_inference_king_local_append_double_arg(argv, &argc, &stream->request, "top_p", "--top-p");
    king_inference_king_local_append_stop_args(argv, &argc, &stream->request);

    if (king_inference_check_gpu_policy_before_run(stream, function_name) != SUCCESS) {
        king_inference_king_local_argv_release(argv, argc);
        return FAILURE;
    }

    if (king_inference_king_local_spawn_argv(stream, argv, argc) != SUCCESS) {
        king_inference_king_local_argv_release(argv, argc);
        return FAILURE;
    }

    king_inference_king_local_argv_release(argv, argc);
    return SUCCESS;
}
