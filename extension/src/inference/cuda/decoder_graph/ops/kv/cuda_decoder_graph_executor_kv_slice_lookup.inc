/*
 * CUDA decoder graph K/V slice lookup helpers.
 */

static bool king_inference_cuda_decoder_graph_executor_query_slice_identity(
    zend_string *query_slice_id,
    zend_ulong *layer,
    zend_ulong *head
) {
    unsigned long parsed_layer = 0;
    unsigned long parsed_head = 0;
    int consumed = 0;

    *layer = 0;
    *head = 0;
    if (query_slice_id == NULL
        || ZSTR_LEN(query_slice_id) > INT_MAX
        || sscanf(ZSTR_VAL(query_slice_id), "l%lu_h%lu_q_slice%n", &parsed_layer, &parsed_head, &consumed) != 2
        || consumed != (int) ZSTR_LEN(query_slice_id)) {
        return false;
    }

    *layer = (zend_ulong) parsed_layer;
    *head = (zend_ulong) parsed_head;
    return true;
}

static zval *king_inference_cuda_decoder_graph_executor_kv_slice_op_for_query(
    zval *graph,
    zend_string *linear_id,
    zend_string *query_slice_id,
    const char *kind
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    zend_ulong layer = 0;
    zend_ulong head = 0;
    zend_string *expected_id;

    if (ops == NULL
        || linear_id == NULL
        || query_slice_id == NULL
        || kind == NULL
        || !king_inference_cuda_decoder_graph_executor_query_slice_identity(query_slice_id, &layer, &head)) {
        return NULL;
    }

    expected_id = strpprintf(0, "l%lu_h%lu_%s_slice", (unsigned long) layer, (unsigned long) head, kind);
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *op_id;
        zval *input;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        op_id = king_inference_array_find(entry, "id");
        input = king_inference_array_find(entry, "input");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && zend_string_equals_literal(Z_STR_P(op_name), "slice")
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(op_id, expected_id)
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(input, linear_id)) {
            zend_string_release(expected_id);
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    zend_string_release(expected_id);
    return NULL;
}
