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

        if ((graph != NULL && Z_TYPE_P(graph) != IS_ARRAY)
            || (graphs != NULL && Z_TYPE_P(graphs) != IS_ARRAY)
            || (graph_options != NULL && Z_TYPE_P(graph_options) != IS_ARRAY)) {
            if (has_error_response != NULL) {
                *has_error_response = true;
            }
            return king_inference_openai_error_response(
                return_value,
                400,
                "Native graph generation requires graph, graphs, and graph_options to be arrays when provided.",
                "invalid_request_error"
            );
        }
        if (graph != NULL || graphs != NULL) {
            return SUCCESS;
        }
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
