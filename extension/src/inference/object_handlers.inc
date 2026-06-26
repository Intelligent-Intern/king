/*
 * King inference PHP object handlers.
 */

static zend_object_handlers king_inference_model_object_handlers;
static zend_object_handlers king_inference_stream_object_handlers;

static void king_inference_model_object_free(zend_object *object)
{
    king_inference_model_object *intern = php_king_inference_model_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->config)) {
        zval_ptr_dtor(&intern->config);
        ZVAL_UNDEF(&intern->config);
    }
    if (!Z_ISUNDEF(intern->tensor_index)) {
        zval_ptr_dtor(&intern->tensor_index);
        ZVAL_UNDEF(&intern->tensor_index);
    }
    if (!Z_ISUNDEF(intern->tokenizer_tokens)) {
        zval_ptr_dtor(&intern->tokenizer_tokens);
        ZVAL_UNDEF(&intern->tokenizer_tokens);
    }
    if (!Z_ISUNDEF(intern->tokenizer_lookup)) {
        zval_ptr_dtor(&intern->tokenizer_lookup);
        ZVAL_UNDEF(&intern->tokenizer_lookup);
    }
    if (!Z_ISUNDEF(intern->tokenizer_merges)) {
        zval_ptr_dtor(&intern->tokenizer_merges);
        ZVAL_UNDEF(&intern->tokenizer_merges);
    }
    if (!Z_ISUNDEF(intern->paged_kv_cache_plan)) {
        zval_ptr_dtor(&intern->paged_kv_cache_plan);
        ZVAL_UNDEF(&intern->paged_kv_cache_plan);
    }
    if (intern->name != NULL) {
        zend_string_release(intern->name);
        intern->name = NULL;
    }
    if (intern->artifact_path != NULL) {
        zend_string_release(intern->artifact_path);
        intern->artifact_path = NULL;
    }
    king_inference_cuda_logits_readback_release(intern);
    king_inference_cuda_output_projection_release(intern);
    king_inference_cuda_ffn_swiglu_release(intern);
    king_inference_cuda_attention_values_release(intern);
    king_inference_cuda_attention_softmax_release(intern);
    king_inference_cuda_attention_scores_release(intern);
    king_inference_cuda_rope_release(intern);
    king_inference_cuda_rms_norm_release(intern);
    king_inference_cuda_device_kv_cache_release(intern);
    king_inference_cuda_device_vector_ops_release(intern);
    king_inference_cuda_embedding_row_release(intern);
    king_inference_cuda_quantized_matvec_release(intern);
    king_inference_cuda_weight_upload_release(intern);
    king_inference_cuda_device_allocator_release(intern);
    king_inference_cuda_context_release(intern);
    king_inference_native_mapping_release(intern);
    king_inference_gguf_metadata_release(&intern->gguf);

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_inference_model_object_create(zend_class_entry *ce)
{
    king_inference_model_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->config);
    array_init(&intern->config);
    ZVAL_UNDEF(&intern->tensor_index);
    array_init(&intern->tensor_index);
    ZVAL_UNDEF(&intern->tokenizer_tokens);
    array_init(&intern->tokenizer_tokens);
    ZVAL_UNDEF(&intern->tokenizer_lookup);
    array_init(&intern->tokenizer_lookup);
    ZVAL_UNDEF(&intern->tokenizer_merges);
    array_init(&intern->tokenizer_merges);
    ZVAL_UNDEF(&intern->paged_kv_cache_plan);
    array_init(&intern->paged_kv_cache_plan);
    intern->name = NULL;
    intern->artifact_path = NULL;
    intern->native_map = NULL;
    intern->native_map_size = 0;
    intern->native_map_loaded = false;
    king_inference_cuda_context_reset_fields(intern);
    king_inference_cuda_device_allocator_reset_fields(intern);
    king_inference_cuda_weight_upload_reset_fields(intern);
    king_inference_cuda_quantized_matvec_reset_fields(intern);
    king_inference_cuda_embedding_row_reset_fields(intern);
    king_inference_cuda_device_vector_ops_reset_fields(intern);
    king_inference_cuda_device_kv_cache_reset_fields(intern);
    king_inference_cuda_rms_norm_reset_fields(intern);
    king_inference_cuda_rope_reset_fields(intern);
    king_inference_cuda_attention_scores_reset_fields(intern);
    king_inference_cuda_attention_softmax_reset_fields(intern);
    king_inference_cuda_attention_values_reset_fields(intern);
    king_inference_cuda_ffn_swiglu_reset_fields(intern);
    king_inference_cuda_output_projection_reset_fields(intern);
    king_inference_cuda_logits_readback_reset_fields(intern);
    memset(&intern->gguf, 0, sizeof(intern->gguf));
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_inference_model_object_handlers;

    return &intern->std;
}

static void king_inference_stream_object_free(zend_object *object)
{
    king_inference_stream_object *intern = php_king_inference_stream_obj_from_zend(object);

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
    if (intern->response_id != NULL) {
        zend_string_release(intern->response_id);
        intern->response_id = NULL;
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_inference_stream_object_create(zend_class_entry *ce)
{
    king_inference_stream_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->model);
    ZVAL_UNDEF(&intern->request);
    ZVAL_UNDEF(&intern->options);
    ZVAL_UNDEF(&intern->native_events);
    intern->native_event_index = 0;
    intern->stdout_fd = -1;
    intern->stderr_fd = -1;
    intern->child_pid = -1;
    intern->exit_code = -1;
    intern->chunk_count = 0;
    intern->stderr_count = 0;
    intern->bytes_emitted = 0;
    king_inference_stream_reset_decoder_metrics(intern);
    intern->created_at = 0;
    intern->gpu_thermal_preflight_at = 0;
    intern->gpu_thermal_abort_at = 0;
    intern->gpu_thermal_preflight_temperature_c = 0.0;
    intern->gpu_thermal_abort_temperature_c = 0.0;
    intern->gpu_thermal_abort_ceiling_c = 0.0;
    intern->response_id = NULL;
    intern->start_event_pending = true;
    intern->openai_compatible = false;
    intern->gpu_thermal_preflight_checked = false;
    intern->gpu_thermal_preflight_temperature_available = false;
    intern->gpu_thermal_aborted = false;
    intern->done = false;
    intern->cancelled = false;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_inference_stream_object_handlers;

    return &intern->std;
}

static void king_inference_model_object_handlers_init(void)
{
    memcpy(
        &king_inference_model_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_inference_model_object_handlers.offset = XtOffsetOf(king_inference_model_object, std);
    king_inference_model_object_handlers.free_obj = king_inference_model_object_free;
    king_inference_model_object_handlers.clone_obj = NULL;
    king_ce_inference_model->create_object = king_inference_model_object_create;
}

static void king_inference_stream_object_handlers_init(void)
{
    memcpy(
        &king_inference_stream_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_inference_stream_object_handlers.offset = XtOffsetOf(king_inference_stream_object, std);
    king_inference_stream_object_handlers.free_obj = king_inference_stream_object_free;
    king_inference_stream_object_handlers.clone_obj = NULL;
    king_ce_inference_stream->create_object = king_inference_stream_object_create;
}
