/*
 * CUDA decoder graph executor device-op helpers.
 */

static bool king_inference_cuda_decoder_graph_executor_device_op(zend_string *op_name)
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
        || zend_string_equals_literal(op_name, "stack");
}

static bool king_inference_cuda_decoder_graph_executor_host_sampling_op(zend_string *op_name)
{
    return zend_string_equals_literal(op_name, "argmax_token")
        || zend_string_equals_literal(op_name, "sample_token");
}

static bool king_inference_cuda_decoder_graph_executor_zval_string_equals(
    zval *value,
    zend_string *expected
) {
    return value != NULL
        && expected != NULL
        && Z_TYPE_P(value) == IS_STRING
        && zend_string_equals(Z_STR_P(value), expected);
}

static zval *king_inference_cuda_decoder_graph_executor_ops(zval *graph)
{
    zval *ops = king_inference_array_find(graph, "ops");

    if (ops == NULL) {
        ops = king_inference_array_find(graph, "steps");
    }
    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY) {
        return NULL;
    }
    return ops;
}

static bool king_inference_cuda_decoder_graph_executor_op_index_ready(zval *graph)
{
    zval *ready = king_inference_array_find(graph, "_cuda_decode_op_index_ready");

    return ready != NULL && zend_is_true(ready);
}

static bool king_inference_cuda_decoder_graph_executor_array_index_ready(zval *graph, const char *key)
{
    zval *index = king_inference_array_find(graph, key);

    return king_inference_cuda_decoder_graph_executor_op_index_ready(graph)
        && index != NULL
        && Z_TYPE_P(index) == IS_ARRAY;
}

static void king_inference_cuda_decoder_graph_executor_profile_increment(zval *graph, const char *key)
{
    zval *value;

    if (graph == NULL || Z_TYPE_P(graph) != IS_ARRAY || key == NULL) {
        return;
    }
    value = king_inference_array_find(graph, key);
    if (value != NULL && Z_TYPE_P(value) == IS_LONG) {
        ZVAL_LONG(value, Z_LVAL_P(value) + 1);
        return;
    }
    add_assoc_long(graph, key, 1);
}

static zend_string *king_inference_cuda_decoder_graph_executor_input_index_key(
    zend_string *input_id,
    const char *name
) {
    return strpprintf(0, "%s\x1f%s", name, ZSTR_VAL(input_id));
}

static zend_string *king_inference_cuda_decoder_graph_executor_binary_index_key(
    const char *name,
    zend_string *left_id,
    zend_string *right_id
) {
    zend_string *first = zend_binary_strcmp(
        ZSTR_VAL(left_id),
        ZSTR_LEN(left_id),
        ZSTR_VAL(right_id),
        ZSTR_LEN(right_id)
    ) <= 0 ? left_id : right_id;
    zend_string *second = first == left_id ? right_id : left_id;

    return strpprintf(0, "%s\x1f%s\x1f%s", name, ZSTR_VAL(first), ZSTR_VAL(second));
}

static void king_inference_cuda_decoder_graph_executor_index_append(
    zval *index,
    zend_string *key,
    zend_ulong op_index
) {
    zval *bucket = zend_hash_find(Z_ARRVAL_P(index), key);

    if (bucket == NULL) {
        zval list;

        array_init(&list);
        add_next_index_long(&list, (zend_long) op_index);
        zend_hash_update(Z_ARRVAL_P(index), key, &list);
        return;
    }
    if (Z_TYPE_P(bucket) == IS_ARRAY) {
        add_next_index_long(bucket, (zend_long) op_index);
    }
}

