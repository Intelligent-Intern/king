/*
 * CUDA decoder graph K/V write helpers for native King GPU inference.
 */

static bool king_inference_cuda_decoder_graph_executor_query_slice_head(
    zend_string *query_slice_id,
    zend_ulong *layer,
    zend_ulong *query_head
) {
    unsigned long parsed_layer = 0;
    unsigned long parsed_head = 0;
    int consumed = 0;

    *layer = 0;
    *query_head = 0;
    return query_slice_id != NULL
        && ZSTR_LEN(query_slice_id) <= INT_MAX
        && sscanf(ZSTR_VAL(query_slice_id), "l%lu_h%lu_q_slice%n", &parsed_layer, &parsed_head, &consumed) == 2
        && consumed == (int) ZSTR_LEN(query_slice_id)
        && ((*layer = (zend_ulong) parsed_layer), (*query_head = (zend_ulong) parsed_head), true);
}

static zend_result king_inference_cuda_decoder_graph_executor_kv_write_shape(
    king_inference_model_object *model,
    zval *kv_write_op,
    zend_ulong key_length,
    zend_ulong value_length,
    zend_ulong *layer,
    zend_ulong *head,
    zend_ulong *position
) {
    zend_string *cache = NULL;
    zval *slot;
    unsigned long parsed_layer = 0;
    unsigned long parsed_head = 0;
    int consumed = 0;

    *layer = 0;
    *head = 0;
    *position = 0;
    if (kv_write_op == NULL
        || king_inference_graph_required_string(kv_write_op, "cache", &cache) != SUCCESS
        || ZSTR_LEN(cache) > INT_MAX
        || sscanf(ZSTR_VAL(cache), "l%lu.h%lu%n", &parsed_layer, &parsed_head, &consumed) != 2
        || consumed != (int) ZSTR_LEN(cache)) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_write_cache_invalid");
        return FAILURE;
    }

    slot = king_inference_array_find(kv_write_op, "slot");
    if (slot == NULL || Z_TYPE_P(slot) != IS_LONG || Z_LVAL_P(slot) < 0) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_write_slot_invalid");
        return FAILURE;
    }
    if (!model->cuda_device_kv_cache_available
        || key_length == 0
        || value_length == 0
        || key_length != model->cuda_device_kv_cache_key_length
        || value_length != model->cuda_device_kv_cache_value_length
        || (zend_ulong) parsed_layer >= model->cuda_device_kv_cache_layers
        || (zend_ulong) parsed_head >= model->cuda_device_kv_cache_kv_heads
        || (zend_ulong) Z_LVAL_P(slot) >= model->cuda_device_kv_cache_context_tokens) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_write_shape_invalid");
        return FAILURE;
    }

    *layer = (zend_ulong) parsed_layer;
    *head = (zend_ulong) parsed_head;
    *position = (zend_ulong) Z_LVAL_P(slot);
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_kv_write_offsets(
    king_inference_model_object *model,
    zend_ulong layer,
    zend_ulong head,
    zend_ulong position,
    size_t *slot,
    size_t *key_offset_bytes,
    size_t *value_offset_bytes
) {
    size_t computed_slot;
    size_t key_offset;
    size_t value_offset;

    *slot = 0;
    *key_offset_bytes = 0;
    *value_offset_bytes = 0;
    if (!king_inference_cuda_device_kv_cache_mul_size((size_t) layer, (size_t) model->cuda_device_kv_cache_kv_heads, &computed_slot)
        || !king_inference_cuda_device_kv_cache_add_size(computed_slot, (size_t) head, &computed_slot)
        || !king_inference_cuda_device_kv_cache_mul_size(computed_slot, (size_t) model->cuda_device_kv_cache_context_tokens, &computed_slot)
        || !king_inference_cuda_device_kv_cache_add_size(computed_slot, (size_t) position, &computed_slot)
        || !king_inference_cuda_device_kv_cache_mul_size(computed_slot, (size_t) model->cuda_device_kv_cache_key_length, &key_offset)
        || !king_inference_cuda_device_kv_cache_mul_size(key_offset, sizeof(float), key_offset_bytes)
        || !king_inference_cuda_device_kv_cache_mul_size(computed_slot, (size_t) model->cuda_device_kv_cache_value_length, &value_offset)
        || !king_inference_cuda_device_kv_cache_mul_size(value_offset, sizeof(float), value_offset_bytes)) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_write_offset_overflow");
        return FAILURE;
    }

    *slot = computed_slot;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_kv_write(
    king_inference_model_object *model,
    zval *kv_write_op,
    zend_string *query_slice_id,
    king_inference_cuda_device_ptr key_output,
    zend_ulong key_length,
    king_inference_cuda_device_ptr value_output,
    zend_ulong value_length,
    zval *kv_write_probe,
    zend_ulong *executed_device_ops
) {
    zend_ulong layer = 0;
    zend_ulong head = 0;
    zend_ulong position = 0;
    zend_ulong query_layer = 0;
    zend_ulong query_head = 0;
    size_t cache_slot = 0;
    size_t key_offset_bytes = 0;
    size_t value_offset_bytes = 0;
    bool query_head_available;

    add_assoc_bool(kv_write_probe, "attempted", kv_write_op != NULL);
    add_assoc_bool(kv_write_probe, "available", model->cuda_decoder_graph_executor_kv_write_execution_available);
    add_assoc_bool(kv_write_probe, "executed", false);
    if (kv_write_op == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_write_missing");
        return FAILURE;
    }
    if (!model->cuda_decoder_graph_executor_kv_write_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_write_execution_unavailable");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_kv_write_shape(
            model,
            kv_write_op,
            key_length,
            value_length,
            &layer,
            &head,
            &position
    ) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_kv_write_offsets(
            model,
            layer,
            head,
            position,
            &cache_slot,
            &key_offset_bytes,
            &value_offset_bytes
    ) != SUCCESS) {
        return FAILURE;
    }
    query_head_available = king_inference_cuda_decoder_graph_executor_query_slice_head(
        query_slice_id,
        &query_layer,
        &query_head
    );
    if (king_inference_cuda_device_kv_cache_write(
            model,
            layer,
            head,
            position,
            key_output,
            key_length,
            value_output,
            value_length
    ) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_kv_cache_result,
            model->cuda_device_kv_cache_error[0] != '\0'
                ? model->cuda_device_kv_cache_error
                : "cuda_decoder_graph_kv_write_execution_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_kv_write_execution_count++;
    model->cuda_decoder_graph_executor_last_kv_write_key_length = key_length;
    model->cuda_decoder_graph_executor_last_kv_write_value_length = value_length;
    model->cuda_decoder_graph_executor_last_kv_write_layer = layer;
    model->cuda_decoder_graph_executor_last_kv_write_head = head;
    model->cuda_decoder_graph_executor_last_kv_write_position = position;
    (*executed_device_ops)++;

    add_assoc_bool(kv_write_probe, "executed", true);
    add_assoc_long(kv_write_probe, "layer", (zend_long) layer);
    add_assoc_long(kv_write_probe, "head", (zend_long) head);
    add_assoc_long(kv_write_probe, "kv_head", (zend_long) head);
    add_assoc_bool(kv_write_probe, "query_head_available", query_head_available);
    if (query_head_available) {
        add_assoc_long(kv_write_probe, "query_layer", (zend_long) query_layer);
        add_assoc_long(kv_write_probe, "query_head", (zend_long) query_head);
    } else {
        add_assoc_null(kv_write_probe, "query_layer");
        add_assoc_null(kv_write_probe, "query_head");
    }
    add_assoc_long(kv_write_probe, "position", (zend_long) position);
    add_assoc_long(kv_write_probe, "key_length", (zend_long) key_length);
    add_assoc_long(kv_write_probe, "value_length", (zend_long) value_length);
    add_assoc_long(kv_write_probe, "cache_key_stride", (zend_long) model->cuda_device_kv_cache_key_length);
    add_assoc_long(kv_write_probe, "cache_value_stride", (zend_long) model->cuda_device_kv_cache_value_length);
    add_assoc_long(kv_write_probe, "cache_slot_index", (zend_long) cache_slot);
    add_assoc_long(kv_write_probe, "key_offset_bytes", (zend_long) key_offset_bytes);
    add_assoc_long(kv_write_probe, "value_offset_bytes", (zend_long) value_offset_bytes);
    add_assoc_long(kv_write_probe, "key_write_bytes", (zend_long) ((size_t) key_length * sizeof(float)));
    add_assoc_long(kv_write_probe, "value_write_bytes", (zend_long) ((size_t) value_length * sizeof(float)));
    return SUCCESS;
}
