/*
 * Native stream lazy-event helpers.
 */

static bool king_inference_stream_has_pending_native_events(king_inference_stream_object *stream)
{
    return king_inference_stream_has_native_events(stream)
        && stream->native_event_index < (zend_ulong) zend_hash_num_elements(Z_ARRVAL(stream->native_events));
}

static zend_result king_inference_stream_ensure_native_event_available(king_inference_stream_object *stream)
{
    king_inference_model_object *model;

    if (stream == NULL || stream->native_gpu_stream_state == NULL) {
        return SUCCESS;
    }
    if (Z_ISUNDEF(stream->model) || Z_TYPE(stream->model) != IS_OBJECT) {
        return SUCCESS;
    }

    model = php_king_inference_model_obj_from_zend(Z_OBJ(stream->model));
    while (stream->native_gpu_stream_state != NULL
        && !king_inference_stream_has_pending_native_events(stream)) {
        if (king_inference_cuda_decoder_prompt_stream_step(model, stream) != SUCCESS) {
            return FAILURE;
        }
    }
    return SUCCESS;
}
