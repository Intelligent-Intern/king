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

typedef unsigned long long king_inference_cuda_device_ptr;
typedef struct _king_inference_cuda_device_allocation king_inference_cuda_device_allocation;
typedef struct _king_inference_cuda_weight_upload king_inference_cuda_weight_upload;

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
    void *cuda_driver_handle;
    void *cuda_context;
    void *cuda_nvrtc_handle;
    void *cuda_quantized_matvec_module;
    void *cuda_q8_0_matvec_function;
    void *cuda_rms_norm_nvrtc_handle;
    void *cuda_rms_norm_module;
    void *cuda_rms_norm_function;
    void *cuda_rope_nvrtc_handle;
    void *cuda_rope_module;
    void *cuda_rope_function;
    void *cuda_attention_scores_nvrtc_handle;
    void *cuda_attention_scores_module;
    void *cuda_attention_scores_function;
    void *cuda_attention_softmax_nvrtc_handle;
    void *cuda_attention_softmax_module;
    void *cuda_attention_softmax_function;
    void *cuda_attention_values_nvrtc_handle;
    void *cuda_attention_values_module;
    void *cuda_attention_values_function;
    void *cuda_ffn_swiglu_nvrtc_handle;
    void *cuda_ffn_swiglu_module;
    void *cuda_ffn_swiglu_function;
    king_inference_cuda_device_allocation *cuda_device_allocations;
    king_inference_cuda_weight_upload *cuda_weight_uploads;
    int cuda_device;
    int cuda_context_result;
    int cuda_device_allocator_result;
    int cuda_weight_upload_result;
    int cuda_quantized_matvec_result;
    int cuda_rms_norm_result;
    int cuda_rope_result;
    int cuda_attention_scores_result;
    int cuda_attention_softmax_result;
    int cuda_attention_values_result;
    int cuda_ffn_swiglu_result;
    int cuda_output_projection_result;
    char cuda_context_error[160];
    char cuda_device_allocator_error[160];
    char cuda_weight_upload_error[160];
    char cuda_quantized_matvec_error[160];
    char cuda_rms_norm_error[160];
    char cuda_rope_error[160];
    char cuda_attention_scores_error[160];
    char cuda_attention_softmax_error[160];
    char cuda_attention_values_error[160];
    char cuda_ffn_swiglu_error[160];
    char cuda_output_projection_error[160];
    size_t cuda_device_bytes_allocated;
    size_t cuda_device_peak_bytes_allocated;
    size_t cuda_device_allocation_count;
    size_t cuda_weight_required_count;
    size_t cuda_weight_resolved_count;
    size_t cuda_weight_uploaded_count;
    size_t cuda_weight_duplicate_count;
    size_t cuda_weight_failed_count;
    size_t cuda_weight_uploaded_bytes;
    size_t cuda_weight_cache_hits;
    size_t cuda_weight_cache_misses;
    size_t cuda_weight_cache_stores;
    size_t cuda_quantized_matvec_launch_count;
    size_t cuda_rms_norm_launch_count;
    size_t cuda_rope_launch_count;
    size_t cuda_attention_scores_launch_count;
    size_t cuda_attention_softmax_launch_count;
    size_t cuda_attention_values_launch_count;
    size_t cuda_ffn_swiglu_launch_count;
    size_t cuda_output_projection_launch_count;
    HashTable cuda_weight_cache;
    bool native_map_loaded;
    bool cuda_context_attempted;
    bool cuda_context_available;
    bool cuda_context_owned;
    bool cuda_device_allocator_attempted;
    bool cuda_device_allocator_symbols_available;
    bool cuda_device_allocator_available;
    bool cuda_weight_upload_attempted;
    bool cuda_weight_upload_complete;
    bool cuda_weight_cache_initialized;
    bool cuda_weight_cache_ready;
    bool cuda_quantized_matvec_attempted;
    bool cuda_quantized_matvec_available;
    bool cuda_quantized_matvec_nvrtc_available;
    bool cuda_quantized_matvec_module_loaded;
    bool cuda_quantized_matvec_q8_0_available;
    bool cuda_rms_norm_attempted;
    bool cuda_rms_norm_available;
    bool cuda_rms_norm_nvrtc_available;
    bool cuda_rms_norm_module_loaded;
    bool cuda_rms_norm_f32_available;
    bool cuda_rope_attempted;
    bool cuda_rope_available;
    bool cuda_rope_nvrtc_available;
    bool cuda_rope_module_loaded;
    bool cuda_rope_f32_available;
    bool cuda_attention_scores_attempted;
    bool cuda_attention_scores_available;
    bool cuda_attention_scores_nvrtc_available;
    bool cuda_attention_scores_module_loaded;
    bool cuda_attention_scores_f32_available;
    bool cuda_attention_softmax_attempted;
    bool cuda_attention_softmax_available;
    bool cuda_attention_softmax_nvrtc_available;
    bool cuda_attention_softmax_module_loaded;
    bool cuda_attention_softmax_f32_available;
    bool cuda_attention_values_attempted;
    bool cuda_attention_values_available;
    bool cuda_attention_values_nvrtc_available;
    bool cuda_attention_values_module_loaded;
    bool cuda_attention_values_f32_available;
    bool cuda_ffn_swiglu_attempted;
    bool cuda_ffn_swiglu_available;
    bool cuda_ffn_swiglu_nvrtc_available;
    bool cuda_ffn_swiglu_module_loaded;
    bool cuda_ffn_swiglu_f32_available;
    bool cuda_ffn_swiglu_path_available;
    bool cuda_output_projection_attempted;
    bool cuda_output_projection_available;
    bool cuda_output_projection_resolved;
    bool cuda_output_projection_uploaded;
    bool cuda_output_projection_tied_token_embedding;
    bool cuda_output_projection_q8_0_available;
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
    zend_long native_decoder_token_count;
    zend_long created_at;
    zend_long gpu_thermal_preflight_at;
    zend_long gpu_thermal_abort_at;
    zend_ulong native_decoder_last_token_id;
    double native_decoder_last_probability;
    double native_decoder_last_logit;
    double native_decoder_last_rank;
    double gpu_thermal_preflight_temperature_c;
    double gpu_thermal_abort_temperature_c;
    double gpu_thermal_abort_ceiling_c;
    zend_string *response_id;
    bool start_event_pending;
    bool openai_compatible;
    bool native_decoder_last_token_available;
    bool native_decoder_last_score_available;
    bool gpu_thermal_preflight_checked;
    bool gpu_thermal_preflight_temperature_available;
    bool gpu_thermal_aborted;
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
PHP_FUNCTION(king_inference_token_decode_graph);
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
