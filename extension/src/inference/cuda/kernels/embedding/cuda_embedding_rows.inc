/*
 * CUDA batched token embedding row loader for native King GPU inference.
 */

static zend_result king_inference_cuda_embedding_rows_descriptor(
    king_inference_model_object *model,
    zend_string **tensor_out,
    king_inference_cuda_weight_upload **upload_out,
    zend_ulong *width_out,
    zend_ulong *rows_out,
    zend_ulong *type_out
) {
    zend_string *tensor = NULL;
    const char *status = NULL;
    zval *descriptor;
    zval *dimensions;
    zend_ulong rank;

    *tensor_out = NULL;
    *upload_out = NULL;
    *width_out = 0;
    *rows_out = 0;
    *type_out = 0;
    if (king_inference_resolve_token_embedding_tensor_name(model, NULL, &tensor, NULL, &status) != SUCCESS
        || tensor == NULL) {
        king_inference_cuda_embedding_row_set_error(
            model,
            -1,
            status != NULL ? status : "cuda_embedding_rows_tensor_not_resolved"
        );
        return FAILURE;
    }

    descriptor = king_inference_tensor_index_descriptor(model, tensor);
    dimensions = descriptor != NULL
        ? zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1)
        : NULL;
    if (descriptor == NULL
        || dimensions == NULL
        || Z_TYPE_P(dimensions) != IS_ARRAY
        || !king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, type_out)
        || rank != 2
        || !king_inference_cuda_embedding_row_supported_type(*type_out)
        || king_inference_tensor_dimension_at(dimensions, 0, width_out) != SUCCESS
        || king_inference_tensor_dimension_at(dimensions, 1, rows_out) != SUCCESS
        || *width_out == 0
        || *width_out > UINT_MAX
        || *rows_out == 0
        || *rows_out > UINT_MAX
        || (*type_out == 8 && (*width_out % 32) != 0)
        || (*type_out == 14 && (*width_out % 256) != 0)) {
        zend_string_release(tensor);
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_rows_tensor_descriptor_invalid");
        return FAILURE;
    }

    *upload_out = king_inference_cuda_weight_cache_find(model, tensor, true);
    if (*upload_out == NULL || (*upload_out)->device_ptr == 0) {
        zend_string_release(tensor);
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_rows_weight_not_uploaded");
        return FAILURE;
    }
    *tensor_out = tensor;
    return SUCCESS;
}

