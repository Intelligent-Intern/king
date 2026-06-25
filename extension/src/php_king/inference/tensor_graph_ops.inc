#define KING_INFERENCE_GRAPH_PI 3.14159265358979323846264338327950288
#define KING_INFERENCE_GRAPH_HALF_PI 1.57079632679489661923132169163975144
#define KING_INFERENCE_GRAPH_TWO_PI 6.28318530717958647692528676655900576
#define KING_INFERENCE_GRAPH_LN2 0.693147180559945309417232121458176568

static double king_inference_graph_wrap_angle(double value)
{
    if (value > KING_INFERENCE_GRAPH_TWO_PI || value < -KING_INFERENCE_GRAPH_TWO_PI) {
        long turns = (long) (value / KING_INFERENCE_GRAPH_TWO_PI);
        value -= (double) turns * KING_INFERENCE_GRAPH_TWO_PI;
    }
    while (value > KING_INFERENCE_GRAPH_PI) {
        value -= KING_INFERENCE_GRAPH_TWO_PI;
    }
    while (value < -KING_INFERENCE_GRAPH_PI) {
        value += KING_INFERENCE_GRAPH_TWO_PI;
    }

    return value;
}

static double king_inference_graph_sin(double value)
{
    double x = king_inference_graph_wrap_angle(value);
    double x2;

    if (x > KING_INFERENCE_GRAPH_HALF_PI) {
        x = KING_INFERENCE_GRAPH_PI - x;
    } else if (x < -KING_INFERENCE_GRAPH_HALF_PI) {
        x = -KING_INFERENCE_GRAPH_PI - x;
    }

    x2 = x * x;
    return x * (1.0 - x2 / 6.0 + (x2 * x2) / 120.0 - (x2 * x2 * x2) / 5040.0);
}

static double king_inference_graph_cos(double value)
{
    double x = king_inference_graph_wrap_angle(value);
    double sign = 1.0;
    double x2;

    if (x > KING_INFERENCE_GRAPH_HALF_PI) {
        x = KING_INFERENCE_GRAPH_PI - x;
        sign = -1.0;
    } else if (x < -KING_INFERENCE_GRAPH_HALF_PI) {
        x = -KING_INFERENCE_GRAPH_PI - x;
        sign = -1.0;
    }

    x2 = x * x;
    return sign * (1.0 - x2 / 2.0 + (x2 * x2) / 24.0 - (x2 * x2 * x2) / 720.0);
}

static double king_inference_graph_exp(double value)
{
    long exponent;
    int i;
    double reduced;
    double term = 1.0;
    double sum = 1.0;

    if (value <= -60.0) {
        return 0.0;
    }
    if (value > 60.0) {
        value = 60.0;
    }

    exponent = (long) (value / KING_INFERENCE_GRAPH_LN2);
    if ((double) exponent * KING_INFERENCE_GRAPH_LN2 > value) {
        exponent--;
    }
    reduced = value - (double) exponent * KING_INFERENCE_GRAPH_LN2;

    for (i = 1; i <= 12; i++) {
        term *= reduced / (double) i;
        sum += term;
    }

    while (exponent > 0) {
        sum *= 2.0;
        exponent--;
    }
    while (exponent < 0) {
        sum *= 0.5;
        exponent++;
    }

    return sum > 0.0 ? sum : 0.0;
}

static zend_result king_inference_graph_lookup_vector(
    king_inference_graph_vector *vectors,
    zval *op,
    const char *key,
    king_inference_graph_vector **vector_out
) {
    zend_string *id;

    *vector_out = NULL;
    if (king_inference_graph_required_string(op, key, &id) != SUCCESS) {
        return FAILURE;
    }
    *vector_out = king_inference_graph_vector_find(vectors, id);
    if (*vector_out == NULL) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph input '%s' was not produced.", ZSTR_VAL(id));
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_inference_graph_vector_from_optional_zval(
    zval *op,
    const char *array_key,
    const char *input_key,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out,
    bool *owned_out
) {
    zval *input = king_inference_array_find(op, input_key);
    zval *array;

    *values_out = NULL;
    *length_out = 0;
    *owned_out = false;

    if (input != NULL && Z_TYPE_P(input) != IS_STRING) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph vector input field '%s' must be a vector name.", input_key);
        return FAILURE;
    }
    if (input != NULL) {
        king_inference_graph_vector *vector = king_inference_graph_vector_find(vectors, Z_STR_P(input));
        if (vector == NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph vector input '%s' was not produced.", Z_STRVAL_P(input));
            return FAILURE;
        }
        *values_out = vector->values;
        *length_out = vector->length;
        return SUCCESS;
    }

    array = king_inference_array_find(op, array_key);
    if (array == NULL) {
        return SUCCESS;
    }
    if (king_inference_graph_vector_from_zval(array, max_values, values_out, length_out) != SUCCESS) {
        return FAILURE;
    }
    *owned_out = true;
    return SUCCESS;
}

