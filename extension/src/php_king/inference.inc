/*
 * Local quantized model inference surface.
 *
 * The first King AI primitive owns model registration, safety policy and
 * streaming process lifecycle. The local backend is King-owned and the public
 * contract stays stable while its internals evolve.
 */

#include "Zend/zend_smart_str.h"
#include "ext/standard/base64.h"
#include "ext/json/php_json.h"
#include "include/config/high_perf_compute_and_ai/base_layer.h"

#include <errno.h>
#include <fcntl.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <strings.h>
#include <sys/mman.h>
#include <sys/select.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#include "inference/arginfo.inc"
#include "inference/helpers.inc"
#include "inference/openai_messages.inc"
#include "inference/backend_contract.inc"
#include "inference/thermal_policy.inc"
#include "inference/resource_policy.inc"
#include "inference/gguf_loader.inc"
#include "inference/native_memory.inc"
#include "inference/tensor_view.inc"
#include "inference/tensor_math.inc"
#include "inference/tensor_graph.inc"
#include "inference/paged_kv_cache.inc"
#include "inference/backend_king_local.inc"
#include "inference/backend_king_native.inc"
#include "inference/backend_registry.inc"
#include "inference/model_config.inc"
#include "inference/tokenizer.inc"
#include "inference/openai_options.inc"
#include "inference/openai_usage.inc"
#include "inference/stream_events.inc"
#include "inference/openai_compat.inc"
#include "inference/openai_responses.inc"
#include "inference/openai_completions.inc"
#include "inference/openai_embeddings.inc"
#include "inference/openai_http_router.inc"

static zend_result king_inference_next_async_runner(king_awaitable_object *intern, zval *result)
{
    zval *stream_object;
    zval *timeout;
    zval event;
    zend_long timeout_ms = 0;
    king_inference_stream_object *stream;
    zend_result status;

    if (Z_TYPE(intern->payload) != IS_ARRAY) {
        king_set_error("King inference async stream reader has an invalid native payload.");
        return FAILURE;
    }

    stream_object = zend_hash_str_find(
        Z_ARRVAL(intern->payload),
        "stream",
        sizeof("stream") - 1
    );
    timeout = zend_hash_str_find(
        Z_ARRVAL(intern->payload),
        "timeout_ms",
        sizeof("timeout_ms") - 1
    );
    if (stream_object == NULL
        || Z_TYPE_P(stream_object) != IS_OBJECT
        || !instanceof_function(Z_OBJCE_P(stream_object), king_ce_inference_stream)) {
        king_set_error("King inference async stream reader requires a valid stream.");
        return FAILURE;
    }
    if (timeout != NULL && Z_TYPE_P(timeout) == IS_LONG) {
        timeout_ms = Z_LVAL_P(timeout);
    }

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(stream_object));
    ZVAL_UNDEF(&event);
    status = king_inference_stream_next_event(stream, timeout_ms, &event);
    if (status != SUCCESS) {
        if (!Z_ISUNDEF(event)) {
            zval_ptr_dtor(&event);
        }
        return FAILURE;
    }

    if (Z_TYPE(event) == IS_NULL && !stream->done) {
        zval_ptr_dtor(&event);
        ZVAL_UNDEF(result);
        return SUCCESS;
    }

    ZVAL_COPY_VALUE(result, &event);
    return SUCCESS;
}

static zend_result king_inference_next_async_create(
    zval *return_value,
    zval *stream_object,
    zend_long timeout_ms,
    const char *operation,
    size_t operation_len)
{
    zval payload;
    zval stream_copy;
    zend_result status;

    array_init(&payload);
    ZVAL_COPY(&stream_copy, stream_object);
    add_assoc_zval(&payload, "stream", &stream_copy);
    add_assoc_long(&payload, "timeout_ms", timeout_ms);

    status = king_awaitable_create(
        return_value,
        operation,
        operation_len,
        king_inference_next_async_runner,
        &payload,
        NULL
    );
    zval_ptr_dtor(&payload);

    return status;
}