static zend_result king_inference_cuda_embedding_rows_load(
    king_inference_model_object *model,
    zval *tokenized_prompt,
    zend_ulong batch_tokens,
    king_inference_cuda_device_ptr output_matrix,
    zend_ulong output_width,
    double output_scale
) {
    king_inference_cuda_weight_upload *upload;
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    king_inference_cuda_upload_cu_memcpy_htod_fn cu_memcpy_htod;
    static king_inference_cuda_launch_kernel_fn cached_cu_launch_kernel = NULL;
    static king_inference_cuda_upload_cu_memcpy_htod_fn cached_cu_memcpy_htod = NULL;
    zend_string *tensor = NULL;
    zval *tokens;
    zval *token;
    zend_ulong width;
    zend_ulong rows;
    zend_ulong type;
    zend_ulong index = 0;
    unsigned int *host_token_ids;
    king_inference_cuda_device_ptr device_token_ids = 0;
    size_t token_bytes;
    unsigned int width32;
    unsigned int rows32;
    unsigned int batch32;
    unsigned int type32;
    float scale32;
    unsigned int block_x = 256;
    unsigned int grid_x;
    void *params[8];
    int result;

    if (!model->cuda_embedding_rows_available || model->cuda_embedding_rows_function == NULL) {
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_rows_loader_unavailable");
        return FAILURE;
    }
    if (tokenized_prompt == NULL
        || Z_TYPE_P(tokenized_prompt) != IS_ARRAY
        || batch_tokens == 0
        || batch_tokens > UINT_MAX
        || output_matrix == 0
        || output_width == 0
        || output_width > UINT_MAX) {
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_rows_argument_invalid");
        return FAILURE;
    }
    tokens = king_inference_array_find(tokenized_prompt, "tokens");
    if (tokens == NULL || Z_TYPE_P(tokens) != IS_ARRAY
        || zend_hash_num_elements(Z_ARRVAL_P(tokens)) < batch_tokens) {
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_rows_tokens_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_embedding_rows_descriptor(
            model,
            &tensor,
            &upload,
            &width,
            &rows,
            &type
    ) != SUCCESS) {
        return FAILURE;
    }
    if (width != output_width || batch_tokens > (zend_ulong) (SIZE_MAX / sizeof(unsigned int))) {
        zend_string_release(tensor);
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_rows_shape_invalid");
        return FAILURE;
    }

    token_bytes = (size_t) batch_tokens * sizeof(unsigned int);
    host_token_ids = emalloc(token_bytes);
    for (index = 0; index < batch_tokens; index++) {
        token = zend_hash_index_find(Z_ARRVAL_P(tokens), index);
        if (token == NULL
            || Z_TYPE_P(token) != IS_LONG
            || Z_LVAL_P(token) < 0
            || (zend_ulong) Z_LVAL_P(token) >= rows
            || (zend_ulong) Z_LVAL_P(token) > UINT_MAX) {
            efree(host_token_ids);
            zend_string_release(tensor);
            king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_rows_token_invalid");
            return FAILURE;
        }
        host_token_ids[index] = (unsigned int) Z_LVAL_P(token);
    }

    if (king_inference_cuda_device_allocator_alloc(model, token_bytes, &device_token_ids) != SUCCESS) {
        efree(host_token_ids);
        zend_string_release(tensor);
        king_inference_cuda_embedding_row_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_embedding_rows_token_id_allocation_failed"
        );
        return FAILURE;
    }
    if (cached_cu_memcpy_htod == NULL
        && !king_inference_cuda_embedding_row_driver_symbol(model, "cuMemcpyHtoD_v2", (void **) &cached_cu_memcpy_htod)) {
        (void) king_inference_cuda_device_allocator_free(model, device_token_ids);
        efree(host_token_ids);
        zend_string_release(tensor);
        return FAILURE;
    }
    cu_memcpy_htod = cached_cu_memcpy_htod;
    result = cu_memcpy_htod(device_token_ids, host_token_ids, token_bytes);
    efree(host_token_ids);
    if (result != 0) {
        (void) king_inference_cuda_device_allocator_free(model, device_token_ids);
        zend_string_release(tensor);
        king_inference_cuda_embedding_row_set_error(model, result, "cuMemcpyHtoD_failed");
        return FAILURE;
    }
    if (cached_cu_launch_kernel == NULL
        && !king_inference_cuda_embedding_row_driver_symbol(model, "cuLaunchKernel", (void **) &cached_cu_launch_kernel)) {
        (void) king_inference_cuda_device_allocator_free(model, device_token_ids);
        zend_string_release(tensor);
        return FAILURE;
    }
    cu_launch_kernel = cached_cu_launch_kernel;

    width32 = (unsigned int) width;
    rows32 = (unsigned int) rows;
    batch32 = (unsigned int) batch_tokens;
    type32 = (unsigned int) type;
    scale32 = (float) output_scale;
    grid_x = (width32 + block_x - 1) / block_x;
    params[0] = &upload->device_ptr;
    params[1] = &device_token_ids;
    params[2] = &output_matrix;
    params[3] = &width32;
    params[4] = &rows32;
    params[5] = &batch32;
    params[6] = &type32;
    params[7] = &scale32;

    result = cu_launch_kernel(
        model->cuda_embedding_rows_function,
        grid_x,
        batch32,
        1,
        block_x,
        1,
        1,
        0,
        NULL,
        params,
        NULL
    );
    (void) king_inference_cuda_device_allocator_free(model, device_token_ids);
    zend_string_release(tensor);
    if (result != 0) {
        king_inference_cuda_embedding_row_set_error(model, result, "cuLaunchKernel_failed");
        return FAILURE;
    }

    model->cuda_embedding_rows_launch_count++;
    model->cuda_embedding_rows_last_batch_tokens = batch_tokens;
    model->cuda_embedding_rows_last_width = output_width;
    model->cuda_embedding_row_result = 0;
    model->cuda_embedding_row_error[0] = '\0';
    return SUCCESS;
}
