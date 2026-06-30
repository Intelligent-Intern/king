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

static zend_result king_inference_cuda_decoder_graph_executor_validate_embedding_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zend_ulong *length_out
) {
    zval *tensor_value = king_inference_array_find(entry, "tensor");
    zend_string *tensor = NULL;
    zend_string *resolved_tensor = NULL;
    const char *status = NULL;
    zval *descriptor = NULL;
    zval *dimensions = NULL;
    zend_ulong rank = 0;
    zend_ulong type = 0;
    zend_ulong cols = 0;
    zend_ulong rows = 0;
    zend_ulong token_id = 0;
    double scale = 1.0;

    if (king_inference_cuda_decoder_graph_executor_non_negative_long_field(
            model,
            entry,
            node_id,
            king_inference_array_find(entry, "token_id") != NULL ? "token_id" : "token",
            true,
            0,
            &token_id
        ) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_finite_double_field(
            model,
            entry,
            node_id,
            "scale",
            1.0,
            false,
            &scale
        ) != SUCCESS) {
        return FAILURE;
    }

    if (tensor_value != NULL) {
        if (Z_TYPE_P(tensor_value) != IS_STRING || Z_STRLEN_P(tensor_value) == 0) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "tensor_name",
                node_id,
                "tensor",
                "required_non_empty_string"
            );
        }
        tensor = Z_STR_P(tensor_value);
    } else {
        if (king_inference_resolve_token_embedding_tensor_name(
                model,
                NULL,
                &resolved_tensor,
                NULL,
                &status
        ) != SUCCESS || resolved_tensor == NULL) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "metadata",
                node_id,
                "tensor",
                status != NULL ? status : "embedding_tensor_unresolved"
            );
        }
        tensor = resolved_tensor;
    }

    if (king_inference_cuda_decoder_graph_executor_tensor_descriptor(
            model,
            node_id,
            "tensor",
            tensor,
            &descriptor,
            &dimensions,
            &rank,
            &type
        ) != SUCCESS
        || rank != 2
        || !king_inference_cuda_embedding_row_supported_type(type)
        || king_inference_tensor_dimension_at(dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(dimensions, 1, &rows) != SUCCESS
        || cols == 0
        || rows == 0
        || cols > UINT_MAX
        || rows > UINT_MAX
        || token_id >= rows) {
        if (resolved_tensor != NULL) {
            zend_string_release(resolved_tensor);
        }
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "shape",
            node_id,
            "tensor",
            "embedding_tensor_or_token_invalid"
        );
    }
    if (resolved_tensor != NULL) {
        zend_string_release(resolved_tensor);
    }

    *length_out = cols;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_rms_norm_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *lengths,
    zend_ulong *length_out
) {
    zend_string *input_id = NULL;
    zend_string *weight = NULL;
    zval *descriptor = NULL;
    zval *dimensions = NULL;
    zend_ulong input_length = 0;
    zend_ulong weight_width = 0;
    zend_ulong rank = 0;
    zend_ulong type = 0;
    double epsilon = 0.000001;

    if (king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, "input", &input_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, "input", input_id, &input_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_required_string_field(model, entry, node_id, "tensor_name", "weight", &weight) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "epsilon", 0.000001, true, &epsilon) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_tensor_descriptor(
            model,
            node_id,
            "weight",
            weight,
            &descriptor,
            &dimensions,
            &rank,
            &type
        ) != SUCCESS
        || rank != 1
        || (type != 0 && type != 1)
        || king_inference_tensor_dimension_at(dimensions, 0, &weight_width) != SUCCESS
        || weight_width != input_length) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "shape",
            node_id,
            "weight",
            "rms_norm_weight_shape_or_dtype_invalid"
        );
    }

    *length_out = input_length;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_linear_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *lengths,
    zend_ulong *length_out
) {
    zend_string *input_id = NULL;
    zend_string *weight = NULL;
    zval *descriptor = NULL;
    zval *dimensions = NULL;
    zend_ulong input_length = 0;
    zend_ulong cols = 0;
    zend_ulong rows = 0;
    zend_ulong rank = 0;
    zend_ulong type = 0;
    zend_ulong row_limit = 0;
    zend_long row_offset = 0;

    if (king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, "input", &input_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, "input", input_id, &input_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_required_string_field(model, entry, node_id, "tensor_name", "weight", &weight) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_optional_long_field(model, entry, node_id, "row_offset", 0, &row_offset) != SUCCESS) {
        return FAILURE;
    }
    if (row_offset != 0) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "unsupported",
            node_id,
            "row_offset",
            "cuda_linear_row_offset_unsupported"
        );
    }
    if (king_inference_cuda_decoder_graph_executor_tensor_descriptor(
            model,
            node_id,
            "weight",
            weight,
            &descriptor,
            &dimensions,
            &rank,
            &type
        ) != SUCCESS
        || rank != 2
        || !king_inference_cuda_quantized_matvec_type_supported(type)
        || king_inference_tensor_dimension_at(dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(dimensions, 1, &rows) != SUCCESS
        || cols != input_length
        || rows == 0
        || rows > UINT_MAX) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "shape",
            node_id,
            "weight",
            "linear_weight_shape_or_dtype_invalid"
        );
    }
    if (king_inference_cuda_decoder_graph_executor_non_negative_long_field(
        model,
        entry,
        node_id,
        "row_limit",
        false,
        rows,
        &row_limit
    ) != SUCCESS) {
        return FAILURE;
    }
    if (row_limit == 0 || row_limit > rows) {
        row_limit = rows;
    }
    *length_out = row_limit;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_unary_vector_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *lengths,
    const char *input_field,
    zend_ulong *length_out
) {
    zend_string *input_id = NULL;

    if (king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, input_field, &input_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, input_field, input_id, length_out) != SUCCESS) {
        return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_binary_vector_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *lengths,
    zend_ulong *length_out
) {
    zend_string *left_id = NULL;
    zend_string *right_id = NULL;
    zend_ulong left_length = 0;
    zend_ulong right_length = 0;
    double left_scale = 1.0;
    double right_scale = 1.0;

    if (king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, "left", &left_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, "right", &right_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, "left", left_id, &left_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, "right", right_id, &right_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "left_scale", 1.0, false, &left_scale) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "right_scale", 1.0, false, &right_scale) != SUCCESS) {
        return FAILURE;
    }
    if (left_length != right_length) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "shape",
            node_id,
            "left/right",
            "binary_inputs_must_have_equal_length"
        );
    }
    *length_out = left_length;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_slice_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *lengths,
    zend_ulong *length_out
) {
    zend_string *input_id = NULL;
    zend_ulong input_length = 0;
    zend_ulong offset = 0;
    zend_ulong count = 0;

    if (king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, "input", &input_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, "input", input_id, &input_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_non_negative_long_field(model, entry, node_id, "offset", false, 0, &offset) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_non_negative_long_field(model, entry, node_id, "count", false, 0, &count) != SUCCESS) {
        return FAILURE;
    }
    if (count == 0) {
        count = input_length > offset ? input_length - offset : 0;
    }
    if (input_length == 0
        || offset > input_length
        || count == 0
        || count > input_length - offset
        || offset > UINT_MAX
        || count > UINT_MAX) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "shape",
            node_id,
            "offset/count",
            "slice_range_invalid"
        );
    }
    *length_out = count;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_rope_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *lengths,
    zend_ulong *length_out
) {
    zend_string *input_id = NULL;
    zend_ulong input_length = 0;
    zend_ulong offset = 0;
    zend_ulong head_dim = 0;
    zend_ulong position = 0;
    zend_ulong pairing = 0;
    double position_scale = 1.0;
    double rope_base = 10000.0;

    if (king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, "input", &input_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, "input", input_id, &input_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_non_negative_long_field(model, entry, node_id, "offset", false, 0, &offset) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_non_negative_long_field(model, entry, node_id, "position", false, 0, &position) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_non_negative_long_field(model, entry, node_id, "pairing", false, 0, &pairing) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "position_scale", 1.0, false, &position_scale) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "rope_base", 10000.0, true, &rope_base) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_non_negative_long_field(
        model,
        entry,
        node_id,
        "head_dim",
        false,
        input_length > offset ? input_length - offset : 0,
        &head_dim
    ) != SUCCESS) {
        return FAILURE;
    }
    if (input_length == 0
        || input_length > UINT_MAX
        || offset > input_length
        || offset > UINT_MAX
        || head_dim == 0
        || (head_dim % 2) != 0
        || head_dim > input_length - offset
        || head_dim > UINT_MAX
        || pairing > 1
        || (model->gguf.architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH] > 0
            && position >= model->gguf.architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH])) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "shape",
            node_id,
            "head_dim/position",
            "rope_shape_invalid"
        );
    }
    *length_out = input_length;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_kv_write_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *lengths,
    zend_ulong *length_out
) {
    zend_string *key_id = NULL;
    zend_string *value_id = NULL;
    zend_ulong key_length = 0;
    zend_ulong value_length = 0;
    zend_ulong expected_key_length = 0;
    zend_ulong expected_value_length = 0;
    zend_ulong slot = 0;
    zval *cache = king_inference_array_find(entry, "cache");
    zval *key = king_inference_array_find(entry, "key");
    zval *value = king_inference_array_find(entry, "value");

    if (cache != NULL && (Z_TYPE_P(cache) != IS_STRING || Z_STRLEN_P(cache) == 0)) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "field_type",
            node_id,
            "cache",
            "expected_non_empty_string"
        );
    }
    if (king_inference_cuda_decoder_graph_executor_non_negative_long_field(model, entry, node_id, "slot", true, 0, &slot) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_positive_long_field(model, entry, node_id, "key_length", &expected_key_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_positive_long_field(model, entry, node_id, "value_length", &expected_value_length) != SUCCESS) {
        return FAILURE;
    }
    if (key == NULL && value == NULL) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "dependency",
            node_id,
            "key/value",
            "kv_write_requires_key_or_value"
        );
    }
    if (key != NULL) {
        if (king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, "key", &key_id) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, "key", key_id, &key_length) != SUCCESS) {
            return FAILURE;
        }
        if (key_length != expected_key_length) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "shape",
                node_id,
                "key_length",
                "kv_write_key_length_mismatch"
            );
        }
    }
    if (value != NULL) {
        if (king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, "value", &value_id) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, "value", value_id, &value_length) != SUCCESS) {
            return FAILURE;
        }
        if (value_length != expected_value_length) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "shape",
                node_id,
                "value_length",
                "kv_write_value_length_mismatch"
            );
        }
    }
    if (model->gguf.architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH] > 0
        && slot >= model->gguf.architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH]) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "index_range",
            node_id,
            "slot",
            "kv_slot_exceeds_context"
        );
    }
    *length_out = 3;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_kv_attention_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *lengths,
    zend_ulong *length_out
) {
    zend_string *query_id = NULL;
    zend_ulong query_length = 0;
    zend_ulong slot_start = 0;
    zend_ulong slot_count = 0;
    zend_ulong key_length = 0;
    zend_ulong value_length = 0;
    zval *cache = king_inference_array_find(entry, "cache");
    double scale = 1.0;
    double temperature = 1.0;

    if (cache != NULL && (Z_TYPE_P(cache) != IS_STRING || Z_STRLEN_P(cache) == 0)) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "field_type",
            node_id,
            "cache",
            "expected_non_empty_string"
        );
    }
    if (king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, "query", &query_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, "query", query_id, &query_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_non_negative_long_field(model, entry, node_id, "slot_start", false, 0, &slot_start) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_positive_long_field(model, entry, node_id, "slot_count", &slot_count) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_positive_long_field(model, entry, node_id, "key_length", &key_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_positive_long_field(model, entry, node_id, "value_length", &value_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "scale", 1.0, false, &scale) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "temperature", 1.0, true, &temperature) != SUCCESS) {
        return FAILURE;
    }
    if (query_length != key_length
        || value_length == 0
        || slot_count == 0
        || slot_start > ZEND_ULONG_MAX - (slot_count - 1)
        || (model->gguf.architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH] > 0
            && slot_start + slot_count > model->gguf.architecture_params[KING_INFERENCE_ARCH_CONTEXT_LENGTH])) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "shape",
            node_id,
            "query/key/value",
            "kv_attention_shape_invalid"
        );
    }
    *length_out = value_length;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_stack_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *lengths,
    zend_ulong *length_out
) {
    zval *inputs = king_inference_array_find(entry, "inputs");
    zval *input;
    zend_ulong total = 0;

    if (inputs == NULL || Z_TYPE_P(inputs) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(inputs)) == 0) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "dependency",
            node_id,
            "inputs",
            "stack_requires_non_empty_inputs"
        );
    }
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(inputs), input) {
        zend_ulong length = 0;

        if (input == NULL || Z_TYPE_P(input) != IS_STRING || Z_STRLEN_P(input) == 0) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "field_type",
                node_id,
                "inputs",
                "stack_input_must_be_non_empty_string"
            );
        }
        if (!zend_hash_exists(Z_ARRVAL_P(defined), Z_STR_P(input))) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "dangling_read",
                node_id,
                "inputs",
                Z_STRVAL_P(input)
            );
        }
        if (king_inference_cuda_decoder_graph_executor_reference_length(
            model,
            lengths,
            node_id,
            "inputs",
            Z_STR_P(input),
            &length
        ) != SUCCESS) {
            return FAILURE;
        }
        if (total > ZEND_ULONG_MAX - length) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "shape",
                node_id,
                "inputs",
                "stack_length_overflow"
            );
        }
        total += length;
    } ZEND_HASH_FOREACH_END();

    *length_out = total;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_graph_inputs(
    king_inference_model_object *model,
    zval *graph,
    zval *defined,
    zval *lengths
) {
    zval *inputs = king_inference_array_find(graph, "inputs");
    zval *input;
    zend_string *name;
    zend_ulong index;

    if (inputs == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(inputs) != IS_ARRAY) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "field_type",
            NULL,
            "inputs",
            "expected_object_array"
        );
    }

    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(inputs), index, name, input) {
        zend_ulong length;
        zval marker;
        zval stored;

        (void) index;
        if (name == NULL || ZSTR_LEN(name) == 0) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "field_type",
                NULL,
                "inputs",
                "input_name_must_be_non_empty_string"
            );
        }
        if (input == NULL
            || Z_TYPE_P(input) != IS_ARRAY
            || zend_hash_num_elements(Z_ARRVAL_P(input)) == 0) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "shape",
                name,
                "inputs",
                "input_vector_must_be_non_empty_array"
            );
        }
        length = zend_hash_num_elements(Z_ARRVAL_P(input));
        if (length > (zend_ulong) ZEND_LONG_MAX) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "shape",
                name,
                "inputs",
                "input_vector_too_large"
            );
        }
        ZVAL_TRUE(&marker);
        zend_hash_update(Z_ARRVAL_P(defined), name, &marker);
        ZVAL_LONG(&stored, (zend_long) length);
        zend_hash_update(Z_ARRVAL_P(lengths), name, &stored);
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_sampler_op(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *lengths,
    zend_string *op_name,
    zend_ulong *length_out
) {
    zend_string *logits_id = NULL;
    zend_ulong logits_length = 0;
    zend_ulong top_k = 0;
    zend_ulong token_offset = 0;
    zend_ulong sample_index = 0;
    zend_long seed = 0;
    double temperature = zend_string_equals_literal(op_name, "argmax_token") ? 0.0 : 1.0;
    double top_p = 1.0;

    if (king_inference_cuda_decoder_graph_executor_defined_reference(model, entry, node_id, defined, "logits", &logits_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_reference_length(model, lengths, node_id, "logits", logits_id, &logits_length) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_non_negative_long_field(model, entry, node_id, "token_offset", false, 0, &token_offset) != SUCCESS) {
        return FAILURE;
    }
    if (logits_length == 0) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "shape",
            node_id,
            "logits",
            "sampler_requires_non_empty_logits"
        );
    }
    if (zend_string_equals_literal(op_name, "sample_token")) {
        if (king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "temperature", 1.0, false, &temperature) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "top_p", 1.0, false, &top_p) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_non_negative_long_field(model, entry, node_id, "top_k", false, 0, &top_k) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_non_negative_long_field(model, entry, node_id, "sample_index", false, 0, &sample_index) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_optional_long_field(model, entry, node_id, "seed", 0, &seed) != SUCCESS) {
            return FAILURE;
        }
        if (temperature < 0.0 || top_p <= 0.0 || top_p > 1.0) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "numeric_range",
                node_id,
                "temperature/top_p/top_k",
                "sample_token_options_invalid"
            );
        }
    }
    *length_out = 4;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_op_contract(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zend_string *op_name,
    zval *defined,
    zval *lengths,
    zend_ulong *length_out
) {
    double factor = 1.0;

    if (zend_string_equals_literal(op_name, "embedding")) {
        return king_inference_cuda_decoder_graph_executor_validate_embedding_op(model, entry, node_id, length_out);
    }
    if (zend_string_equals_literal(op_name, "rms_norm")) {
        return king_inference_cuda_decoder_graph_executor_validate_rms_norm_op(model, entry, node_id, defined, lengths, length_out);
    }
    if (zend_string_equals_literal(op_name, "linear")) {
        return king_inference_cuda_decoder_graph_executor_validate_linear_op(model, entry, node_id, defined, lengths, length_out);
    }
    if (zend_string_equals_literal(op_name, "add") || zend_string_equals_literal(op_name, "mul")) {
        return king_inference_cuda_decoder_graph_executor_validate_binary_vector_op(model, entry, node_id, defined, lengths, length_out);
    }
    if (zend_string_equals_literal(op_name, "scale")) {
        if (king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "scale", 1.0, false, &factor) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_finite_double_field(model, entry, node_id, "factor", factor, false, &factor) != SUCCESS) {
            return FAILURE;
        }
        return king_inference_cuda_decoder_graph_executor_validate_unary_vector_op(model, entry, node_id, defined, lengths, "input", length_out);
    }
    if (zend_string_equals_literal(op_name, "silu") || zend_string_equals_literal(op_name, "gelu_tanh")) {
        return king_inference_cuda_decoder_graph_executor_validate_unary_vector_op(model, entry, node_id, defined, lengths, "input", length_out);
    }
    if (zend_string_equals_literal(op_name, "slice")) {
        return king_inference_cuda_decoder_graph_executor_validate_slice_op(model, entry, node_id, defined, lengths, length_out);
    }
    if (zend_string_equals_literal(op_name, "rope")) {
        return king_inference_cuda_decoder_graph_executor_validate_rope_op(model, entry, node_id, defined, lengths, length_out);
    }
    if (zend_string_equals_literal(op_name, "kv_write")) {
        return king_inference_cuda_decoder_graph_executor_validate_kv_write_op(model, entry, node_id, defined, lengths, length_out);
    }
    if (zend_string_equals_literal(op_name, "kv_attention")) {
        return king_inference_cuda_decoder_graph_executor_validate_kv_attention_op(model, entry, node_id, defined, lengths, length_out);
    }
    if (zend_string_equals_literal(op_name, "stack")) {
        return king_inference_cuda_decoder_graph_executor_validate_stack_op(model, entry, node_id, defined, lengths, length_out);
    }
    if (zend_string_equals_literal(op_name, "argmax_token") || zend_string_equals_literal(op_name, "sample_token")) {
        return king_inference_cuda_decoder_graph_executor_validate_sampler_op(model, entry, node_id, defined, lengths, op_name, length_out);
    }

    return king_inference_cuda_decoder_graph_executor_validation_error(
        model,
        "unsupported",
        node_id,
        "op",
        ZSTR_VAL(op_name)
    );
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_terminal(
    king_inference_model_object *model,
    zval *graph,
    zval *defined
) {
    zval *terminal = king_inference_array_find(graph, "terminal");
    static const char *fields[] = {
        "hidden_output",
        "final_norm",
        "logits"
    };
    size_t i;

    if (terminal == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(terminal) != IS_ARRAY) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "field_type",
            NULL,
            "terminal",
            "expected_object_array"
        );
    }
    for (i = 0; i < sizeof(fields) / sizeof(fields[0]); i++) {
        zval *field = king_inference_array_find(terminal, fields[i]);

        if (field == NULL) {
            continue;
        }
        if (Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "field_type",
                NULL,
                fields[i],
                "terminal_reference_must_be_non_empty_string"
            );
        }
        if (!zend_hash_exists(Z_ARRVAL_P(defined), Z_STR_P(field))) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "dangling_read",
                NULL,
                fields[i],
                Z_STRVAL_P(field)
            );
        }
    }

    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_validate_graph(
    king_inference_model_object *model,
    zval *graph
) {
    zval *ops;
    zval *entry;
    zval defined;
    zval lengths;
    zend_ulong op_count = 0;
    zend_ulong supported_count = 0;
    zend_result status = SUCCESS;

    if (!model->cuda_decoder_graph_executor_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_executor_unavailable"
        );
        return FAILURE;
    }
    if (graph == NULL || Z_TYPE_P(graph) != IS_ARRAY) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_invalid");
        return FAILURE;
    }

    ops = king_inference_array_find(graph, "ops");
    if (ops == NULL) {
        ops = king_inference_array_find(graph, "steps");
    }
    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(ops)) == 0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_ops_missing");
        return FAILURE;
    }

    array_init(&defined);
    array_init(&lengths);
    status = king_inference_cuda_decoder_graph_executor_validate_graph_inputs(
        model,
        graph,
        &defined,
        &lengths
    );
    if (status != SUCCESS) {
        zval_ptr_dtor(&lengths);
        zval_ptr_dtor(&defined);
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zend_string *id = NULL;
        zend_string *op_name = NULL;
        zend_ulong output_length = 0;
        zval marker;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "node",
                NULL,
                "ops",
                "entry_must_be_object_array"
            );
            break;
        }
        if (king_inference_graph_required_string(entry, "id", &id) != SUCCESS
            || king_inference_graph_required_string(entry, "op", &op_name) != SUCCESS) {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "node",
                id,
                id == NULL ? "id" : "op",
                "id_and_op_required"
            );
            break;
        }
        if (zend_hash_exists(Z_ARRVAL(defined), id)) {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "ambiguous_write",
                id,
                "id",
                "duplicate_output_id"
            );
            break;
        }
        if (!king_inference_cuda_decoder_graph_executor_supported_op(op_name)) {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "unsupported",
                id,
                "op",
                ZSTR_VAL(op_name)
            );
            break;
        }
        if (king_inference_cuda_decoder_graph_executor_validate_op_contract(
            model,
            entry,
            id,
            op_name,
            &defined,
            &lengths,
            &output_length
        ) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_set_validated_length(
                model,
                &lengths,
                id,
                output_length
        ) != SUCCESS) {
            status = FAILURE;
            break;
        }
        ZVAL_TRUE(&marker);
        zend_hash_update(Z_ARRVAL(defined), id, &marker);
        op_count++;
        supported_count++;
    } ZEND_HASH_FOREACH_END();

    if (status == SUCCESS) {
        status = king_inference_cuda_decoder_graph_executor_validate_terminal(model, graph, &defined);
    }
    zval_ptr_dtor(&lengths);
    zval_ptr_dtor(&defined);
    if (status != SUCCESS) {
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_graph_count++;
    model->cuda_decoder_graph_executor_last_op_count = op_count;
    model->cuda_decoder_graph_executor_last_supported_op_count = supported_count;
    model->cuda_decoder_graph_executor_result = 0;
    model->cuda_decoder_graph_executor_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_decoder_graph_executor_copy_string_field(
    zval *target,
    zval *source,
    const char *key
) {
    zval *value = king_inference_array_find(source, key);

    if (value != NULL && Z_TYPE_P(value) == IS_STRING) {
        add_assoc_str(target, key, zend_string_copy(Z_STR_P(value)));
    }
}

static void king_inference_cuda_decoder_graph_executor_copy_long_field(
    zval *target,
    zval *source,
    const char *key
) {
    zval *value = king_inference_array_find(source, key);

    if (value != NULL && Z_TYPE_P(value) == IS_LONG) {
        add_assoc_long(target, key, Z_LVAL_P(value));
    }
}

static void king_inference_cuda_decoder_graph_executor_copy_bool_field(
    zval *target,
    zval *source,
    const char *key
) {
    zval *value = king_inference_array_find(source, key);

    if (value != NULL) {
        add_assoc_bool(target, key, zend_is_true(value));
    }
}

#include "../device/core/cuda_decoder_graph_executor_device_ops.inc"

static zend_result king_inference_cuda_decoder_graph_executor_prepare_result(
    king_inference_model_object *model,
    zval *graph,
    zval *result
) {
    zval terminal;
    zval *graph_terminal;
    bool has_terminal = false;

    if (king_inference_cuda_decoder_graph_executor_validate_graph(model, graph) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_prepare_op_index(model, graph) != SUCCESS) {
        return FAILURE;
    }

    array_init(result);
    add_assoc_string(result, "type", "gpu_decoder_graph_execution_result");
    add_assoc_string(result, "backend", "king_native_gpu");
    add_assoc_bool(result, "result_contract_ready", true);
    add_assoc_bool(result, "graph_validated", true);
    add_assoc_bool(result, "device_execution_result_ready", false);
    add_assoc_bool(result, "token_result_ready", false);
    add_assoc_bool(result, "decoded_token_ready", false);
    add_assoc_bool(result, "execution_plan_ready", false);
    add_assoc_bool(result, "graph_op_index_ready", true);
    add_assoc_bool(result, "embedding_device_execution_ready", false);
    add_assoc_bool(result, "first_device_op_executed", false);
    add_assoc_bool(result, "silent_cpu_fallback", false);
    add_assoc_long(result, "op_count", (zend_long) model->cuda_decoder_graph_executor_last_op_count);
    add_assoc_long(
        result,
        "supported_op_count",
        (zend_long) model->cuda_decoder_graph_executor_last_supported_op_count
    );
    king_inference_cuda_decoder_graph_executor_copy_string_field(result, graph, "graph_type");
    king_inference_cuda_decoder_graph_executor_copy_string_field(result, graph, "output");
    king_inference_cuda_decoder_graph_executor_copy_string_field(result, graph, "token_selection");
    king_inference_cuda_decoder_graph_executor_copy_string_field(result, graph, "token_selection_op");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "token_id");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "position");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "block_count");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "attention_head_count");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "attention_head_count_kv");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "attention_key_length");
    king_inference_cuda_decoder_graph_executor_copy_long_field(result, graph, "attention_value_length");

    graph_terminal = king_inference_array_find(graph, "terminal");
    array_init(&terminal);
    if (graph_terminal != NULL && Z_TYPE_P(graph_terminal) == IS_ARRAY) {
        has_terminal = true;
        king_inference_cuda_decoder_graph_executor_copy_string_field(&terminal, graph_terminal, "hidden_output");
        king_inference_cuda_decoder_graph_executor_copy_string_field(&terminal, graph_terminal, "final_norm");
        king_inference_cuda_decoder_graph_executor_copy_string_field(&terminal, graph_terminal, "logits");
        king_inference_cuda_decoder_graph_executor_copy_string_field(&terminal, graph_terminal, "token_selection");
        king_inference_cuda_decoder_graph_executor_copy_string_field(&terminal, graph_terminal, "token_selection_op");
        king_inference_cuda_decoder_graph_executor_copy_bool_field(&terminal, graph_terminal, "emits_token");
        king_inference_cuda_decoder_graph_executor_copy_bool_field(&terminal, graph_terminal, "emits_logits");
    }
    add_assoc_bool(result, "terminal_present", has_terminal);
    add_assoc_zval(result, "terminal", &terminal);
    if (king_inference_cuda_decoder_graph_executor_prepare_plan(model, graph, result) != SUCCESS) {
        zval_ptr_dtor(result);
        ZVAL_UNDEF(result);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_execute_initial_device_ops(model, graph, result) != SUCCESS) {
        zval_ptr_dtor(result);
        ZVAL_UNDEF(result);
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_result_count++;
    model->cuda_decoder_graph_executor_last_result_op_count =
        model->cuda_decoder_graph_executor_last_op_count;
    return SUCCESS;
}