PHP_FUNCTION(king_inference_model_load)
{
    zval *config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    object_init_ex(return_value, king_ce_inference_model);
    if (king_inference_model_init(return_value, config, "king_inference_model_load") != SUCCESS) {
        zval_ptr_dtor(return_value);
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_model_info)
{
    zval *model;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
    ZEND_PARSE_PARAMETERS_END();

    king_inference_model_info_array(php_king_inference_model_obj_from_zend(Z_OBJ_P(model)), return_value);
}

PHP_FUNCTION(king_inference_tokenize)
{
    zval *model;
    zend_string *text;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_STR(text)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tokenizer_encode(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        text,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_kv_cache_plan)
{
    zval *model;
    zval *request = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(request)
    ZEND_PARSE_PARAMETERS_END();

    king_inference_paged_kv_cache_plan(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        request,
        return_value
    );
}

PHP_FUNCTION(king_inference_tensor_view)
{
    zval *model;
    zend_string *name;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_STR(name)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_view_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        name,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_tensor_index)
{
    zval *model;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    king_inference_tensor_index_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        options,
        return_value
    );
}

PHP_FUNCTION(king_inference_tensor_dequantize)
{
    zval *model;
    zend_string *name;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_STR(name)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_dequantize_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        name,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_tensor_matmul)
{
    zval *model;
    zend_string *name;
    zval *input;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_STR(name)
        Z_PARAM_ARRAY(input)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_matmul_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        name,
        input,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_graph_run)
{
    zval *model;
    zval *graph;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_ARRAY(graph)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_graph_run_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(model)),
        graph,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_stream)
{
    zval *model;
    zval *request;
    zval *options = NULL;
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_ARRAY(request)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    object_init_ex(return_value, king_ce_inference_stream);
    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(return_value));
    ZVAL_COPY(&stream->model, model);
    ZVAL_COPY(&stream->request, request);
    if (options != NULL) {
        ZVAL_COPY(&stream->options, options);
    } else {
        array_init(&stream->options);
    }
    stream->openai_compatible = king_inference_stream_openai_requested(&stream->request, &stream->options);
    stream->created_at = (zend_long) time(NULL);
    if (stream->openai_compatible) {
        king_inference_stream_prepare_openai(stream);
    }

    if (king_inference_stream_start(stream, "king_inference_stream") != SUCCESS) {
        zval_ptr_dtor(return_value);
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_next)
{
    zval *stream_object;
    zend_long timeout_ms = 0;
    bool timeout_is_null = true;
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(stream_object, king_ce_inference_stream)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_is_null) {
        timeout_ms = 0;
    }

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(stream_object));
    if (king_inference_stream_next_event(stream, timeout_ms, return_value) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_next_async)
{
    zval *stream_object;
    zend_long timeout_ms = 0;
    bool timeout_is_null = true;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(stream_object, king_ce_inference_stream)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_is_null) {
        timeout_ms = 0;
    }

    if (king_inference_next_async_create(
        return_value,
        stream_object,
        timeout_ms,
        "king_inference_next_async",
        sizeof("king_inference_next_async") - 1
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_inference_cancel)
{
    zval *stream_object;
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(stream_object, king_ce_inference_stream)
    ZEND_PARSE_PARAMETERS_END();

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(stream_object));
    stream->cancelled = true;
    stream->done = true;
    king_inference_stream_close_process(stream, true);
    RETURN_TRUE;
}

PHP_METHOD(King_Inference_Model, __construct)
{
    zval *config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_model_init(ZEND_THIS, config, "King\\Inference\\Model::__construct") != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, info)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_inference_model_info_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        return_value
    );
}

PHP_METHOD(King_Inference_Model, tokenize)
{
    zend_string *text;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(text)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tokenizer_encode(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        text,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, kvCachePlan)
{
    zval *request = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(request)
    ZEND_PARSE_PARAMETERS_END();

    king_inference_paged_kv_cache_plan(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        request,
        return_value
    );
}

PHP_METHOD(King_Inference_Model, tensorView)
{
    zend_string *name;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(name)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_view_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        name,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, tensorIndex)
{
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    king_inference_tensor_index_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        options,
        return_value
    );
}

