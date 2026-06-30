/*
 * Initial linear matvec numeric compares.
 *
 * The file still owns the initial compare aggregation because the first CUDA
 * linear op currently has one probe envelope. Individual tensor-type compares
 * live in focused helpers under this directory.
 */

static void king_inference_cuda_decoder_graph_executor_add_q4_matvec_compare_failure(
    zval *linear_probe,
    zval *compare,
    const char *status
) {
    add_assoc_string(compare, "status", status != NULL ? status : "q4_matvec_numeric_compare_failed");
    add_assoc_bool(compare, "matched", false);
    add_assoc_zval(linear_probe, "q4_matvec_numeric_compare", compare);
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_q4_attn_q_row(
    king_inference_model_object *model,
    zend_ulong token_id,
    zend_string *rms_weight_tensor,
    zend_string *linear_weight_tensor,
    king_inference_cuda_device_ptr linear_output,
    zend_ulong input_width,
    zend_ulong linear_rows,
    double epsilon,
    zval *linear_probe
) {
    king_inference_tensor_native_view weight_view;
    zend_ulong cols = 0;
    zend_ulong rows = 0;
    double *input_vector = NULL;
    double scale = 0.0;
    double expected = 0.0;
    float expected_f32;
    zval compare;
    zval *matched;

    if (!king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }
    if (linear_weight_tensor == NULL
        || !zend_string_equals_literal(linear_weight_tensor, "blk.0.attn_q.weight")) {
        return SUCCESS;
    }

    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_q4_matvec_row_numeric_compare");
    add_assoc_long(&compare, "token_id", (zend_long) token_id);
    if (linear_weight_tensor != NULL) {
        add_assoc_str(&compare, "tensor", zend_string_copy(linear_weight_tensor));
    }

    if (linear_output == 0
        || input_width == 0
        || linear_rows == 0
        || rms_weight_tensor == NULL
        || linear_weight_tensor == NULL) {
        king_inference_cuda_decoder_graph_executor_add_q4_matvec_compare_failure(
            linear_probe,
            &compare,
            "q4_matvec_compare_argument_invalid"
        );
        return FAILURE;
    }
    if (king_inference_tensor_native_view_resolve(model, linear_weight_tensor, &weight_view) != SUCCESS) {
        zval_ptr_dtor(&compare);
        return FAILURE;
    }
    if (weight_view.type != 12) {
        zval_ptr_dtor(&compare);
        return SUCCESS;
    }
    if (weight_view.rank != 2
        || king_inference_tensor_dimension_at(weight_view.dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(weight_view.dimensions, 1, &rows) != SUCCESS
        || cols != input_width
        || rows == 0
        || linear_rows > rows) {
        king_inference_cuda_decoder_graph_executor_add_q4_matvec_compare_failure(
            linear_probe,
            &compare,
            "q4_matvec_tensor_shape_invalid"
        );
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_build_bos_first_rms_norm_vector(
            model,
            token_id,
            rms_weight_tensor,
            input_width,
            epsilon,
            &input_vector,
            &scale
    ) != SUCCESS) {
        zval_ptr_dtor(&compare);
        return FAILURE;
    }
    if (king_inference_tensor_row_dot(&weight_view, 0, input_width, input_vector, &expected) != SUCCESS) {
        efree(input_vector);
        zval_ptr_dtor(&compare);
        return FAILURE;
    }
    efree(input_vector);
    expected_f32 = (float) expected;

    zval_ptr_dtor(&compare);
    if (king_inference_cuda_device_vector_numeric_compare(
            model,
            "blk0_attn_q_q4_k_row0",
            linear_output,
            1,
            &expected_f32,
            1,
            0.001,
            &compare
    ) != SUCCESS) {
        add_assoc_string(&compare, "type", "gpu_decoder_q4_matvec_row_numeric_compare");
        add_assoc_str(&compare, "tensor", zend_string_copy(linear_weight_tensor));
        add_assoc_string(&compare, "tensor_type", "Q4_K");
        add_assoc_long(&compare, "row_index", 0);
        add_assoc_long(&compare, "input_width", (zend_long) input_width);
        add_assoc_long(&compare, "rows", (zend_long) rows);
        add_assoc_double(&compare, "cpu_reference", expected);
        add_assoc_double(&compare, "rms_norm_scale", scale);
        add_assoc_zval(linear_probe, "q4_matvec_numeric_compare", &compare);
        return FAILURE;
    }

    add_assoc_string(&compare, "type", "gpu_decoder_q4_matvec_row_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_q4_k_row_dot_first_rms_norm");
    add_assoc_str(&compare, "tensor", zend_string_copy(linear_weight_tensor));
    add_assoc_string(&compare, "tensor_type", "Q4_K");
    add_assoc_long(&compare, "row_index", 0);
    add_assoc_long(&compare, "output_index", 0);
    add_assoc_long(&compare, "input_width", (zend_long) input_width);
    add_assoc_long(&compare, "rows", (zend_long) rows);
    add_assoc_double(&compare, "cpu_reference", expected);
    add_assoc_double(&compare, "rms_norm_scale", scale);
    matched = king_inference_array_find(&compare, "matched");
    add_assoc_bool(linear_probe, "q4_matvec_numeric_compare_failed", matched == NULL || !zend_is_true(matched));
    add_assoc_zval(linear_probe, "q4_matvec_numeric_compare", &compare);
    return matched != NULL && zend_is_true(matched) ? SUCCESS : FAILURE;
}

static void king_inference_cuda_decoder_graph_executor_record_q4_matvec_compare(
    king_inference_model_object *model,
    zend_ulong token_id,
    zend_string *rms_weight_tensor,
    zend_string *linear_weight_tensor,
    king_inference_cuda_device_ptr linear_output,
    zend_ulong input_width,
    zend_ulong linear_rows,
    double epsilon,
    zval *linear_probe
) {
    (void) king_inference_cuda_decoder_graph_executor_compare_q4_attn_q_row(
        model,
        token_id,
        rms_weight_tensor,
        linear_weight_tensor,
        linear_output,
        input_width,
        linear_rows,
        epsilon,
        linear_probe
    );
}

static void king_inference_cuda_decoder_graph_executor_copy_q4_matvec_compare(
    zval *result,
    zval *linear_probe
) {
    zval *compare = king_inference_array_find(linear_probe, "q4_matvec_numeric_compare");
    zval copy;

    if (compare == NULL) {
        return;
    }

    ZVAL_COPY(&copy, compare);
    add_assoc_zval(result, "q4_matvec_numeric_compare", &copy);
}

#include "cuda_decoder_graph_executor_q6_matvec_compare.inc"

static void king_inference_cuda_decoder_graph_executor_record_quantized_matvec_compare(
    king_inference_model_object *model,
    zend_ulong token_id,
    zend_string *rms_weight_tensor,
    zend_string *linear_weight_tensor,
    king_inference_cuda_device_ptr linear_output,
    zend_ulong input_width,
    zend_ulong linear_rows,
    double epsilon,
    zval *linear_probe
) {
    king_inference_cuda_decoder_graph_executor_record_q4_matvec_compare(
        model,
        token_id,
        rms_weight_tensor,
        linear_weight_tensor,
        linear_output,
        input_width,
        linear_rows,
        epsilon,
        linear_probe
    );
    king_inference_cuda_decoder_graph_executor_record_q6_matvec_compare(
        model,
        token_id,
        rms_weight_tensor,
        linear_weight_tensor,
        linear_output,
        input_width,
        linear_rows,
        epsilon,
        linear_probe
    );
    king_inference_cuda_decoder_graph_executor_record_qkv_projection_compare(
        model,
        token_id,
        rms_weight_tensor,
        linear_weight_tensor,
        linear_output,
        input_width,
        linear_rows,
        epsilon,
        linear_probe
    );
}

static void king_inference_cuda_decoder_graph_executor_copy_initial_numeric_compares(
    zval *result,
    zval *embedding_probe,
    zval *rms_norm_probe,
    zval *linear_probe
) {
    king_inference_cuda_decoder_graph_executor_copy_embedding_compare(result, embedding_probe);
    king_inference_cuda_decoder_graph_executor_copy_first_rms_norm_compare(result, rms_norm_probe);
    king_inference_cuda_decoder_graph_executor_copy_q4_matvec_compare(result, linear_probe);
    king_inference_cuda_decoder_graph_executor_copy_q6_matvec_compare(result, linear_probe);
    king_inference_cuda_decoder_graph_executor_copy_qkv_projection_compare(result, linear_probe);
}
