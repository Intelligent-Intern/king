/*
 * CUDA decoder graph K/V head preparation helpers for native King GPU inference.
 */

#include "../../device/compare/matvec/cuda_decoder_graph_executor_matvec_compare_helpers.inc"
#include "../../device/compare/projection/cuda_decoder_graph_executor_qkv_projection_compare.inc"

static zend_result king_inference_cuda_decoder_graph_executor_linear_to_device(
    king_inference_model_object *model,
    zval *linear_op,
    king_inference_cuda_device_ptr input,
    zend_ulong input_width,
    king_inference_cuda_device_ptr *output,
    zend_ulong *rows
) {
    zend_string *weight = NULL;

    *output = 0;
    *rows = 0;
    if (linear_op == NULL
        || input == 0
        || king_inference_cuda_decoder_graph_executor_linear_shape(
            model,
            linear_op,
            input_width,
            &weight,
            rows
        ) != SUCCESS
        || *rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(model, (size_t) *rows * sizeof(float), output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_linear_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_quantized_matvec(model, weight, input, input_width, *output, *rows) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_quantized_matvec_result,
            model->cuda_quantized_matvec_error[0] != '\0'
                ? model->cuda_quantized_matvec_error
                : "cuda_decoder_graph_kv_linear_execution_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_linear_execution_count++;
    model->cuda_decoder_graph_executor_last_linear_input_width = input_width;
    model->cuda_decoder_graph_executor_last_linear_rows = *rows;
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_slice_to_device(
    king_inference_model_object *model,
    zval *slice_op,
    king_inference_cuda_device_ptr input,
    zend_ulong input_length,
    king_inference_cuda_device_ptr *output,
    zend_ulong *offset,
    zend_ulong *length
) {
    *output = 0;
    *offset = 0;
    *length = 0;
    if (slice_op == NULL
        || input == 0
        || king_inference_cuda_decoder_graph_executor_slice_shape(
            model,
            slice_op,
            input_length,
            offset,
            length
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_cuda_device_allocator_alloc(model, (size_t) *length * sizeof(float), output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_slice_allocation_failed"
        );
        return FAILURE;
    }
    if (king_inference_cuda_vector_slice(model, input, *offset, *output, *length) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_vector_ops_result,
            model->cuda_device_vector_ops_error[0] != '\0'
                ? model->cuda_device_vector_ops_error
                : "cuda_decoder_graph_kv_slice_execution_failed"
        );
        return FAILURE;
    }

    model->cuda_decoder_graph_executor_slice_execution_count++;
    model->cuda_decoder_graph_executor_last_slice_offset = *offset;
    model->cuda_decoder_graph_executor_last_slice_length = *length;
    return SUCCESS;
}

static zval *king_inference_cuda_decoder_graph_executor_kv_write_op_for_vectors(
    zval *graph,
    zend_string *key_id,
    zend_string *value_id
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;

    if (ops == NULL || key_id == NULL || value_id == NULL) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *key;
        zval *value;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        key = king_inference_array_find(entry, "key");
        value = king_inference_array_find(entry, "value");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && zend_string_equals_literal(Z_STR_P(op_name), "kv_write")
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(key, key_id)
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(value, value_id)) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

#include "cuda_decoder_graph_executor_kv_write_ops.inc"

#include "../attention/cuda_decoder_graph_executor_attention_ops.inc"
#include "../residual/cuda_decoder_graph_executor_stack_ops.inc"
#include "../../prefill/kv/cuda_decoder_graph_executor_prefilled_kv_ops.inc"

static zend_result king_inference_cuda_decoder_graph_executor_execute_kv_head_prepare(
    king_inference_model_object *model,
    zval *graph,
    zend_string *rms_norm_id,
    zend_string *rms_weight_tensor,
    king_inference_cuda_device_ptr rms_norm_output,
    zend_ulong width,
    zend_ulong token_id,
    double epsilon,
    zend_string *query_slice_id,
    king_inference_cuda_device_ptr query_slice_output,
    zend_ulong query_slice_length,
    zval *query_rope_probe,
    zval *kv_probe,
    king_inference_cuda_device_ptr *attention_stack_output,
    zend_ulong *attention_stack_width,
    zend_string **attention_stack_id,
    zend_ulong *executed_device_ops,
    bool *kv_prepared
) {
    zval *key_linear_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, rms_norm_id, "linear", 1);
    zval *value_linear_op = king_inference_cuda_decoder_graph_executor_nth_op_for_input(graph, rms_norm_id, "linear", 2);
    zval *key_slice_op = NULL;
    zval *value_slice_op = NULL;
    zval *kv_write_op = NULL;
    zval *kv_attention_op = NULL;
    zval key_rope_probe;
    zval kv_write_probe;
    zval kv_attention_probe;
    zval attention_stack_probe;
    zend_string *key_linear_id = NULL;
    zend_string *key_slice_id = NULL;
    zend_string *key_rope_id = NULL;
    zend_string *value_linear_id = NULL;
    zend_string *value_slice_id = NULL;
    zend_string *query_rope_id = NULL;
    zend_string *kv_attention_id = NULL;
    king_inference_cuda_device_ptr key_linear_output = 0;
    king_inference_cuda_device_ptr value_linear_output = 0;
    king_inference_cuda_device_ptr key_slice_output = 0;
    king_inference_cuda_device_ptr key_rope_output = 0;
    king_inference_cuda_device_ptr value_slice_output = 0;
    king_inference_cuda_device_ptr query_rope_output = 0;
    king_inference_cuda_device_ptr attention_context_output = 0;
    zend_ulong key_rows = 0;
    zend_ulong value_rows = 0;
    zend_ulong key_slice_offset = 0;
    zend_ulong key_slice_length = 0;
    zend_ulong value_slice_offset = 0;
    zend_ulong value_slice_length = 0;
    zend_ulong key_rope_length = 0;
    zend_ulong query_rope_length = 0;
    zend_ulong attention_context_length = 0;
    bool key_rope_executed = false;
    bool query_rope_executed = false;
    bool used_prefilled_kv = false;

    *kv_prepared = false;
    add_assoc_bool(kv_probe, "attempted", key_linear_op != NULL || value_linear_op != NULL);
    if (key_linear_op == NULL && value_linear_op == NULL) {
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_kv_head_prepare_execution_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_kv_head_prepare_execution_unavailable"
        );
        return FAILURE;
    }
    if (key_linear_op == NULL
        || value_linear_op == NULL
        || king_inference_graph_required_string(key_linear_op, "id", &key_linear_id) != SUCCESS
        || king_inference_graph_required_string(value_linear_op, "id", &value_linear_id) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_linear_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_try_prefilled_kv_head_prepare(
            model,
            graph,
            key_linear_id,
            value_linear_id,
            query_slice_id,
            query_slice_output,
            query_slice_length,
            query_rope_probe,
            kv_probe,
            attention_stack_output,
            attention_stack_width,
            attention_stack_id,
            executed_device_ops,
            kv_prepared,
            &used_prefilled_kv
    ) != SUCCESS) {
        return FAILURE;
    }
    if (used_prefilled_kv) {
        return SUCCESS;
    }
    if (king_inference_cuda_decoder_graph_executor_linear_to_device(
            model,
            key_linear_op,
            rms_norm_output,
            width,
            &key_linear_output,
            &key_rows
    ) != SUCCESS) {
        return FAILURE;
    }
    (*executed_device_ops)++;
    king_inference_cuda_decoder_graph_executor_add_qkv_projection_execution_probe(
        model,
        kv_probe,
        "key_projection_device_execution",
        "gpu_decoder_key_projection_device_execution",
        token_id,
        rms_weight_tensor,
        key_linear_op,
        key_linear_output,
        width,
        key_rows,
        epsilon
    );
    key_slice_op = king_inference_cuda_decoder_graph_executor_kv_slice_op_for_query(
        graph,
        key_linear_id,
        query_slice_id,
        "k"
    );
    if (key_slice_op == NULL || king_inference_graph_required_string(key_slice_op, "id", &key_slice_id) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_key_slice_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_slice_to_device(
            model,
            key_slice_op,
            key_linear_output,
            key_rows,
            &key_slice_output,
            &key_slice_offset,
            &key_slice_length
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    (*executed_device_ops)++;
    array_init(&key_rope_probe);
    add_assoc_string(&key_rope_probe, "type", "gpu_decoder_key_rope_device_execution");
    add_assoc_bool(&key_rope_probe, "attempted", false);
    add_assoc_bool(&key_rope_probe, "available", model->cuda_decoder_graph_executor_rope_execution_available);
    add_assoc_bool(&key_rope_probe, "executed", false);
    add_assoc_bool(&key_rope_probe, "retained_device_output", false);
    if (king_inference_cuda_decoder_graph_executor_execute_rope_after_slice_to_device(
            model,
            graph,
            key_slice_id,
            key_slice_output,
            key_slice_length,
            &key_rope_probe,
            executed_device_ops,
            &key_rope_output,
            &key_rope_length,
            &key_rope_id,
            &key_rope_executed
    ) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        zval_ptr_dtor(&key_rope_probe);
        return FAILURE;
    }
    if (!key_rope_executed) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        zval_ptr_dtor(&key_rope_probe);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_key_rope_missing");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_linear_to_device(
            model,
            value_linear_op,
            rms_norm_output,
            width,
            &value_linear_output,
            &value_rows
    ) != SUCCESS) {
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    (*executed_device_ops)++;
    king_inference_cuda_decoder_graph_executor_add_qkv_projection_execution_probe(
        model,
        kv_probe,
        "value_projection_device_execution",
        "gpu_decoder_value_projection_device_execution",
        token_id,
        rms_weight_tensor,
        value_linear_op,
        value_linear_output,
        width,
        value_rows,
        epsilon
    );
    value_slice_op = king_inference_cuda_decoder_graph_executor_kv_slice_op_for_query(
        graph,
        value_linear_id,
        query_slice_id,
        "v"
    );
    if (value_slice_op == NULL || king_inference_graph_required_string(value_slice_op, "id", &value_slice_id) != SUCCESS) {
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_value_slice_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_slice_to_device(
            model,
            value_slice_op,
            value_linear_output,
            value_rows,
            &value_slice_output,
            &value_slice_offset,
            &value_slice_length
    ) != SUCCESS) {
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    (*executed_device_ops)++;

    kv_write_op = king_inference_cuda_decoder_graph_executor_kv_write_op_for_vectors(
        graph,
        key_rope_id,
        value_slice_id
    );
    array_init(&kv_write_probe);
    add_assoc_string(&kv_write_probe, "type", "gpu_decoder_kv_write_device_execution");
    if (king_inference_cuda_decoder_graph_executor_execute_kv_write(
            model,
            kv_write_op,
            query_slice_id,
            key_rope_output,
            key_rope_length,
            value_slice_output,
            value_slice_length,
            &kv_write_probe,
            executed_device_ops
    ) != SUCCESS) {
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }

    if (query_slice_id == NULL || query_slice_output == 0) {
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_attention_query_rope_contract_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_execute_rope_after_slice_to_device(
            model,
            graph,
            query_slice_id,
            query_slice_output,
            query_slice_length,
            query_rope_probe,
            executed_device_ops,
            &query_rope_output,
            &query_rope_length,
            &query_rope_id,
            &query_rope_executed
    ) != SUCCESS) {
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    if (!query_rope_executed) {
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_attention_query_rope_missing");
        return FAILURE;
    }

    kv_attention_op = king_inference_cuda_decoder_graph_executor_kv_attention_op_for_query(graph, query_rope_id);
    array_init(&kv_attention_probe);
    add_assoc_string(&kv_attention_probe, "type", "gpu_decoder_kv_attention_device_execution");
    if (king_inference_cuda_decoder_graph_executor_execute_kv_attention(
            model,
            kv_attention_op,
            query_rope_output,
            query_rope_length,
            &kv_attention_probe,
            &attention_context_output,
            &attention_context_length,
            executed_device_ops
    ) != SUCCESS) {
        zval_ptr_dtor(&kv_attention_probe);
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_context_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    if (kv_attention_op == NULL
        || king_inference_graph_required_string(kv_attention_op, "id", &kv_attention_id) != SUCCESS) {
        zval_ptr_dtor(&kv_attention_probe);
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_context_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_kv_attention_id_invalid");
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &query_rope_output) != SUCCESS) {
        zval_ptr_dtor(&kv_attention_probe);
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_context_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_attention_query_rope_free_failed"
        );
        return FAILURE;
    }
    add_assoc_bool(query_rope_probe, "retained_device_output", false);
    array_init(&attention_stack_probe);
    add_assoc_string(&attention_stack_probe, "type", "gpu_decoder_attention_stack_slot_device_execution");
    if (king_inference_cuda_decoder_graph_executor_prepare_attention_stack_slot(
            model,
            graph,
            kv_attention_id,
            attention_context_output,
            attention_context_length,
            &attention_stack_probe,
            attention_stack_output,
            attention_stack_width,
            attention_stack_id,
            executed_device_ops
    ) != SUCCESS) {
        zval_ptr_dtor(&attention_stack_probe);
        zval_ptr_dtor(&kv_attention_probe);
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_context_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    add_assoc_long(kv_probe, "attention_context_length", (zend_long) attention_context_length);
    add_assoc_long(kv_probe, "attention_stack_width", (zend_long) *attention_stack_width);
    add_assoc_bool(&key_rope_probe, "retained_device_output", false);

    model->cuda_decoder_graph_executor_kv_head_prepare_execution_count++;
    model->cuda_decoder_graph_executor_last_kv_head_key_length = key_slice_length;
    model->cuda_decoder_graph_executor_last_kv_head_value_length = value_slice_length;
    *kv_prepared = true;

    add_assoc_bool(kv_probe, "executed", true);
    add_assoc_bool(kv_probe, "key_rope_executed", key_rope_executed);
    add_assoc_stringl(kv_probe, "key_linear_id", ZSTR_VAL(key_linear_id), ZSTR_LEN(key_linear_id));
    add_assoc_stringl(kv_probe, "key_slice_id", ZSTR_VAL(key_slice_id), ZSTR_LEN(key_slice_id));
    add_assoc_stringl(kv_probe, "key_rope_id", ZSTR_VAL(key_rope_id), ZSTR_LEN(key_rope_id));
    add_assoc_long(kv_probe, "key_linear_rows", (zend_long) key_rows);
    add_assoc_long(kv_probe, "key_slice_offset", (zend_long) key_slice_offset);
    add_assoc_long(kv_probe, "key_slice_length", (zend_long) key_slice_length);
    add_assoc_long(kv_probe, "key_rope_length", (zend_long) key_rope_length);
    add_assoc_stringl(kv_probe, "value_linear_id", ZSTR_VAL(value_linear_id), ZSTR_LEN(value_linear_id));
    add_assoc_stringl(kv_probe, "value_slice_id", ZSTR_VAL(value_slice_id), ZSTR_LEN(value_slice_id));
    add_assoc_long(kv_probe, "value_linear_rows", (zend_long) value_rows);
    add_assoc_long(kv_probe, "value_slice_offset", (zend_long) value_slice_offset);
    add_assoc_long(kv_probe, "value_slice_length", (zend_long) value_slice_length);

    add_assoc_bool(kv_probe, "attention_stack_released", false);
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &attention_context_output) != SUCCESS) {
        zval_ptr_dtor(&attention_stack_probe);
        zval_ptr_dtor(&kv_attention_probe);
        zval_ptr_dtor(&kv_write_probe);
        zval_ptr_dtor(&key_rope_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_attention_context_free_failed"
        );
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    add_assoc_bool(&kv_attention_probe, "retained_device_output", false);
    add_assoc_bool(kv_probe, "attention_context_released", true);
    add_assoc_zval(kv_probe, "key_rope_execution", &key_rope_probe);
    add_assoc_zval(kv_probe, "kv_write_execution", &kv_write_probe);
    add_assoc_zval(kv_probe, "kv_attention_execution", &kv_attention_probe);
    add_assoc_zval(kv_probe, "attention_stack_slot_execution", &attention_stack_probe);

    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_slice_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_head_prepare_free_failed"
        );
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &value_linear_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_head_prepare_free_failed"
        );
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_rope_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_head_prepare_free_failed"
        );
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output);
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_slice_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_head_prepare_free_failed"
        );
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &key_linear_output) != SUCCESS) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_kv_head_prepare_free_failed"
        );
        return FAILURE;
    }
    return SUCCESS;
}
