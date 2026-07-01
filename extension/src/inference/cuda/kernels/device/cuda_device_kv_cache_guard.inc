/*
 * Fail-closed guard for CUDA device KV-cache access.
 */

#ifndef KING_INFERENCE_DEFAULT_KV_PAGE_TOKENS
#define KING_INFERENCE_DEFAULT_KV_PAGE_TOKENS 16
#endif

static zend_ulong king_inference_cuda_device_kv_cache_guard_page_tokens(
    king_inference_model_object *model
) {
    return king_inference_cuda_device_kv_cache_plan_long(
        model,
        "page_tokens",
        KING_INFERENCE_DEFAULT_KV_PAGE_TOKENS
    );
}

static void king_inference_cuda_device_kv_cache_guard_record(
    king_inference_model_object *model,
    const char *operation,
    const char *failure,
    zend_ulong layer,
    zend_ulong head,
    zend_ulong position,
    zend_ulong count
) {
    zend_ulong page_tokens;
    zend_ulong last_position;

    model->cuda_device_kv_cache_guard_check_count++;
    model->cuda_device_kv_cache_guard_fail_closed = true;
    model->cuda_device_kv_cache_guard_last_layer = layer;
    model->cuda_device_kv_cache_guard_last_head = head;
    model->cuda_device_kv_cache_guard_last_position = position;
    model->cuda_device_kv_cache_guard_last_count = count;
    snprintf(
        model->cuda_device_kv_cache_guard_last_operation,
        sizeof(model->cuda_device_kv_cache_guard_last_operation),
        "%s",
        operation != NULL && operation[0] != '\0' ? operation : "unknown"
    );

    page_tokens = king_inference_cuda_device_kv_cache_guard_page_tokens(model);
    if (count > 0
        && page_tokens > 0
        && position <= (zend_ulong) -1 - (count - 1)) {
        last_position = position + count - 1;
        model->cuda_device_kv_cache_guard_last_page_start = position / page_tokens;
        model->cuda_device_kv_cache_guard_last_page_end = last_position / page_tokens;
        model->cuda_device_kv_cache_guard_last_page_crossed =
            model->cuda_device_kv_cache_guard_last_page_start != model->cuda_device_kv_cache_guard_last_page_end;
    } else {
        model->cuda_device_kv_cache_guard_last_page_start = 0;
        model->cuda_device_kv_cache_guard_last_page_end = 0;
        model->cuda_device_kv_cache_guard_last_page_crossed = false;
    }

    if (failure != NULL && failure[0] != '\0') {
        model->cuda_device_kv_cache_guard_failure_count++;
        snprintf(
            model->cuda_device_kv_cache_guard_last_failure,
            sizeof(model->cuda_device_kv_cache_guard_last_failure),
            "%s",
            failure
        );
        king_inference_cuda_device_kv_cache_set_error(model, -1, failure);
    }
}

static zend_result king_inference_cuda_device_kv_cache_guard_range(
    king_inference_model_object *model,
    const char *operation,
    zend_ulong layer,
    zend_ulong head,
    zend_ulong position_start,
    zend_ulong count,
    bool require_allocated
) {
    zend_ulong page_tokens = king_inference_cuda_device_kv_cache_guard_page_tokens(model);
    const char *failure = NULL;

    if (!model->cuda_device_kv_cache_available || !model->cuda_device_kv_cache_shape_ready) {
        failure = "cuda_device_kv_cache_guard_unavailable";
    } else if (require_allocated && !model->cuda_device_kv_cache_allocated) {
        failure = "cuda_device_kv_cache_guard_not_allocated";
    } else if (model->cuda_device_kv_cache_layers == 0
        || model->cuda_device_kv_cache_kv_heads == 0
        || model->cuda_device_kv_cache_context_tokens == 0
        || model->cuda_device_kv_cache_key_length == 0
        || model->cuda_device_kv_cache_value_length == 0) {
        failure = "cuda_device_kv_cache_guard_shape_invalid";
    } else if (count == 0) {
        failure = "cuda_device_kv_cache_guard_empty_range";
    } else if (layer >= model->cuda_device_kv_cache_layers) {
        failure = "cuda_device_kv_cache_guard_layer_out_of_range";
    } else if (head >= model->cuda_device_kv_cache_kv_heads) {
        failure = "cuda_device_kv_cache_guard_head_out_of_range";
    } else if (position_start >= model->cuda_device_kv_cache_context_tokens
        || count > model->cuda_device_kv_cache_context_tokens - position_start) {
        failure = "cuda_device_kv_cache_guard_context_overflow";
    }

    king_inference_cuda_device_kv_cache_guard_record(
        model,
        operation,
        failure,
        layer,
        head,
        position_start,
        count
    );
    return failure == NULL ? SUCCESS : FAILURE;
}

