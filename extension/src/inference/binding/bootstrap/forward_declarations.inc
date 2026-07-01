static zend_result king_inference_llm_cache_prepare_stream(
    king_inference_model_object *model,
    king_inference_stream_object *stream,
    zval *graph_options,
    bool with_memory,
    const char *function_name);
static void king_inference_stream_model_runtime_started(king_inference_stream_object *stream);
