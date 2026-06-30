#include "../backend/openai_backend.inc"

static bool king_inference_openai_model_has_token(
    king_inference_model_object *model,
    const char *token
) {
    return token != NULL
        && Z_TYPE(model->tokenizer_lookup) == IS_ARRAY
        && zend_hash_str_exists(Z_ARRVAL(model->tokenizer_lookup), token, strlen(token));
}

static bool king_inference_openai_model_is_gemma(king_inference_model_object *model)
{
    return model != NULL
        && ((model->gguf.architecture != NULL
                && (zend_string_equals_literal(model->gguf.architecture, "gemma3")
                    || zend_string_equals_literal(model->gguf.architecture, "gemma4")))
            || (model->gguf.tokenizer_model != NULL
                && (zend_string_equals_literal(model->gguf.tokenizer_model, "gemma")
                    || zend_string_equals_literal(model->gguf.tokenizer_model, "gemma3")
                    || zend_string_equals_literal(model->gguf.tokenizer_model, "gemma4"))));
}

static bool king_inference_openai_model_uses_gemma_pipe_turn_template(king_inference_model_object *model)
{
    return king_inference_openai_model_is_gemma(model)
        && king_inference_openai_model_has_token(model, "<|turn>")
        && king_inference_openai_model_has_token(model, "<turn|>");
}

static bool king_inference_openai_model_uses_gemma_start_turn_template(king_inference_model_object *model)
{
    return king_inference_openai_model_is_gemma(model)
        && king_inference_openai_model_has_token(model, "<start_of_turn>")
        && king_inference_openai_model_has_token(model, "<end_of_turn>");
}

static bool king_inference_openai_model_uses_gemma_chat_template(king_inference_model_object *model)
{
    return king_inference_openai_model_uses_gemma_start_turn_template(model)
        || king_inference_openai_model_uses_gemma_pipe_turn_template(model);
}

static zend_result king_inference_openai_append_message_text(zval *message, smart_str *text)
{
    zval *role = king_inference_array_find(message, "role");
    zval *content = king_inference_array_find(message, "content");

    if (king_inference_openai_append_content_text(content, text) == SUCCESS) {
        return SUCCESS;
    }
    if (role != NULL
        && Z_TYPE_P(role) == IS_STRING
        && zend_string_equals_literal(Z_STR_P(role), "assistant")
        && king_inference_openai_append_message_tool_calls_text(message, text) == SUCCESS) {
        return SUCCESS;
    }
    return FAILURE;
}

static zend_result king_inference_openai_render_gemma_messages_prompt(
    zval *payload,
    zval *prompt,
    bool pipe_turn_template,
    bool prepend_bos
)
{
    zval *messages = king_inference_array_find(payload, "messages");
    zval *message;
    smart_str rendered = {0};
    smart_str pending_instructions = {0};

    if (prepend_bos) {
        smart_str_appends(&rendered, "<bos>");
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(messages), message) {
        zval *role;
        const char *turn_role = "user";
        smart_str text = {0};

        if (Z_TYPE_P(message) != IS_ARRAY) {
            smart_str_free(&rendered);
            return FAILURE;
        }
        role = king_inference_array_find(message, "role");
        if (role == NULL || Z_TYPE_P(role) != IS_STRING || Z_STRLEN_P(role) == 0) {
            smart_str_free(&pending_instructions);
            smart_str_free(&rendered);
            return FAILURE;
        }
        if (zend_string_equals_literal(Z_STR_P(role), "assistant")) {
            turn_role = "model";
        }
        if (king_inference_openai_append_message_text(message, &text) != SUCCESS) {
            smart_str_free(&text);
            smart_str_free(&pending_instructions);
            smart_str_free(&rendered);
            return FAILURE;
        }
        if (zend_string_equals_literal(Z_STR_P(role), "system")
            || zend_string_equals_literal(Z_STR_P(role), "developer")) {
            smart_str_0(&text);
            if (text.s != NULL) {
                smart_str_append(&pending_instructions, text.s);
                smart_str_appends(&pending_instructions, "\n\n");
            }
            smart_str_free(&text);
            continue;
        }

        smart_str_appends(&rendered, pipe_turn_template ? "<|turn>" : "<start_of_turn>");
        smart_str_appends(&rendered, turn_role);
        smart_str_appendc(&rendered, '\n');
        if (zend_string_equals_literal(Z_STR_P(role), "user")
            && pending_instructions.s != NULL
            && ZSTR_LEN(pending_instructions.s) > 0) {
            smart_str_0(&pending_instructions);
            smart_str_append(&rendered, pending_instructions.s);
            smart_str_free(&pending_instructions);
        }
        if (zend_string_equals_literal(Z_STR_P(role), "tool")) {
            smart_str_appends(&rendered, "Tool result:\n");
        }
        smart_str_0(&text);
        if (text.s != NULL) {
            smart_str_append(&rendered, text.s);
        }
        smart_str_free(&text);
        smart_str_appends(&rendered, pipe_turn_template ? "<turn|>\n" : "<end_of_turn>\n");
    } ZEND_HASH_FOREACH_END();

    king_inference_openai_append_response_format_instruction(payload, &pending_instructions);
    if (pending_instructions.s != NULL && ZSTR_LEN(pending_instructions.s) > 0) {
        smart_str_appends(&rendered, pipe_turn_template ? "<|turn>user\n" : "<start_of_turn>user\n");
        smart_str_0(&pending_instructions);
        smart_str_append(&rendered, pending_instructions.s);
        smart_str_appends(&rendered, pipe_turn_template ? "<turn|>\n" : "<end_of_turn>\n");
        smart_str_free(&pending_instructions);
    }

    smart_str_appends(&rendered, pipe_turn_template ? "<|turn>model\n" : "<start_of_turn>model\n");
    smart_str_0(&rendered);
    if (rendered.s == NULL) {
        ZVAL_STRING(prompt, pipe_turn_template ? "<|turn>model\n" : "<start_of_turn>model\n");
    } else {
        ZVAL_STR(prompt, rendered.s);
    }
    return SUCCESS;
}