static void king_inference_cuda_decoder_graph_executor_release(king_inference_model_object *model)
{
    king_inference_cuda_decoder_graph_executor_reset_fields(model);
}

static void king_inference_cuda_decoder_graph_executor_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_decoder_graph_executor_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_decoder_graph_executor_attempted = true;
    if (!king_inference_cuda_decoder_graph_executor_dependencies_ready(model)) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_executor_dependencies_unavailable"
        );
        return;
    }

    model->cuda_decoder_graph_executor_available = true;
    model->cuda_decoder_graph_executor_token_decode_available = true;
    model->cuda_decoder_graph_executor_sampling_readback_available = true;
    model->cuda_decoder_graph_executor_result_contract_available = true;
    model->cuda_decoder_graph_executor_execution_plan_available = true;
    model->cuda_decoder_graph_executor_embedding_execution_available =
        model->cuda_embedding_row_available && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_rms_norm_execution_available =
        model->cuda_rms_norm_available && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_linear_execution_available =
        model->cuda_quantized_matvec_available && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_slice_execution_available =
        model->cuda_vector_slice_available && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_rope_execution_available =
        model->cuda_rope_f32_available && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_kv_head_prepare_execution_available =
        model->cuda_decoder_graph_executor_linear_execution_available
        && model->cuda_decoder_graph_executor_slice_execution_available
        && model->cuda_decoder_graph_executor_rope_execution_available;
    model->cuda_decoder_graph_executor_kv_write_execution_available =
        model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
        && model->cuda_device_kv_cache_available
        && model->cuda_device_kv_cache_dtod_available;
    model->cuda_decoder_graph_executor_kv_attention_execution_available =
        model->cuda_decoder_graph_executor_kv_write_execution_available
        && model->cuda_attention_scores_f32_available
        && model->cuda_attention_softmax_f32_available
        && model->cuda_attention_values_f32_available;
    model->cuda_decoder_graph_executor_attention_stack_slot_execution_available =
        model->cuda_decoder_graph_executor_kv_attention_execution_available
        && model->cuda_vector_copy_to_offset_available
        && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_attention_heads_execution_available =
        model->cuda_decoder_graph_executor_attention_stack_slot_execution_available;
    model->cuda_decoder_graph_executor_attention_stack_execution_available =
        model->cuda_decoder_graph_executor_attention_heads_execution_available;
    model->cuda_decoder_graph_executor_attention_output_projection_execution_available =
        model->cuda_decoder_graph_executor_attention_stack_execution_available
        && model->cuda_decoder_graph_executor_linear_execution_available;
    model->cuda_decoder_graph_executor_attention_residual_execution_available =
        model->cuda_decoder_graph_executor_attention_output_projection_execution_available
        && model->cuda_vector_add_available
        && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_ffn_norm_execution_available =
        model->cuda_decoder_graph_executor_attention_residual_execution_available
        && model->cuda_decoder_graph_executor_rms_norm_execution_available
        && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available =
        model->cuda_decoder_graph_executor_ffn_norm_execution_available
        && model->cuda_decoder_graph_executor_linear_execution_available
        && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_ffn_swiglu_execution_available =
        model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available
        && model->cuda_ffn_swiglu_path_available
        && model->cuda_ffn_swiglu_f32_available
        && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_ffn_down_projection_execution_available =
        model->cuda_decoder_graph_executor_ffn_swiglu_execution_available
        && model->cuda_decoder_graph_executor_linear_execution_available
        && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_ffn_output_residual_execution_available =
        model->cuda_decoder_graph_executor_ffn_down_projection_execution_available
        && model->cuda_decoder_graph_executor_attention_residual_execution_available
        && model->cuda_device_vector_ops_available
        && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_final_norm_execution_available =
        model->cuda_decoder_graph_executor_ffn_output_residual_execution_available
        && model->cuda_decoder_graph_executor_rms_norm_execution_available
        && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_logits_projection_execution_available =
        model->cuda_decoder_graph_executor_final_norm_execution_available
        && model->cuda_output_projection_available
        && model->cuda_device_allocator_available;
    model->cuda_decoder_graph_executor_result = 0;
    model->cuda_decoder_graph_executor_error[0] = '\0';
}

