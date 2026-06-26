/*
 * CUDA decoder graph executor device-op admission for native King GPU inference.
 */

static bool king_inference_cuda_decoder_graph_executor_device_op(zend_string *op_name)
{
    return zend_string_equals_literal(op_name, "embedding")
        || zend_string_equals_literal(op_name, "rms_norm")
        || zend_string_equals_literal(op_name, "linear")
        || zend_string_equals_literal(op_name, "add")
        || zend_string_equals_literal(op_name, "scale")
        || zend_string_equals_literal(op_name, "mul")
        || zend_string_equals_literal(op_name, "silu")
        || zend_string_equals_literal(op_name, "slice")
        || zend_string_equals_literal(op_name, "rope")
        || zend_string_equals_literal(op_name, "kv_write")
        || zend_string_equals_literal(op_name, "kv_attention")
        || zend_string_equals_literal(op_name, "stack");
}

static bool king_inference_cuda_decoder_graph_executor_host_sampling_op(zend_string *op_name)
{
    return zend_string_equals_literal(op_name, "argmax_token")
        || zend_string_equals_literal(op_name, "sample_token");
}

static bool king_inference_cuda_decoder_graph_executor_zval_string_equals(
    zval *value,
    zend_string *expected
) {
    return value != NULL
        && expected != NULL
        && Z_TYPE_P(value) == IS_STRING
        && zend_string_equals(Z_STR_P(value), expected);
}

static zval *king_inference_cuda_decoder_graph_executor_ops(zval *graph)
{
    zval *ops = king_inference_array_find(graph, "ops");

    if (ops == NULL) {
        ops = king_inference_array_find(graph, "steps");
    }
    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY) {
        return NULL;
    }
    return ops;
}

static zend_result king_inference_cuda_decoder_graph_executor_prepare_plan(
    king_inference_model_object *model,
    zval *graph,
    zval *result
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    zval plan;
    zend_ulong total_ops = 0;
    zend_ulong device_ops = 0;
    zend_ulong host_sampling_ops = 0;

    if (ops == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_plan_ops_missing");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zend_string *op_name = NULL;

        if (entry == NULL
            || Z_TYPE_P(entry) != IS_ARRAY
            || king_inference_graph_required_string(entry, "op", &op_name) != SUCCESS) {
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_plan_op_invalid");
            return FAILURE;
        }
        if (king_inference_cuda_decoder_graph_executor_device_op(op_name)) {
            device_ops++;
        } else if (king_inference_cuda_decoder_graph_executor_host_sampling_op(op_name)) {
            host_sampling_ops++;
        } else {
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_plan_op_unsupported");
            return FAILURE;
        }
        total_ops++;
    } ZEND_HASH_FOREACH_END();

    if (host_sampling_ops > 0 && !model->cuda_decoder_graph_executor_sampling_readback_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_plan_sampling_readback_unavailable"
        );
        return FAILURE;
    }

    array_init(&plan);
    add_assoc_string(&plan, "type", "gpu_decoder_graph_execution_plan");
    add_assoc_bool(&plan, "ready", true);
    add_assoc_bool(&plan, "device_execution_result_ready", false);
    add_assoc_bool(&plan, "token_result_ready", false);
    add_assoc_bool(&plan, "device_ops_ready", true);
    add_assoc_bool(&plan, "host_sampling_after_bounded_readback", host_sampling_ops > 0);
    add_assoc_long(&plan, "total_ops", (zend_long) total_ops);
    add_assoc_long(&plan, "device_ops", (zend_long) device_ops);
    add_assoc_long(&plan, "host_sampling_ops", (zend_long) host_sampling_ops);
    add_assoc_string(&plan, "next_bridge", "execute_device_ops_and_write_bounded_logits_result");
    add_assoc_zval(result, "execution_plan", &plan);
    add_assoc_bool(result, "execution_plan_ready", true);
    add_assoc_long(result, "planned_device_ops", (zend_long) device_ops);
    add_assoc_long(result, "planned_host_sampling_ops", (zend_long) host_sampling_ops);

    model->cuda_decoder_graph_executor_plan_count++;
    model->cuda_decoder_graph_executor_last_plan_op_count = total_ops;
    model->cuda_decoder_graph_executor_last_plan_device_ops = device_ops;
    model->cuda_decoder_graph_executor_last_plan_host_sampling_ops = host_sampling_ops;
    return SUCCESS;
}

