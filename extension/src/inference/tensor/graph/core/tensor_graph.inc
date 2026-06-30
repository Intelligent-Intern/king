typedef struct _king_inference_graph_vector {
    zend_string *id;
    double *values;
    zend_ulong length;
    struct _king_inference_graph_vector *next;
} king_inference_graph_vector;

static void king_inference_graph_vectors_release(king_inference_graph_vector *head)
{
    while (head != NULL) {
        king_inference_graph_vector *next = head->next;
        if (head->id != NULL) {
            zend_string_release(head->id);
        }
        if (head->values != NULL) {
            efree(head->values);
        }
        efree(head);
        head = next;
    }
}

static king_inference_graph_vector *king_inference_graph_vector_find(
    king_inference_graph_vector *head,
    zend_string *id
) {
    while (head != NULL) {
        if (zend_string_equals(head->id, id)) {
            return head;
        }
        head = head->next;
    }

    return NULL;
}

static zend_result king_inference_graph_vector_add(
    king_inference_graph_vector **head,
    zend_string *id,
    double *values,
    zend_ulong length
) {
    king_inference_graph_vector *node;

    if (king_inference_graph_vector_find(*head, id) != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King inference graph output '%s' is defined more than once.",
            ZSTR_VAL(id)
        );
        return FAILURE;
    }

    node = ecalloc(1, sizeof(*node));
    node->id = zend_string_copy(id);
    node->values = values;
    node->length = length;
    node->next = *head;
    *head = node;
    return SUCCESS;
}

static zval *king_inference_graph_required_field(zval *array, const char *key)
{
    if (array == NULL || Z_TYPE_P(array) != IS_ARRAY) {
        return NULL;
    }

    return king_inference_array_find(array, key);
}

static zend_result king_inference_graph_required_string(
    zval *array,
    const char *key,
    zend_string **value
) {
    zval *field = king_inference_graph_required_field(array, key);

    if (field == NULL || Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph requires string field '%s'.", key);
        return FAILURE;
    }

    *value = Z_STR_P(field);
    return SUCCESS;
}

static double king_inference_graph_double_option(zval *array, const char *key, double fallback)
{
    zval *field = king_inference_array_find(array, key);

    if (field == NULL) {
        return fallback;
    }
    if (Z_TYPE_P(field) == IS_DOUBLE) {
        return Z_DVAL_P(field);
    }
    if (Z_TYPE_P(field) == IS_LONG) {
        return (double) Z_LVAL_P(field);
    }

    return fallback;
}

static zend_result king_inference_graph_finite_double_option(
    zval *array,
    const char *key,
    double fallback,
    double *value
) {
    zval *field = king_inference_array_find(array, key);

    *value = king_inference_graph_double_option(array, key, fallback);
    if (field == NULL || (Z_TYPE_P(field) != IS_DOUBLE && Z_TYPE_P(field) != IS_LONG)) {
        return SUCCESS;
    }
    if (!isfinite(*value)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King inference graph option '%s' must be a finite number.",
            key
        );
        return FAILURE;
    }

    return SUCCESS;
}

static double king_inference_graph_sqrt(double value)
{
    double guess;
    int i;

    if (value <= 0.0) {
        return 0.0;
    }

    guess = value >= 1.0 ? value : 1.0;
    for (i = 0; i < 24; i++) {
        guess = 0.5 * (guess + value / guess);
    }

    return guess;
}

