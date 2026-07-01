/*
 * Internal synthetic CUDA top-k ordering self-check.
 */

typedef int (*king_inference_cuda_logits_memcpy_htod_fn)(
    king_inference_cuda_device_ptr dst,
    const void *src,
    size_t bytes
);

static zend_result king_inference_cuda_logits_readback_synthetic_ordering_check(
    king_inference_model_object *model
) {
    static const float synthetic_logits[16] = {
        -7.0f,
        3.25f,
        11.0f,
        11.0f,
        -1.0f,
        4.5f,
        4.5f,
        0.0f,
        10.5f,
        8.25f,
        4.5f,
        -20.0f,
        10.5f,
        2.0f,
        -0.25f,
        7.0f
    };
    static const unsigned int expected_indices[8] = {2u, 3u, 8u, 12u, 9u, 15u, 5u, 6u};
    static const float expected_logits[8] = {11.0f, 11.0f, 10.5f, 10.5f, 8.25f, 7.0f, 4.5f, 4.5f};
    king_inference_cuda_logits_memcpy_htod_fn cu_memcpy_htod = NULL;
    king_inference_cuda_device_ptr device_logits = 0;
    unsigned int actual_indices[KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES];
    float actual_logits[KING_INFERENCE_CUDA_LOGITS_READBACK_MAX_CANDIDATES];
    zend_ulong candidate_count = 0;
    zend_ulong matched = 0;
    zend_ulong rank_matched = 0;
    zend_ulong token_id_matched = 0;
    zend_ulong logit_matched = 0;
    int result;

    model->cuda_logits_readback_synthetic_ordering_checked = true;
    model->cuda_logits_readback_synthetic_ordering_matched = false;
    model->cuda_logits_readback_synthetic_vocab_size = 16;
    model->cuda_logits_readback_synthetic_candidate_count = 8;
    model->cuda_logits_readback_synthetic_matched_count = 0;
    model->cuda_logits_readback_synthetic_rank_matched_count = 0;
    model->cuda_logits_readback_synthetic_token_id_matched_count = 0;
    model->cuda_logits_readback_synthetic_logit_matched_count = 0;

    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_logits_readback_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        return FAILURE;
    }
    if (!king_inference_cuda_logits_readback_driver_symbol(
            model,
            "cuMemcpyHtoD_v2",
            "cuMemcpyHtoD",
            (void **) &cu_memcpy_htod
    )) {
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(
            model,
            sizeof(synthetic_logits),
            &device_logits
    ) != SUCCESS) {
        king_inference_cuda_logits_readback_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_logits_readback_synthetic_allocation_failed"
        );
        return FAILURE;
    }

    result = cu_memcpy_htod(device_logits, synthetic_logits, sizeof(synthetic_logits));
    if (result != 0) {
        (void) king_inference_cuda_device_allocator_free(model, device_logits);
        king_inference_cuda_logits_readback_set_error(
            model,
            result,
            "cuMemcpyHtoD_synthetic_logits_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_logits_readback_top_k(
            model,
            device_logits,
            16,
            8,
            actual_indices,
            actual_logits,
            &candidate_count
    ) != SUCCESS) {
        (void) king_inference_cuda_device_allocator_free(model, device_logits);
        return FAILURE;
    }
    (void) king_inference_cuda_device_allocator_free(model, device_logits);

    if (candidate_count != 8) {
        king_inference_cuda_logits_readback_set_error(
            model,
            -1,
            "cuda_logits_readback_synthetic_candidate_count_mismatch"
        );
        return FAILURE;
    }
    for (zend_ulong index = 0; index < candidate_count; index++) {
        bool token_id_matches = actual_indices[index] == expected_indices[index];
        bool logit_matches = fabs((double) actual_logits[index] - (double) expected_logits[index]) <= 0.000001;

        if (token_id_matches) {
            token_id_matched++;
        }
        if (logit_matches) {
            logit_matched++;
        }
        if (token_id_matches && logit_matches) {
            matched++;
            rank_matched++;
        }
    }
    model->cuda_logits_readback_synthetic_matched_count = matched;
    model->cuda_logits_readback_synthetic_rank_matched_count = rank_matched;
    model->cuda_logits_readback_synthetic_token_id_matched_count = token_id_matched;
    model->cuda_logits_readback_synthetic_logit_matched_count = logit_matched;
    if (matched != candidate_count) {
        king_inference_cuda_logits_readback_set_error(
            model,
            -1,
            "cuda_logits_readback_synthetic_ordering_mismatch"
        );
        return FAILURE;
    }

    model->cuda_logits_readback_synthetic_ordering_matched = true;
    model->cuda_logits_readback_result = 0;
    model->cuda_logits_readback_error[0] = '\0';
    return SUCCESS;
}