static zend_result king_inference_openai_render_messages_prompt(
    king_inference_model_object *model,
    zval *payload,
    zval *prompt)
{
    zval *messages = king_inference_array_find(payload, "messages");
    zval *message;
    smart_str rendered = {0};

    if (!king_inference_openai_array_is_list(messages)) {
        return FAILURE;
    }
    if (king_inference_openai_model_uses_gemma_chat_template(model)) {
        bool start_turn_template = king_inference_openai_model_uses_gemma_start_turn_template(model);
        return king_inference_openai_render_gemma_messages_prompt(
            payload,
            prompt,
            !start_turn_template && king_inference_openai_model_uses_gemma_pipe_turn_template(model),
            king_inference_openai_model_has_token(model, "<bos>")
        );
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(messages), message) {
        zval *role;
        smart_str text = {0};

        if (Z_TYPE_P(message) != IS_ARRAY) {
            smart_str_free(&rendered);
            return FAILURE;
        }
        role = king_inference_array_find(message, "role");
        if (role == NULL || Z_TYPE_P(role) != IS_STRING || Z_STRLEN_P(role) == 0) {
            smart_str_free(&rendered);
            return FAILURE;
        }

        smart_str_append(&rendered, Z_STR_P(role));
        smart_str_appends(&rendered, ": ");
        if (king_inference_openai_append_message_text(message, &text) == SUCCESS) {
            smart_str_0(&text);
            if (text.s != NULL) {
                smart_str_append(&rendered, text.s);
            }
        }
        smart_str_free(&text);
        smart_str_appends(&rendered, "\n");
    } ZEND_HASH_FOREACH_END();

    king_inference_openai_append_response_format_instruction(payload, &rendered);
    smart_str_appends(&rendered, "assistant: ");
    smart_str_0(&rendered);
    if (rendered.s == NULL) {
        ZVAL_STRING(prompt, "assistant: ");
    } else {
        ZVAL_STR(prompt, rendered.s);
    }
    return SUCCESS;
}

static zend_long king_inference_openai_native_cpu_max_operations(king_inference_model_object *model)
{
    zend_ulong vocab = model->gguf.tokenizer_token_count > 0 ? model->gguf.tokenizer_token_count : 262144;
    zend_ulong hidden = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH] > 0
        ? model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH]
        : 1152;
    zend_ulong fallback = 400000000;
    zend_ulong candidate = fallback;

    if (hidden > 0 && vocab <= ((zend_ulong) ZEND_LONG_MAX - 1024) / hidden) {
        candidate = (vocab * hidden) + 1024;
        if (candidate < fallback) {
            candidate = fallback;
        }
    }

    return candidate > (zend_ulong) ZEND_LONG_MAX ? ZEND_LONG_MAX : (zend_long) candidate;
}

