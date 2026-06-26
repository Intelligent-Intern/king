const zend_function_entry king_inference_class_methods[] = {
    ZEND_ME_MAPPING(runtimeModelConfig, king_inference_runtime_model_config, arginfo_king_inference_runtime_model_config, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(runtimeModelLoad, king_inference_runtime_model_load, arginfo_king_inference_runtime_model_load, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(gpuRuntimeStatus, king_inference_gpu_runtime_status, arginfo_king_inference_gpu_runtime_status, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(llmCacheStatus, king_inference_llm_cache_status, arginfo_king_inference_llm_cache_status, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(loadModel, king_inference_model_load, arginfo_king_inference_model_load, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(modelInfo, king_inference_model_info, arginfo_king_inference_model_info, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tokenize, king_inference_tokenize, arginfo_king_inference_tokenize, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tokenDecode, king_inference_token_decode, arginfo_king_inference_token_decode, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tokenDecodeGraph, king_inference_token_decode_graph, arginfo_king_inference_token_decode_graph, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tensorView, king_inference_tensor_view, arginfo_king_inference_tensor_view, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tensorIndex, king_inference_tensor_index, arginfo_king_inference_tensor_index, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tensorDequantize, king_inference_tensor_dequantize, arginfo_king_inference_tensor_dequantize, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tensorMatmul, king_inference_tensor_matmul, arginfo_king_inference_tensor_matmul, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(graphRun, king_inference_graph_run, arginfo_king_inference_graph_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(kvCachePlan, king_inference_kv_cache_plan, arginfo_king_inference_kv_cache_plan, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(stream, king_inference_stream, arginfo_king_inference_stream, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(openaiChatHttpResponse, king_inference_openai_chat_http_response, arginfo_king_inference_openai_chat_http_response, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(openaiHttpResponse, king_inference_openai_http_response, arginfo_king_inference_openai_http_response, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(next, king_inference_next, arginfo_king_inference_next, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(nextAsync, king_inference_next_async, arginfo_king_inference_next_async, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(cancel, king_inference_cancel, arginfo_king_inference_cancel, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};

const zend_function_entry king_inference_model_class_methods[] = {
    PHP_ME(King_Inference_Model, __construct, arginfo_class_King_Inference_Model___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_Inference_Model, info, arginfo_class_King_Inference_Model_info, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Model, tokenize, arginfo_class_King_Inference_Model_tokenize, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Model, tokenDecode, arginfo_class_King_Inference_Model_tokenDecode, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Model, tokenDecodeGraph, arginfo_class_King_Inference_Model_tokenDecodeGraph, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Model, tensorView, arginfo_class_King_Inference_Model_tensorView, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Model, tensorIndex, arginfo_class_King_Inference_Model_tensorIndex, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Model, tensorDequantize, arginfo_class_King_Inference_Model_tensorDequantize, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Model, tensorMatmul, arginfo_class_King_Inference_Model_tensorMatmul, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Model, graphRun, arginfo_class_King_Inference_Model_graphRun, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Model, kvCachePlan, arginfo_class_King_Inference_Model_kvCachePlan, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

const zend_function_entry king_inference_stream_class_methods[] = {
    PHP_ME(King_Inference_Stream, __construct, arginfo_class_King_Inference_Stream___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_Inference_Stream, next, arginfo_class_King_Inference_Stream_next, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Stream, nextAsync, arginfo_class_King_Inference_Stream_nextAsync, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Stream, cancel, arginfo_class_King_Inference_Stream_cancel, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Stream, isDone, arginfo_class_King_Inference_Stream_isDone, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Stream, getMetrics, arginfo_class_King_Inference_Stream_getMetrics, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
