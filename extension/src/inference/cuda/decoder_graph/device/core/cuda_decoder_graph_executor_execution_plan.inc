/*
 * CUDA decoder graph execution plan builder.
 */

static const char *king_inference_cuda_decoder_graph_executor_plan_owner(zend_string *op_name)
{
    if (king_inference_cuda_decoder_graph_executor_host_sampling_op(op_name)) {
        return "host";
    }
    if (zend_string_equals_literal(op_name, "kv_write")) {
        return "cuda_device_kv_cache";
    }
    return "cuda_device";
}

static const char *king_inference_cuda_decoder_graph_executor_plan_dtype(zend_string *op_name)
{
    if (king_inference_cuda_decoder_graph_executor_host_sampling_op(op_name)) {
        return "token_tuple_f64";
    }
    if (zend_string_equals_literal(op_name, "kv_write")) {
        return "kv_write_marker";
    }
    return "f32";
}

static const char *king_inference_cuda_decoder_graph_executor_plan_allocation_scope(zend_string *op_name)
{
    if (king_inference_cuda_decoder_graph_executor_host_sampling_op(op_name)) {
        return "host_sampling_result";
    }
    if (zend_string_equals_literal(op_name, "kv_write")) {
        return "device_kv_cache_side_effect";
    }
    if (zend_string_equals_literal(op_name, "kv_attention")) {
        return "temporary_device_attention_context";
    }
    return "temporary_device_vector";
}

static void king_inference_cuda_decoder_graph_executor_plan_record_read(
    zval *read_counts,
    zval *read_last,
    zend_string *reference,
    zend_ulong op_index
) {
    zval *count = zend_hash_find(Z_ARRVAL_P(read_counts), reference);
    zval value;

    if (count != NULL && Z_TYPE_P(count) == IS_LONG) {
        ZVAL_LONG(count, Z_LVAL_P(count) + 1);
    } else {
        ZVAL_LONG(&value, 1);
        zend_hash_update(Z_ARRVAL_P(read_counts), reference, &value);
    }
    ZVAL_LONG(&value, (zend_long) op_index);
    zend_hash_update(Z_ARRVAL_P(read_last), reference, &value);
}

static void king_inference_cuda_decoder_graph_executor_plan_scan_read_field(
    zval *entry,
    const char *field,
    zval *read_counts,
    zval *read_last,
    zend_ulong op_index
) {
    zval *value = king_inference_array_find(entry, field);

    if (value != NULL && Z_TYPE_P(value) == IS_STRING && Z_STRLEN_P(value) > 0) {
        king_inference_cuda_decoder_graph_executor_plan_record_read(read_counts, read_last, Z_STR_P(value), op_index);
    }
}

static void king_inference_cuda_decoder_graph_executor_plan_scan_reads(
    zval *entry,
    zval *read_counts,
    zval *read_last,
    zend_ulong op_index
) {
    zval *inputs;
    zval *input;

    king_inference_cuda_decoder_graph_executor_plan_scan_read_field(entry, "input", read_counts, read_last, op_index);
    king_inference_cuda_decoder_graph_executor_plan_scan_read_field(entry, "left", read_counts, read_last, op_index);
    king_inference_cuda_decoder_graph_executor_plan_scan_read_field(entry, "right", read_counts, read_last, op_index);
    king_inference_cuda_decoder_graph_executor_plan_scan_read_field(entry, "key", read_counts, read_last, op_index);
    king_inference_cuda_decoder_graph_executor_plan_scan_read_field(entry, "value", read_counts, read_last, op_index);
    king_inference_cuda_decoder_graph_executor_plan_scan_read_field(entry, "query", read_counts, read_last, op_index);
    king_inference_cuda_decoder_graph_executor_plan_scan_read_field(entry, "logits", read_counts, read_last, op_index);

    inputs = king_inference_array_find(entry, "inputs");
    if (inputs == NULL || Z_TYPE_P(inputs) != IS_ARRAY) {
        return;
    }
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(inputs), input) {
        if (input != NULL && Z_TYPE_P(input) == IS_STRING && Z_STRLEN_P(input) > 0) {
            king_inference_cuda_decoder_graph_executor_plan_record_read(read_counts, read_last, Z_STR_P(input), op_index);
        }
    } ZEND_HASH_FOREACH_END();
}