static zend_result king_inference_graph_op_add(
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    king_inference_graph_vector *left;
    king_inference_graph_vector *right;
    double left_scale = king_inference_graph_double_option(op, "left_scale", 1.0);
    double right_scale = king_inference_graph_double_option(op, "right_scale", 1.0);
    double *values;
    zend_ulong i;

    if (king_inference_graph_lookup_vector(vectors, op, "left", &left) != SUCCESS
        || king_inference_graph_lookup_vector(vectors, op, "right", &right) != SUCCESS) {
        return FAILURE;
    }
    if (left->length == 0 || left->length != right->length || left->length > max_values) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph add requires equal non-empty vector lengths.");
        return FAILURE;
    }

    values = ecalloc((size_t) left->length, sizeof(double));
    for (i = 0; i < left->length; i++) {
        values[i] = left->values[i] * left_scale + right->values[i] * right_scale;
    }

    *values_out = values;
    *length_out = left->length;
    return SUCCESS;
}

static zend_result king_inference_graph_op_scale(
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    king_inference_graph_vector *input;
    double factor = king_inference_graph_double_option(op, "factor", king_inference_graph_double_option(op, "scale", 1.0));
    double *values;
    zend_ulong i;

    if (king_inference_graph_lookup_vector(vectors, op, "input", &input) != SUCCESS) {
        return FAILURE;
    }
    if (input->length == 0 || input->length > max_values) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph scale input exceeds configured limits.");
        return FAILURE;
    }

    values = ecalloc((size_t) input->length, sizeof(double));
    for (i = 0; i < input->length; i++) {
        values[i] = input->values[i] * factor;
    }

    *values_out = values;
    *length_out = input->length;
    return SUCCESS;
}

static zend_result king_inference_graph_op_dot(
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    zend_ulong max_operations,
    double **values_out,
    zend_ulong *length_out
) {
    king_inference_graph_vector *left;
    king_inference_graph_vector *right;
    double scale = king_inference_graph_double_option(op, "scale", 1.0);
    double sum = 0.0;
    double *values;
    zend_ulong i;

    if (king_inference_graph_lookup_vector(vectors, op, "left", &left) != SUCCESS
        || king_inference_graph_lookup_vector(vectors, op, "right", &right) != SUCCESS) {
        return FAILURE;
    }
    if (left->length == 0 || left->length != right->length || left->length > max_values || left->length > max_operations) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph dot requires equal non-empty vector lengths inside configured limits.");
        return FAILURE;
    }

    for (i = 0; i < left->length; i++) {
        sum += left->values[i] * right->values[i];
    }
    values = ecalloc(1, sizeof(double));
    values[0] = sum * scale;

    *values_out = values;
    *length_out = 1;
    return SUCCESS;
}

static zend_result king_inference_graph_op_stack(
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    zval *inputs = king_inference_array_find(op, "inputs");
    zval *entry;
    double *values;
    zend_ulong length = 0;
    zend_ulong offset = 0;

    if (inputs == NULL || Z_TYPE_P(inputs) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(inputs)) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph stack requires a non-empty inputs array.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(inputs), entry) {
        king_inference_graph_vector *input;
        if (entry == NULL || Z_TYPE_P(entry) != IS_STRING) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph stack inputs must be vector names.");
            return FAILURE;
        }
        input = king_inference_graph_vector_find(vectors, Z_STR_P(entry));
        if (input == NULL || input->length == 0 || input->length > max_values || length > max_values - input->length) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph stack input is missing or exceeds configured limits.");
            return FAILURE;
        }
        length += input->length;
    } ZEND_HASH_FOREACH_END();

    values = ecalloc((size_t) length, sizeof(double));
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(inputs), entry) {
        king_inference_graph_vector *input = king_inference_graph_vector_find(vectors, Z_STR_P(entry));
        memcpy(values + offset, input->values, (size_t) input->length * sizeof(double));
        offset += input->length;
    } ZEND_HASH_FOREACH_END();

    *values_out = values;
    *length_out = length;
    return SUCCESS;
}

