#include "openai_backend.inc"

static zend_result king_inference_openai_render_messages_prompt(zval *payload, zval *prompt)
{
    zval *messages = king_inference_array_find(payload, "messages");
    zval *message;
    smart_str rendered = {0};

    if (!king_inference_openai_array_is_list(messages)) {
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(messages), message) {
        zval *role;
        zval *content;
        smart_str text = {0};

        if (Z_TYPE_P(message) != IS_ARRAY) {
            smart_str_free(&rendered);
            return FAILURE;
        }
        role = king_inference_array_find(message, "role");
        content = king_inference_array_find(message, "content");
        if (role == NULL || Z_TYPE_P(role) != IS_STRING || Z_STRLEN_P(role) == 0) {
            smart_str_free(&rendered);
            return FAILURE;
        }

        smart_str_append(&rendered, Z_STR_P(role));
        smart_str_appends(&rendered, ": ");
        if (king_inference_openai_append_content_text(content, &text) == SUCCESS) {
            smart_str_0(&text);
            if (text.s != NULL) {
                smart_str_append(&rendered, text.s);
            }
        }
        smart_str_free(&text);
        smart_str_appends(&rendered, "\n");
    } ZEND_HASH_FOREACH_END();

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
    if (king_inference_openai_render_messages_prompt(request, &prompt) != SUCCESS) {
        return SUCCESS;
    }

    add_assoc_zval(request, "native_prompt_text", &prompt);
    if (king_inference_array_find(request, "max_tokens") == NULL) {
        add_assoc_long(request, "max_tokens", 128);
    }
    king_inference_openai_add_native_cpu_graph_defaults(model, request);
    return SUCCESS;
}

static zend_result king_inference_openai_prepare_native_gpu_prompt_request(zval *request)
{
    zval prompt;

    if (king_inference_array_find(request, "graph") != NULL
        || king_inference_array_find(request, "graphs") != NULL
        || king_inference_array_find(request, "native_prompt_text") != NULL) {
        return SUCCESS;
    }
    if (king_inference_openai_render_messages_prompt(request, &prompt) != SUCCESS) {
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
        && king_inference_openai_prepare_native_gpu_prompt_request(&stream->request) != SUCCESS) {
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
        zval_ptr_dtor(stream_value);
        ZVAL_UNDEF(stream_value);
        return FAILURE;
    }

    return SUCCESS;
}
