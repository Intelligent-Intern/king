/*
 * CUDA decoder graph query-head fan-in helpers for native King GPU inference.
 */

static void king_inference_cuda_decoder_graph_executor_init_head_probe(
    zval *probe,
    const char *type,
    bool available
) {
    array_init(probe);
    add_assoc_string(probe, "type", type);
    add_assoc_bool(probe, "attempted", true);
    add_assoc_bool(probe, "available", available);
    add_assoc_bool(probe, "executed", false);
    add_assoc_bool(probe, "retained_device_output", false);
}

static void king_inference_cuda_decoder_graph_executor_destroy_query_head_probes(
    zval *head_probe,
    zval *slice_probe,
    zval *rope_probe,
    zval *kv_probe
) {
    zval_ptr_dtor(kv_probe);
    zval_ptr_dtor(rope_probe);
    zval_ptr_dtor(slice_probe);
    zval_ptr_dtor(head_probe);
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_query_attention_heads(
    king_inference_model_object *model,
    zval *graph,
    zend_string *rms_norm_id,
    king_inference_cuda_device_ptr rms_norm_output,
    zend_ulong width,
    zend_string *linear_id,
    king_inference_cuda_device_ptr linear_output,
    zend_ulong linear_rows,
    zval *slice_probe,
    zval *rope_probe,
    zval *kv_head_prepare_probe,
    king_inference_cuda_device_ptr *attention_stack_output,
    zend_ulong *attention_stack_width,
    zend_string **attention_stack_id,
    zend_ulong *executed_device_ops,
    zend_ulong *first_slice_length,
    bool *rope_executed,
    bool *kv_prepared
) {
    zval heads;
    zend_ulong head_index = 0;
    zend_ulong prepared_heads = 0;
    bool first_slice_recorded = false;

    *first_slice_length = 0;
    *rope_executed = false;
    *kv_prepared = false;
    array_init(&heads);

    while (true) {
        zval *slice_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(
            graph,
            linear_id,
            "slice",
            head_index
        );
        zval head_probe;
        zval head_slice_probe;
        zval head_rope_probe;
        zval head_kv_probe;
        zend_string *slice_id = NULL;
        king_inference_cuda_device_ptr slice_output = 0;
        zend_ulong slice_offset = 0;
        zend_ulong slice_length = 0;
        bool head_prepared = false;

        if (slice_op == NULL) {
            break;
        }
        if (!model->cuda_decoder_graph_executor_slice_execution_available) {
            zval_ptr_dtor(&heads);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                -1,
                "cuda_decoder_graph_slice_execution_unavailable"
            );
            return FAILURE;
        }
        array_init(&head_probe);
        add_assoc_string(&head_probe, "type", "gpu_decoder_query_attention_head_device_execution");
        add_assoc_long(&head_probe, "head_index", (zend_long) head_index);
        king_inference_cuda_decoder_graph_executor_init_head_probe(
            &head_slice_probe,
            "gpu_decoder_slice_device_execution",
            model->cuda_decoder_graph_executor_slice_execution_available
        );
        king_inference_cuda_decoder_graph_executor_init_head_probe(
            &head_rope_probe,
            "gpu_decoder_rope_device_execution",
            model->cuda_decoder_graph_executor_rope_execution_available
        );
        array_init(&head_kv_probe);
        add_assoc_string(&head_kv_probe, "type", "gpu_decoder_kv_head_prepare_device_execution");
        add_assoc_bool(&head_kv_probe, "attempted", true);
        add_assoc_bool(
            &head_kv_probe,
            "available",
            model->cuda_decoder_graph_executor_kv_head_prepare_execution_available
        );
        add_assoc_bool(&head_kv_probe, "executed", false);
        add_assoc_bool(&head_kv_probe, "retained_device_output", false);

        if (king_inference_graph_required_string(slice_op, "id", &slice_id) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_slice_to_device(
                model,
                slice_op,
                linear_output,
                linear_rows,
                &slice_output,
                &slice_offset,
                &slice_length
            ) != SUCCESS) {
            king_inference_cuda_decoder_graph_executor_destroy_query_head_probes(
                &head_probe,
                &head_slice_probe,
                &head_rope_probe,
                &head_kv_probe
            );
            zval_ptr_dtor(&heads);
            return FAILURE;
        }
        (*executed_device_ops)++;
        add_assoc_bool(&head_slice_probe, "executed", true);
        add_assoc_long(&head_slice_probe, "offset", (zend_long) slice_offset);
        add_assoc_long(&head_slice_probe, "length", (zend_long) slice_length);
        add_assoc_long(&head_slice_probe, "bytes", (zend_long) ((size_t) slice_length * sizeof(float)));
        if (!first_slice_recorded) {
            first_slice_recorded = true;
            *first_slice_length = slice_length;
            add_assoc_long(slice_probe, "offset", (zend_long) slice_offset);
            add_assoc_long(slice_probe, "length", (zend_long) slice_length);
            add_assoc_long(slice_probe, "bytes", (zend_long) ((size_t) slice_length * sizeof(float)));
            add_assoc_long(slice_probe, "first_offset", (zend_long) slice_offset);
            add_assoc_long(slice_probe, "first_length", (zend_long) slice_length);
        }

        if (king_inference_cuda_decoder_graph_executor_execute_kv_head_prepare(
                model,
                graph,
                rms_norm_id,
                rms_norm_output,
                width,
                slice_id,
                slice_output,
                slice_length,
                &head_rope_probe,
                &head_kv_probe,
                attention_stack_output,
                attention_stack_width,
                attention_stack_id,
                executed_device_ops,
                &head_prepared
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &slice_output);
            king_inference_cuda_decoder_graph_executor_destroy_query_head_probes(
                &head_probe,
                &head_slice_probe,
                &head_rope_probe,
                &head_kv_probe
            );
            zval_ptr_dtor(&heads);
            return FAILURE;
        }
        if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &slice_output) != SUCCESS) {
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_device_allocator_result,
                model->cuda_device_allocator_error[0] != '\0'
                    ? model->cuda_device_allocator_error
                    : "cuda_decoder_graph_query_slice_free_failed"
            );
            king_inference_cuda_decoder_graph_executor_destroy_query_head_probes(
                &head_probe,
                &head_slice_probe,
                &head_rope_probe,
                &head_kv_probe
            );
            zval_ptr_dtor(&heads);
            return FAILURE;
        }
        add_assoc_bool(&head_slice_probe, "retained_device_output", false);
        if (head_prepared) {
            prepared_heads++;
        }

        add_assoc_bool(&head_probe, "prepared", head_prepared);
        add_assoc_zval(&head_probe, "slice_execution", &head_slice_probe);
        add_assoc_zval(&head_probe, "rope_execution", &head_rope_probe);
        add_assoc_zval(&head_probe, "kv_head_prepare_execution", &head_kv_probe);
        add_next_index_zval(&heads, &head_probe);
        head_index++;
    }

    add_assoc_bool(slice_probe, "attempted", head_index > 0);
    add_assoc_bool(slice_probe, "executed", head_index > 0);
    add_assoc_long(slice_probe, "query_head_count", (zend_long) head_index);
    add_assoc_bool(rope_probe, "attempted", head_index > 0);
    add_assoc_bool(rope_probe, "executed", prepared_heads > 0);
    add_assoc_long(rope_probe, "query_head_count", (zend_long) head_index);
    add_assoc_bool(kv_head_prepare_probe, "attempted", head_index > 0);
    add_assoc_bool(kv_head_prepare_probe, "executed", prepared_heads > 0);
    add_assoc_long(kv_head_prepare_probe, "expected_heads", (zend_long) head_index);
    add_assoc_long(kv_head_prepare_probe, "prepared_heads", (zend_long) prepared_heads);
    add_assoc_bool(kv_head_prepare_probe, "all_heads_prepared", head_index > 0 && prepared_heads == head_index);
    add_assoc_long(kv_head_prepare_probe, "attention_stack_width", (zend_long) *attention_stack_width);
    add_assoc_bool(kv_head_prepare_probe, "attention_stack_retained", *attention_stack_output != 0);
    add_assoc_bool(
        kv_head_prepare_probe,
        "complete_attention_stack",
        head_index > 0 && prepared_heads == head_index && *attention_stack_output != 0
    );
    add_assoc_bool(kv_head_prepare_probe, "attention_stack_released", false);
    add_assoc_zval(kv_head_prepare_probe, "query_heads", &heads);

    *rope_executed = prepared_heads > 0;
    *kv_prepared = head_index > 0 && prepared_heads == head_index;
    model->cuda_decoder_graph_executor_last_attention_stack_expected_heads = head_index;
    model->cuda_decoder_graph_executor_last_attention_stack_prepared_heads = prepared_heads;
    if (*kv_prepared) {
        model->cuda_decoder_graph_executor_attention_heads_execution_count++;
        if (*attention_stack_output != 0) {
            model->cuda_decoder_graph_executor_attention_stack_execution_count++;
        }
    }
    return SUCCESS;
}
