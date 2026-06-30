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