static void king_inference_openai_add_native_cpu_graph_defaults(
    king_inference_model_object *model,
    zval *request)
{
    zval graph_options;
    zend_ulong vocab;

    if (king_inference_array_find(request, "graph_options") != NULL) {
        return;
    }

    vocab = model->gguf.tokenizer_token_count > 0 ? model->gguf.tokenizer_token_count : 262144;
    array_init(&graph_options);
    add_assoc_long(&graph_options, "max_vector_values", (zend_long) (vocab > 262144 ? vocab : 262144));
    add_assoc_long(&graph_options, "max_operations", king_inference_openai_native_cpu_max_operations(model));
    add_assoc_bool(&graph_options, "return_outputs", false);
    add_assoc_zval(request, "graph_options", &graph_options);
}

static zend_result king_inference_openai_prepare_native_cpu_prompt_request(
    king_inference_model_object *model,
    zval *request)
{
    zval prompt;

    if (king_inference_array_find(request, "graph") != NULL
        || king_inference_array_find(request, "graphs") != NULL
        || king_inference_array_find(request, "native_prompt_text") != NULL) {
        return SUCCESS;
    }
    if (king_inference_openai_render_messages_prompt(model, request, &prompt) != SUCCESS) {
        return SUCCESS;
    }

    add_assoc_zval(request, "native_prompt_text", &prompt);
    if (king_inference_array_find(request, "max_tokens") == NULL) {
        add_assoc_long(request, "max_tokens", 128);
    }
    king_inference_openai_add_native_cpu_graph_defaults(model, request);
    return SUCCESS;
}

static zend_result king_inference_openai_prepare_native_gpu_prompt_request(
    king_inference_model_object *model,
    zval *request)
{
    zval prompt;

    if (king_inference_array_find(request, "graph") != NULL
        || king_inference_array_find(request, "graphs") != NULL
        || king_inference_array_find(request, "native_prompt_text") != NULL) {
        return SUCCESS;
    }
    if (king_inference_openai_render_messages_prompt(model, request, &prompt) != SUCCESS) {
        return SUCCESS;
    }

    add_assoc_zval(request, "native_prompt_text", &prompt);
    if (king_inference_array_find(request, "max_tokens") == NULL) {
        add_assoc_long(request, "max_tokens", 128);
    }
    return SUCCESS;
}

static zend_result king_inference_openai_create_stream(
    zval *stream_value,
    zval *model,
    zval *payload,
    zval *options,
    const char *function_name)
{
    king_inference_stream_object *stream;
    king_inference_model_object *model_object;
    king_inference_backend_kind kind;

    object_init_ex(stream_value, king_ce_inference_stream);
    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(stream_value));
    ZVAL_COPY(&stream->model, model);
    ZVAL_DUP(&stream->request, payload);
    SEPARATE_ARRAY(&stream->request);
    if (options != NULL && Z_TYPE_P(options) == IS_ARRAY) {
        ZVAL_DUP(&stream->options, options);
        SEPARATE_ARRAY(&stream->options);
    } else {
        array_init(&stream->options);
    }

    add_assoc_bool(&stream->request, "openai_compatible", true);
    add_assoc_string(&stream->request, "format", "openai_chat_completions");
    king_inference_openai_copy_chat_generation_options(&stream->request, &stream->request);

    model_object = php_king_inference_model_obj_from_zend(Z_OBJ_P(model));
    if (king_inference_backend_kind_from_config(&model_object->config, &kind, function_name) != SUCCESS) {
        zval_ptr_dtor(stream_value);
        ZVAL_UNDEF(stream_value);
        return FAILURE;
    }
    if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU
        && king_inference_openai_prepare_native_cpu_prompt_request(model_object, &stream->request) != SUCCESS) {
        zval_ptr_dtor(stream_value);
        ZVAL_UNDEF(stream_value);
        return FAILURE;
    }
    if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        && king_inference_openai_prepare_native_gpu_prompt_request(model_object, &stream->request) != SUCCESS) {
        zval_ptr_dtor(stream_value);
        ZVAL_UNDEF(stream_value);
        return FAILURE;
    }

    add_assoc_bool(&stream->options, "openai_compatible", true);
    add_assoc_string(&stream->options, "format", "openai_chat_completions");
    stream->openai_compatible = true;
    stream->created_at = (zend_long) time(NULL);
    king_inference_stream_prepare_openai(stream);

    if (king_inference_stream_start(stream, function_name) != SUCCESS) {
        king_inference_stream_throw_start_failure(stream, function_name);
        zval_ptr_dtor(stream_value);
        ZVAL_UNDEF(stream_value);
        return FAILURE;
    }

    return SUCCESS;
}
