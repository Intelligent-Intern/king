/*
 * CUDA prompt prefill query-RoPE batch path.
 */

static zend_result king_inference_cuda_decoder_prompt_loop_prefill_layer0_query_rope_batch(
    king_inference_model_object *model,
    zval *graph,
    zend_string *rms_norm_id,
    zend_ulong prefill_token_count,
    zend_ulong width
) {
    zval *query_linear_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, rms_norm_id, "linear", 0);
    zval *query_slice_op;
    zval *query_rope_op = NULL;
    zend_string *query_linear_weight = NULL;
    zend_string *query_linear_id = NULL;
    zend_string *query_slice_id = NULL;
    zend_ulong query_rows = 0;
    zend_ulong configured_heads = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT];
    zend_ulong head_dim = 0;
    zend_ulong heads;
    zend_ulong head;
    size_t query_rope_bytes;
    double rope_position_scale = 1.0;
    double rope_base = 10000.0;
    zend_ulong rope_pairing = 0;

    if (query_linear_op == NULL
        || rms_norm_id == NULL
        || model->cuda_decoder_prompt_prefill_initial_linear == 0
        || prefill_token_count == 0
        || configured_heads == 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_query_rope_template_invalid"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(query_linear_op, "id", &query_linear_id) != SUCCESS
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            query_linear_op,
            width,
            &query_linear_weight,
            &query_rows
        ) != SUCCESS
        || query_rows == 0
        || query_rows != model->cuda_decoder_prompt_prefill_initial_linear_rows
        || query_rows % configured_heads != 0) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_query_rope_shape_invalid"
        );
        return FAILURE;
    }
    heads = configured_heads;
    head_dim = query_rows / configured_heads;
    if (heads == 0
        || head_dim == 0
        || head_dim > model->cuda_device_kv_cache_key_length
        || prefill_token_count > (zend_ulong) (SIZE_MAX / (size_t) query_rows)
        || (size_t) prefill_token_count * (size_t) query_rows > SIZE_MAX / sizeof(float)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_query_rope_size_invalid"
        );
        return FAILURE;
    }

    query_slice_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, query_linear_id, "slice");
    if (query_slice_op != NULL
        && king_inference_graph_required_string(query_slice_op, "id", &query_slice_id) == SUCCESS) {
        query_rope_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, query_slice_id, "rope");
    }
    if (query_rope_op != NULL
        && (king_inference_graph_finite_double_option(query_rope_op, "position_scale", 1.0, &rope_position_scale) != SUCCESS
            || king_inference_graph_finite_double_option(query_rope_op, "rope_base", 10000.0, &rope_base) != SUCCESS)) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            -1,
            "cuda_decoder_prompt_batch_prefill_query_rope_options_invalid"
        );
        return FAILURE;
    }
    if (query_rope_op != NULL) {
        rope_pairing = king_inference_tensor_option_ulong(query_rope_op, "pairing", 0);
    }

    query_rope_bytes = (size_t) prefill_token_count * (size_t) query_rows * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(
            model,
            query_rope_bytes,
            &model->cuda_decoder_prompt_prefill_initial_query_rope
    ) != SUCCESS) {
        king_inference_cuda_decoder_prompt_loop_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_prompt_batch_prefill_query_rope_allocation_failed"
        );
        return FAILURE;
    }

    for (head = 0; head < heads; head++) {
        king_inference_cuda_device_ptr output = model->cuda_decoder_prompt_prefill_initial_query_rope
            + (king_inference_cuda_device_ptr) ((size_t) head * (size_t) prefill_token_count * (size_t) head_dim * sizeof(float));

        if (king_inference_cuda_rope_f32_batch_slice(
                model,
                model->cuda_decoder_prompt_prefill_initial_linear,
                output,
                query_rows,
                head * head_dim,
                head_dim,
                prefill_token_count,
                0,
                rope_position_scale,
                rope_base,
                rope_pairing
        ) != SUCCESS) {
            king_inference_cuda_decoder_prompt_loop_set_error(
                model,
                model->cuda_rope_result,
                model->cuda_rope_error[0] != '\0'
                    ? model->cuda_rope_error
                    : "cuda_decoder_prompt_batch_prefill_query_rope_failed"
            );
            return FAILURE;
        }
    }

    model->cuda_decoder_prompt_prefill_initial_query_rope_bytes = query_rope_bytes;
    model->cuda_decoder_prompt_prefill_initial_query_rope_heads = heads;
    model->cuda_decoder_prompt_prefill_initial_query_rope_head_dim = head_dim;
    return SUCCESS;
}