static zend_result king_inference_graph_vector_from_zval(
    zval *source,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    zend_ulong length;
    zend_ulong index = 0;
    double *values;
    zval *entry;

    *values_out = NULL;
    *length_out = 0;
    if (source == NULL || Z_TYPE_P(source) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph vector inputs must be arrays.");
        return FAILURE;
    }

    length = (zend_ulong) zend_hash_num_elements(Z_ARRVAL_P(source));
    if (length == 0 || length > max_values || length > (zend_ulong) (SIZE_MAX / sizeof(double))) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph vector length exceeds configured limits.");
        return FAILURE;
    }

    values = ecalloc((size_t) length, sizeof(double));
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(source), entry) {
        if (Z_TYPE_P(entry) == IS_LONG) {
            values[index] = (double) Z_LVAL_P(entry);
        } else if (Z_TYPE_P(entry) == IS_DOUBLE) {
            values[index] = Z_DVAL_P(entry);
        } else {
            efree(values);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph vectors must contain only int or float values.");
            return FAILURE;
        }
        index++;
    } ZEND_HASH_FOREACH_END();

    *values_out = values;
    *length_out = length;
    return SUCCESS;
}

static zend_result king_inference_graph_op_embedding(
    king_inference_model_object *model,
    zval *op,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    zend_string *tensor = NULL;
    const char *status = NULL;
    king_inference_tensor_native_view view;
    zend_ulong cols;
    zend_ulong rows;
    zend_ulong col;
    zend_long token_id;
    zval *token;
    double output_scale = 1.0;
    double *values;

    if (king_inference_resolve_token_embedding_tensor_name(model, op, &tensor, NULL, &status) != SUCCESS
        || tensor == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            status != NULL && strcmp(status, "ambiguous_shape_scan") == 0
                ? "King inference graph embedding found more than one possible token embedding tensor; set tensor explicitly."
                : "King inference graph embedding requires tensor or a resolvable token embedding tensor."
        );
        return FAILURE;
    }
    token = king_inference_array_find(op, "token_id");
    if (token == NULL) {
        token = king_inference_array_find(op, "token");
    }
    if (token == NULL || Z_TYPE_P(token) != IS_LONG || Z_LVAL_P(token) < 0) {
        zend_string_release(tensor);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph embedding requires token_id.");
        return FAILURE;
    }
    token_id = Z_LVAL_P(token);
    if (king_inference_graph_finite_double_option(op, "scale", 1.0, &output_scale) != SUCCESS) {
        zend_string_release(tensor);
        return FAILURE;
    }

    if (king_inference_tensor_native_view_resolve(model, tensor, &view) != SUCCESS) {
        zend_string_release(tensor);
        return FAILURE;
    }
    if (view.rank != 2
        || king_inference_tensor_dimension_at(view.dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(view.dimensions, 1, &rows) != SUCCESS
        || (zend_ulong) token_id >= rows
        || cols == 0
        || cols > max_values
        || cols > (zend_ulong) (SIZE_MAX / sizeof(double))) {
        zend_string_release(tensor);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph embedding tensor dimensions or token_id are invalid.");
        return FAILURE;
    }

    values = ecalloc((size_t) cols, sizeof(double));
    for (col = 0; col < cols; col++) {
        if (king_inference_tensor_value_at(&view, ((zend_ulong) token_id) * cols + col, &values[col]) != SUCCESS) {
            efree(values);
            zend_string_release(tensor);
            return FAILURE;
        }
        values[col] *= output_scale;
    }
    zend_string_release(tensor);

    *values_out = values;
    *length_out = cols;
    return SUCCESS;
}