static zend_result king_inference_cuda_decoder_graph_executor_prepare_op_index(
    king_inference_model_object *model,
    zval *graph
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval first_index;
    zval input_index;
    zval binary_index;
    zval *entry;
    zend_ulong numeric_index;
    zend_string *string_index;

    if (king_inference_cuda_decoder_graph_executor_op_index_ready(graph)) {
        return SUCCESS;
    }
    if (ops == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_op_index_ops_missing");
        return FAILURE;
    }

    array_init(&first_index);
    array_init(&input_index);
    array_init(&binary_index);
    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(ops), numeric_index, string_index, entry) {
        zval *op_name;
        zval *input;
        zval *left;
        zval *right;
        zval op_index_value;
        zend_string *index_key;

        if (string_index != NULL
            || numeric_index > (zend_ulong) ZEND_LONG_MAX
            || entry == NULL
            || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        if (op_name == NULL || Z_TYPE_P(op_name) != IS_STRING) {
            continue;
        }
        if (!zend_hash_exists(Z_ARRVAL(first_index), Z_STR_P(op_name))) {
            ZVAL_LONG(&op_index_value, (zend_long) numeric_index);
            zend_hash_update(Z_ARRVAL(first_index), Z_STR_P(op_name), &op_index_value);
        }
        input = king_inference_array_find(entry, "input");
        if (input != NULL && Z_TYPE_P(input) == IS_STRING) {
            index_key = king_inference_cuda_decoder_graph_executor_input_index_key(
                Z_STR_P(input),
                ZSTR_VAL(Z_STR_P(op_name))
            );
            king_inference_cuda_decoder_graph_executor_index_append(&input_index, index_key, numeric_index);
            zend_string_release(index_key);
        }
        left = king_inference_array_find(entry, "left");
        right = king_inference_array_find(entry, "right");
        if (left != NULL
            && Z_TYPE_P(left) == IS_STRING
            && right != NULL
            && Z_TYPE_P(right) == IS_STRING
            && (zend_string_equals_literal(Z_STR_P(op_name), "add")
                || zend_string_equals_literal(Z_STR_P(op_name), "mul"))) {
            index_key = king_inference_cuda_decoder_graph_executor_binary_index_key(
                ZSTR_VAL(Z_STR_P(op_name)),
                Z_STR_P(left),
                Z_STR_P(right)
            );
            king_inference_cuda_decoder_graph_executor_index_append(&binary_index, index_key, numeric_index);
            zend_string_release(index_key);
        }
    } ZEND_HASH_FOREACH_END();

    add_assoc_zval(graph, "_cuda_first_op_index", &first_index);
    add_assoc_zval(graph, "_cuda_input_op_index", &input_index);
    add_assoc_zval(graph, "_cuda_binary_op_index", &binary_index);
    add_assoc_bool(graph, "_cuda_decode_op_index_ready", true);
    return SUCCESS;
}

static zval *king_inference_cuda_decoder_graph_executor_indexed_op(
    zval *graph,
    zend_ulong op_index
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);

    return ops != NULL ? zend_hash_index_find(Z_ARRVAL_P(ops), op_index) : NULL;
}

static zval *king_inference_cuda_decoder_graph_executor_indexed_nth_op_for_input(
    zval *graph,
    zend_string *input_id,
    const char *name,
    zend_ulong ordinal
) {
    zval *index = king_inference_array_find(graph, "_cuda_input_op_index");
    zval *bucket;
    zval *op_index;
    zend_string *index_key;
    zval *entry;

    if (!king_inference_cuda_decoder_graph_executor_op_index_ready(graph)
        || index == NULL
        || Z_TYPE_P(index) != IS_ARRAY
        || input_id == NULL
        || name == NULL) {
        return NULL;
    }
    king_inference_cuda_decoder_graph_executor_profile_increment(graph, "_cuda_profile_op_index_lookups");
    index_key = king_inference_cuda_decoder_graph_executor_input_index_key(input_id, name);
    bucket = zend_hash_find(Z_ARRVAL_P(index), index_key);
    zend_string_release(index_key);
    if (bucket == NULL || Z_TYPE_P(bucket) != IS_ARRAY) {
        return NULL;
    }
    op_index = zend_hash_index_find(Z_ARRVAL_P(bucket), ordinal);
    if (op_index == NULL || Z_TYPE_P(op_index) != IS_LONG || Z_LVAL_P(op_index) < 0) {
        return NULL;
    }
    entry = king_inference_cuda_decoder_graph_executor_indexed_op(graph, (zend_ulong) Z_LVAL_P(op_index));
    if (entry != NULL && Z_TYPE_P(entry) == IS_ARRAY) {
        king_inference_cuda_decoder_graph_executor_profile_increment(graph, "_cuda_profile_op_index_hits");
    }
    return entry != NULL && Z_TYPE_P(entry) == IS_ARRAY ? entry : NULL;
}

#include "cuda_decoder_graph_executor_execution_plan.inc"

