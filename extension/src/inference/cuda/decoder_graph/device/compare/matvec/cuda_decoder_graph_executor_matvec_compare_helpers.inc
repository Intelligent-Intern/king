static zend_result king_inference_cuda_decoder_graph_executor_build_bos_first_rms_norm_vector(
    king_inference_model_object *model,
    zend_ulong token_id,
    zend_string *rms_weight_tensor,
    zend_ulong width,
    double epsilon,
    double **vector_out,
    double *scale_out
) {
    zend_string *embedding_tensor = NULL;
    king_inference_tensor_native_view embedding_view;
    king_inference_tensor_native_view weight_view;
    zend_ulong cols = 0;
    zend_ulong rows = 0;
    zend_ulong base;
    double *vector;
    double sumsq = 0.0;
    double scale;

    *vector_out = NULL;
    *scale_out = 0.0;
    if (width == 0 || rms_weight_tensor == NULL || epsilon <= 0.0) {
        return FAILURE;
    }
    if (king_inference_resolve_token_embedding_tensor_name(model, NULL, &embedding_tensor, NULL, NULL) != SUCCESS
        || embedding_tensor == NULL) {
        return FAILURE;
    }
    if (king_inference_tensor_native_view_resolve(model, embedding_tensor, &embedding_view) != SUCCESS) {
        zend_string_release(embedding_tensor);
        return FAILURE;
    }
    zend_string_release(embedding_tensor);
    if (king_inference_tensor_native_view_resolve(model, rms_weight_tensor, &weight_view) != SUCCESS) {
        return FAILURE;
    }
    if (embedding_view.rank != 2
        || king_inference_tensor_dimension_at(embedding_view.dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(embedding_view.dimensions, 1, &rows) != SUCCESS
        || cols != width
        || token_id >= rows
        || token_id > ((zend_ulong) -1) / cols
        || weight_view.rank != 1
        || weight_view.elements != width) {
        return FAILURE;
    }

    base = token_id * cols;
    vector = ecalloc((size_t) width, sizeof(double));
    for (zend_ulong i = 0; i < width; i++) {
        double value;

        if (king_inference_tensor_value_at(&embedding_view, base + i, &value) != SUCCESS) {
            efree(vector);
            return FAILURE;
        }
        sumsq += value * value;
        vector[i] = value;
    }
    scale = 1.0 / king_inference_graph_sqrt((sumsq / (double) width) + epsilon);
    if (!isfinite(scale)) {
        efree(vector);
        return FAILURE;
    }
    for (zend_ulong i = 0; i < width; i++) {
        double weight_value;

        if (king_inference_tensor_value_at(&weight_view, i, &weight_value) != SUCCESS) {
            efree(vector);
            return FAILURE;
        }
        vector[i] *= scale * weight_value;
    }

    *vector_out = vector;
    *scale_out = scale;
    return SUCCESS;
}