static zend_result king_inference_cuda_decoder_graph_executor_plan_collect_read_field(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *reads,
    const char *field
) {
    zval *value = king_inference_array_find(entry, field);

    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "execution_plan_dependency",
            node_id,
            field,
            "read_dependency_must_be_non_empty_string"
        );
    }
    if (!zend_hash_exists(Z_ARRVAL_P(defined), Z_STR_P(value))) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "execution_plan_dependency",
            node_id,
            field,
            Z_STRVAL_P(value)
        );
    }
    add_next_index_str(reads, zend_string_copy(Z_STR_P(value)));
    return SUCCESS;
}

static zend_result king_inference_cuda_decoder_graph_executor_plan_collect_reads(
    king_inference_model_object *model,
    zval *entry,
    zend_string *node_id,
    zval *defined,
    zval *reads
) {
    zval *inputs;
    zval *input;

    if (king_inference_cuda_decoder_graph_executor_plan_collect_read_field(model, entry, node_id, defined, reads, "input") != SUCCESS
        || king_inference_cuda_decoder_graph_executor_plan_collect_read_field(model, entry, node_id, defined, reads, "left") != SUCCESS
        || king_inference_cuda_decoder_graph_executor_plan_collect_read_field(model, entry, node_id, defined, reads, "right") != SUCCESS
        || king_inference_cuda_decoder_graph_executor_plan_collect_read_field(model, entry, node_id, defined, reads, "key") != SUCCESS
        || king_inference_cuda_decoder_graph_executor_plan_collect_read_field(model, entry, node_id, defined, reads, "value") != SUCCESS
        || king_inference_cuda_decoder_graph_executor_plan_collect_read_field(model, entry, node_id, defined, reads, "query") != SUCCESS
        || king_inference_cuda_decoder_graph_executor_plan_collect_read_field(model, entry, node_id, defined, reads, "logits") != SUCCESS) {
        return FAILURE;
    }

    inputs = king_inference_array_find(entry, "inputs");
    if (inputs == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(inputs) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(inputs)) == 0) {
        return king_inference_cuda_decoder_graph_executor_validation_error(
            model,
            "execution_plan_dependency",
            node_id,
            "inputs",
            "inputs_must_be_non_empty_array"
        );
    }
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(inputs), input) {
        if (input == NULL || Z_TYPE_P(input) != IS_STRING || Z_STRLEN_P(input) == 0) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "execution_plan_dependency",
                node_id,
                "inputs",
                "input_dependency_must_be_non_empty_string"
            );
        }
        if (!zend_hash_exists(Z_ARRVAL_P(defined), Z_STR_P(input))) {
            return king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "execution_plan_dependency",
                node_id,
                "inputs",
                Z_STRVAL_P(input)
            );
        }
        add_next_index_str(reads, zend_string_copy(Z_STR_P(input)));
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static void king_inference_cuda_decoder_graph_executor_plan_add_boundary(
    zval *step_boundaries,
    zval *global_boundaries,
    zend_ulong step_order,
    zend_string *node_id,
    zend_string *op_name,
    const char *boundary
) {
    zval global;

    add_next_index_string(step_boundaries, boundary);
    array_init(&global);
    add_assoc_long(&global, "step_order", (zend_long) step_order);
    add_assoc_str(&global, "node", zend_string_copy(node_id));
    add_assoc_str(&global, "op", zend_string_copy(op_name));
    add_assoc_string(&global, "boundary", boundary);
    add_next_index_zval(global_boundaries, &global);
}

