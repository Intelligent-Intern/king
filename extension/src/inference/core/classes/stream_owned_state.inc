/*
 * Stream-owned state release for object teardown and re-construction.
 */

static void king_inference_stream_release_owned_state(king_inference_stream_object *intern)
{
    king_inference_stream_close_process(intern, true);
    if (!Z_ISUNDEF(intern->model)) {
        zval_ptr_dtor(&intern->model);
        ZVAL_UNDEF(&intern->model);
    }
    if (!Z_ISUNDEF(intern->request)) {
        zval_ptr_dtor(&intern->request);
        ZVAL_UNDEF(&intern->request);
    }
    if (!Z_ISUNDEF(intern->options)) {
        zval_ptr_dtor(&intern->options);
        ZVAL_UNDEF(&intern->options);
    }
    if (!Z_ISUNDEF(intern->native_events)) {
        zval_ptr_dtor(&intern->native_events);
        ZVAL_UNDEF(&intern->native_events);
    }
    king_inference_cuda_decoder_prompt_stream_release(intern);
    if (intern->response_id != NULL) {
        zend_string_release(intern->response_id);
        intern->response_id = NULL;
    }
}