PHP_METHOD(King_Inference_Model, tensorDequantize)
{
    zend_string *name;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(name)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_dequantize_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        name,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, tensorMatmul)
{
    zend_string *name;
    zval *input;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(name)
        Z_PARAM_ARRAY(input)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_tensor_matmul_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        name,
        input,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Model, graphRun)
{
    zval *graph;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(graph)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_inference_graph_run_array(
        php_king_inference_model_obj_from_zend(Z_OBJ_P(ZEND_THIS)),
        graph,
        options,
        return_value
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Stream, __construct)
{
    zval *model;
    zval *request;
    zval *options = NULL;
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(model, king_ce_inference_model)
        Z_PARAM_ARRAY(request)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    king_inference_stream_close_process(stream, true);
    if (!Z_ISUNDEF(stream->model)) {
        zval_ptr_dtor(&stream->model);
    }
    if (!Z_ISUNDEF(stream->request)) {
        zval_ptr_dtor(&stream->request);
    }
    if (!Z_ISUNDEF(stream->options)) {
        zval_ptr_dtor(&stream->options);
    }
    ZVAL_COPY(&stream->model, model);
    ZVAL_COPY(&stream->request, request);
    if (options != NULL) {
        ZVAL_COPY(&stream->options, options);
    } else {
        array_init(&stream->options);
    }
    stream->start_event_pending = true;
    stream->done = false;
    stream->cancelled = false;
    stream->exit_code = -1;
    stream->chunk_count = 0;
    stream->stderr_count = 0;
    stream->bytes_emitted = 0;
    stream->openai_compatible = king_inference_stream_openai_requested(&stream->request, &stream->options);
    stream->created_at = (zend_long) time(NULL);
    if (stream->openai_compatible) {
        king_inference_stream_prepare_openai(stream);
    } else if (stream->response_id != NULL) {
        zend_string_release(stream->response_id);
        stream->response_id = NULL;
    }

    if (king_inference_stream_start(stream, "King\\Inference\\Stream::__construct") != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Stream, next)
{
    zend_long timeout_ms = 0;
    bool timeout_is_null = true;
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_is_null) {
        timeout_ms = 0;
    }

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (king_inference_stream_next_event(stream, timeout_ms, return_value) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Stream, nextAsync)
{
    zend_long timeout_ms = 0;
    bool timeout_is_null = true;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_is_null) {
        timeout_ms = 0;
    }

    if (king_inference_next_async_create(
        return_value,
        ZEND_THIS,
        timeout_ms,
        "King\\Inference\\Stream::nextAsync",
        sizeof("King\\Inference\\Stream::nextAsync") - 1
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Inference_Stream, cancel)
{
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_NONE();

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    stream->cancelled = true;
    stream->done = true;
    king_inference_stream_close_process(stream, true);
    RETURN_TRUE;
}

PHP_METHOD(King_Inference_Stream, isDone)
{
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_NONE();

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    RETURN_BOOL(stream->done);
}

PHP_METHOD(King_Inference_Stream, getMetrics)
{
    king_inference_stream_object *stream;

    ZEND_PARSE_PARAMETERS_NONE();

    stream = php_king_inference_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    king_inference_stream_metrics(stream, return_value);
}

const zend_function_entry king_inference_class_methods[] = {
    ZEND_ME_MAPPING(loadModel, king_inference_model_load, arginfo_king_inference_model_load, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tokenize, king_inference_tokenize, arginfo_king_inference_tokenize, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tensorView, king_inference_tensor_view, arginfo_king_inference_tensor_view, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tensorIndex, king_inference_tensor_index, arginfo_king_inference_tensor_index, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tensorDequantize, king_inference_tensor_dequantize, arginfo_king_inference_tensor_dequantize, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(tensorMatmul, king_inference_tensor_matmul, arginfo_king_inference_tensor_matmul, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(graphRun, king_inference_graph_run, arginfo_king_inference_graph_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(kvCachePlan, king_inference_kv_cache_plan, arginfo_king_inference_kv_cache_plan, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(stream, king_inference_stream, arginfo_king_inference_stream, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(openaiChatHttpResponse, king_inference_openai_chat_http_response, arginfo_king_inference_openai_chat_http_response, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(openaiHttpResponse, king_inference_openai_http_response, arginfo_king_inference_openai_http_response, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};

const zend_function_entry king_inference_model_class_methods[] = {
    PHP_ME(King_Inference_Model, __construct, arginfo_class_King_Inference_Model___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_Inference_Model, info, arginfo_class_King_Inference_Model_info, ZEND_ACC_PUBLIC)
    PHP_ME(King_Inference_Model, tokenize, arginfo_class_King_Inference_Model_tokenize, ZEND_ACC_PUBLIC)
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