static void king_inference_cuda_decoder_graph_executor_plan_add_kernel(
    zval *step_kernels,
    zval *global_kernels,
    zend_ulong *kernel_order,
    zend_ulong step_order,
    zend_string *node_id,
    zend_string *op_name,
    const char *owner,
    const char *kernel
) {
    zval global;

    add_next_index_string(step_kernels, kernel);
    array_init(&global);
    add_assoc_long(&global, "kernel_order", (zend_long) *kernel_order);
    add_assoc_long(&global, "step_order", (zend_long) step_order);
    add_assoc_str(&global, "node", zend_string_copy(node_id));
    add_assoc_str(&global, "op", zend_string_copy(op_name));
    add_assoc_string(&global, "owner", owner);
    add_assoc_string(&global, "kernel", kernel);
    add_next_index_zval(global_kernels, &global);
    (*kernel_order)++;
}

static void king_inference_cuda_decoder_graph_executor_plan_add_kernel_sequence(
    zval *step_kernels,
    zval *global_kernels,
    zend_ulong *kernel_order,
    zend_ulong step_order,
    zend_string *node_id,
    zend_string *op_name,
    const char *owner
) {
    if (zend_string_equals_literal(op_name, "embedding")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.embedding_row_load");
    } else if (zend_string_equals_literal(op_name, "rms_norm")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.rms_norm_f32");
    } else if (zend_string_equals_literal(op_name, "linear")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.quantized_matvec");
    } else if (zend_string_equals_literal(op_name, "add")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.vector_add");
    } else if (zend_string_equals_literal(op_name, "scale")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.vector_scale");
    } else if (zend_string_equals_literal(op_name, "mul")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.vector_mul");
    } else if (zend_string_equals_literal(op_name, "silu")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.vector_silu");
    } else if (zend_string_equals_literal(op_name, "gelu_tanh")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.ffn_activation_gelu_tanh");
    } else if (zend_string_equals_literal(op_name, "slice")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.vector_slice");
    } else if (zend_string_equals_literal(op_name, "rope")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.rope_f32");
    } else if (zend_string_equals_literal(op_name, "kv_write")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.kv_cache_write");
    } else if (zend_string_equals_literal(op_name, "kv_attention")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.attention_scores");
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.attention_softmax");
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.attention_values");
    } else if (zend_string_equals_literal(op_name, "stack")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "cuda.copy_to_offset");
    } else if (zend_string_equals_literal(op_name, "argmax_token")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "host.bounded_logits_argmax");
    } else if (zend_string_equals_literal(op_name, "sample_token")) {
        king_inference_cuda_decoder_graph_executor_plan_add_kernel(step_kernels, global_kernels, kernel_order, step_order, node_id, op_name, owner, "host.bounded_logits_sample");
    }
}

static void king_inference_cuda_decoder_graph_executor_plan_add_boundaries(
    zval *step_boundaries,
    zval *global_boundaries,
    zend_ulong step_order,
    zend_string *node_id,
    zend_string *op_name
) {
    king_inference_cuda_decoder_graph_executor_plan_add_boundary(step_boundaries, global_boundaries, step_order, node_id, op_name, "validation_preflight");
    if (king_inference_cuda_decoder_graph_executor_host_sampling_op(op_name)) {
        king_inference_cuda_decoder_graph_executor_plan_add_boundary(step_boundaries, global_boundaries, step_order, node_id, op_name, "bounded_logits_readback");
        king_inference_cuda_decoder_graph_executor_plan_add_boundary(step_boundaries, global_boundaries, step_order, node_id, op_name, "host_sampling");
        return;
    }
    if (zend_string_equals_literal(op_name, "kv_attention")) {
        king_inference_cuda_decoder_graph_executor_plan_add_boundary(step_boundaries, global_boundaries, step_order, node_id, op_name, "kv_cache_read");
    }
    if (zend_string_equals_literal(op_name, "kv_write")) {
        king_inference_cuda_decoder_graph_executor_plan_add_boundary(step_boundaries, global_boundaries, step_order, node_id, op_name, "kv_cache_write");
    }
    king_inference_cuda_decoder_graph_executor_plan_add_boundary(step_boundaries, global_boundaries, step_order, node_id, op_name, "device_allocation");
    king_inference_cuda_decoder_graph_executor_plan_add_boundary(step_boundaries, global_boundaries, step_order, node_id, op_name, "cuda_kernel");
}

