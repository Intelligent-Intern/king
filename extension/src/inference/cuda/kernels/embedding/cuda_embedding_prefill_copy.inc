/*
 * CUDA prefilled embedding row copy bridge for decoder prompt prefill.
 */

static zend_result king_inference_cuda_embedding_prefill_copy_row(
    king_inference_model_object *model,
    zend_ulong row,
    king_inference_cuda_device_ptr output_vector,
    zend_ulong output_width
) {
    king_inference_cuda_device_kv_cache_memcpy_dtod_fn cu_memcpy_dtod;
    static king_inference_cuda_device_kv_cache_memcpy_dtod_fn cached_cu_memcpy_dtod = NULL;
    king_inference_cuda_device_ptr source;
    size_t offset;
    size_t bytes;
    int result;

    if (!model->cuda_decoder_prompt_prefill_embeddings_active
        || model->cuda_decoder_prompt_prefill_embeddings == 0
        || row >= model->cuda_decoder_prompt_prefill_tokens
        || output_vector == 0
        || output_width == 0
        || output_width != model->cuda_decoder_prompt_prefill_width
        || output_width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_prefill_copy_argument_invalid");
        return FAILURE;
    }
    bytes = (size_t) output_width * sizeof(float);
    if (row > (zend_ulong) (SIZE_MAX / bytes)) {
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_prefill_copy_offset_invalid");
        return FAILURE;
    }
    offset = (size_t) row * bytes;
    source = model->cuda_decoder_prompt_prefill_embeddings + (king_inference_cuda_device_ptr) offset;
    if (cached_cu_memcpy_dtod == NULL
        && !king_inference_cuda_device_kv_cache_symbol(
            model,
            "cuMemcpyDtoD_v2",
            "cuMemcpyDtoD",
            (void **) &cached_cu_memcpy_dtod
    )) {
        king_inference_cuda_embedding_row_set_error(
            model,
            model->cuda_device_kv_cache_result,
            model->cuda_device_kv_cache_error[0] != '\0'
                ? model->cuda_device_kv_cache_error
                : "cuda_embedding_prefill_copy_symbol_unavailable"
        );
        return FAILURE;
    }
    cu_memcpy_dtod = cached_cu_memcpy_dtod;
    result = cu_memcpy_dtod(output_vector, source, bytes);
    if (result != 0) {
        king_inference_cuda_embedding_row_set_error(model, result, "cuMemcpyDtoD_failed");
        return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_prefill_copy_matrix_row(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr matrix,
    zend_ulong row,
    zend_ulong rows,
    zend_ulong width,
    king_inference_cuda_device_ptr output_vector,
    const char *error_prefix
) {
    king_inference_cuda_device_kv_cache_memcpy_dtod_fn cu_memcpy_dtod;
    static king_inference_cuda_device_kv_cache_memcpy_dtod_fn cached_cu_memcpy_dtod = NULL;
    king_inference_cuda_device_ptr source;
    size_t offset;
    size_t bytes;
    int result;

    if (matrix == 0 || output_vector == 0 || row >= rows || width == 0
        || width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_embedding_row_set_error(model, -1, error_prefix);
        return FAILURE;
    }
    bytes = (size_t) width * sizeof(float);
    if (row > (zend_ulong) (SIZE_MAX / bytes)) {
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_prefill_copy_offset_invalid");
        return FAILURE;
    }
    offset = (size_t) row * bytes;
    source = matrix + (king_inference_cuda_device_ptr) offset;
    if (cached_cu_memcpy_dtod == NULL
        && !king_inference_cuda_device_kv_cache_symbol(
            model,
            "cuMemcpyDtoD_v2",
            "cuMemcpyDtoD",
            (void **) &cached_cu_memcpy_dtod
    )) {
        king_inference_cuda_embedding_row_set_error(
            model,
            model->cuda_device_kv_cache_result,
            model->cuda_device_kv_cache_error[0] != '\0'
                ? model->cuda_device_kv_cache_error
                : "cuda_prefill_copy_symbol_unavailable"
        );
        return FAILURE;
    }
    cu_memcpy_dtod = cached_cu_memcpy_dtod;
    result = cu_memcpy_dtod(output_vector, source, bytes);
    if (result != 0) {
        king_inference_cuda_embedding_row_set_error(model, result, "cuda_prefill_copy_failed");
        return FAILURE;
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_prefill_copy_initial_norm_row(
    king_inference_model_object *model,
    zend_ulong row,
    king_inference_cuda_device_ptr output_vector,
    zend_ulong output_width
) {
    if (!model->cuda_decoder_prompt_prefill_embeddings_active
        || output_width != model->cuda_decoder_prompt_prefill_width) {
        king_inference_cuda_embedding_row_set_error(
            model,
            -1,
            "cuda_prefill_initial_norm_copy_argument_invalid"
        );
        return FAILURE;
    }
    return king_inference_cuda_prefill_copy_matrix_row(
        model,
        model->cuda_decoder_prompt_prefill_initial_norm,
        row,
        model->cuda_decoder_prompt_prefill_tokens,
        output_width,
        output_vector,
        "cuda_prefill_initial_norm_copy_argument_invalid"
    );
}

static zend_result king_inference_cuda_prefill_copy_initial_linear_row(
    king_inference_model_object *model,
    zend_ulong row,
    king_inference_cuda_device_ptr output_vector,
    zend_ulong output_rows
) {
    if (!model->cuda_decoder_prompt_prefill_embeddings_active
        || output_rows != model->cuda_decoder_prompt_prefill_initial_linear_rows) {
        king_inference_cuda_embedding_row_set_error(
            model,
            -1,
            "cuda_prefill_initial_linear_copy_argument_invalid"
        );
        return FAILURE;
    }
    return king_inference_cuda_prefill_copy_matrix_row(
        model,
        model->cuda_decoder_prompt_prefill_initial_linear,
        row,
        model->cuda_decoder_prompt_prefill_tokens,
        output_rows,
        output_vector,
        "cuda_prefill_initial_linear_copy_argument_invalid"
    );
}