static zend_result king_inference_cuda_device_kv_cache_guard_slot(
    king_inference_model_object *model,
    const char *operation,
    zend_ulong layer,
    zend_ulong head,
    zend_ulong position,
    bool require_allocated
) {
    return king_inference_cuda_device_kv_cache_guard_range(
        model,
        operation,
        layer,
        head,
        position,
        1,
        require_allocated
    );
}

static zend_result king_inference_cuda_device_kv_cache_guard_vectors(
    king_inference_model_object *model,
    const char *operation,
    king_inference_cuda_device_ptr key_vector,
    zend_ulong key_length,
    king_inference_cuda_device_ptr value_vector,
    zend_ulong value_length
) {
    const char *failure = NULL;

    if (key_vector == 0 || value_vector == 0) {
        failure = "cuda_device_kv_cache_guard_vector_invalid";
    } else if (key_length == 0 || value_length == 0) {
        failure = "cuda_device_kv_cache_guard_vector_empty";
    } else if (key_length > model->cuda_device_kv_cache_key_length
        || value_length > model->cuda_device_kv_cache_value_length) {
        failure = "cuda_device_kv_cache_guard_vector_shape_invalid";
    } else if (key_length > (zend_ulong) (SIZE_MAX / sizeof(float))
        || value_length > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        failure = "cuda_device_kv_cache_guard_vector_bytes_overflow";
    }

    if (failure == NULL) {
        return SUCCESS;
    }
    king_inference_cuda_device_kv_cache_guard_record(model, operation, failure, 0, 0, 0, 0);
    return FAILURE;
}

static zend_result king_inference_cuda_device_kv_cache_guard_batch_source(
    king_inference_model_object *model,
    const char *operation,
    zend_ulong count,
    zend_ulong key_row_stride,
    zend_ulong key_offset,
    zend_ulong key_length,
    zend_ulong value_row_stride,
    zend_ulong value_offset,
    zend_ulong value_length
) {
    size_t key_units;
    size_t value_units;
    zend_ulong last_index = count > 0 ? count - 1 : 0;
    const char *failure = NULL;

    if (count == 0) {
        failure = "cuda_device_kv_cache_guard_batch_empty";
    } else if (key_length == 0 || value_length == 0 || key_row_stride == 0 || value_row_stride == 0) {
        failure = "cuda_device_kv_cache_guard_batch_vector_empty";
    } else if (key_offset > key_row_stride
        || value_offset > value_row_stride
        || key_length > key_row_stride - key_offset
        || value_length > value_row_stride - value_offset) {
        failure = "cuda_device_kv_cache_guard_batch_stride_invalid";
    } else if (key_length > model->cuda_device_kv_cache_key_length
        || value_length > model->cuda_device_kv_cache_value_length) {
        failure = "cuda_device_kv_cache_guard_batch_vector_shape_invalid";
    } else if (!king_inference_cuda_device_kv_cache_mul_size((size_t) last_index, (size_t) key_row_stride, &key_units)
        || !king_inference_cuda_device_kv_cache_add_size(key_units, (size_t) key_offset, &key_units)
        || !king_inference_cuda_device_kv_cache_add_size(key_units, (size_t) key_length, &key_units)
        || !king_inference_cuda_device_kv_cache_mul_size((size_t) last_index, (size_t) value_row_stride, &value_units)
        || !king_inference_cuda_device_kv_cache_add_size(value_units, (size_t) value_offset, &value_units)
        || !king_inference_cuda_device_kv_cache_add_size(value_units, (size_t) value_length, &value_units)) {
        failure = "cuda_device_kv_cache_guard_batch_source_overflow";
    }

    if (failure == NULL) {
        return SUCCESS;
    }
    king_inference_cuda_device_kv_cache_guard_record(model, operation, failure, 0, 0, 0, count);
    return FAILURE;
}
