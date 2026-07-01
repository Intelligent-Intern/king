/*
 * CUDA decoder graph executor contract for native King GPU inference.
 */

static void king_inference_cuda_decoder_graph_executor_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_decoder_graph_executor_error";
    }

    model->cuda_decoder_graph_executor_result = result;
    snprintf(
        model->cuda_decoder_graph_executor_error,
        sizeof(model->cuda_decoder_graph_executor_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_decoder_graph_executor_dependencies_ready(
    king_inference_model_object *model
) {
    return model->cuda_embedding_row_available
        && model->cuda_quantized_matvec_available
        && model->cuda_device_vector_ops_available
        && model->cuda_device_kv_cache_available
        && model->cuda_rms_norm_available
        && model->cuda_rope_available
        && model->cuda_attention_scores_available
        && model->cuda_attention_softmax_available
        && model->cuda_attention_values_available
        && model->cuda_ffn_swiglu_path_available
        && model->cuda_output_projection_available
        && model->cuda_logits_readback_available;
}

static bool king_inference_cuda_decoder_graph_executor_supported_op(zend_string *op_name)
{
    return zend_string_equals_literal(op_name, "embedding")
        || zend_string_equals_literal(op_name, "rms_norm")
        || zend_string_equals_literal(op_name, "linear")
        || zend_string_equals_literal(op_name, "add")
        || zend_string_equals_literal(op_name, "scale")
        || zend_string_equals_literal(op_name, "mul")
        || zend_string_equals_literal(op_name, "silu")
        || zend_string_equals_literal(op_name, "gelu_tanh")
        || zend_string_equals_literal(op_name, "slice")
        || zend_string_equals_literal(op_name, "rope")
        || zend_string_equals_literal(op_name, "kv_write")
        || zend_string_equals_literal(op_name, "kv_attention")
        || zend_string_equals_literal(op_name, "stack")
        || zend_string_equals_literal(op_name, "argmax_token")
        || zend_string_equals_literal(op_name, "sample_token");
}

static zend_result king_inference_cuda_decoder_graph_executor_validation_error(
    king_inference_model_object *model,
    const char *class_name,
    zend_string *node_id,
    const char *field,
    const char *detail
) {
    char error[512];

    snprintf(
        error,
        sizeof(error),
        "cuda_decoder_graph_validation class=%s node=%s field=%s detail=%s",
        class_name != NULL ? class_name : "unknown",
        node_id != NULL ? ZSTR_VAL(node_id) : "<graph>",
        field != NULL ? field : "-",
        detail != NULL ? detail : "-"
    );
    king_inference_cuda_decoder_graph_executor_set_error(model, -1, error);
    return FAILURE;
}

static zval *king_inference_cuda_decoder_graph_executor_shape_dimensions(
    zval *descriptor
) {
    zval *dimensions;

    if (descriptor == NULL || Z_TYPE_P(descriptor) != IS_ARRAY) {
        return NULL;
    }
    dimensions = zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1);
    return dimensions != NULL && Z_TYPE_P(dimensions) == IS_ARRAY ? dimensions : NULL;
}

static zend_result king_inference_cuda_decoder_graph_executor_tensor_descriptor(
    king_inference_model_object *model,
    zend_string *node_id,
    const char *field,
    zend_string *tensor,
    zval **descriptor_out,
    zval **dimensions_out,
    zend_ulong *rank_out,
    zend_ulong *type_out
) {
    zval *descriptor;
    zval *dimensions;
    zend_ulong rank;
    zend_ulong type;

    if (tensor == NULL || ZSTR_LEN(tensor) == 0) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "tensor_name",
            node_id,
            field,
            "missing_tensor_name"
        );
    }

    model->cuda_decoder_graph_executor_tensor_lookup_count++;
    descriptor = king_inference_tensor_index_descriptor(model, tensor);
    dimensions = king_inference_cuda_decoder_graph_executor_shape_dimensions(descriptor);
    if (descriptor == NULL
        || dimensions == NULL
        || !king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "tensor_descriptor",
            node_id,
            field,
            ZSTR_VAL(tensor)
        );
    }

    *descriptor_out = descriptor;
    *dimensions_out = dimensions;
    *rank_out = rank;
    *type_out = type;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_required_string_field(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    const char *class_name,
    const char *field,
    zend_string **value
) {
    zval *found = king_inference_array_find(entry, field);

    if (found == NULL || Z_TYPE_P(found) != IS_STRING || Z_STRLEN_P(found) == 0) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            class_name,
            node_id,
            field,
            "required_non_empty_string"
        );
    }
    *value = Z_STR_P(found);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_optional_long_field(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    const char *field,
    zend_long fallback,
    zend_long *value
) {
    zval *found = king_inference_array_find(entry, field);

    if (found == NULL) {
        *value = fallback;
        return SUCCESS;
    }
    if (Z_TYPE_P(found) != IS_LONG) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "field_type",
            node_id,
            field,
            "expected_integer"
        );
    }
    *value = Z_LVAL_P(found);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_non_negative_long_field(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    const char *field,
    bool required,
    zend_ulong fallback,
    zend_ulong *value
) {
    zval *found = king_inference_array_find(entry, field);

    if (found == NULL) {
        if (required) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "field_type",
                node_id,
                field,
                "required_non_negative_integer"
            );
        }
        *value = fallback;
        return SUCCESS;
    }
    if (Z_TYPE_P(found) != IS_LONG || Z_LVAL_P(found) < 0) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "field_type",
            node_id,
            field,
            "expected_non_negative_integer"
        );
    }
    *value = (zend_ulong) Z_LVAL_P(found);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_positive_long_field(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    const char *field,
    zend_ulong *value
) {
    zval *found = king_inference_array_find(entry, field);

    if (found == NULL || Z_TYPE_P(found) != IS_LONG || Z_LVAL_P(found) <= 0) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "field_type",
            node_id,
            field,
            "required_positive_integer"
        );
    }
    *value = (zend_ulong) Z_LVAL_P(found);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_finite_double_field(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    const char *field,
    double fallback,
    bool must_be_positive,
    double *value
) {
    zval *found = king_inference_array_find(entry, field);

    *value = fallback;
    if (found == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(found) == IS_DOUBLE) {
        *value = Z_DVAL_P(found);
    } else if (Z_TYPE_P(found) == IS_LONG) {
        *value = (double) Z_LVAL_P(found);
    } else {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "field_type",
            node_id,
            field,
            "expected_number"
        );
    }
    if (!isfinite(*value) || (must_be_positive && *value <= 0.0)) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "numeric_range",
            node_id,
            field,
            must_be_positive ? "expected_positive_finite_number" : "expected_finite_number"
        );
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_defined_reference(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    const char *field,
    zend_string **value
) {
    if (king_inference_cuda_decoder_graph_executor_required_string_field(
        model,
        entry,
        node_id,
        "dependency",
        field,
        value
    ) != SUCCESS) {
        return FAILURE;
    }
    if (!zend_hash_exists(Z_ARRVAL_P(defined), *value)) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "dangling_read",
            node_id,
            field,
            ZSTR_VAL(*value)
        );
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_reference_length(
    king_inference_model_object *model,
    zval *lengths,
    zend_string *node_id,
    const char *field,
    zend_string *reference,
    zend_ulong *length
) {
    zval *found = zend_hash_find(Z_ARRVAL_P(lengths), reference);

    if (found == NULL || Z_TYPE_P(found) != IS_LONG || Z_LVAL_P(found) < 0) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "output_dependency",
            node_id,
            field,
            ZSTR_VAL(reference)
        );
    }
    *length = (zend_ulong) Z_LVAL_P(found);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_set_validated_length(
    king_inference_model_object *model,
    zval *lengths,
    zend_string *node_id,
    zend_ulong length
) {
    zval stored;

    if (length == 0 || length > (zend_ulong) ZEND_LONG_MAX) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "shape",
            node_id,
            "output",
            "invalid_output_length"
        );
    }
    ZVAL_LONG(&stored, (zend_long) length);
    zend_hash_update(Z_ARRVAL_P(lengths), node_id, &stored);
    return SUCCESS;
}