static zend_result king_inference_graph_op_rms_norm(
    king_inference_model_object *model,
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    zend_string *input_id;
    zend_string *weight_tensor = NULL;
    king_inference_graph_vector *input;
    king_inference_tensor_native_view weight;
    zend_ulong i;
    double sumsq = 0.0;
    double epsilon;
    double scale;
    double *values;

    if (king_inference_graph_finite_double_option(op, "epsilon", 0.000001, &epsilon) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_graph_required_string(op, "input", &input_id) != SUCCESS) {
        return FAILURE;
    }
    input = king_inference_graph_vector_find(vectors, input_id);
    if (input == NULL) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph input '%s' was not produced.", ZSTR_VAL(input_id));
        return FAILURE;
    }
    if (input->length == 0 || input->length > max_values) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph rms_norm input exceeds configured limits.");
        return FAILURE;
    }

    for (i = 0; i < input->length; i++) {
        sumsq += input->values[i] * input->values[i];
    }
    scale = 1.0 / king_inference_graph_sqrt((sumsq / (double) input->length) + epsilon);
    values = ecalloc((size_t) input->length, sizeof(double));

    {
        zval *weight_field = king_inference_array_find(op, "weight");
        if (weight_field != NULL) {
            if (Z_TYPE_P(weight_field) != IS_STRING || Z_STRLEN_P(weight_field) == 0) {
                efree(values);
                zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph rms_norm weight must be a tensor name.");
                return FAILURE;
            }
            weight_tensor = Z_STR_P(weight_field);
        }
    }

    if (weight_tensor != NULL) {
        if (king_inference_tensor_native_view_resolve(model, weight_tensor, &weight) != SUCCESS) {
            efree(values);
            return FAILURE;
        }
        if (weight.rank != 1 || weight.elements != input->length) {
            efree(values);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph rms_norm weight length does not match input.");
            return FAILURE;
        }
        for (i = 0; i < input->length; i++) {
            double weight_value;
            if (king_inference_tensor_value_at(&weight, i, &weight_value) != SUCCESS) {
                efree(values);
                return FAILURE;
            }
            values[i] = input->values[i] * scale * weight_value;
        }
    } else {
        for (i = 0; i < input->length; i++) {
            values[i] = input->values[i] * scale;
        }
    }

    *values_out = values;
    *length_out = input->length;
    return SUCCESS;
}

static zend_result king_inference_graph_op_linear(
    king_inference_model_object *model,
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    zend_ulong max_operations,
    double **values_out,
    zend_ulong *length_out
) {
    zend_string *input_id;
    zend_string *weight_tensor;
    zend_string *bias_tensor = NULL;
    king_inference_graph_vector *input;
    king_inference_tensor_native_view weight;
    king_inference_tensor_native_view bias;
    zend_ulong cols;
    zend_ulong rows = 1;
    zend_ulong row;
    zend_ulong row_offset = king_inference_tensor_option_ulong(op, "row_offset", 0);
    zend_ulong row_limit;
    double *values;
    zval *bias_field;

    if (king_inference_graph_required_string(op, "input", &input_id) != SUCCESS
        || king_inference_graph_required_string(op, "weight", &weight_tensor) != SUCCESS) {
        return FAILURE;
    }
    input = king_inference_graph_vector_find(vectors, input_id);
    if (input == NULL) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph input '%s' was not produced.", ZSTR_VAL(input_id));
        return FAILURE;
    }
    if (king_inference_tensor_native_view_resolve(model, weight_tensor, &weight) != SUCCESS) {
        return FAILURE;
    }
    if (weight.rank == 1) {
        cols = weight.elements;
    } else if (weight.rank == 2) {
        if (king_inference_tensor_dimension_at(weight.dimensions, 0, &cols) != SUCCESS
            || king_inference_tensor_dimension_at(weight.dimensions, 1, &rows) != SUCCESS) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph linear weight has invalid dimensions.");
            return FAILURE;
        }
    } else {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph linear requires rank-1 or rank-2 weight.");
        return FAILURE;
    }
    if (input->length != cols || row_offset > rows) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph linear input length does not match weight columns.");
        return FAILURE;
    }

    row_limit = king_inference_tensor_option_ulong(op, "row_limit", rows - row_offset);
    if (row_limit > rows - row_offset) {
        row_limit = rows - row_offset;
    }
    if (row_limit == 0 || row_limit > max_values || (cols > 0 && row_limit > max_operations / cols)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph linear exceeds configured limits.");
        return FAILURE;
    }

    bias_field = king_inference_array_find(op, "bias");
    if (bias_field != NULL) {
        if (Z_TYPE_P(bias_field) != IS_STRING || Z_STRLEN_P(bias_field) == 0) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph linear bias must be a tensor name.");
            return FAILURE;
        }
        bias_tensor = Z_STR_P(bias_field);
        if (king_inference_tensor_native_view_resolve(model, bias_tensor, &bias) != SUCCESS) {
            return FAILURE;
        }
        if (bias.rank != 1 || bias.elements < row_offset + row_limit) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph linear bias length does not cover output rows.");
            return FAILURE;
        }
    }

    values = ecalloc((size_t) row_limit, sizeof(double));
    for (row = 0; row < row_limit; row++) {
        zend_ulong base = (row_offset + row) * cols;
        if (king_inference_tensor_row_dot(&weight, base, cols, input->values, &values[row]) != SUCCESS) {
            efree(values);
            return FAILURE;
        }
        if (bias_tensor != NULL) {
            double bias_value;
            if (king_inference_tensor_value_at(&bias, row_offset + row, &bias_value) != SUCCESS) {
                efree(values);
                return FAILURE;
            }
            values[row] += bias_value;
        }
    }

    *values_out = values;
    *length_out = row_limit;
    return SUCCESS;
}