static zend_result king_inference_graph_op_softmax(
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    king_inference_graph_vector *input;
    double *mask = NULL;
    double *values;
    zend_ulong mask_length = 0;
    bool mask_owned = false;
    bool any_active = false;
    double max_score = 0.0;
    double sum = 0.0;
    double temperature = king_inference_graph_double_option(op, "temperature", 1.0);
    double scale = king_inference_graph_double_option(op, "scale", 1.0);
    zend_ulong i;

    if (king_inference_graph_lookup_vector(vectors, op, "input", &input) != SUCCESS) {
        return FAILURE;
    }
    if (input->length == 0 || input->length > max_values) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph softmax input exceeds configured limits.");
        return FAILURE;
    }
    if (temperature <= 0.0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph softmax temperature must be positive.");
        return FAILURE;
    }

    if (king_inference_graph_vector_from_optional_zval(
        op,
        "mask",
        "mask_input",
        vectors,
        max_values,
        &mask,
        &mask_length,
        &mask_owned
    ) != SUCCESS) {
        return FAILURE;
    }
    if (mask != NULL && mask_length != input->length) {
        if (mask_owned) {
            efree(mask);
        }
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph softmax mask length must match input length.");
        return FAILURE;
    }

    for (i = 0; i < input->length; i++) {
        if (mask != NULL && mask[i] <= 0.0) {
            continue;
        }
        {
            double score = input->values[i] * scale / temperature;
            if (!any_active || score > max_score) {
                max_score = score;
            }
            any_active = true;
        }
    }
    if (!any_active) {
        if (mask_owned) {
            efree(mask);
        }
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph softmax mask disabled every score.");
        return FAILURE;
    }

    values = ecalloc((size_t) input->length, sizeof(double));
    for (i = 0; i < input->length; i++) {
        if (mask != NULL && mask[i] <= 0.0) {
            values[i] = 0.0;
            continue;
        }
        values[i] = king_inference_graph_exp((input->values[i] * scale / temperature) - max_score);
        sum += values[i];
    }
    if (sum <= 0.0) {
        if (mask_owned) {
            efree(mask);
        }
        efree(values);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference graph softmax produced an invalid probability sum.");
        return FAILURE;
    }
    for (i = 0; i < input->length; i++) {
        values[i] /= sum;
    }
    if (mask_owned) {
        efree(mask);
    }

    *values_out = values;
    *length_out = input->length;
    return SUCCESS;
}

static zend_result king_inference_graph_op_weighted_sum(
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    zend_ulong max_operations,
    double **values_out,
    zend_ulong *length_out
) {
    zval *inputs = king_inference_array_find(op, "inputs");
    king_inference_graph_vector *weights;
    zval *entry;
    double *values;
    zend_ulong vector_count;
    zend_ulong width = 0;
    zend_ulong index = 0;
    zend_ulong i;

    if (king_inference_graph_lookup_vector(vectors, op, "weights", &weights) != SUCCESS) {
        return FAILURE;
    }
    if (inputs == NULL || Z_TYPE_P(inputs) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(inputs)) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph weighted_sum requires a non-empty inputs array.");
        return FAILURE;
    }

    vector_count = (zend_ulong) zend_hash_num_elements(Z_ARRVAL_P(inputs));
    if (weights->length != vector_count || vector_count > max_values || vector_count > max_operations) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph weighted_sum weight count does not match inputs.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(inputs), entry) {
        king_inference_graph_vector *input;
        if (entry == NULL || Z_TYPE_P(entry) != IS_STRING) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph weighted_sum inputs must be vector names.");
            return FAILURE;
        }
        input = king_inference_graph_vector_find(vectors, Z_STR_P(entry));
        if (input == NULL || input->length == 0 || input->length > max_values) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph weighted_sum input is missing or exceeds configured limits.");
            return FAILURE;
        }
        if (width == 0) {
            width = input->length;
        } else if (width != input->length) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph weighted_sum inputs must have equal lengths.");
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    if (width == 0 || width > max_values || vector_count > max_operations / width) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph weighted_sum exceeds configured limits.");
        return FAILURE;
    }

    values = ecalloc((size_t) width, sizeof(double));
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(inputs), entry) {
        king_inference_graph_vector *input = king_inference_graph_vector_find(vectors, Z_STR_P(entry));
        double weight = weights->values[index++];
        for (i = 0; i < width; i++) {
            values[i] += weight * input->values[i];
        }
    } ZEND_HASH_FOREACH_END();

    *values_out = values;
    *length_out = width;
    return SUCCESS;
}

