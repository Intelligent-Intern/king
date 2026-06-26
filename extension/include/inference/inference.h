/*
 * include/inference/inference.h - Native model inference object contract
 */

#ifndef KING_INFERENCE_H
#define KING_INFERENCE_H

#include <php.h>
#include <zend_object_handlers.h>
#include <stdbool.h>
#include <stddef.h>

typedef struct _king_inference_gguf_metadata {
    zend_ulong version;
    zend_ulong tensor_count;
    zend_ulong metadata_count;
    zend_ulong file_size;
    zend_ulong parsed_metadata_count;
    zend_ulong tensor_directory_count;
    zend_ulong tensor_data_offset;
    zend_ulong tensor_data_alignment;
    zend_ulong tokenizer_token_count;
    zend_ulong tokenizer_score_count;
    zend_ulong tokenizer_type_count;
    zend_ulong tokenizer_merge_count;
    zend_ulong tokenizer_max_token_bytes;
    zend_ulong architecture_params[10];
    zend_long tokenizer_bos_id;
    zend_long tokenizer_eos_id;
    zend_long tokenizer_unknown_id;
    zend_long tokenizer_pad_id;
    bool tokenizer_tokens_loaded;
    bool tokenizer_lookup_loaded;
    bool tokenizer_merges_loaded;
    zend_ulong max_tensor_elements;
    zend_ulong max_tensor_rank;
    zend_ulong tensor_type_counts[32];
    zend_long file_type;
    zend_string *architecture;
    zend_string *general_name;
    zend_string *tokenizer_model;
    bool loaded;
    bool metadata_parsed;
    bool tensor_directory_parsed;
} king_inference_gguf_metadata;

typedef struct _king_inference_model_object {
    zval config;
    zval tensor_index;
    zval tokenizer_tokens;
    zval tokenizer_lookup;
    zval tokenizer_merges;
    zval paged_kv_cache_plan;
    zend_string *name;
    zend_string *artifact_path;
    king_inference_gguf_metadata gguf;
    void *native_map;
    size_t native_map_size;
    bool native_map_loaded;
    zend_object std;
} king_inference_model_object;

typedef struct _king_inference_stream_object {
    zval model;
    zval request;
    zval options;
    zval native_events;
    zend_ulong native_event_index;
    int stdout_fd;
    int stderr_fd;
    zend_long child_pid;
    zend_long exit_code;
    zend_long chunk_count;
    zend_long stderr_count;
    zend_long bytes_emitted;
    zend_long created_at;
    zend_long gpu_thermal_preflight_at;
    double gpu_thermal_preflight_temperature_c;
    zend_string *response_id;
    bool start_event_pending;
    bool openai_compatible;
    bool gpu_thermal_preflight_checked;
    bool gpu_thermal_preflight_temperature_available;
    bool done;
    bool cancelled;
    zend_object std;
} king_inference_stream_object;

/* --- PHP Function Prototypes --- */

PHP_FUNCTION(king_inference_model_load);
PHP_FUNCTION(king_inference_runtime_model_config);
PHP_FUNCTION(king_inference_runtime_model_load);
PHP_FUNCTION(king_inference_gpu_runtime_status);
PHP_FUNCTION(king_inference_llm_cache_status);
PHP_FUNCTION(king_inference_model_info);
PHP_FUNCTION(king_inference_tokenize);
PHP_FUNCTION(king_inference_token_decode);
PHP_FUNCTION(king_inference_tensor_view);
PHP_FUNCTION(king_inference_tensor_index);
PHP_FUNCTION(king_inference_tensor_dequantize);
PHP_FUNCTION(king_inference_tensor_matmul);
PHP_FUNCTION(king_inference_graph_run);
PHP_FUNCTION(king_inference_kv_cache_plan);
PHP_FUNCTION(king_inference_stream);
PHP_FUNCTION(king_inference_openai_chat_http_response);
PHP_FUNCTION(king_inference_openai_http_response);
PHP_FUNCTION(king_inference_next);
PHP_FUNCTION(king_inference_next_async);
PHP_FUNCTION(king_inference_cancel);

static inline king_inference_model_object *
php_king_inference_model_obj_from_zend(zend_object *obj)
{
    return (king_inference_model_object *)
        ((char*)obj - XtOffsetOf(king_inference_model_object, std));
}

static inline king_inference_stream_object *
php_king_inference_stream_obj_from_zend(zend_object *obj)
{
    return (king_inference_stream_object *)
        ((char*)obj - XtOffsetOf(king_inference_stream_object, std));
}

#endif /* KING_INFERENCE_H */
