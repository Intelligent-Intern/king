/*
 * CUDA decoder graph probe lifecycle helpers for native King GPU inference.
 */

static void king_inference_cuda_decoder_graph_executor_destroy_initial_probes(
    zval *embedding_probe,
    zval *rms_norm_probe,
    zval *linear_probe,
    zval *slice_probe,
    zval *rope_probe,
    zval *kv_head_prepare_probe
) {
    zval_ptr_dtor(embedding_probe);
    zval_ptr_dtor(rms_norm_probe);
    zval_ptr_dtor(linear_probe);
    zval_ptr_dtor(slice_probe);
    zval_ptr_dtor(rope_probe);
    zval_ptr_dtor(kv_head_prepare_probe);
}

static void king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(
    bool projection_initialized,
    zval *projection_probe,
    bool residual_initialized,
    zval *residual_probe,
    bool ffn_norm_initialized,
    zval *ffn_norm_probe
) {
    if (projection_initialized) {
        zval_ptr_dtor(projection_probe);
    }
    if (residual_initialized) {
        zval_ptr_dtor(residual_probe);
    }
    if (ffn_norm_initialized) {
        zval_ptr_dtor(ffn_norm_probe);
    }
}
