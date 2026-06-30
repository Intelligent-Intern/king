#include "cuda_decoder_graph_executor_device_helpers.inc"
#include "../../ops/rope/cuda_decoder_graph_executor_rope_ops.inc"
#include "../../ops/kv/cuda_decoder_graph_executor_kv_slice_lookup.inc"
#include "../../ops/kv/cuda_decoder_graph_executor_kv_ops.inc"
#include "../../ops/projection/cuda_decoder_graph_executor_query_ops.inc"
#include "../../ops/projection/cuda_decoder_graph_executor_projection_ops.inc"
#include "../../ops/norm/cuda_decoder_graph_executor_norm_ops.inc"
#include "../../ops/final/cuda_decoder_graph_executor_final_ops.inc"
#include "../../ops/ffn/cuda_decoder_graph_executor_ffn_ops.inc"
#include "../../ops/control/cuda_decoder_graph_executor_block_loop_ops.inc"
#include "../../ops/control/cuda_decoder_graph_executor_probe_ops.inc"
#include "../../prefill/ffn/cuda_decoder_graph_executor_prefilled_ffn_ops.inc"
#include "../../prefill/residual/cuda_decoder_graph_executor_prefilled_residual_ops.inc"
#include "../compare/embedding/cuda_decoder_graph_executor_embedding_compare.inc"
#include "../compare/norm/cuda_decoder_graph_executor_first_rms_norm_compare.inc"
#include "../compare/matvec/cuda_decoder_graph_executor_q4_matvec_compare.inc"