static void king_inference_cuda_decoder_graph_executor_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval executor;
    zval supported_ops;

    array_init(&executor);
    add_assoc_bool(&executor, "attempted", model->cuda_decoder_graph_executor_attempted);
    add_assoc_bool(&executor, "available", model->cuda_decoder_graph_executor_available);
    add_assoc_bool(&executor, "token_decode_available", model->cuda_decoder_graph_executor_token_decode_available);
    add_assoc_bool(
        &executor,
        "sampling_readback_available",
        model->cuda_decoder_graph_executor_sampling_readback_available
    );
    add_assoc_bool(
        &executor,
        "result_contract_available",
        model->cuda_decoder_graph_executor_result_contract_available
    );
    add_assoc_bool(
        &executor,
        "execution_plan_available",
        model->cuda_decoder_graph_executor_execution_plan_available
    );
    add_assoc_bool(
        &executor,
        "embedding_execution_available",
        model->cuda_decoder_graph_executor_embedding_execution_available
    );
    add_assoc_bool(
        &executor,
        "rms_norm_execution_available",
        model->cuda_decoder_graph_executor_rms_norm_execution_available
    );
    add_assoc_bool(
        &executor,
        "linear_execution_available",
        model->cuda_decoder_graph_executor_linear_execution_available
    );
    add_assoc_bool(
        &executor,
        "slice_execution_available",
        model->cuda_decoder_graph_executor_slice_execution_available
    );
    add_assoc_bool(
        &executor,
        "rope_execution_available",
        model->cuda_decoder_graph_executor_rope_execution_available
    );
    add_assoc_bool(
        &executor,
        "kv_head_prepare_execution_available",
        model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
    );
    add_assoc_bool(
        &executor,
        "kv_write_execution_available",
        model->cuda_decoder_graph_executor_kv_write_execution_available
    );
    add_assoc_bool(
        &executor,
        "kv_attention_execution_available",
        model->cuda_decoder_graph_executor_kv_attention_execution_available
    );
    add_assoc_bool(
        &executor,
        "attention_stack_slot_execution_available",
        model->cuda_decoder_graph_executor_attention_stack_slot_execution_available
    );
    add_assoc_bool(
        &executor,
        "attention_heads_execution_available",
        model->cuda_decoder_graph_executor_attention_heads_execution_available
    );
    add_assoc_bool(&executor, "attention_stack_execution_available", model->cuda_decoder_graph_executor_attention_stack_execution_available);
    add_assoc_bool(&executor, "attention_output_projection_execution_available", model->cuda_decoder_graph_executor_attention_output_projection_execution_available);
    add_assoc_bool(&executor, "attention_residual_execution_available", model->cuda_decoder_graph_executor_attention_residual_execution_available);
    add_assoc_bool(&executor, "ffn_norm_execution_available", model->cuda_decoder_graph_executor_ffn_norm_execution_available);
    add_assoc_bool(&executor, "ffn_gate_up_projection_execution_available", model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available);
    add_assoc_bool(&executor, "ffn_swiglu_execution_available", model->cuda_decoder_graph_executor_ffn_swiglu_execution_available);
    add_assoc_bool(&executor, "ffn_down_projection_execution_available", model->cuda_decoder_graph_executor_ffn_down_projection_execution_available);
    add_assoc_bool(&executor, "ffn_output_residual_execution_available", model->cuda_decoder_graph_executor_ffn_output_residual_execution_available);
    add_assoc_bool(&executor, "final_norm_execution_available", model->cuda_decoder_graph_executor_final_norm_execution_available);
    add_assoc_bool(&executor, "logits_projection_execution_available", model->cuda_decoder_graph_executor_logits_projection_execution_available);
    add_assoc_long(&executor, "graphs", (zend_long) model->cuda_decoder_graph_executor_graph_count);
    add_assoc_long(&executor, "last_op_count", (zend_long) model->cuda_decoder_graph_executor_last_op_count);
    add_assoc_long(
        &executor,
        "last_supported_op_count",
        (zend_long) model->cuda_decoder_graph_executor_last_supported_op_count
    );
    add_assoc_long(&executor, "result_envelopes", (zend_long) model->cuda_decoder_graph_executor_result_count);
    add_assoc_long(&executor, "execution_plans", (zend_long) model->cuda_decoder_graph_executor_plan_count);
    add_assoc_long(
        &executor,
        "last_result_op_count",
        (zend_long) model->cuda_decoder_graph_executor_last_result_op_count
    );
    add_assoc_long(
        &executor,
        "last_plan_op_count",
        (zend_long) model->cuda_decoder_graph_executor_last_plan_op_count
    );
    add_assoc_long(
        &executor,
        "last_plan_device_ops",
        (zend_long) model->cuda_decoder_graph_executor_last_plan_device_ops
    );
    add_assoc_long(
        &executor,
        "last_plan_host_sampling_ops",
        (zend_long) model->cuda_decoder_graph_executor_last_plan_host_sampling_ops
    );
    add_assoc_long(
        &executor,
        "embedding_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_embedding_execution_count
    );
    add_assoc_long(
        &executor,
        "last_embedding_width",
        (zend_long) model->cuda_decoder_graph_executor_last_embedding_width
    );
    add_assoc_long(
        &executor,
        "rms_norm_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_rms_norm_execution_count
    );
    add_assoc_long(
        &executor,
        "last_rms_norm_width",
        (zend_long) model->cuda_decoder_graph_executor_last_rms_norm_width
    );
    add_assoc_long(
        &executor,
        "linear_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_linear_execution_count
    );
    add_assoc_long(
        &executor,
        "last_linear_input_width",
        (zend_long) model->cuda_decoder_graph_executor_last_linear_input_width
    );
    add_assoc_long(
        &executor,
        "last_linear_rows",
        (zend_long) model->cuda_decoder_graph_executor_last_linear_rows
    );
    add_assoc_long(
        &executor,
        "slice_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_slice_execution_count
    );
    add_assoc_long(
        &executor,
        "last_slice_offset",
        (zend_long) model->cuda_decoder_graph_executor_last_slice_offset
    );
    add_assoc_long(
        &executor,
        "last_slice_length",
        (zend_long) model->cuda_decoder_graph_executor_last_slice_length
    );
    add_assoc_long(
        &executor,
        "rope_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_rope_execution_count
    );
    add_assoc_long(
        &executor,
        "last_rope_length",
        (zend_long) model->cuda_decoder_graph_executor_last_rope_length
    );
    add_assoc_long(
        &executor,
        "last_rope_position",
        (zend_long) model->cuda_decoder_graph_executor_last_rope_position
    );
    add_assoc_long(
        &executor,
        "kv_head_prepare_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_kv_head_prepare_execution_count
    );
    add_assoc_long(
        &executor,
        "last_kv_head_key_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_head_key_length
    );
    add_assoc_long(
        &executor,
        "last_kv_head_value_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_head_value_length
    );
    add_assoc_long(
        &executor,
        "kv_write_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_kv_write_execution_count
    );
    add_assoc_long(
        &executor,
        "last_kv_write_key_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_write_key_length
    );
    add_assoc_long(
        &executor,
        "last_kv_write_value_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_write_value_length
    );
    add_assoc_long(
        &executor,
        "last_kv_write_layer",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_write_layer
    );
    add_assoc_long(
        &executor,
        "last_kv_write_head",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_write_head
    );
    add_assoc_long(
        &executor,
        "last_kv_write_position",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_write_position
    );
    add_assoc_long(
        &executor,
        "kv_attention_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_kv_attention_execution_count
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_key_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_key_length
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_value_length",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_value_length
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_layer",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_layer
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_head",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_head
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_slot_start",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_slot_start
    );
    add_assoc_long(
        &executor,
        "last_kv_attention_slot_count",
        (zend_long) model->cuda_decoder_graph_executor_last_kv_attention_slot_count
    );
    add_assoc_long(
        &executor,
        "attention_stack_slot_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_attention_stack_slot_execution_count
    );
    add_assoc_long(
        &executor,
        "last_attention_stack_width",
        (zend_long) model->cuda_decoder_graph_executor_last_attention_stack_width
    );
    add_assoc_long(
        &executor,
        "last_attention_stack_input_index",
        (zend_long) model->cuda_decoder_graph_executor_last_attention_stack_input_index
    );
    add_assoc_long(
        &executor,
        "last_attention_stack_input_count",
        (zend_long) model->cuda_decoder_graph_executor_last_attention_stack_input_count
    );
    add_assoc_long(
        &executor,
        "attention_heads_device_executions",
        (zend_long) model->cuda_decoder_graph_executor_attention_heads_execution_count
    );
    add_assoc_long(&executor, "attention_stack_device_executions", (zend_long) model->cuda_decoder_graph_executor_attention_stack_execution_count);
    add_assoc_long(&executor, "attention_output_projection_device_executions", (zend_long) model->cuda_decoder_graph_executor_attention_output_projection_execution_count);
    add_assoc_long(&executor, "attention_residual_device_executions", (zend_long) model->cuda_decoder_graph_executor_attention_residual_execution_count);
    add_assoc_long(&executor, "ffn_norm_device_executions", (zend_long) model->cuda_decoder_graph_executor_ffn_norm_execution_count);
    add_assoc_long(&executor, "ffn_gate_up_projection_device_executions", (zend_long) model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_count);
    add_assoc_long(&executor, "ffn_swiglu_device_executions", (zend_long) model->cuda_decoder_graph_executor_ffn_swiglu_execution_count);
    add_assoc_long(&executor, "ffn_down_projection_device_executions", (zend_long) model->cuda_decoder_graph_executor_ffn_down_projection_execution_count);
    add_assoc_long(&executor, "ffn_output_residual_device_executions", (zend_long) model->cuda_decoder_graph_executor_ffn_output_residual_execution_count);
    add_assoc_long(&executor, "final_norm_device_executions", (zend_long) model->cuda_decoder_graph_executor_final_norm_execution_count);
    add_assoc_long(&executor, "logits_projection_device_executions", (zend_long) model->cuda_decoder_graph_executor_logits_projection_execution_count);
    add_assoc_long(
        &executor,
        "last_attention_stack_expected_heads",
        (zend_long) model->cuda_decoder_graph_executor_last_attention_stack_expected_heads
    );
    add_assoc_long(
        &executor,
        "last_attention_stack_prepared_heads",
        (zend_long) model->cuda_decoder_graph_executor_last_attention_stack_prepared_heads
    );
    add_assoc_long(&executor, "last_attention_output_projection_width", (zend_long) model->cuda_decoder_graph_executor_last_attention_output_projection_width);
    add_assoc_long(&executor, "last_attention_residual_width", (zend_long) model->cuda_decoder_graph_executor_last_attention_residual_width);
    add_assoc_long(&executor, "last_ffn_norm_width", (zend_long) model->cuda_decoder_graph_executor_last_ffn_norm_width);
    add_assoc_long(&executor, "last_ffn_gate_rows", (zend_long) model->cuda_decoder_graph_executor_last_ffn_gate_rows);
    add_assoc_long(&executor, "last_ffn_up_rows", (zend_long) model->cuda_decoder_graph_executor_last_ffn_up_rows);
    add_assoc_long(&executor, "last_ffn_swiglu_width", (zend_long) model->cuda_decoder_graph_executor_last_ffn_swiglu_width);
    add_assoc_long(&executor, "last_ffn_down_rows", (zend_long) model->cuda_decoder_graph_executor_last_ffn_down_rows);
    add_assoc_long(&executor, "last_ffn_output_residual_width", (zend_long) model->cuda_decoder_graph_executor_last_ffn_output_residual_width);
    add_assoc_long(&executor, "last_final_norm_width", (zend_long) model->cuda_decoder_graph_executor_last_final_norm_width);
    add_assoc_long(&executor, "last_logits_rows", (zend_long) model->cuda_decoder_graph_executor_last_logits_rows);
    if (model->cuda_decoder_graph_executor_last_embedding_token_available
        && model->cuda_decoder_graph_executor_last_embedding_token_id <= (zend_ulong) ZEND_LONG_MAX) {
        add_assoc_long(
            &executor,
            "last_embedding_token_id",
            (zend_long) model->cuda_decoder_graph_executor_last_embedding_token_id
        );
    } else {
        add_assoc_null(&executor, "last_embedding_token_id");
    }
    add_assoc_bool(&executor, "device_execution_result_ready", false);
    add_assoc_long(&executor, "result", model->cuda_decoder_graph_executor_result);
    add_assoc_string(&executor, "error", model->cuda_decoder_graph_executor_error);

    array_init(&supported_ops);
    add_next_index_string(&supported_ops, "embedding");
    add_next_index_string(&supported_ops, "rms_norm");
    add_next_index_string(&supported_ops, "linear");
    add_next_index_string(&supported_ops, "add");
    add_next_index_string(&supported_ops, "scale");
    add_next_index_string(&supported_ops, "mul");
    add_next_index_string(&supported_ops, "silu");
    add_next_index_string(&supported_ops, "slice");
    add_next_index_string(&supported_ops, "rope");
    add_next_index_string(&supported_ops, "kv_write");
    add_next_index_string(&supported_ops, "kv_attention");
    add_next_index_string(&supported_ops, "stack");
    add_next_index_string(&supported_ops, "argmax_token");
    add_next_index_string(&supported_ops, "sample_token");
    add_assoc_zval(&executor, "supported_ops", &supported_ops);

    add_assoc_zval(return_value, "decoder_graph_executor", &executor);
    add_assoc_bool(return_value, "decoder_graph_executor_ready", model->cuda_decoder_graph_executor_available);
    add_assoc_bool(
        return_value,
        "decoder_graph_result_contract_ready",
        model->cuda_decoder_graph_executor_result_contract_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_execution_plan_ready",
        model->cuda_decoder_graph_executor_execution_plan_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_embedding_execution_ready",
        model->cuda_decoder_graph_executor_embedding_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_rms_norm_execution_ready",
        model->cuda_decoder_graph_executor_rms_norm_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_linear_execution_ready",
        model->cuda_decoder_graph_executor_linear_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_slice_execution_ready",
        model->cuda_decoder_graph_executor_slice_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_rope_execution_ready",
        model->cuda_decoder_graph_executor_rope_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_kv_head_prepare_execution_ready",
        model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_kv_write_execution_ready",
        model->cuda_decoder_graph_executor_kv_write_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_kv_attention_execution_ready",
        model->cuda_decoder_graph_executor_kv_attention_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_attention_stack_slot_execution_ready",
        model->cuda_decoder_graph_executor_attention_stack_slot_execution_available
    );
    add_assoc_bool(
        return_value,
        "decoder_graph_attention_heads_execution_ready",
        model->cuda_decoder_graph_executor_attention_heads_execution_available
    );
    add_assoc_bool(return_value, "decoder_graph_attention_stack_execution_ready", model->cuda_decoder_graph_executor_attention_stack_execution_available);
    add_assoc_bool(return_value, "decoder_graph_attention_output_projection_execution_ready", model->cuda_decoder_graph_executor_attention_output_projection_execution_available);
    add_assoc_bool(return_value, "decoder_graph_attention_residual_execution_ready", model->cuda_decoder_graph_executor_attention_residual_execution_available);
    add_assoc_bool(return_value, "decoder_graph_ffn_norm_execution_ready", model->cuda_decoder_graph_executor_ffn_norm_execution_available);
    add_assoc_bool(return_value, "decoder_graph_ffn_gate_up_projection_execution_ready", model->cuda_decoder_graph_executor_ffn_gate_up_projection_execution_available);
    add_assoc_bool(return_value, "decoder_graph_ffn_swiglu_execution_ready", model->cuda_decoder_graph_executor_ffn_swiglu_execution_available);
    add_assoc_bool(return_value, "decoder_graph_ffn_down_projection_execution_ready", model->cuda_decoder_graph_executor_ffn_down_projection_execution_available);
    add_assoc_bool(return_value, "decoder_graph_ffn_output_residual_execution_ready", model->cuda_decoder_graph_executor_ffn_output_residual_execution_available);
    add_assoc_bool(return_value, "decoder_graph_final_norm_execution_ready", model->cuda_decoder_graph_executor_final_norm_execution_available);
    add_assoc_bool(return_value, "decoder_graph_logits_projection_execution_ready", model->cuda_decoder_graph_executor_logits_projection_execution_available);
}

static void king_inference_cuda_decoder_graph_executor_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_executor;

    king_inference_cuda_decoder_graph_executor_add_status(model, return_value);
    if (!model->cuda_decoder_graph_executor_attempted
        || model->cuda_decoder_graph_executor_available
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_executor = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_executor) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_decoder_graph_executor_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_decoder_graph_executor_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_decoder_graph_executor_unavailable");
    }
}