#include "../ops/tensor_graph_ops.inc"
#include "../ops/tensor_graph_sampling.inc"
#include "../ops/tensor_graph_kv.inc"

static void king_inference_graph_add_vector_payload(
    zval *target,
    king_inference_graph_vector *vector
) {
    zval payload;
    zval values;
    zend_ulong i;

    array_init(&payload);
    add_assoc_long(&payload, "length", (zend_long) vector->length);
    array_init(&values);
    for (i = 0; i < vector->length; i++) {
        add_next_index_double(&values, vector->values[i]);
    }
    add_assoc_zval(&payload, "values", &values);
    zend_hash_update(Z_ARRVAL_P(target), vector->id, &payload);
}

static zend_result king_inference_graph_run_array(
    king_inference_model_object *model,
    zval *graph,
    zval *options,
    zval *return_value
) {
    king_inference_graph_vector *vectors = NULL;
    king_inference_graph_vector *node;
    zval *inputs;
    zval *ops;
    zval *output_id;
    zval *entry;
    zval state;
    bool state_initialized = false;
    zend_string *key;
    zend_ulong op_count = 0;
    zend_ulong max_values = king_inference_tensor_option_ulong(options, "max_vector_values", 65536);
    zend_ulong max_operations = king_inference_tensor_option_ulong(options, "max_operations", 16777216);
    zval *return_outputs_option = king_inference_array_find(options, "return_outputs");
    bool return_outputs = return_outputs_option == NULL || zend_is_true(return_outputs_option);

    if (graph == NULL || Z_TYPE_P(graph) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph requires a graph array.");
        return FAILURE;
    }

    inputs = king_inference_array_find(graph, "inputs");
    if (inputs != NULL && Z_TYPE_P(inputs) == IS_ARRAY) {
        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(inputs), key, entry) {
            double *values;
            zend_ulong length;
            if (key == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
                king_inference_graph_vectors_release(vectors);
                zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph inputs must be named vector arrays.");
                return FAILURE;
            }
            if (king_inference_graph_vector_from_zval(entry, max_values, &values, &length) != SUCCESS) {
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
            if (king_inference_graph_vector_add(&vectors, key, values, length) != SUCCESS) {
                efree(values);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } ZEND_HASH_FOREACH_END();
    }

    ops = king_inference_array_find(graph, "ops");
    if (ops == NULL) {
        ops = king_inference_array_find(graph, "steps");
    }
    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY) {
        king_inference_graph_vectors_release(vectors);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph requires ops.");
        return FAILURE;
    }

    if (king_inference_graph_state_init(graph, &state) != SUCCESS) {
        king_inference_graph_vectors_release(vectors);
        return FAILURE;
    }
    state_initialized = true;

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zend_string *id;
        zend_string *op_name;
        double *values = NULL;
        zend_ulong length = 0;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            zval_ptr_dtor(&state);
            king_inference_graph_vectors_release(vectors);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph operations must be arrays.");
            return FAILURE;
        }
        if (king_inference_graph_required_string(entry, "id", &id) != SUCCESS
            || king_inference_graph_required_string(entry, "op", &op_name) != SUCCESS) {
            zval_ptr_dtor(&state);
            king_inference_graph_vectors_release(vectors);
            return FAILURE;
        }
        if (zend_string_equals_literal(op_name, "embedding")) {
            if (king_inference_graph_op_embedding(model, entry, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "rms_norm")) {
            if (king_inference_graph_op_rms_norm(model, entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "linear")) {
            if (king_inference_graph_op_linear(model, entry, vectors, max_values, max_operations, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "add")) {
            if (king_inference_graph_op_add(entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "scale")) {
            if (king_inference_graph_op_scale(entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "mul")) {
            if (king_inference_graph_op_mul(entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "silu")) {
            if (king_inference_graph_op_silu(entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "gelu_tanh")) {
            if (king_inference_graph_op_gelu_tanh(entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "slice")) {
            if (king_inference_graph_op_slice(entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "dot")) {
            if (king_inference_graph_op_dot(entry, vectors, max_values, max_operations, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "stack")) {
            if (king_inference_graph_op_stack(entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "softmax")) {
            if (king_inference_graph_op_softmax(entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "weighted_sum")) {
            if (king_inference_graph_op_weighted_sum(entry, vectors, max_values, max_operations, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "argmax_token")) {
            if (king_inference_graph_op_argmax_token(entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "sample_token")) {
            if (king_inference_graph_op_sample_token(entry, vectors, max_values, max_operations, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "kv_read")) {
            if (king_inference_graph_op_kv_read(entry, &state, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "kv_write")) {
            if (king_inference_graph_op_kv_write(entry, vectors, &state, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "kv_attention")) {
            if (king_inference_graph_op_kv_attention(entry, vectors, &state, max_values, max_operations, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else if (zend_string_equals_literal(op_name, "rope")) {
            if (king_inference_graph_op_rope(entry, vectors, max_values, &values, &length) != SUCCESS) {
                zval_ptr_dtor(&state);
                king_inference_graph_vectors_release(vectors);
                return FAILURE;
            }
        } else {
            zval_ptr_dtor(&state);
            king_inference_graph_vectors_release(vectors);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph operation '%s' is not supported.", ZSTR_VAL(op_name));
            return FAILURE;
        }

        if (king_inference_graph_vector_add(&vectors, id, values, length) != SUCCESS) {
            if (values != NULL) {
                efree(values);
            }
            zval_ptr_dtor(&state);
            king_inference_graph_vectors_release(vectors);
            return FAILURE;
        }
        op_count++;
    } ZEND_HASH_FOREACH_END();

    array_init(return_value);
    add_assoc_long(return_value, "op_count", (zend_long) op_count);
    add_assoc_long(return_value, "max_vector_values", (zend_long) max_values);
    add_assoc_long(return_value, "max_operations", (zend_long) max_operations);
    if (return_outputs) {
        zval outputs;
        array_init(&outputs);
        for (node = vectors; node != NULL; node = node->next) {
            king_inference_graph_add_vector_payload(&outputs, node);
        }
        add_assoc_zval(return_value, "outputs", &outputs);
    }

    output_id = king_inference_array_find(graph, "output");
    if (output_id != NULL && Z_TYPE_P(output_id) == IS_STRING) {
        king_inference_graph_vector *final_vector = king_inference_graph_vector_find(vectors, Z_STR_P(output_id));
        if (final_vector != NULL) {
            zval final;
            array_init(&final);
            king_inference_graph_add_vector_payload(&final, final_vector);
            add_assoc_str(return_value, "output", zend_string_copy(Z_STR_P(output_id)));
            add_assoc_zval(return_value, "final", &final);
        }
    }

    if (state_initialized) {
        add_assoc_zval(return_value, "state", &state);
        state_initialized = false;
    }
    king_inference_graph_vectors_release(vectors);
    return SUCCESS;
}