static zend_result king_inference_graph_op_rope(
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    king_inference_graph_vector *input;
    king_inference_graph_vector *frequency_vector = NULL;
    double *owned_frequencies = NULL;
    double *frequencies = NULL;
    double *values;
    zend_ulong frequency_length = 0;
    zend_ulong offset = king_inference_tensor_option_ulong(op, "offset", 0);
    zend_ulong head_dim;
    zend_ulong position = king_inference_tensor_option_ulong(op, "position", 0);
    double position_scale = king_inference_graph_double_option(op, "position_scale", 1.0);
    zval *frequency_input;
    zval *frequency_values;
    zend_ulong pair;

    if (king_inference_graph_lookup_vector(vectors, op, "input", &input) != SUCCESS) {
        return FAILURE;
    }
    if (input->length == 0 || input->length > max_values || offset > input->length) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph rope input exceeds configured limits.");
        return FAILURE;
    }

    head_dim = king_inference_tensor_option_ulong(op, "head_dim", input->length - offset);
    if (head_dim == 0 || (head_dim % 2) != 0 || head_dim > input->length - offset) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph rope requires an even head_dim inside the input vector.");
        return FAILURE;
    }

    frequency_input = king_inference_array_find(op, "frequency_input");
    if (frequency_input == NULL) {
        frequency_input = king_inference_array_find(op, "inv_freq_input");
    }
    if (frequency_input != NULL && Z_TYPE_P(frequency_input) == IS_STRING) {
        frequency_vector = king_inference_graph_vector_find(vectors, Z_STR_P(frequency_input));
        if (frequency_vector == NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph rope frequency input '%s' was not produced.", Z_STRVAL_P(frequency_input));
            return FAILURE;
        }
        frequencies = frequency_vector->values;
        frequency_length = frequency_vector->length;
    } else {
        frequency_values = king_inference_array_find(op, "frequencies");
        if (frequency_values == NULL) {
            frequency_values = king_inference_array_find(op, "inv_freqs");
        }
        if (king_inference_graph_vector_from_zval(frequency_values, max_values, &owned_frequencies, &frequency_length) != SUCCESS) {
            return FAILURE;
        }
        frequencies = owned_frequencies;
    }
    if (frequency_length < head_dim / 2) {
        if (owned_frequencies != NULL) {
            efree(owned_frequencies);
        }
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph rope frequency count is smaller than head_dim pairs.");
        return FAILURE;
    }

    values = ecalloc((size_t) input->length, sizeof(double));
    memcpy(values, input->values, (size_t) input->length * sizeof(double));
    for (pair = 0; pair < head_dim / 2; pair++) {
        zend_ulong even = offset + pair * 2;
        zend_ulong odd = even + 1;
        double angle = (double) position * position_scale * frequencies[pair];
        double sin_value = king_inference_graph_sin(angle);
        double cos_value = king_inference_graph_cos(angle);
        double x0 = values[even];
        double x1 = values[odd];
        values[even] = x0 * cos_value - x1 * sin_value;
        values[odd] = x0 * sin_value + x1 * cos_value;
    }
    if (owned_frequencies != NULL) {
        efree(owned_frequencies);
    }

    *values_out = values;
    *length_out = input->length;
    return SUCCESS;
}