static zval *king_inference_cuda_decoder_graph_executor_first_op(
    zval *graph,
    const char *name
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *index = king_inference_array_find(graph, "_cuda_first_op_index");
    zval *indexed;
    zval *entry;
    size_t name_length = strlen(name);

    if (ops == NULL) {
        return NULL;
    }
    if (king_inference_cuda_decoder_graph_executor_op_index_ready(graph)
        && index != NULL
        && Z_TYPE_P(index) == IS_ARRAY) {
        king_inference_cuda_decoder_graph_executor_profile_increment(graph, "_cuda_profile_op_index_lookups");
        indexed = zend_hash_str_find(Z_ARRVAL_P(index), name, name_length);
        if (indexed != NULL && Z_TYPE_P(indexed) == IS_LONG && Z_LVAL_P(indexed) >= 0) {
            entry = king_inference_cuda_decoder_graph_executor_indexed_op(graph, (zend_ulong) Z_LVAL_P(indexed));
            if (entry != NULL && Z_TYPE_P(entry) == IS_ARRAY) {
                king_inference_cuda_decoder_graph_executor_profile_increment(graph, "_cuda_profile_op_index_hits");
            }
            return entry != NULL && Z_TYPE_P(entry) == IS_ARRAY ? entry : NULL;
        }
        return NULL;
    }

    king_inference_cuda_decoder_graph_executor_profile_increment(graph, "_cuda_profile_op_index_fallback_scans");
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && Z_STRLEN_P(op_name) == name_length
            && memcmp(Z_STRVAL_P(op_name), name, name_length) == 0) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zval *king_inference_cuda_decoder_graph_executor_op_for_input(
    zval *graph,
    zend_string *input_id,
    const char *name
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    size_t name_length = name != NULL ? strlen(name) : 0;

    if (ops == NULL || input_id == NULL || name_length == 0) {
        return NULL;
    }
    entry = king_inference_cuda_decoder_graph_executor_indexed_nth_op_for_input(graph, input_id, name, 0);
    if (entry != NULL) {
        return entry;
    }
    if (king_inference_cuda_decoder_graph_executor_array_index_ready(graph, "_cuda_input_op_index")) {
        return NULL;
    }

    king_inference_cuda_decoder_graph_executor_profile_increment(graph, "_cuda_profile_op_index_fallback_scans");
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *input;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        input = king_inference_array_find(entry, "input");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && Z_STRLEN_P(op_name) == name_length
            && memcmp(Z_STRVAL_P(op_name), name, name_length) == 0
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(input, input_id)) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zval *king_inference_cuda_decoder_graph_executor_nth_op_for_input(
    zval *graph,
    zend_string *input_id,
    const char *name,
    zend_ulong ordinal
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    zend_ulong matched = 0;
    size_t name_length = name != NULL ? strlen(name) : 0;

    if (ops == NULL || input_id == NULL || name_length == 0) {
        return NULL;
    }
    entry = king_inference_cuda_decoder_graph_executor_indexed_nth_op_for_input(
        graph,
        input_id,
        name,
        ordinal
    );
    if (entry != NULL) {
        return entry;
    }
    if (king_inference_cuda_decoder_graph_executor_array_index_ready(graph, "_cuda_input_op_index")) {
        return NULL;
    }

    king_inference_cuda_decoder_graph_executor_profile_increment(graph, "_cuda_profile_op_index_fallback_scans");
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *input;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        input = king_inference_array_find(entry, "input");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && Z_STRLEN_P(op_name) == name_length
            && memcmp(Z_STRVAL_P(op_name), name, name_length) == 0
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(input, input_id)) {
            if (matched == ordinal) {
                return entry;
            }
            matched++;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zval *king_inference_cuda_decoder_graph_executor_binary_op_for_inputs(
    zval *graph,
    zend_string *left_id,
    zend_string *right_id,
    const char *name
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *index = king_inference_array_find(graph, "_cuda_binary_op_index");
    zval *bucket;
    zval *op_index;
    zval *entry;
    zend_string *index_key;

    if (ops == NULL || left_id == NULL || right_id == NULL || name == NULL) {
        return NULL;
    }
    if (king_inference_cuda_decoder_graph_executor_op_index_ready(graph)
        && index != NULL
        && Z_TYPE_P(index) == IS_ARRAY) {
        king_inference_cuda_decoder_graph_executor_profile_increment(graph, "_cuda_profile_op_index_lookups");
        index_key = king_inference_cuda_decoder_graph_executor_binary_index_key(name, left_id, right_id);
        bucket = zend_hash_find(Z_ARRVAL_P(index), index_key);
        zend_string_release(index_key);
        if (bucket != NULL && Z_TYPE_P(bucket) == IS_ARRAY) {
            op_index = zend_hash_index_find(Z_ARRVAL_P(bucket), 0);
            if (op_index != NULL && Z_TYPE_P(op_index) == IS_LONG && Z_LVAL_P(op_index) >= 0) {
                entry = king_inference_cuda_decoder_graph_executor_indexed_op(
                    graph,
                    (zend_ulong) Z_LVAL_P(op_index)
                );
                if (entry != NULL && Z_TYPE_P(entry) == IS_ARRAY) {
                    king_inference_cuda_decoder_graph_executor_profile_increment(graph, "_cuda_profile_op_index_hits");
                    return entry;
                }
            }
        }
        return NULL;
    }

    king_inference_cuda_decoder_graph_executor_profile_increment(graph, "_cuda_profile_op_index_fallback_scans");
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *left;
        zval *right;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        left = king_inference_array_find(entry, "left");
        right = king_inference_array_find(entry, "right");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && ZSTR_LEN(Z_STR_P(op_name)) == strlen(name)
            && memcmp(ZSTR_VAL(Z_STR_P(op_name)), name, strlen(name)) == 0
            && ((king_inference_cuda_decoder_graph_executor_zval_string_equals(left, left_id)
                    && king_inference_cuda_decoder_graph_executor_zval_string_equals(right, right_id))
                || (king_inference_cuda_decoder_graph_executor_zval_string_equals(left, right_id)
                    && king_inference_cuda_decoder_graph_executor_zval_string_equals(right, left_id)))) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zval *king_inference_cuda_decoder_graph_executor_add_op_for_inputs(
    zval *graph,
    zend_string *left_id,
    zend_string *right_id
) {
    return king_inference_cuda_decoder_graph_executor_binary_op_for_inputs(graph, left_id, right_id, "add");
}

static zval *king_inference_cuda_decoder_graph_executor_mul_op_for_inputs(
    zval *graph,
    zend_string *left_id,
    zend_string *right_id
) {
    return king_inference_cuda_decoder_graph_executor_binary_op_for_inputs(graph, left_id, right_id, "mul");
}

static zend_result king_inference_cuda_decoder_graph_executor_linear_shape(
    king_inference_model_object *model,
    zval *op,
    zend_ulong input_width,
    zend_string **weight_name,
    zend_ulong *rows
) {
    zval *descriptor;
    zval *dimensions;
    zend_ulong rank;
    zend_ulong type;
    zend_ulong cols;
    zend_ulong tensor_rows;
    zend_ulong row_offset = king_inference_tensor_option_ulong(op, "row_offset", 0);
    zend_ulong row_limit;

    *weight_name = NULL;
    *rows = 0;
    if (king_inference_graph_required_string(op, "weight", weight_name) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_linear_weight_invalid");
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_tensor_lookup_count++;
    descriptor = king_inference_tensor_index_descriptor(model, *weight_name);
    dimensions = descriptor != NULL
        ? zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1)
        : NULL;
    if (descriptor == NULL || dimensions == NULL || Z_TYPE_P(dimensions) != IS_ARRAY) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_linear_quantized_shape_missing");
        return FAILURE;
    }
    if (!king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)
        || king_inference_tensor_dimension_at(dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(dimensions, 1, &tensor_rows) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_linear_descriptor_invalid");
        return FAILURE;
    }
    if (rank != 2
        || !king_inference_cuda_quantized_matvec_type_supported(type)
        || cols != input_width
        || tensor_rows == 0
        || tensor_rows > UINT_MAX) {
        char error[256];

        snprintf(
            error,
            sizeof(error),
            "cuda_decoder_graph_linear_quantized_shape_invalid weight=%s input_width=%lu cols=%lu rows=%lu rank=%lu type=%lu",
            weight_name != NULL && *weight_name != NULL ? ZSTR_VAL(*weight_name) : "",
            (unsigned long) input_width,
            (unsigned long) cols,
            (unsigned long) tensor_rows,
            (unsigned long) rank,
            (unsigned long) type
        );
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, error);
        return FAILURE;
    }

    if (row_offset != 0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_linear_row_offset_unsupported");
        return FAILURE;
    }

    row_limit = king_inference_tensor_option_ulong(op, "row_limit", tensor_rows);
    if (row_limit == 0 || row_limit > tensor_rows) {
        row_limit = tensor_rows;
    }
    *rows = row_limit;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_slice_shape(
    king_inference_model_object *model,
    zval *op,
    zend_ulong input_length,
    zend_ulong *offset,
    zend_ulong *length
) {
    zend_ulong slice_offset = king_inference_tensor_option_ulong(op, "offset", 0);
    zend_ulong slice_length = king_inference_tensor_option_ulong(op, "count", 0);

    if (slice_length == 0) {
        slice_length = input_length > slice_offset ? input_length - slice_offset : 0;
    }
    if (input_length == 0
        || slice_offset > input_length
        || slice_length == 0
        || slice_length > input_length - slice_offset
        || slice_offset > UINT_MAX
        || slice_length > UINT_MAX
        || slice_length > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_slice_shape_invalid");
        return FAILURE;
    }

    *offset = slice_offset;
    *length = slice_length;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_release_device_ptr(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr *device_ptr
) {
    if (device_ptr != NULL && *device_ptr != 0) {
        if (king_inference_cuda_device_allocator_free(model, *device_ptr) != SUCCESS) {
            return FAILURE;
        }
        *device_ptr = 0;
    }
    return SUCCESS;
}
