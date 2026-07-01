/*
 * CUDA decoder graph block loop for full native GPU token decoding.
 */

static zend_result king_inference_cuda_decoder_graph_executor_execute_remaining_blocks(
    king_inference_model_object *model,
    zval *graph,
    zend_string **current_id,
    king_inference_cuda_device_ptr *current_output,
    zend_ulong *current_width,
    zval *slice_probe,
    zval *rope_probe,
    zval *kv_head_prepare_probe,
    zval *attention_projection_probe,
    zval *attention_residual_probe,
    zval *ffn_norm_probe,
    zval *ffn_gate_up_probe,
    zval *ffn_swiglu_probe,
    zval *ffn_down_probe,
    zval *ffn_output_probe,
    zval *final_norm_probe,
    zval *logits_probe,
    zend_ulong *executed_device_ops,
    bool *attention_projection_executed,
    bool *attention_residual_executed,
    bool *ffn_norm_executed,
    bool *ffn_gate_up_executed,
    bool *ffn_swiglu_executed,
    bool *ffn_down_executed,
    bool *ffn_output_executed,
    bool *final_norm_executed,
    bool *logits_executed
) {
    zend_ulong guard = 0;
    zend_ulong guard_limit = model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT] + 2;

    while (*current_output != 0 && *current_id != NULL && guard++ < guard_limit) {
        zval *norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, *current_id, "rms_norm");
        zval *query_op;
        zend_string *norm_id = NULL;
        zend_string *query_id = NULL;
        zend_string *next_id = NULL;
        king_inference_cuda_device_ptr norm_output = 0;
        king_inference_cuda_device_ptr query_output = 0;
        king_inference_cuda_device_ptr attention_stack_output = 0;
        king_inference_cuda_device_ptr next_output = 0;
        zend_ulong norm_width = 0;
        zend_ulong query_rows = 0;
        zend_ulong attention_stack_width = 0;
        zend_ulong next_width = 0;
        zend_ulong slice_length = 0;
        bool rope_executed = false;
        bool kv_prepared = false;

        if (norm_op == NULL) {
            break;
        }
        if (king_inference_graph_required_string(norm_op, "id", &norm_id) != SUCCESS) {
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_block_norm_id_invalid");
            return FAILURE;
        }
        if (zend_string_equals_literal(norm_id, "final_norm")) {
            if (king_inference_cuda_decoder_graph_executor_execute_final_norm(
                    model,
                    graph,
                    *current_id,
                    *current_output,
                    *current_width,
                    final_norm_probe,
                    &norm_output,
                    &norm_width,
                    &norm_id,
                    executed_device_ops
            ) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &norm_output);
                return FAILURE;
            }
            *final_norm_executed = norm_output != 0 && norm_width > 0;
            if (*final_norm_executed
                && king_inference_cuda_decoder_graph_executor_execute_logits_projection(
                    model,
                    graph,
                    norm_id,
                    norm_output,
                    norm_width,
                    logits_probe,
                    &query_output,
                    &query_rows,
                    &query_id,
                    executed_device_ops
            ) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &norm_output);
                return FAILURE;
            }
            *logits_executed = query_output != 0 && query_rows > 0 && query_id != NULL;
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &norm_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, current_output);
            return SUCCESS;
        }
        if (king_inference_cuda_decoder_graph_executor_execute_ffn_norm(
                model,
                graph,
                *current_id,
                *current_output,
                *current_width,
                ffn_norm_probe,
                &norm_output,
                &norm_width,
                &norm_id,
                executed_device_ops
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &norm_output);
            return FAILURE;
        }
        query_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, norm_id, "linear", 0);
        if (query_op == NULL
            || king_inference_graph_required_string(query_op, "id", &query_id) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_linear_to_device(
                model,
                query_op,
                norm_output,
                norm_width,
                &query_output,
                &query_rows
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &norm_output);
            return FAILURE;
        }
        if (king_inference_cuda_decoder_graph_executor_execute_query_attention_heads(
                model,
                graph,
                norm_id,
                NULL,
                norm_output,
                norm_width,
                0,
                0.000001,
                query_id,
                query_output,
                query_rows,
                slice_probe,
                rope_probe,
                kv_head_prepare_probe,
                &attention_stack_output,
                &attention_stack_width,
                &next_id,
                executed_device_ops,
                &slice_length,
                &rope_executed,
                &kv_prepared
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_stack_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &norm_output);
            return FAILURE;
        }
        (void) slice_length;
        (void) rope_executed;
        (void) kv_prepared;
        if (king_inference_cuda_decoder_graph_executor_execute_attention_projection_residual_ffn_norm_gate_up_swiglu_down_and_output(
                model,
                graph,
                *current_id,
                *current_output,
                *current_width,
                next_id,
                attention_stack_output,
                attention_stack_width,
                attention_projection_probe,
                attention_residual_probe,
                ffn_norm_probe,
                ffn_gate_up_probe,
                ffn_swiglu_probe,
                ffn_down_probe,
                ffn_output_probe,
                final_norm_probe,
                logits_probe,
                executed_device_ops,
                attention_projection_executed,
                attention_residual_executed,
                ffn_norm_executed,
                ffn_gate_up_executed,
                ffn_swiglu_executed,
                ffn_down_executed,
                ffn_output_executed,
                final_norm_executed,
                logits_executed,
                &next_id,
                &next_output,
                &next_width
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &next_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_stack_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &norm_output);
            return FAILURE;
        }
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_stack_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &norm_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, current_output);
        if (*logits_executed || next_output == 0 || next_id == NULL || next_width == 0) {
            *current_id = next_id;
            *current_output = next_output;
            *current_width = next_width;
            return SUCCESS;
        }
        *current_id = next_id;
        *current_output = next_output;
        *current_width = next_width;
    }

    if (!*logits_executed) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_block_loop_terminal_unreached");
        return FAILURE;
    }
    return SUCCESS;
}
