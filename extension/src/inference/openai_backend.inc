static zend_string *king_inference_openai_gpu_decoder_blockers_message(zval *gpu_runtime)
{
    zval *blockers = king_inference_array_find(gpu_runtime, "decoder_blockers");
    zval *entry;
    smart_str buffer = {0};
    bool first = true;

    if (blockers == NULL || Z_TYPE_P(blockers) != IS_ARRAY) {
        return zend_string_init("unknown", sizeof("unknown") - 1, 0);
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(blockers), entry) {
        if (Z_TYPE_P(entry) != IS_STRING || Z_STRLEN_P(entry) == 0) {
            continue;
        }
        if (!first) {
            smart_str_appendc(&buffer, ',');
        }
        smart_str_append(&buffer, Z_STR_P(entry));
        first = false;
    } ZEND_HASH_FOREACH_END();

    if (first) {
        return zend_string_init("none", sizeof("none") - 1, 0);
    }

    smart_str_0(&buffer);
    return buffer.s;
}

static zend_result king_inference_openai_require_generation_backend(
    zval *return_value,
    zval *model,
    zval *payload,
    const char *endpoint_name,
    bool *has_error_response)
{
    king_inference_model_object *model_object;
    king_inference_backend_kind kind;
    const char *kind_name;
    zend_string *message;
    zend_result result;

    if (has_error_response != NULL) {
        *has_error_response = false;
    }

    model_object = php_king_inference_model_obj_from_zend(Z_OBJ_P(model));
    if (king_inference_backend_kind_from_config(&model_object->config, &kind, endpoint_name) != SUCCESS) {
        if (EG(exception)) {
            zend_clear_exception();
        }
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        return king_inference_openai_error_response(
            return_value,
            400,
            "Selected King model has an invalid inference backend configuration.",
            "invalid_request_error"
        );
    }

    if (kind == KING_INFERENCE_BACKEND_LOCAL) {
        return SUCCESS;
    }

    if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_CPU) {
        zval *graph = king_inference_array_find(payload, "graph");
        zval *graphs = king_inference_array_find(payload, "graphs");
        zval *graph_options = king_inference_array_find(payload, "graph_options");
        zval *messages = king_inference_array_find(payload, "messages");
        zval *prompt = king_inference_array_find(payload, "prompt");

        if ((graph != NULL && !king_inference_openai_array_is_object(graph))
            || (graphs != NULL && !king_inference_openai_array_is_list(graphs))
            || (graph_options != NULL && !king_inference_openai_array_is_object(graph_options))) {
            if (has_error_response != NULL) {
                *has_error_response = true;
            }
            return king_inference_openai_error_response(
                return_value,
                400,
                "Native graph generation requires graph to be an object, graphs to be an array, and graph_options to be an object when provided.",
                "invalid_request_error"
            );
        }
        if (graph != NULL || graphs != NULL) {
            return SUCCESS;
        }
        if ((messages != NULL && king_inference_openai_array_is_list(messages))
            || (prompt != NULL && Z_TYPE_P(prompt) == IS_STRING)) {
            return SUCCESS;
        }
    }

    if (kind == KING_INFERENCE_BACKEND_KING_NATIVE_GPU) {
        zend_string *blockers;
        zval gpu_runtime;
        zval *graph = king_inference_array_find(payload, "graph");
        zval *graphs = king_inference_array_find(payload, "graphs");
        zval *messages = king_inference_array_find(payload, "messages");
        zval *prompt = king_inference_array_find(payload, "prompt");
        zval *native_prompt_text = king_inference_array_find(payload, "native_prompt_text");
        zval *reason_value;
        zval *generation_ready;
        const char *reason = "gpu_decoder_kernel_not_implemented";

        king_inference_gpu_runtime_status_from_model(model_object, &gpu_runtime);
        blockers = king_inference_openai_gpu_decoder_blockers_message(&gpu_runtime);
        generation_ready = king_inference_array_find(&gpu_runtime, "generation_ready");
        reason_value = king_inference_array_find(&gpu_runtime, "reason");
        if (reason_value != NULL && Z_TYPE_P(reason_value) == IS_STRING && Z_STRLEN_P(reason_value) > 0) {
            reason = Z_STRVAL_P(reason_value);
        }
        if (graph != NULL || graphs != NULL) {
            zend_string_release(blockers);
            zval_ptr_dtor(&gpu_runtime);
            if (has_error_response != NULL) {
                *has_error_response = true;
            }
            return king_inference_openai_error_response(
                return_value,
                400,
                "OpenAI-compatible King GPU generation accepts plain text messages only; use the native stream contract for GPU graph payloads.",
                "invalid_request_error"
            );
        }
        if ((messages != NULL && king_inference_openai_array_is_list(messages))
            || (prompt != NULL && Z_TYPE_P(prompt) == IS_STRING)
            || (native_prompt_text != NULL && Z_TYPE_P(native_prompt_text) == IS_STRING && Z_STRLEN_P(native_prompt_text) > 0)) {
            if (zend_string_equals_literal(blockers, "none")
                && generation_ready != NULL
                && zend_is_true(generation_ready)) {
                zend_string_release(blockers);
                zval_ptr_dtor(&gpu_runtime);
                return SUCCESS;
            }
        }

        message = strpprintf(
            0,
            "%s cannot generate with the selected King GPU model yet. "
            "The model is registered for metadata and readiness inspection, "
            "but the native GPU plain text decoder or runtime policy is not ready. "
            "Primary gpu_runtime.reason=%s. "
            "Decoder blockers=%s. "
            "King will not silently fall back to CPU for a GPU profile.",
            endpoint_name,
            reason,
            ZSTR_VAL(blockers)
        );
        zend_string_release(blockers);
        zval_ptr_dtor(&gpu_runtime);
        if (has_error_response != NULL) {
            *has_error_response = true;
        }
        result = king_inference_openai_error_response(
            return_value,
            400,
            ZSTR_VAL(message),
            "invalid_request_error"
        );
        zend_string_release(message);
        return result;
    }

    kind_name = king_inference_backend_kind_name(kind);
    message = strpprintf(
        0,
        "%s requires a text-generation stream backend or an explicit native "
        "graph or graphs request. The selected King model uses '%s'.",
        endpoint_name,
        kind_name
    );
    if (has_error_response != NULL) {
        *has_error_response = true;
    }
    result = king_inference_openai_error_response(
        return_value,
        400,
        ZSTR_VAL(message),
        "invalid_request_error"
    );
    zend_string_release(message);
    return result;
}
