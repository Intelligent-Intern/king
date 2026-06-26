/*
 * CUDA decoder graph KV-attention execution helpers for native King GPU inference.
 */

static zval *king_inference_cuda_decoder_graph_executor_kv_attention_op_for_query(
    zval *graph,
    zend_string *query_id
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;

    if (ops == NULL || query_id == NULL) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *query;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        query = king_inference_array_find(entry, "query");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && zend_string_equals_literal(Z_STR_P(op_name), "kv_attention")
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(query, query_id)) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_cuda_decoder_graph_executor_kv_attention_shape(
    king_inference_model_object *model,
    zval *attention_op,
    zend_ulong query_length,
    zend_ulong *layer,
    zend_ulong *head,
    zend_ulong *slot_start,
    zend_ulong *slot_count,
    double *scale
) {
    zend_string *cache = NULL;
    unsigned long parsed_layer = 0;
    unsigned long parsed_head = 0;
    int consumed = 0;

    *layer = 0;
    *head = 0;
    *slot_start = 0;
    *slot_count = 0;
    *scale = 1.0;
    if (attention_op == NULL
        || king_inference_graph_required_string(attention_op, "cache", &cache) != SUCCESS
        || ZSTR_LEN(cache) > INT_MAX
        || sscanf(ZSTR_VAL(cache), "l%lu.h%lu%n", &parsed_layer, &parsed_head, &consumed) != 2
        || consumed != (int) ZSTR_LEN(cache)
        || king_inference_graph_finite_double_option(attention_op, "scale", 1.0, scale) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_attention_contract_invalid");
        return FAILURE;
    }

    *slot_start = king_inference_tensor_option_ulong(attention_op, "slot_start", 0);
    *slot_count = king_inference_tensor_option_ulong(attention_op, "slot_count", 0);
    if (!model->cuda_device_kv_cache_available
        || query_length == 0
        || query_length != model->cuda_device_kv_cache_key_length
        || *slot_count == 0
        || *slot_start >= model->cuda_device_kv_cache_context_tokens
        || *slot_count > model->cuda_device_kv_cache_context_tokens - *slot_start
        || (zend_ulong) parsed_layer >= model->cuda_device_kv_cache_layers
        || (zend_ulong) parsed_head >= model->cuda_device_kv_cache_kv_heads
        || !isfinite(*scale)) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_attention_shape_invalid");
        return FAILURE;
    }

    *layer = (zend_ulong) parsed_layer;
    *head = (zend_ulong) parsed_head;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_attention_alloc(
    king_inference_model_object *model,
    zend_ulong slot_count,
    zend_ulong value_length,
    king_inference_cuda_device_ptr *scores_output,
    king_inference_cuda_device_ptr *probabilities_output,
    king_inference_cuda_device_ptr *context_output
) {
    *scores_output = 0;
    *probabilities_output = 0;
    *context_output = 0;
    if (slot_count > (zend_ulong) (SIZE_MAX / sizeof(float))
        || value_length == 0
        || value_length > (zend_ulong) (SIZE_MAX / sizeof(float))
        || king_inference_cuda_device_allocator_alloc(model, (size_t) slot_count * sizeof(float), scores_output) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, (size_t) slot_count * sizeof(float), probabilities_output) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, (size_t) value_length * sizeof(float), context_output) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, context_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, probabilities_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, scores_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_attention_allocation_failed"
        );
        return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_kv_attention(
    king_inference_model_object *model,
    zval *attention_op,
    king_inference_cuda_device_ptr query_output,
    zend_ulong query_length,
    zval *attention_probe,
    zend_ulong *executed_device_ops
) {
    king_inference_cuda_device_ptr key_cache = 0;
    king_inference_cuda_device_ptr value_cache = 0;
    king_inference_cuda_device_ptr scores_output = 0;
    king_inference_cuda_device_ptr probabilities_output = 0;
    king_inference_cuda_device_ptr context_output = 0;
    zend_ulong key_stride = 0;
    zend_ulong value_stride = 0;
    zend_ulong layer = 0;
    zend_ulong head = 0;
    zend_ulong slot_start = 0;
    zend_ulong slot_count = 0;
    zend_ulong value_length = model->cuda_device_kv_cache_value_length;
    double scale = 1.0;

    add_assoc_bool(attention_probe, "attempted", attention_op != NULL);
    add_assoc_bool(attention_probe, "available", model->cuda_decoder_graph_executor_kv_attention_execution_available);
    add_assoc_bool(attention_probe, "executed", false);
    if (attention_op == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_attention_missing");
        return FAILURE;
    }
    if (!model->cuda_decoder_graph_executor_kv_attention_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_attention_execution_unavailable");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_kv_attention_shape(
            model,
            attention_op,
            query_length,
            &layer,
            &head,
            &slot_start,
            &slot_count,
            &scale
    ) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_device_kv_cache_head(model, false, layer, head, &key_cache, &key_stride) != SUCCESS
        || king_inference_cuda_device_kv_cache_head(model, true, layer, head, &value_cache, &value_stride) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_kv_cache_result,
            model->cuda_device_kv_cache_error[0] != '\0'
                ? model->cuda_device_kv_cache_error
                : "cuda_decoder_graph_kv_attention_cache_unavailable"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_attention_alloc(
            model,
            slot_count,
            value_length,
            &scores_output,
            &probabilities_output,
            &context_output
    ) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_attention_scores_f32(
            model,
            query_output,
            key_cache,
            scores_output,
            query_length,
            key_stride,
            slot_start,
            slot_count,
            scale
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &context_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_attention_scores_result,
            model->cuda_attention_scores_error[0] != '\0'
                ? model->cuda_attention_scores_error
                : "cuda_decoder_graph_kv_attention_scores_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_attention_softmax_f32(
            model,
            scores_output,
            0,
            probabilities_output,
            0,
            slot_count,
            1.0,
            1.0
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &context_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_attention_softmax_result,
            model->cuda_attention_softmax_error[0] != '\0'
                ? model->cuda_attention_softmax_error
                : "cuda_decoder_graph_kv_attention_softmax_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_attention_values_f32(
            model,
            probabilities_output,
            value_cache,
            context_output,
            value_length,
            value_stride,
            slot_start,
            slot_count
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &context_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_attention_values_result,
            model->cuda_attention_values_error[0] != '\0'
                ? model->cuda_attention_values_error
                : "cuda_decoder_graph_kv_attention_values_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_kv_attention_execution_count++;
    model->cuda_decoder_graph_executor_last_kv_attention_key_length = query_length;
    model->cuda_decoder_graph_executor_last_kv_attention_value_length = value_length;
    model->cuda_decoder_graph_executor_last_kv_attention_layer = layer;
    model->cuda_decoder_graph_executor_last_kv_attention_head = head;
    model->cuda_decoder_graph_executor_last_kv_attention_slot_start = slot_start;
    model->cuda_decoder_graph_executor_last_kv_attention_slot_count = slot_count;
    (*executed_device_ops)++;

    add_assoc_bool(attention_probe, "executed", true);
    add_assoc_long(attention_probe, "layer", (zend_long) layer);
    add_assoc_long(attention_probe, "head", (zend_long) head);
    add_assoc_long(attention_probe, "slot_start", (zend_long) slot_start);
    add_assoc_long(attention_probe, "slot_count", (zend_long) slot_count);
    add_assoc_long(attention_probe, "key_length", (zend_long) query_length);
    add_assoc_long(attention_probe, "value_length", (zend_long) value_length);
    add_assoc_double(attention_probe, "scale", scale);

    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &context_output) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &probabilities_output) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &scores_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_attention_free_failed"
        );
        return FAILURE;
    }
    return SUCCESS;
}