static zend_result king_inference_cuda_decoder_graph_executor_execute_initial_device_ops(
    king_inference_model_object *model,
    zval *graph,
    zval *result
) {
    zval *embedding_op = king_inference_cuda_decoder_graph_executor_first_op(graph, "embedding");
    zval *rms_norm_op = NULL;
    zval *token_value;
    zval *prefill_batch_index_value;
    zval embedding_probe;
    zval rms_norm_probe;
    zval linear_probe;
    zval slice_probe;
    zval rope_probe;
    zval kv_head_prepare_probe;
    zval attention_projection_probe;
    zval attention_residual_probe;
    zval ffn_norm_probe;
    zval ffn_gate_up_probe;
    zval ffn_swiglu_probe;
    zval ffn_down_probe;
    zval ffn_output_probe;
    zval final_norm_probe;
    zval logits_probe;
    zend_string *embedding_id = NULL;
    zend_string *rms_norm_id = NULL;
    zend_string *rms_weight = NULL;
    zend_string *linear_id = NULL;
    zend_string *linear_weight = NULL;
    zend_string *attention_stack_id = NULL;
    zend_ulong width = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong linear_rows = 0;
    zend_ulong slice_length = 0;
    zend_ulong token_id;
    zend_ulong prefill_batch_index = 0;
    size_t bytes;
    king_inference_cuda_device_ptr embedding_output = 0;
    king_inference_cuda_device_ptr rms_norm_output = 0;
    king_inference_cuda_device_ptr linear_output = 0;
    king_inference_cuda_device_ptr attention_stack_output = 0;
    king_inference_cuda_device_ptr next_hidden_output = 0;
    zend_string *next_hidden_id = NULL;
    zend_ulong attention_stack_width = 0;
    zend_ulong next_hidden_width = 0;
    zend_ulong executed_device_ops = 0;
    bool rope_executed = false;
    bool kv_head_prepared = false;
    bool attention_projection_probe_initialized = false;
    bool attention_residual_probe_initialized = false;
    bool ffn_norm_probe_initialized = false;
    bool ffn_gate_up_probe_initialized = false;
    bool ffn_swiglu_probe_initialized = false, ffn_down_probe_initialized = false, ffn_output_probe_initialized = false, final_norm_probe_initialized = false, logits_probe_initialized = false;
    bool attention_projection_executed = false;
    bool attention_residual_executed = false;
    bool ffn_norm_executed = false;
    bool ffn_gate_up_executed = false;
    bool ffn_swiglu_executed = false, ffn_down_executed = false, ffn_output_executed = false, final_norm_executed = false, logits_executed = false;
    bool use_prefill_embedding = false;
    bool used_prefill_embedding = false;
    bool used_prefill_initial_norm = false;
    bool used_prefill_initial_linear = false;
    bool used_prefill_attention_residual = false;
    double epsilon = 0.000001;
    double embedding_scale = 1.0;

    array_init(&embedding_probe);
    add_assoc_string(&embedding_probe, "type", "gpu_decoder_embedding_device_execution");
    add_assoc_bool(&embedding_probe, "attempted", embedding_op != NULL);
    add_assoc_bool(&embedding_probe, "available", model->cuda_decoder_graph_executor_embedding_execution_available);
    add_assoc_bool(&embedding_probe, "executed", false);
    add_assoc_bool(&embedding_probe, "prefill_copy_available", model->cuda_decoder_prompt_prefill_embeddings_active);
    add_assoc_bool(&embedding_probe, "retained_device_output", false);
    array_init(&rms_norm_probe);
    add_assoc_string(&rms_norm_probe, "type", "gpu_decoder_rms_norm_device_execution");
    add_assoc_bool(&rms_norm_probe, "attempted", false);
    add_assoc_bool(&rms_norm_probe, "available", model->cuda_decoder_graph_executor_rms_norm_execution_available);
    add_assoc_bool(&rms_norm_probe, "executed", false);
    add_assoc_bool(&rms_norm_probe, "retained_device_output", false);
    array_init(&linear_probe);
    add_assoc_string(&linear_probe, "type", "gpu_decoder_linear_device_execution");
    add_assoc_bool(&linear_probe, "attempted", false);
    add_assoc_bool(&linear_probe, "available", model->cuda_decoder_graph_executor_linear_execution_available);
    add_assoc_bool(&linear_probe, "executed", false);
    add_assoc_bool(&linear_probe, "retained_device_output", false);
    array_init(&slice_probe);
    add_assoc_string(&slice_probe, "type", "gpu_decoder_slice_device_execution");
    add_assoc_bool(&slice_probe, "attempted", false);
    add_assoc_bool(&slice_probe, "available", model->cuda_decoder_graph_executor_slice_execution_available);
    add_assoc_bool(&slice_probe, "executed", false);
    add_assoc_bool(&slice_probe, "retained_device_output", false);
    array_init(&rope_probe);
    add_assoc_string(&rope_probe, "type", "gpu_decoder_rope_device_execution");
    add_assoc_bool(&rope_probe, "attempted", false);
    add_assoc_bool(&rope_probe, "available", model->cuda_decoder_graph_executor_rope_execution_available);
    add_assoc_bool(&rope_probe, "executed", false);
    add_assoc_bool(&rope_probe, "retained_device_output", false);
    array_init(&kv_head_prepare_probe);
    add_assoc_string(&kv_head_prepare_probe, "type", "gpu_decoder_kv_head_prepare_device_execution");
    add_assoc_bool(&kv_head_prepare_probe, "attempted", false);
    add_assoc_bool(&kv_head_prepare_probe, "available", model->cuda_decoder_graph_executor_kv_head_prepare_execution_available);
    add_assoc_bool(&kv_head_prepare_probe, "executed", false);
    add_assoc_bool(&kv_head_prepare_probe, "retained_device_output", false);

    if (embedding_op == NULL) {
        king_inference_cuda_decoder_graph_executor_add_empty_initial_result(result, &embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_embedding_execution_available) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_embedding_execution_unavailable"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(embedding_op, "id", &embedding_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_embedding_id_invalid");
        return FAILURE;
    }
    if (king_inference_graph_finite_double_option(embedding_op, "scale", 1.0, &embedding_scale) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_embedding_scale_invalid");
        return FAILURE;
    }

    token_value = king_inference_array_find(embedding_op, "token_id");
    if (token_value == NULL) {
        token_value = king_inference_array_find(embedding_op, "token");
    }
    if (token_value == NULL || Z_TYPE_P(token_value) != IS_LONG || Z_LVAL_P(token_value) < 0) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_embedding_token_invalid"
        );
        return FAILURE;
    }
    prefill_batch_index_value = king_inference_array_find(embedding_op, "prefill_batch_index");
    if (prefill_batch_index_value != NULL) {
        if (Z_TYPE_P(prefill_batch_index_value) != IS_LONG || Z_LVAL_P(prefill_batch_index_value) < 0) {
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                -1,
                "cuda_decoder_graph_embedding_prefill_batch_index_invalid"
            );
            return FAILURE;
        }
        prefill_batch_index = (zend_ulong) Z_LVAL_P(prefill_batch_index_value);
        use_prefill_embedding = true;
    }
    if (width == 0 || width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_embedding_width_invalid"
        );
        return FAILURE;
    }

    token_id = (zend_ulong) Z_LVAL_P(token_value);
    bytes = (size_t) width * sizeof(float);
    if (king_inference_cuda_device_allocator_alloc(model, bytes, &embedding_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_embedding_allocation_failed"
        );
        return FAILURE;
    }

    if (use_prefill_embedding) {
        if (king_inference_cuda_embedding_prefill_copy_row(
                model,
                prefill_batch_index,
                embedding_output,
                width
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_embedding_row_result,
                model->cuda_embedding_row_error[0] != '\0'
                    ? model->cuda_embedding_row_error
                    : "cuda_decoder_graph_embedding_prefill_copy_failed"
            );
            return FAILURE;
        }
        used_prefill_embedding = true;
    } else if (king_inference_cuda_embedding_row_load(model, token_id, embedding_output, width, embedding_scale) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_embedding_row_result,
            model->cuda_embedding_row_error[0] != '\0'
                ? model->cuda_embedding_row_error
                : "cuda_decoder_graph_embedding_execution_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_embedding_execution_count++;
    model->cuda_decoder_graph_executor_last_embedding_width = width;
    model->cuda_decoder_graph_executor_last_embedding_token_id = token_id;
    model->cuda_decoder_graph_executor_last_embedding_token_available = true;
    executed_device_ops++;

    add_assoc_bool(&embedding_probe, "executed", true);
    add_assoc_bool(&embedding_probe, "used_prefill_embedding", used_prefill_embedding);
    add_assoc_long(&embedding_probe, "token_id", (zend_long) token_id);
    if (used_prefill_embedding) {
        add_assoc_long(&embedding_probe, "prefill_batch_index", (zend_long) prefill_batch_index);
    }
    add_assoc_long(&embedding_probe, "width", (zend_long) width);
    add_assoc_long(&embedding_probe, "bytes", (zend_long) bytes);
    add_assoc_double(&embedding_probe, "scale", embedding_scale);
    if (!used_prefill_embedding && king_inference_cuda_decoder_graph_executor_compare_embedding_row(model, token_id, embedding_output, width, embedding_scale, &embedding_probe) != SUCCESS) {
        add_assoc_bool(&embedding_probe, "numeric_compare_failed", true);
    }

    rms_norm_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, embedding_id, "rms_norm");
    add_assoc_bool(&rms_norm_probe, "attempted", rms_norm_op != NULL);
    if (rms_norm_op != NULL) {
        zval *linear_op = NULL;

        if (!model->cuda_decoder_graph_executor_rms_norm_execution_available) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                -1,
                "cuda_decoder_graph_rms_norm_execution_unavailable"
            );
            return FAILURE;
        }
        if (king_inference_graph_required_string(rms_norm_op, "id", &rms_norm_id) != SUCCESS
            || king_inference_graph_required_string(rms_norm_op, "weight", &rms_weight) != SUCCESS
            || king_inference_graph_finite_double_option(rms_norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
            || epsilon <= 0.0) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_rms_norm_contract_invalid");
            return FAILURE;
        }
        if (king_inference_cuda_device_allocator_alloc(model, bytes, &rms_norm_output) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_device_allocator_result,
                model->cuda_device_allocator_error[0] != '\0'
                    ? model->cuda_device_allocator_error
                    : "cuda_decoder_graph_rms_norm_allocation_failed"
            );
            return FAILURE;
        }
        if (use_prefill_embedding && model->cuda_decoder_prompt_prefill_initial_norm != 0) {
            if (king_inference_cuda_prefill_copy_initial_norm_row(
                    model,
                    prefill_batch_index,
                    rms_norm_output,
                    width
            ) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    model->cuda_embedding_row_result,
                    model->cuda_embedding_row_error[0] != '\0'
                        ? model->cuda_embedding_row_error
                        : "cuda_decoder_graph_prefill_initial_norm_copy_failed"
                );
                return FAILURE;
            }
            used_prefill_initial_norm = true;
        } else if (king_inference_cuda_rms_norm_f32(
                    model,
                    rms_weight,
                    embedding_output,
                    rms_norm_output,
                    width,
                    epsilon
        ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_rms_norm_result,
                model->cuda_rms_norm_error[0] != '\0'
                    ? model->cuda_rms_norm_error
                    : "cuda_decoder_graph_rms_norm_execution_failed"
            );
            return FAILURE;
        }
        model->cuda_decoder_graph_executor_rms_norm_execution_count++;
        model->cuda_decoder_graph_executor_last_rms_norm_width = width;
        executed_device_ops++;

        add_assoc_bool(&rms_norm_probe, "executed", true);
        add_assoc_bool(&rms_norm_probe, "used_prefill_initial_norm", used_prefill_initial_norm);
        add_assoc_long(&rms_norm_probe, "width", (zend_long) width);
        add_assoc_long(&rms_norm_probe, "bytes", (zend_long) bytes);
        add_assoc_double(&rms_norm_probe, "epsilon", epsilon);
        king_inference_cuda_decoder_graph_executor_record_first_rms_norm_compare(model, token_id, rms_weight, rms_norm_output, width, epsilon, embedding_scale, &rms_norm_probe);

        linear_op = king_inference_cuda_decoder_graph_executor_op_for_input(graph, rms_norm_id, "linear");
        add_assoc_bool(&linear_probe, "attempted", linear_op != NULL);
        if (linear_op != NULL) {
            if (!model->cuda_decoder_graph_executor_linear_execution_available) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    -1,
                    "cuda_decoder_graph_linear_execution_unavailable"
                );
                return FAILURE;
            }
            if (king_inference_graph_required_string(linear_op, "id", &linear_id) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_linear_id_invalid");
                return FAILURE;
            }
            if (king_inference_cuda_decoder_graph_executor_linear_shape(
                    model,
                    linear_op,
                    width,
                    &linear_weight,
                    &linear_rows
                ) != SUCCESS
                || linear_rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                return FAILURE;
            }
            if (king_inference_cuda_device_allocator_alloc(
                    model,
                    (size_t) linear_rows * sizeof(float),
                    &linear_output
                ) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    model->cuda_device_allocator_result,
                    model->cuda_device_allocator_error[0] != '\0'
                        ? model->cuda_device_allocator_error
                        : "cuda_decoder_graph_linear_allocation_failed"
                );
                return FAILURE;
            }
            if (use_prefill_embedding && model->cuda_decoder_prompt_prefill_initial_linear != 0) {
                if (king_inference_cuda_prefill_copy_initial_linear_row(
                        model,
                        prefill_batch_index,
                        linear_output,
                        linear_rows
                ) != SUCCESS) {
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                    king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                    king_inference_cuda_decoder_graph_executor_set_error(
                        model,
                        model->cuda_embedding_row_result,
                        model->cuda_embedding_row_error[0] != '\0'
                            ? model->cuda_embedding_row_error
                            : "cuda_decoder_graph_prefill_initial_linear_copy_failed"
                    );
                    return FAILURE;
                }
                used_prefill_initial_linear = true;
            } else if (king_inference_cuda_quantized_matvec(
                        model,
                        linear_weight,
                        rms_norm_output,
                        width,
                        linear_output,
                        linear_rows
            ) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    model->cuda_quantized_matvec_result,
                    model->cuda_quantized_matvec_error[0] != '\0'
                        ? model->cuda_quantized_matvec_error
                        : "cuda_decoder_graph_linear_execution_failed"
                );
                return FAILURE;
            }
            model->cuda_decoder_graph_executor_linear_execution_count++;
            model->cuda_decoder_graph_executor_last_linear_input_width = width;
            model->cuda_decoder_graph_executor_last_linear_rows = linear_rows;
            executed_device_ops++;

            add_assoc_bool(&linear_probe, "executed", true);
            add_assoc_bool(&linear_probe, "used_prefill_initial_linear", used_prefill_initial_linear);
            add_assoc_long(&linear_probe, "input_width", (zend_long) width);
            add_assoc_long(&linear_probe, "rows", (zend_long) linear_rows);
            add_assoc_long(&linear_probe, "bytes", (zend_long) ((size_t) linear_rows * sizeof(float)));
            king_inference_cuda_decoder_graph_executor_record_quantized_matvec_compare(model, token_id, rms_weight, linear_weight, linear_output, width, linear_rows, epsilon, &linear_probe);
            if (use_prefill_embedding
                && king_inference_cuda_decoder_graph_executor_try_execute_prefilled_layer0_attention_residual(
                    model,
                    graph,
                    embedding_id,
                    width,
                    prefill_batch_index,
                    &slice_probe,
                    &rope_probe,
                    &kv_head_prepare_probe,
                    &attention_projection_probe,
                    &attention_residual_probe,
                    &ffn_norm_probe,
                    &ffn_gate_up_probe,
                    &ffn_swiglu_probe,
                    &ffn_down_probe,
                    &ffn_output_probe,
                    &final_norm_probe,
                    &logits_probe,
                    &attention_projection_probe_initialized,
                    &attention_residual_probe_initialized,
                    &ffn_norm_probe_initialized,
                    &ffn_gate_up_probe_initialized,
                    &ffn_swiglu_probe_initialized,
                    &ffn_down_probe_initialized,
                    &ffn_output_probe_initialized,
                    &final_norm_probe_initialized,
                    &logits_probe_initialized,
                    &executed_device_ops,
                    &used_prefill_attention_residual,
                    &attention_projection_executed,
                    &attention_residual_executed,
                    &ffn_norm_executed,
                    &ffn_gate_up_executed,
                    &ffn_swiglu_executed,
                    &ffn_down_executed,
                    &ffn_output_executed,
                    &final_norm_executed,
                    &logits_executed,
                    &next_hidden_id,
                    &next_hidden_output,
                    &next_hidden_width
            ) != SUCCESS) {
                king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(attention_projection_probe_initialized, &attention_projection_probe, attention_residual_probe_initialized, &attention_residual_probe, ffn_norm_probe_initialized, &ffn_norm_probe, ffn_gate_up_probe_initialized, &ffn_gate_up_probe, ffn_swiglu_probe_initialized, &ffn_swiglu_probe, ffn_down_probe_initialized, &ffn_down_probe, ffn_output_probe_initialized, &ffn_output_probe, final_norm_probe_initialized, &final_norm_probe, logits_probe_initialized, &logits_probe);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                return FAILURE;
            }
            if (used_prefill_attention_residual) {
                slice_length = model->cuda_device_kv_cache_key_length;
                attention_stack_width = model->cuda_decoder_prompt_prefill_layer0_attention_stack_width;
                rope_executed = false;
                kv_head_prepared = true;
            }

            if (use_prefill_embedding && !used_prefill_attention_residual) {
                bool used_prefill_attention_stack = false;

                if (king_inference_cuda_decoder_graph_executor_try_prefilled_layer0_attention_stack(
                        model,
                        graph,
                        prefill_batch_index,
                        &slice_probe,
                        &rope_probe,
                        &kv_head_prepare_probe,
                        &attention_stack_output,
                        &attention_stack_width,
                        &attention_stack_id,
                        &executed_device_ops,
                        &used_prefill_attention_stack
                ) != SUCCESS) {
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_stack_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                    king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                    return FAILURE;
                }
                if (used_prefill_attention_stack) {
                    slice_length = model->cuda_device_kv_cache_key_length;
                    rope_executed = false;
                    kv_head_prepared = true;
                }
            }

            if (!kv_head_prepared
                && king_inference_cuda_decoder_graph_executor_execute_query_attention_heads(
                    model,
                    graph,
                    rms_norm_id, rms_weight, rms_norm_output, width, token_id, epsilon,
                    linear_id,
                    linear_output,
                    linear_rows,
                    &slice_probe,
                    &rope_probe,
                    &kv_head_prepare_probe,
                    &attention_stack_output,
                    &attention_stack_width,
                    &attention_stack_id,
                    &executed_device_ops,
                    &slice_length,
                    &rope_executed,
                    &kv_head_prepared
            ) != SUCCESS) {
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_stack_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                return FAILURE;
            }
            king_inference_cuda_decoder_graph_executor_copy_block0_qkv_projection_compares(result, &linear_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_copy_block0_attention_score_compare(result, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_copy_block0_attention_softmax_compare(result, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_copy_block0_attention_value_compare(result, &kv_head_prepare_probe);
            if (!used_prefill_attention_residual && attention_stack_output != 0 && attention_stack_id != NULL) {
                array_init(&attention_projection_probe);
                array_init(&attention_residual_probe);
                array_init(&ffn_norm_probe);
                array_init(&ffn_gate_up_probe);
                array_init(&ffn_swiglu_probe);
                array_init(&ffn_down_probe);
                array_init(&ffn_output_probe);
                array_init(&final_norm_probe);
                array_init(&logits_probe);
                attention_projection_probe_initialized = true;
                attention_residual_probe_initialized = true;
                ffn_norm_probe_initialized = true;
                ffn_gate_up_probe_initialized = true;
                ffn_swiglu_probe_initialized = true;
                ffn_down_probe_initialized = true;
                ffn_output_probe_initialized = true;
                final_norm_probe_initialized = true;
                logits_probe_initialized = true;
                if (king_inference_cuda_decoder_graph_executor_execute_attention_projection_residual_ffn_norm_gate_up_swiglu_down_and_output(
                        model,
                        graph,
                        embedding_id,
                        embedding_output,
                        width,
                        attention_stack_id,
                        attention_stack_output,
                        attention_stack_width,
                        &attention_projection_probe,
                        &attention_residual_probe,
                        &ffn_norm_probe,
                        &ffn_gate_up_probe,
                        &ffn_swiglu_probe,
                        &ffn_down_probe,
                        &ffn_output_probe,
                        &final_norm_probe,
                        &logits_probe,
                        &executed_device_ops,
                        &attention_projection_executed,
                        &attention_residual_executed,
                        &ffn_norm_executed,
                        &ffn_gate_up_executed,
                        &ffn_swiglu_executed,
                        &ffn_down_executed,
                        &ffn_output_executed,
                        &final_norm_executed,
                        &logits_executed,
                        &next_hidden_id,
                        &next_hidden_output,
                        &next_hidden_width
                ) != SUCCESS) {
                    king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(true, &attention_projection_probe, true, &attention_residual_probe, true, &ffn_norm_probe, true, &ffn_gate_up_probe, true, &ffn_swiglu_probe, true, &ffn_down_probe, true, &ffn_output_probe, true, &final_norm_probe, true, &logits_probe);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_stack_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                    king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                    return FAILURE;
                }
                if (!logits_executed
                    && next_hidden_output != 0
                    && king_inference_cuda_decoder_graph_executor_execute_remaining_blocks(
                        model,
                        graph,
                        &next_hidden_id,
                        &next_hidden_output,
                        &next_hidden_width,
                        &slice_probe,
                        &rope_probe,
                        &kv_head_prepare_probe,
                        &attention_projection_probe,
                        &attention_residual_probe,
                        &ffn_norm_probe,
                        &ffn_gate_up_probe,
                        &ffn_swiglu_probe,
                        &ffn_down_probe,
                        &ffn_output_probe,
                        &final_norm_probe,
                        &logits_probe,
                        &executed_device_ops,
                        &attention_projection_executed,
                        &attention_residual_executed,
                        &ffn_norm_executed,
                        &ffn_gate_up_executed,
                        &ffn_swiglu_executed,
                        &ffn_down_executed,
                        &ffn_output_executed,
                        &final_norm_executed,
                        &logits_executed
                ) != SUCCESS) {
                    king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(true, &attention_projection_probe, true, &attention_residual_probe, true, &ffn_norm_probe, true, &ffn_gate_up_probe, true, &ffn_swiglu_probe, true, &ffn_down_probe, true, &ffn_output_probe, true, &final_norm_probe, true, &logits_probe);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &next_hidden_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_stack_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                    (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                    king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                    return FAILURE;
                }
            }
            if (attention_stack_output != 0
                && king_inference_cuda_decoder_graph_executor_release_device_ptr(
                    model,
                    &attention_stack_output
            ) != SUCCESS) {
                king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(attention_projection_probe_initialized, &attention_projection_probe, attention_residual_probe_initialized, &attention_residual_probe, ffn_norm_probe_initialized, &ffn_norm_probe, ffn_gate_up_probe_initialized, &ffn_gate_up_probe, ffn_swiglu_probe_initialized, &ffn_swiglu_probe, ffn_down_probe_initialized, &ffn_down_probe, ffn_output_probe_initialized, &ffn_output_probe, final_norm_probe_initialized, &final_norm_probe, logits_probe_initialized, &logits_probe);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    model->cuda_device_allocator_result,
                    model->cuda_device_allocator_error[0] != '\0'
                        ? model->cuda_device_allocator_error
                        : "cuda_decoder_graph_attention_stack_free_failed"
                );
                return FAILURE;
            }
            add_assoc_bool(&kv_head_prepare_probe, "attention_stack_released", true);
            add_assoc_long(&kv_head_prepare_probe, "attention_stack_width", (zend_long) attention_stack_width);
            if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &linear_output) != SUCCESS) {
                king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(attention_projection_probe_initialized, &attention_projection_probe, attention_residual_probe_initialized, &attention_residual_probe, ffn_norm_probe_initialized, &ffn_norm_probe, ffn_gate_up_probe_initialized, &ffn_gate_up_probe, ffn_swiglu_probe_initialized, &ffn_swiglu_probe, ffn_down_probe_initialized, &ffn_down_probe, ffn_output_probe_initialized, &ffn_output_probe, final_norm_probe_initialized, &final_norm_probe, logits_probe_initialized, &logits_probe);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
                (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
                king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
                king_inference_cuda_decoder_graph_executor_set_error(
                    model,
                    model->cuda_device_allocator_result,
                    model->cuda_device_allocator_error[0] != '\0'
                        ? model->cuda_device_allocator_error
                        : "cuda_decoder_graph_linear_free_failed"
                );
                return FAILURE;
            }
        }

        if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output) != SUCCESS) {
            king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(attention_projection_probe_initialized, &attention_projection_probe, attention_residual_probe_initialized, &attention_residual_probe, ffn_norm_probe_initialized, &ffn_norm_probe, ffn_gate_up_probe_initialized, &ffn_gate_up_probe, ffn_swiglu_probe_initialized, &ffn_swiglu_probe, ffn_down_probe_initialized, &ffn_down_probe, ffn_output_probe_initialized, &ffn_output_probe, final_norm_probe_initialized, &final_norm_probe, logits_probe_initialized, &logits_probe);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_device_allocator_result,
                model->cuda_device_allocator_error[0] != '\0'
                    ? model->cuda_device_allocator_error
                    : "cuda_decoder_graph_rms_norm_free_failed"
            );
            return FAILURE;
        }
    }

    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(attention_projection_probe_initialized, &attention_projection_probe, attention_residual_probe_initialized, &attention_residual_probe, ffn_norm_probe_initialized, &ffn_norm_probe, ffn_gate_up_probe_initialized, &ffn_gate_up_probe, ffn_swiglu_probe_initialized, &ffn_swiglu_probe, ffn_down_probe_initialized, &ffn_down_probe, ffn_output_probe_initialized, &ffn_output_probe, final_norm_probe_initialized, &final_norm_probe, logits_probe_initialized, &logits_probe);
        king_inference_cuda_decoder_graph_executor_destroy_initial_probes(&embedding_probe, &rms_norm_probe, &linear_probe, &slice_probe, &rope_probe, &kv_head_prepare_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_embedding_free_failed"
        );
        return FAILURE;
    }
    {
        zval *compact_decode_result = king_inference_array_find(result, "compact_decode_result");

        if (compact_decode_result != NULL && zend_is_true(compact_decode_result)) {
            king_inference_cuda_decoder_graph_executor_copy_initial_numeric_compares(result, &embedding_probe, &rms_norm_probe, &linear_probe);
            king_inference_cuda_decoder_graph_executor_destroy_initial_probes(
                &embedding_probe,
                &rms_norm_probe,
                &linear_probe,
                &slice_probe,
                &rope_probe,
                &kv_head_prepare_probe
            );
            king_inference_cuda_decoder_graph_executor_destroy_downstream_probes(
                attention_projection_probe_initialized,
                &attention_projection_probe,
                attention_residual_probe_initialized,
                &attention_residual_probe,
                ffn_norm_probe_initialized,
                &ffn_norm_probe,
                ffn_gate_up_probe_initialized,
                &ffn_gate_up_probe,
                ffn_swiglu_probe_initialized,
                &ffn_swiglu_probe,
                ffn_down_probe_initialized,
                &ffn_down_probe,
                ffn_output_probe_initialized,
                &ffn_output_probe,
                final_norm_probe_initialized,
                &final_norm_probe,
                false,
                &logits_probe
            );
            if (logits_probe_initialized) {
                add_assoc_zval(result, "logits_projection_device_execution", &logits_probe);
            }
            add_assoc_bool(result, "compact_decode_result_applied", true);
            add_assoc_bool(result, "logits_projection_device_execution_ready", logits_executed);
            add_assoc_bool(result, "bounded_logits_result_ready", logits_executed);
            add_assoc_bool(result, "device_execution_result_ready", logits_executed);
            add_assoc_bool(result, "first_device_op_executed", true);
            add_assoc_bool(result, "initial_device_ops_executed", executed_device_ops > 1);
            add_assoc_long(result, "executed_device_ops", (zend_long) executed_device_ops);
            return SUCCESS;
        }
    }
    add_assoc_zval(result, "embedding_device_execution", &embedding_probe);
    add_assoc_zval(result, "rms_norm_device_execution", &rms_norm_probe);
    add_assoc_zval(result, "linear_device_execution", &linear_probe);
    add_assoc_zval(result, "slice_device_execution", &slice_probe);
    add_assoc_zval(result, "rope_device_execution", &rope_probe);
    add_assoc_zval(result, "kv_head_prepare_device_execution", &kv_head_prepare_probe);
    king_inference_cuda_decoder_graph_executor_add_downstream_probes(result, attention_projection_probe_initialized, &attention_projection_probe, attention_residual_probe_initialized, &attention_residual_probe, ffn_norm_probe_initialized, &ffn_norm_probe, ffn_gate_up_probe_initialized, &ffn_gate_up_probe, ffn_swiglu_probe_initialized, &ffn_swiglu_probe, ffn_down_probe_initialized, &ffn_down_probe, ffn_output_probe_initialized, &ffn_output_probe, final_norm_probe_initialized, &final_norm_probe, logits_probe_initialized, &logits_probe);
    add_assoc_bool(result, "embedding_device_execution_ready", true);
    add_assoc_bool(result, "rms_norm_device_execution_ready", rms_norm_op != NULL);
    add_assoc_bool(result, "linear_device_execution_ready", linear_rows > 0);
    add_assoc_bool(result, "slice_device_execution_ready", slice_length > 0);
    add_assoc_bool(result, "rope_device_execution_ready", rope_executed || kv_head_prepared);
    add_assoc_bool(result, "kv_head_prepare_device_execution_ready", kv_head_prepared);
    add_assoc_bool(result, "kv_write_device_execution_ready", kv_head_prepared);
    add_assoc_bool(result, "kv_attention_device_execution_ready", kv_head_prepared);
    add_assoc_bool(result, "attention_stack_slot_device_execution_ready", kv_head_prepared);
    add_assoc_bool(result, "attention_heads_device_execution_ready", kv_head_prepared);
    add_assoc_bool(result, "attention_stack_device_execution_ready", kv_head_prepared && attention_stack_width > 0);
    add_assoc_bool(result, "attention_output_projection_device_execution_ready", attention_projection_executed);
    add_assoc_bool(result, "attention_residual_device_execution_ready", attention_residual_executed);
    add_assoc_bool(result, "ffn_norm_device_execution_ready", ffn_norm_executed);
    add_assoc_bool(result, "ffn_gate_up_projection_device_execution_ready", ffn_gate_up_executed);
    add_assoc_bool(result, "ffn_swiglu_device_execution_ready", ffn_swiglu_executed);
    add_assoc_bool(result, "ffn_down_projection_device_execution_ready", ffn_down_executed);
    add_assoc_bool(result, "ffn_output_residual_device_execution_ready", ffn_output_executed);
    add_assoc_bool(result, "final_norm_device_execution_ready", final_norm_executed);
    add_assoc_bool(result, "logits_projection_device_execution_ready", logits_executed);
    add_assoc_bool(result, "bounded_logits_result_ready", logits_executed);
    add_assoc_bool(result, "device_execution_result_ready", logits_executed);
    add_assoc_bool(result, "first_device_op_executed", true);
    add_assoc_bool(result, "initial_device_ops_executed", executed_device_ops > 1);
    add_assoc_long(result, "executed_device_ops", (zend_long) executed_device_ops);
    return SUCCESS;
}