static zend_result king_inference_cuda_decoder_graph_executor_prepare_plan(
    king_inference_model_object *model,
    zval *graph,
    zval *result
) {
    zval *ops = king_inference_cuda_decoder_graph_executor_ops(graph);
    zval *entry;
    zval plan;
    zval steps;
    zval buffers;
    zval kernel_sequence;
    zval failure_boundaries;
    zval device_host_handoffs;
    zval read_counts;
    zval read_last;
    zval defined;
    zval lengths;
    zend_ulong numeric_index;
    zend_string *string_index;
    zend_ulong total_ops = 0;
    zend_ulong device_ops = 0;
    zend_ulong host_sampling_ops = 0;
    zend_ulong kernel_order = 0;
    zend_result status = SUCCESS;

    if (ops == NULL) {
        king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_plan_ops_missing");
        return FAILURE;
    }

    array_init(&read_counts);
    array_init(&read_last);
    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(ops), numeric_index, string_index, entry) {
        if (string_index != NULL || numeric_index > (zend_ulong) ZEND_LONG_MAX) {
            zval_ptr_dtor(&read_last);
            zval_ptr_dtor(&read_counts);
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_plan_requires_numeric_ordered_ops");
            return FAILURE;
        }
        if (entry != NULL && Z_TYPE_P(entry) == IS_ARRAY) {
            king_inference_cuda_decoder_graph_executor_plan_scan_reads(entry, &read_counts, &read_last, numeric_index);
        }
    } ZEND_HASH_FOREACH_END();

    array_init(&defined);
    array_init(&lengths);
    if (king_inference_cuda_decoder_graph_executor_validate_graph_inputs(
        model,
        graph,
        &defined,
        &lengths
    ) != SUCCESS) {
        zval_ptr_dtor(&lengths);
        zval_ptr_dtor(&defined);
        zval_ptr_dtor(&read_last);
        zval_ptr_dtor(&read_counts);
        return FAILURE;
    }

    array_init(&steps);
    array_init(&buffers);
    array_init(&kernel_sequence);
    array_init(&failure_boundaries);
    array_init(&device_host_handoffs);

    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(ops), numeric_index, string_index, entry) {
        zend_string *id = NULL;
        zend_string *op_name = NULL;
        zend_ulong output_length = 0;
        zend_long last_read = (zend_long) numeric_index;
        zend_long read_count = 0;
        zval *last_read_value;
        zval *read_count_value;
        zval marker;
        zval step;
        zval reads;
        zval writes;
        zval shape;
        zval lifetime;
        zval step_kernels;
        zval step_boundaries;
        zval buffer;
        const char *owner;
        const char *dtype;
        const char *allocation_scope;

        if (string_index != NULL
            || numeric_index > (zend_ulong) ZEND_LONG_MAX
            || entry == NULL
            || Z_TYPE_P(entry) != IS_ARRAY
            || king_inference_graph_required_string(entry, "id", &id) != SUCCESS
            || king_inference_graph_required_string(entry, "op", &op_name) != SUCCESS) {
            king_inference_cuda_decoder_graph_executor_set_error(model, -1, "cuda_decoder_graph_plan_op_invalid");
            status = FAILURE;
            break;
        }
        if (zend_hash_exists(Z_ARRVAL(defined), id)) {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "execution_plan_write",
                id,
                "id",
                "duplicate_output_id"
            );
            break;
        }
        if (!king_inference_cuda_decoder_graph_executor_supported_op(op_name)) {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "execution_plan_unsupported",
                id,
                "op",
                ZSTR_VAL(op_name)
            );
            break;
        }

        array_init(&reads);
        if (king_inference_cuda_decoder_graph_executor_plan_collect_reads(
            model,
            entry,
            id,
            &defined,
            &reads
        ) != SUCCESS) {
            zval_ptr_dtor(&reads);
            status = FAILURE;
            break;
        }
        if (king_inference_cuda_decoder_graph_executor_validate_op_contract(
            model,
            entry,
            id,
            op_name,
            &defined,
            &lengths,
            &output_length
        ) != SUCCESS
            || king_inference_cuda_decoder_graph_executor_set_validated_length(
                model,
                &lengths,
                id,
                output_length
        ) != SUCCESS) {
            zval_ptr_dtor(&reads);
            status = FAILURE;
            break;
        }

        owner = king_inference_cuda_decoder_graph_executor_plan_owner(op_name);
        dtype = king_inference_cuda_decoder_graph_executor_plan_dtype(op_name);
        allocation_scope = king_inference_cuda_decoder_graph_executor_plan_allocation_scope(op_name);
        last_read_value = zend_hash_find(Z_ARRVAL(read_last), id);
        read_count_value = zend_hash_find(Z_ARRVAL(read_counts), id);
        if (last_read_value != NULL && Z_TYPE_P(last_read_value) == IS_LONG) {
            last_read = Z_LVAL_P(last_read_value);
        }
        if (read_count_value != NULL && Z_TYPE_P(read_count_value) == IS_LONG) {
            read_count = Z_LVAL_P(read_count_value);
        }

        array_init(&writes);
        add_next_index_str(&writes, zend_string_copy(id));
        array_init(&shape);
        add_assoc_string(&shape, "rank", "vector");
        add_assoc_long(&shape, "length", (zend_long) output_length);
        add_assoc_string(&shape, "dtype", dtype);
        array_init(&lifetime);
        add_assoc_long(&lifetime, "produce_step", (zend_long) numeric_index);
        add_assoc_long(&lifetime, "last_read_step", last_read);
        add_assoc_long(&lifetime, "read_count", read_count);
        add_assoc_string(&lifetime, "allocation_scope", allocation_scope);

        array_init(&step_kernels);
        king_inference_cuda_decoder_graph_executor_plan_add_kernel_sequence(
            &step_kernels,
            &kernel_sequence,
            &kernel_order,
            numeric_index,
            id,
            op_name,
            owner
        );
        array_init(&step_boundaries);
        king_inference_cuda_decoder_graph_executor_plan_add_boundaries(
            &step_boundaries,
            &failure_boundaries,
            numeric_index,
            id,
            op_name
        );

        array_init(&step);
        add_assoc_long(&step, "order", (zend_long) total_ops);
        add_assoc_long(&step, "op_index", (zend_long) numeric_index);
        add_assoc_str(&step, "node", zend_string_copy(id));
        add_assoc_str(&step, "op", zend_string_copy(op_name));
        add_assoc_string(&step, "owner", owner);
        add_assoc_zval(&step, "reads", &reads);
        add_assoc_zval(&step, "writes", &writes);
        add_assoc_zval(&step, "shape", &shape);
        add_assoc_zval(&step, "buffer_lifetime", &lifetime);
        add_assoc_zval(&step, "kernel_sequence", &step_kernels);
        add_assoc_zval(&step, "failure_boundaries", &step_boundaries);
        add_next_index_zval(&steps, &step);

        array_init(&buffer);
        add_assoc_str(&buffer, "id", zend_string_copy(id));
        add_assoc_str(&buffer, "producer_op", zend_string_copy(op_name));
        add_assoc_string(&buffer, "owner", owner);
        add_assoc_string(&buffer, "dtype", dtype);
        add_assoc_long(&buffer, "length", (zend_long) output_length);
        add_assoc_long(&buffer, "produce_step", (zend_long) numeric_index);
        add_assoc_long(&buffer, "last_read_step", last_read);
        add_assoc_long(&buffer, "read_count", read_count);
        add_assoc_string(&buffer, "allocation_scope", allocation_scope);
        add_next_index_zval(&buffers, &buffer);

        if (king_inference_cuda_decoder_graph_executor_device_op(op_name)) {
            device_ops++;
        } else if (king_inference_cuda_decoder_graph_executor_host_sampling_op(op_name)) {
            zval handoff;

            host_sampling_ops++;
            array_init(&handoff);
            add_assoc_str(&handoff, "node", zend_string_copy(id));
            add_assoc_string(&handoff, "from", "cuda_device_bounded_logits");
            add_assoc_string(&handoff, "to", "host_sampler");
            add_assoc_string(&handoff, "boundary", "bounded_logits_readback");
            add_next_index_zval(&device_host_handoffs, &handoff);
        } else {
            status = king_inference_cuda_decoder_graph_executor_validation_error(
                model,
                "execution_plan_unsupported",
                id,
                "op",
                ZSTR_VAL(op_name)
            );
            break;
        }

        ZVAL_TRUE(&marker);
        zend_hash_update(Z_ARRVAL(defined), id, &marker);
        total_ops++;
    } ZEND_HASH_FOREACH_END();

    if (status == SUCCESS && host_sampling_ops > 0 && !model->cuda_decoder_graph_executor_sampling_readback_available) {
        king_inference_cuda_decoder_graph_executor_set_error(
            model,
            -1,
            "cuda_decoder_graph_plan_sampling_readback_unavailable"
        );
        status = FAILURE;
    }

    zval_ptr_dtor(&lengths);
    zval_ptr_dtor(&defined);
    zval_ptr_dtor(&read_last);
    zval_ptr_dtor(&read_counts);

    if (status != SUCCESS) {
        zval_ptr_dtor(&device_host_handoffs);
        zval_ptr_dtor(&failure_boundaries);
        zval_ptr_dtor(&kernel_sequence);
        zval_ptr_dtor(&buffers);
        zval_ptr_dtor(&steps);
        return FAILURE;
    }

    array_init(&plan);
    add_assoc_string(&plan, "type", "gpu_decoder_graph_execution_plan");
    add_assoc_long(&plan, "schema_version", 2);
    add_assoc_bool(&plan, "ready", true);
    add_assoc_bool(&plan, "dependency_closure_proven", true);
    add_assoc_bool(&plan, "prompt_content_free_debug_surface", true);
    add_assoc_bool(&plan, "device_execution_result_ready", false);
    add_assoc_bool(&plan, "token_result_ready", false);
    add_assoc_bool(&plan, "device_ops_ready", true);
    add_assoc_bool(&plan, "host_sampling_after_bounded_readback", host_sampling_ops > 0);
    add_assoc_long(&plan, "total_ops", (zend_long) total_ops);
    add_assoc_long(&plan, "device_ops", (zend_long) device_ops);
    add_assoc_long(&plan, "host_sampling_ops", (zend_long) host_sampling_ops);
    add_assoc_long(&plan, "kernel_count", (zend_long) kernel_order);
    add_assoc_string(&plan, "next_bridge", "execute_device_ops_and_write_bounded_logits_result");
    add_assoc_zval(&plan, "steps", &steps);
    add_assoc_zval(&plan, "buffers", &buffers);
    add_assoc_zval(&plan, "kernel_sequence", &kernel_sequence);
    add_assoc_zval(&plan, "failure_boundaries", &failure_boundaries);
    add_assoc_zval(&plan, "device_host_handoffs", &device_host_handoffs);
    add_assoc_zval(result, "execution_plan", &plan);
    add_assoc_bool(result, "execution_plan_ready", true);
    add_assoc_bool(result, "execution_plan_dependency_closure_proven", true);
    add_assoc_bool(result, "execution_plan_prompt_content_free", true);
    add_assoc_long(result, "planned_device_ops", (zend_long) device_ops);
    add_assoc_long(result, "planned_host_sampling_ops", (zend_long) host_sampling_ops);
    add_assoc_long(result, "planned_kernel_count", (zend_long) kernel_order);

    model->cuda_decoder_graph_executor_plan_count++;
    model->cuda_decoder_graph_executor_last_plan_op_count = total_ops;
    model->cuda_decoder_graph_executor_last_plan_device_ops = device_ops;
    model->cuda_decoder_graph_executor_last_plan_host_sampling_ops = host_sampling_ops;
    return SUCCESS;
}
