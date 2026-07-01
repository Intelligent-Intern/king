/*
 * Internal CUDA FFN gate/up projection compare for block-0.
 */

#include "cuda_decoder_graph_executor_ffn_swiglu_compare.inc"

static void king_inference_cuda_decoder_graph_executor_ffn_gate_up_add_projection_summary(
    zval *target,
    const char *name,
    zend_string *tensor,
    zend_ulong rows,
    zend_ulong cols,
    zend_ulong matched_values,
    double max_abs_diff,
    double max_relative_diff,
    double max_expected_abs,
    double tolerance,
    double relative_tolerance,
    zval *samples
) {
    zval summary;
    bool matched = matched_values == rows;

    array_init(&summary);
    add_assoc_string(&summary, "projection", name);
    add_assoc_str(&summary, "tensor", zend_string_copy(tensor));
    king_inference_cuda_decoder_graph_executor_ffn_compare_add_status(&summary, matched);
    add_assoc_bool(&summary, "matched", matched);
    add_assoc_long(&summary, "compared_values", (zend_long) rows);
    add_assoc_long(&summary, "matched_values", (zend_long) matched_values);
    add_assoc_long(&summary, "rows", (zend_long) rows);
    add_assoc_long(&summary, "cols", (zend_long) cols);
    add_assoc_double(&summary, "max_abs_diff", max_abs_diff);
    add_assoc_double(&summary, "max_relative_diff", max_relative_diff);
    add_assoc_double(&summary, "max_expected_abs", max_expected_abs);
    add_assoc_double(&summary, "tolerance", tolerance);
    add_assoc_double(&summary, "max_abs_tolerance", tolerance);
    add_assoc_double(&summary, "max_relative_tolerance", relative_tolerance);
    add_assoc_zval(&summary, "samples", samples);
    add_assoc_zval(target, name, &summary);
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_ffn_projection_rows(
    king_inference_tensor_native_view *view,
    const double *input,
    const float *actual,
    const char *op,
    zend_ulong layer,
    zend_ulong rows,
    zend_ulong cols,
    double tolerance,
    double relative_tolerance,
    zend_ulong sample_limit,
    zend_ulong *matched_values,
    double *max_abs_diff,
    double *max_relative_diff,
    double *max_expected_abs,
    zval *samples
) {
    *matched_values = 0;
    *max_abs_diff = 0.0;
    *max_relative_diff = 0.0;
    *max_expected_abs = 0.0;
    array_init(samples);
    for (zend_ulong row = 0; row < rows; row++) {
        double expected = 0.0;
        double diff;
        double rel_diff;
        double abs_expected;
        const char *classification;

        if (king_inference_tensor_row_dot(view, row * cols, cols, input, &expected) != SUCCESS) {
            zval_ptr_dtor(samples);
            return FAILURE;
        }
        diff = fabs((double) actual[row] - expected);
        rel_diff = king_inference_cuda_numeric_relative_diff((double) actual[row], expected);
        classification = king_inference_cuda_numeric_sample_classification(
            (double) actual[row],
            expected,
            diff,
            rel_diff,
            tolerance,
            relative_tolerance
        );
        abs_expected = fabs(expected);
        if (king_inference_cuda_numeric_sample_matches(classification)) {
            (*matched_values)++;
        }
        if (diff > *max_abs_diff) {
            *max_abs_diff = diff;
        }
        if (rel_diff > *max_relative_diff && isfinite(rel_diff)) {
            *max_relative_diff = rel_diff;
        }
        if (abs_expected > *max_expected_abs) {
            *max_expected_abs = abs_expected;
        }
        if (row < sample_limit) {
            king_inference_cuda_decoder_graph_executor_ffn_compare_add_sample(
                samples,
                row,
                op,
                layer,
                (double) actual[row],
                expected,
                diff,
                rel_diff,
                tolerance,
                relative_tolerance,
                classification
            );
        }
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_compare_block0_ffn_gate_up(
    king_inference_model_object *model,
    zval *gate_op,
    zval *up_op,
    king_inference_cuda_device_ptr ffn_norm_output,
    zend_ulong ffn_norm_width,
    king_inference_cuda_device_ptr gate_output,
    zend_ulong gate_rows,
    king_inference_cuda_device_ptr up_output,
    zend_ulong up_rows,
    zval *ffn_gate_up_probe
) {
    zend_string *gate_weight = NULL;
    zend_string *up_weight = NULL;
    king_inference_tensor_native_view gate_view;
    king_inference_tensor_native_view up_view;
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    zend_ulong gate_cols = 0;
    zend_ulong gate_view_rows = 0;
    zend_ulong up_cols = 0;
    zend_ulong up_view_rows = 0;
    float *input_f32 = NULL;
    float *gate_actual = NULL;
    float *up_actual = NULL;
    double *input = NULL;
    zend_ulong gate_matched = 0;
    zend_ulong up_matched = 0;
    double gate_max_abs_diff = 0.0;
    double up_max_abs_diff = 0.0;
    double gate_max_relative_diff = 0.0;
    double up_max_relative_diff = 0.0;
    double gate_max_expected_abs = 0.0;
    double up_max_expected_abs = 0.0;
    double tolerance;
    double relative_tolerance = king_inference_cuda_numeric_tolerance_relative("ffn_gate_up");
    zend_ulong sample_limit;
    int result;
    bool matched;
    zval compare;
    zval projections;
    zval gate_samples;
    zval up_samples;

    if (!king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        return SUCCESS;
    }
    if (gate_op == NULL
        || up_op == NULL
        || king_inference_graph_required_string(gate_op, "weight", &gate_weight) != SUCCESS
        || king_inference_graph_required_string(up_op, "weight", &up_weight) != SUCCESS) {
        return FAILURE;
    }
    if (!zend_string_equals_literal(gate_weight, "blk.0.ffn_gate.weight")
        || !zend_string_equals_literal(up_weight, "blk.0.ffn_up.weight")) {
        return SUCCESS;
    }

    array_init(&compare);
    add_assoc_string(&compare, "type", "gpu_decoder_ffn_gate_up_numeric_compare");
    add_assoc_string(&compare, "reference", "cpu_ffn_norm_readback_gate_up_row_dot");
    add_assoc_str(&compare, "gate_tensor", zend_string_copy(gate_weight));
    add_assoc_str(&compare, "up_tensor", zend_string_copy(up_weight));
    if (ffn_norm_output == 0
        || gate_output == 0
        || up_output == 0
        || ffn_norm_width == 0
        || gate_rows == 0
        || gate_rows != up_rows
        || ffn_norm_width > (zend_ulong) (SIZE_MAX / sizeof(float))
        || gate_rows > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        add_assoc_string(&compare, "status", "ffn_gate_up_compare_argument_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_gate_up_probe, "ffn_gate_up_numeric_compare_failed", true);
        add_assoc_zval(ffn_gate_up_probe, "ffn_gate_up_numeric_compare", &compare);
        return FAILURE;
    }
    if (king_inference_tensor_native_view_resolve(model, gate_weight, &gate_view) != SUCCESS
        || king_inference_tensor_native_view_resolve(model, up_weight, &up_view) != SUCCESS) {
        zval_ptr_dtor(&compare);
        return FAILURE;
    }
    if (gate_view.rank != 2
        || up_view.rank != 2
        || king_inference_tensor_dimension_at(gate_view.dimensions, 0, &gate_cols) != SUCCESS
        || king_inference_tensor_dimension_at(gate_view.dimensions, 1, &gate_view_rows) != SUCCESS
        || king_inference_tensor_dimension_at(up_view.dimensions, 0, &up_cols) != SUCCESS
        || king_inference_tensor_dimension_at(up_view.dimensions, 1, &up_view_rows) != SUCCESS
        || gate_cols != ffn_norm_width
        || up_cols != ffn_norm_width
        || gate_view_rows != gate_rows
        || up_view_rows != up_rows) {
        add_assoc_string(&compare, "status", "ffn_gate_up_tensor_shape_invalid");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_long(&compare, "input_width", (zend_long) ffn_norm_width);
        add_assoc_long(&compare, "gate_rows", (zend_long) gate_rows);
        add_assoc_long(&compare, "up_rows", (zend_long) up_rows);
        add_assoc_bool(ffn_gate_up_probe, "ffn_gate_up_numeric_compare_failed", true);
        add_assoc_zval(ffn_gate_up_probe, "ffn_gate_up_numeric_compare", &compare);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        add_assoc_string(&compare, "status", "cuda_context_current_failed");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_gate_up_probe, "ffn_gate_up_numeric_compare_failed", true);
        add_assoc_zval(ffn_gate_up_probe, "ffn_gate_up_numeric_compare", &compare);
        return FAILURE;
    }
    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    if (cu_memcpy_dtoh == NULL) {
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_gate_up_probe, "ffn_gate_up_numeric_compare_failed", true);
        add_assoc_zval(ffn_gate_up_probe, "ffn_gate_up_numeric_compare", &compare);
        return FAILURE;
    }

    input_f32 = emalloc((size_t) ffn_norm_width * sizeof(float));
    gate_actual = emalloc((size_t) gate_rows * sizeof(float));
    up_actual = emalloc((size_t) up_rows * sizeof(float));
    input = emalloc((size_t) ffn_norm_width * sizeof(double));
    result = cu_memcpy_dtoh(input_f32, ffn_norm_output, (size_t) ffn_norm_width * sizeof(float));
    if (result == 0) {
        result = cu_memcpy_dtoh(gate_actual, gate_output, (size_t) gate_rows * sizeof(float));
    }
    if (result == 0) {
        result = cu_memcpy_dtoh(up_actual, up_output, (size_t) up_rows * sizeof(float));
    }
    if (result != 0) {
        efree(input);
        efree(up_actual);
        efree(gate_actual);
        efree(input_f32);
        add_assoc_string(&compare, "status", "cuMemcpyDtoH_vector_failed");
        add_assoc_long(&compare, "result", result);
        add_assoc_bool(&compare, "matched", false);
        add_assoc_bool(ffn_gate_up_probe, "ffn_gate_up_numeric_compare_failed", true);
        add_assoc_zval(ffn_gate_up_probe, "ffn_gate_up_numeric_compare", &compare);
        return FAILURE;
    }
    for (zend_ulong i = 0; i < ffn_norm_width; i++) {
        input[i] = (double) input_f32[i];
    }

    tolerance = 0.003;
    sample_limit = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    sample_limit = sample_limit < gate_rows ? sample_limit : gate_rows;
    if (king_inference_cuda_decoder_graph_executor_compare_ffn_projection_rows(&gate_view, input, gate_actual, "ffn_gate_up", 0, gate_rows, gate_cols, tolerance, relative_tolerance, sample_limit, &gate_matched, &gate_max_abs_diff, &gate_max_relative_diff, &gate_max_expected_abs, &gate_samples) != SUCCESS) {
        efree(input);
        efree(up_actual);
        efree(gate_actual);
        efree(input_f32);
        zval_ptr_dtor(&compare);
        return FAILURE;
    }
    if (king_inference_cuda_decoder_graph_executor_compare_ffn_projection_rows(&up_view, input, up_actual, "ffn_gate_up", 0, up_rows, up_cols, tolerance, relative_tolerance, sample_limit, &up_matched, &up_max_abs_diff, &up_max_relative_diff, &up_max_expected_abs, &up_samples) != SUCCESS) {
        zval_ptr_dtor(&gate_samples);
        efree(input);
        efree(up_actual);
        efree(gate_actual);
        efree(input_f32);
        zval_ptr_dtor(&compare);
        return FAILURE;
    }
    matched = gate_matched == gate_rows && up_matched == up_rows;
    array_init(&projections);
    king_inference_cuda_decoder_graph_executor_ffn_gate_up_add_projection_summary(&projections, "gate", gate_weight, gate_rows, gate_cols, gate_matched, gate_max_abs_diff, gate_max_relative_diff, gate_max_expected_abs, tolerance, relative_tolerance, &gate_samples);
    king_inference_cuda_decoder_graph_executor_ffn_gate_up_add_projection_summary(&projections, "up", up_weight, up_rows, up_cols, up_matched, up_max_abs_diff, up_max_relative_diff, up_max_expected_abs, tolerance, relative_tolerance, &up_samples);

    efree(input);
    efree(up_actual);
    efree(gate_actual);
    efree(input_f32);
    king_inference_cuda_decoder_graph_executor_ffn_compare_add_status(&compare, matched);
    add_assoc_bool(&compare, "matched", matched);
    add_assoc_long(&compare, "compared_values", (zend_long) (gate_rows + up_rows));
    add_assoc_long(&compare, "matched_values", (zend_long) (gate_matched + up_matched));
    add_assoc_long(&compare, "input_width", (zend_long) ffn_norm_width);
    add_assoc_long(&compare, "gate_rows", (zend_long) gate_rows);
    add_assoc_long(&compare, "up_rows", (zend_long) up_rows);
    add_assoc_long(&compare, "sampled_values", (zend_long) sample_limit);
    add_assoc_double(&compare, "tolerance", tolerance);
    add_assoc_double(&compare, "max_abs_tolerance", tolerance);
    add_assoc_double(&compare, "max_relative_tolerance", relative_tolerance);
    add_assoc_double(&compare, "max_abs_diff", fmax(gate_max_abs_diff, up_max_abs_diff));
    add_assoc_double(&compare, "max_relative_diff", fmax(gate_max_relative_diff, up_max_relative_diff));
    add_assoc_zval(&compare, "projections", &projections);
    add_assoc_bool(ffn_gate_up_probe, "ffn_gate_up_numeric_compare_failed", !matched);
    add_assoc_zval(ffn_gate_up_probe, "ffn_gate_up_numeric_compare", &compare);
    if (!matched) {
        char message[256];

        snprintf(
            message,
            sizeof(message),
            "king.numeric_mismatch.detected ffn_gate_up_mismatch compared=%lu matched=%lu max_abs_diff=%.9g tolerance=%.9g",
            (unsigned long) (gate_rows + up_rows),
            (unsigned long) (gate_matched + up_matched),
            fmax(gate_max_abs_diff, up_max_abs_diff),
            tolerance
        );
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, message);
    }
    return matched ? SUCCESS : FAILURE;
}
