/*
 * CUDA quantized matrix/batch-vector launcher for prompt prefill.
 */

static zend_result king_inference_cuda_quantized_matvec_batch(
    king_inference_model_object *model,
    zend_string *tensor_name,
    king_inference_cuda_device_ptr input_matrix,
    zend_ulong input_length,
    zend_ulong batch_count,
    king_inference_cuda_device_ptr output_matrix,
    zend_ulong rows
) {
    king_inference_cuda_weight_upload *upload;
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    static king_inference_cuda_launch_kernel_fn cached_cu_launch_kernel = NULL;
    zval *descriptor;
    zval *dimensions;
    zend_ulong type;
    zend_ulong rank;
    zend_ulong cols;
    zend_ulong tensor_rows;
    king_inference_tensor_type_info type_info;
    unsigned int columns32;
    unsigned int rows32;
    unsigned int batch32;
    unsigned int type32;
    unsigned int block_size32;
    unsigned int type_size32;
    unsigned int block_x = 128;
    unsigned int grid_x;
    void *params[9];
    int result;

    if (!model->cuda_quantized_matvec_batch_available
        || model->cuda_quantized_matvec_batch_function == NULL) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_quantized_matvec_batch_unavailable");
        return FAILURE;
    }
    if (tensor_name == NULL || input_matrix == 0 || output_matrix == 0) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_quantized_matvec_batch_argument_invalid");
        return FAILURE;
    }
    if (input_length == 0
        || input_length > UINT_MAX
        || batch_count == 0
        || batch_count > UINT_MAX
        || rows == 0
        || rows > UINT_MAX) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_quantized_matvec_batch_shape_invalid");
        return FAILURE;
    }

    descriptor = king_inference_tensor_index_descriptor(model, tensor_name);
    dimensions = descriptor != NULL
        ? zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1)
        : NULL;
    if (descriptor == NULL
        || dimensions == NULL
        || Z_TYPE_P(dimensions) != IS_ARRAY
        || !king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)
        || !king_inference_tensor_type_lookup(type, &type_info)
        || rank != 2
        || !king_inference_cuda_quantized_matvec_type_supported(type)
        || king_inference_tensor_dimension_at(dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(dimensions, 1, &tensor_rows) != SUCCESS
        || cols != input_length
        || rows > tensor_rows) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_quantized_batch_tensor_descriptor_invalid");
        return FAILURE;
    }

    upload = king_inference_cuda_weight_cache_find(model, tensor_name, true);
    if (upload == NULL || upload->device_ptr == 0) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_quantized_batch_weight_not_uploaded");
        return FAILURE;
    }
    if (cached_cu_launch_kernel == NULL
        && !king_inference_cuda_quantized_matvec_driver_symbol(
            model,
            "cuLaunchKernel",
            (void **) &cached_cu_launch_kernel
        )) {
        return FAILURE;
    }
    cu_launch_kernel = cached_cu_launch_kernel;

    columns32 = (unsigned int) input_length;
    rows32 = (unsigned int) rows;
    batch32 = (unsigned int) batch_count;
    type32 = (unsigned int) type;
    block_size32 = (unsigned int) type_info.block_size;
    type_size32 = (unsigned int) type_info.type_size;
    grid_x = type32 == 8u ? (rows32 + 3u) / 4u : rows32;
    params[0] = &upload->device_ptr;
    params[1] = &input_matrix;
    params[2] = &output_matrix;
    params[3] = &columns32;
    params[4] = &rows32;
    params[5] = &batch32;
    params[6] = &type32;
    params[7] = &block_size32;
    params[8] = &type_size32;

    result = cu_launch_kernel(
        model->cuda_quantized_matvec_batch_function,
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
    if (result != 0) {
        king_inference_cuda_quantized_matvec_set_error(model, result, "cuLaunchKernel_batch_failed");
        return FAILURE;
    }

    model->cuda_quantized_matvec_batch_launch_count++;
    model->cuda_quantized_matvec_result = 0;
    model->cuda_quantized_matvec_error[0] = '\0';
    return SUCCESS;
}
