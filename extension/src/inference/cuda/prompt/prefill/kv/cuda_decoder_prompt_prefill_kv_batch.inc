/*
 * CUDA prompt prefill K/V cache batch path.
 */

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_layer0_kv_batch(
    king_inference_model_object *model,
    zval *graph,
    zend_string *rms_norm_id,
    zend_ulong prefill_token_count,
    zend_ulong width
) {
    zval *key_linear_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, rms_norm_id, "linear", 1);
    zval *value_linear_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, rms_norm_id, "linear", 2);
    zval *key_slice_op;
    zval *key_rope_op = NULL;
    zend_string *key_linear_weight = NULL;
    zend_string *value_linear_weight = NULL;
    zend_string *key_linear_id = NULL;
    zend_string *key_slice_id = NULL;
    zend_ulong key_rows = 0;
    zend_ulong value_rows = 0;
    zend_ulong key_length = model->cuda_decoder_prompt_prefill_initial_query_rope_head_dim;
    zend_ulong value_length = 0;
    zend_ulong kv_heads = 0;
    zend_ulong head;
    king_inference_cuda_device_ptr key_matrix = 0;
    king_inference_cuda_device_ptr value_matrix = 0;
    king_inference_cuda_device_ptr key_rope_matrix = 0;
    size_t key_bytes;
    size_t value_bytes;
    size_t key_rope_bytes;
    double rope_position_scale = 1.0;
    double rope_base = 10000.0;
    zend_ulong rope_pairing = 0;

    if (key_linear_op == NULL || value_linear_op == NULL || rms_norm_id == NULL
        || !model->cuda_device_kv_cache_available || key_length == 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_kv_template_invalid");
        return FAILURE;
    }
    if (king_inference_graph_required_string(key_linear_op, "id", &key_linear_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(model, key_linear_op, width, &key_linear_weight, &key_rows) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(model, value_linear_op, width, &value_linear_weight, &value_rows) != SUCCESS
        || key_length > model->cuda_device_kv_cache_key_length
        || key_rows < key_length
        || key_rows % key_length != 0
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) key_rows)
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) value_rows)) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_kv_shape_invalid");
        return FAILURE;
    }
    kv_heads = key_rows / key_length;
    if (kv_heads == 0
        || kv_heads > model->cuda_device_kv_cache_kv_heads
        || value_rows < kv_heads
        || value_rows % kv_heads != 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_kv_shape_invalid");
        return FAILURE;
    }
    value_length = value_rows / kv_heads;
    if (value_length == 0 || value_length > model->cuda_device_kv_cache_value_length) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_kv_shape_invalid");
        return FAILURE;
    }
    key_slice_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, key_linear_id, "slice");
    if (key_slice_op != NULL
        && king_inference_graph_required_string(key_slice_op, "id", &key_slice_id) == SUCCESS) {
        key_rope_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, key_slice_id, "rope");
    }
    if (key_rope_op != NULL
        && (king_inference_graph_finite_double_option(key_rope_op, "position_scale", 1.0, &rope_position_scale) != SUCCESS
            || king_inference_graph_finite_double_option(key_rope_op, "rope_base", 10000.0, &rope_base) != SUCCESS)) {
        king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_kv_rope_options_invalid");
        return FAILURE;
    }
    if (key_rope_op != NULL) {
        rope_pairing = king_inference_tensor_option_ulong(key_rope_op, "pairing", 0);
    }

    key_bytes = (size_t) prefill_token_count * (size_t) key_rows * sizeof(float);
    value_bytes = (size_t) prefill_token_count * (size_t) value_rows * sizeof(float);
    key_rope_bytes = (size_t) prefill_token_count * (size_t) key_length * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(model, key_bytes, &key_matrix) != SUCCESS
        || king_inference_cuda_device_allocator_alloc(model, value_bytes, &value_matrix) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_matrix);
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_kv_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_quantized_matvec_batch(
            model,
            key_linear_weight,
            model->cuda_decoder_prompt_prefill_initial_norm,
            width,
            prefill_token_count,
            key_matrix,
            key_rows
    ) != SUCCESS
        || king_inference_cuda_quantized_matvec_batch(
            model,
            value_linear_weight,
            model->cuda_decoder_prompt_prefill_initial_norm,
            width,
            prefill_token_count,
            value_matrix,
            value_rows
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_matrix);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_matrix);
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_quantized_matvec_result,
            model->cuda_quantized_matvec_error[0] != '\0'
                ? model->cuda_quantized_matvec_error
                : "cuda_decoder_prompt_batch_prefill_kv_projection_failed"
        );
        return FAILURE;
    }

    for (head = 0; head < kv_heads; head++) {
        if (king_inference_cuda_device_allocator_alloc(model, key_rope_bytes, &key_rope_matrix) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_matrix);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_matrix);
            king_inference_cuda_decoder_prompt_loop_set_error(model, -1, "cuda_decoder_prompt_batch_prefill_kv_rope_allocation_failed");
            return FAILURE;
        }
        if (king_inference_cuda_rope_f32_batch_slice(
                model,
                key_matrix,
                key_rope_matrix,
                key_rows,
                head * key_length,
                key_length,
                prefill_token_count,
                0,
                rope_position_scale,
                rope_base,
                rope_pairing
        ) != SUCCESS
            || king_inference_cuda_device_kv_cache_write_batch_strided(
                model,
                0,
                head,
                0,
                prefill_token_count,
                key_rope_matrix,
                key_length,
                0,
                key_length,
                value_matrix,
                value_rows,
                head * value_length,
                value_length
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_matrix);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_matrix);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_matrix);
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                model->cuda_rope_result != 0 ? model->cuda_rope_result : model->cuda_device_kv_cache_result,
                model->cuda_rope_error[0] != '\0'
                    ? model->cuda_rope_error
                    : (model->cuda_device_kv_cache_error[0] != '\0'
                        ? model->cuda_device_kv_cache_error
                        : "cuda_decoder_prompt_batch_prefill_kv_cache_write_failed")
            );
            return FAILURE;
        }
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_matrix);
        model->cuda_decoder_prompt_prefill_kv_cache_writes++;
    }

    model->cuda_decoder_prompt_prefill_kv_heads = kv_heads;
    model->cuda_decoder_prompt_prefill_kv_key_length = key_length;
    model->cuda_decoder_prompt_prefill_kv_value_length = value_length;
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_matrix);
    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_matrix);
    return SUCCESS;
}