static zval *king_inference_cuda_decoder_graph_executor_first_op(
    zval *graph,
    const char *name
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    size_t name_length = strlen(name);

    if (ops == NULL) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && Z_STRLEN_P(op_name) == name_length
            && memcmp(Z_STRVAL_P(op_name), name, name_length) == 0) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zval *king_inference_cuda_decoder_graph_executor_rms_norm_for_input(
    zval *graph,
    zend_string *input_id
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;

    if (ops == NULL || input_id == NULL) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_name;
        zval *input;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_name = king_inference_array_find(entry, "op");
        input = king_inference_array_find(entry, "input");
        if (op_name != NULL
            && Z_TYPE_P(op_name) == IS_STRING
            && zend_string_equals_literal(Z_STR_P(op_name), "rms_norm")
            && king_inference_cuda_decoder_graph_executor_zval_string_equals(input, input_id)) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_cuda_decoder_graph_executor_release_device_ptr(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr *device_ptr
) {
    if (device_ptr != NULL && *device_ptr != 0) {
        if (king_inference_cuda_device_allocator_free(model, *device_ptr) != SUCCESS) {
            return FAILURE;
        }
        *device_ptr = 0;
    }
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_execute_initial_device_ops(
    king_inference_model_object *model,
    zval *graph,
    zval *result
) {
    zval *embedding_op = king_inference_cuda_decoder_graph_executor_first_op(graph, "embedding");
    zval *rms_norm_op = NULL;
    zval *token_value;
    zval embedding_probe;
    zval rms_norm_probe;
    zend_string *embedding_id = NULL;
    zend_string *rms_weight = NULL;
    zend_ulong width = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong token_id;
    size_t bytes;
    king_inference_cuda_device_ptr embedding_output = 0;
    king_inference_cuda_device_ptr rms_norm_output = 0;
    zend_ulong executed_device_ops = 0;
    double epsilon = 0.000001;

    array_init(&embedding_probe);
    add_assoc_string(&embedding_probe, "type", "gpu_decoder_embedding_device_execution");
    add_assoc_bool(&embedding_probe, "attempted", embedding_op != NULL);
    add_assoc_bool(&embedding_probe, "available", model->cuda_decoder_graph_executor_embedding_execution_available);
    add_assoc_bool(&embedding_probe, "executed", false);
    add_assoc_bool(&embedding_probe, "retained_device_output", false);

    array_init(&rms_norm_probe);
    add_assoc_string(&rms_norm_probe, "type", "gpu_decoder_rms_norm_device_execution");
    add_assoc_bool(&rms_norm_probe, "attempted", false);
    add_assoc_bool(&rms_norm_probe, "available", model->cuda_decoder_graph_executor_rms_norm_execution_available);
    add_assoc_bool(&rms_norm_probe, "executed", false);
    add_assoc_bool(&rms_norm_probe, "retained_device_output", false);

    if (embedding_op == NULL) {
        add_assoc_zval(result, "embedding_device_execution", &embedding_probe);
        add_assoc_zval(result, "rms_norm_device_execution", &rms_norm_probe);
        add_assoc_bool(result, "embedding_device_execution_ready", false);
        add_assoc_bool(result, "rms_norm_device_execution_ready", false);
        add_assoc_long(result, "executed_device_ops", 0);
        return SUCCESS;
    }
    if (!model->cuda_decoder_graph_executor_embedding_execution_available) {
        zval_ptr_dtor(&embedding_probe);
        zval_ptr_dtor(&rms_norm_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_embedding_execution_unavailable"
        );
        return FAILURE;
    }
    if (king_inference_graph_required_string(embedding_op, "id", &embedding_id) != SUCCESS) {
        zval_ptr_dtor(&embedding_probe);
        zval_ptr_dtor(&rms_norm_probe);
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_embedding_id_invalid");
        return FAILURE;
    }

    token_value = king_inference_array_find(embedding_op, "token_id");
    if (token_value == NULL) {
        token_value = king_inference_array_find(embedding_op, "token");
    }
    if (token_value == NULL || Z_TYPE_P(token_value) != IS_LONG || Z_LVAL_P(token_value) < 0) {
        zval_ptr_dtor(&embedding_probe);
        zval_ptr_dtor(&rms_norm_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_embedding_token_invalid"
        );
        return FAILURE;
    }
    if (width == 0 || width > (zend_ulong) (SIZE_MAX / sizeof(float))) {
        zval_ptr_dtor(&embedding_probe);
        zval_ptr_dtor(&rms_norm_probe);
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
        zval_ptr_dtor(&embedding_probe);
        zval_ptr_dtor(&rms_norm_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_embedding_allocation_failed"
        );
        return FAILURE;
    }

    if (king_inference_cuda_embedding_row_load(model, token_id, embedding_output, width) != SUCCESS) {
        (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
        zval_ptr_dtor(&embedding_probe);
        zval_ptr_dtor(&rms_norm_probe);
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
    add_assoc_long(&embedding_probe, "token_id", (zend_long) token_id);
    add_assoc_long(&embedding_probe, "width", (zend_long) width);
    add_assoc_long(&embedding_probe, "bytes", (zend_long) bytes);

    rms_norm_op = king_inference_cuda_decoder_graph_executor_rms_norm_for_input(graph, embedding_id);
    add_assoc_bool(&rms_norm_probe, "attempted", rms_norm_op != NULL);
    if (rms_norm_op != NULL) {
        if (!model->cuda_decoder_graph_executor_rms_norm_execution_available) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            zval_ptr_dtor(&embedding_probe);
            zval_ptr_dtor(&rms_norm_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                -1,
                "cuda_decoder_graph_rms_norm_execution_unavailable"
            );
            return FAILURE;
        }
        if (king_inference_graph_required_string(rms_norm_op, "weight", &rms_weight) != SUCCESS
            || king_inference_graph_finite_double_option(rms_norm_op, "epsilon", 0.000001, &epsilon) != SUCCESS
            || epsilon <= 0.0) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            zval_ptr_dtor(&embedding_probe);
            zval_ptr_dtor(&rms_norm_probe);
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_rms_norm_contract_invalid");
            return FAILURE;
        }
        if (king_inference_cuda_device_allocator_alloc(model, bytes, &rms_norm_output) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            zval_ptr_dtor(&embedding_probe);
            zval_ptr_dtor(&rms_norm_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_device_allocator_result,
                model->cuda_device_allocator_error[0] != '\0'
                    ? model->cuda_device_allocator_error
                    : "cuda_decoder_graph_rms_norm_allocation_failed"
            );
            return FAILURE;
        }
        if (king_inference_cuda_rms_norm_f32(
                model,
                rms_weight,
                embedding_output,
                rms_norm_output,
                width,
                epsilon
            ) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output);
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            zval_ptr_dtor(&embedding_probe);
            zval_ptr_dtor(&rms_norm_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_rms_norm_result,
                model->cuda_rms_norm_error[0] != '\0'
                    ? model->cuda_rms_norm_error
                    : "cuda_decoder_graph_rms_norm_execution_failed"
            );
            return FAILURE;
        }
        if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &rms_norm_output) != SUCCESS) {
            (void) king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output);
            zval_ptr_dtor(&embedding_probe);
            zval_ptr_dtor(&rms_norm_probe);
            king_inference_cuda_decoder_graph_executor_set_error(
                model,
                model->cuda_device_allocator_result,
                model->cuda_device_allocator_error[0] != '\0'
                    ? model->cuda_device_allocator_error
                    : "cuda_decoder_graph_rms_norm_free_failed"
            );
            return FAILURE;
        }
        model->cuda_decoder_graph_executor_rms_norm_execution_count++;
        model->cuda_decoder_graph_executor_last_rms_norm_width = width;
        executed_device_ops++;

        add_assoc_bool(&rms_norm_probe, "executed", true);
        add_assoc_long(&rms_norm_probe, "width", (zend_long) width);
        add_assoc_long(&rms_norm_probe, "bytes", (zend_long) bytes);
        add_assoc_double(&rms_norm_probe, "epsilon", epsilon);
    }

    if (king_inference_cuda_decoder_graph_executor_release_device_ptr(model, &embedding_output) != SUCCESS) {
        zval_ptr_dtor(&embedding_probe);
        zval_ptr_dtor(&rms_norm_probe);
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_decoder_graph_embedding_free_failed"
        );
        return FAILURE;
    }
    add_assoc_zval(result, "embedding_device_execution", &embedding_probe);
    add_assoc_zval(result, "rms_norm_device_execution", &rms_norm_probe);
    add_assoc_bool(result, "embedding_device_execution_ready", true);
    add_assoc_bool(result, "rms_norm_device_execution_ready", rms_norm_op != NULL);
    add_assoc_bool(result, "first_device_op_executed", true);
    add_assoc_bool(result, "initial_device_ops_executed", executed_device_ops > 1);
    add_assoc_long(result, "executed_device_ops", (zend_long) executed_device_ops);
    return SUCCESS;
}
