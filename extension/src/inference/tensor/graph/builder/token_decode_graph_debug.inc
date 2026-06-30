static bool king_inference_decode_graph_debug_long_field(zval *source, const char *key, zend_ulong *value_out)
{
    zval *value = king_inference_array_find(source, key);

    if (value == NULL || Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
        return false;
    }

    *value_out = (zend_ulong) Z_LVAL_P(value);
    return true;
}

static zval *king_inference_decode_graph_debug_find_op(zval *graph, const char *id)
{
    zval *ops = king_inference_array_find(graph, "ops");
    zval *entry;

    if (ops == NULL || Z_TYPE_P(ops) != IS_ARRAY) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(ops), entry) {
        zval *op_id;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        op_id = king_inference_array_find(entry, "id");
        if (op_id != NULL
            && Z_TYPE_P(op_id) == IS_STRING
            && strcmp(Z_STRVAL_P(op_id), id) == 0) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zend_result king_inference_decode_graph_debug_require_op(zval *graph, const char *stage, const char *id)
{
    if (king_inference_decode_graph_debug_find_op(graph, id) != NULL) {
        return SUCCESS;
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "king.graph.decode_missing_op: token decode graph debug stage '%s' requires op '%s'.",
        stage,
        id
    );
    return FAILURE;
}

static zend_string *king_inference_decode_graph_debug_op_string(zval *graph, const char *stage, const char *id, const char *field)
{
    zval *op = king_inference_decode_graph_debug_find_op(graph, id);
    zval *value;

    if (op == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king.graph.decode_missing_op: token decode graph debug stage '%s' requires op '%s'.",
            stage,
            id
        );
        return NULL;
    }

    value = king_inference_array_find(op, field);
    if (value == NULL || Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king.graph.decode_missing_op_field: token decode graph debug stage '%s' op '%s' requires string field '%s'.",
            stage,
            id,
            field
        );
        return NULL;
    }

    return Z_STR_P(value);
}

static zend_result king_inference_decode_graph_debug_tensor(
    king_inference_model_object *model,
    zval *tensors,
    const char *stage,
    const char *role,
    zend_string *name,
    zend_ulong expected_rank,
    zend_ulong expected_dim0,
    zend_ulong expected_dim1,
    bool expected_dim1_at_least
) {
    zval *descriptor;
    zval *dimensions;
    zval dimensions_copy;
    zval tensor;
    king_inference_tensor_type_info type_info;
    zend_ulong rank = 0;
    zend_ulong type = 0;
    zend_ulong dim0 = 0;
    zend_ulong dim1 = 0;
    bool known_type;

    descriptor = king_inference_tensor_index_descriptor(model, name);
    if (descriptor == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king.graph.decode_tensor_not_found: token decode graph debug stage '%s' tensor '%s' is missing.",
            stage,
            ZSTR_VAL(name)
        );
        return FAILURE;
    }

    dimensions = zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1);
    if (!king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)
        || dimensions == NULL
        || Z_TYPE_P(dimensions) != IS_ARRAY
        || rank != expected_rank
        || king_inference_tensor_dimension_at(dimensions, 0, &dim0) != SUCCESS
        || (expected_rank > 1 && king_inference_tensor_dimension_at(dimensions, 1, &dim1) != SUCCESS)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king.graph.decode_tensor_invalid_shape: token decode graph debug stage '%s' tensor '%s' has invalid rank or dimensions.",
            stage,
            ZSTR_VAL(name)
        );
        return FAILURE;
    }

    if ((expected_dim0 > 0 && dim0 != expected_dim0)
        || (expected_rank > 1
            && expected_dim1 > 0
            && (expected_dim1_at_least ? dim1 < expected_dim1 : dim1 != expected_dim1))) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king.graph.decode_tensor_invalid_shape: token decode graph debug stage '%s' tensor '%s' does not match expected shape.",
            stage,
            ZSTR_VAL(name)
        );
        return FAILURE;
    }

    known_type = king_inference_tensor_type_lookup(type, &type_info);
    if (!known_type) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king.graph.decode_tensor_invalid_dtype: token decode graph debug stage '%s' tensor '%s' has unknown tensor type %lu.",
            stage,
            ZSTR_VAL(name),
            (unsigned long) type
        );
        return FAILURE;
    }

    array_init(&tensor);
    add_assoc_string(&tensor, "role", role);
    add_assoc_str(&tensor, "name", zend_string_copy(name));
    add_assoc_long(&tensor, "rank", (zend_long) rank);
    ZVAL_COPY(&dimensions_copy, dimensions);
    add_assoc_zval(&tensor, "dimensions", &dimensions_copy);
    add_assoc_long(&tensor, "type", (zend_long) type);
    add_assoc_string(&tensor, "dtype", type_info.name);
    add_assoc_long(&tensor, "expected_rank", (zend_long) expected_rank);
    if (expected_dim0 > 0) {
        add_assoc_long(&tensor, "expected_dim0", (zend_long) expected_dim0);
    }
    if (expected_rank > 1 && expected_dim1 > 0) {
        add_assoc_long(&tensor, expected_dim1_at_least ? "expected_dim1_min" : "expected_dim1", (zend_long) expected_dim1);
    }
    add_assoc_string(&tensor, "validation_error_code", "none");
    add_next_index_zval(tensors, &tensor);
    return SUCCESS;
}

static void king_inference_decode_graph_debug_add_op_ids(zval *target, const char * const *ids)
{
    size_t index;

    array_init(target);
    for (index = 0; ids[index] != NULL; index++) {
        add_next_index_string(target, ids[index]);
    }
}

static void king_inference_decode_graph_debug_add_output_shape(zval *stage, const char *name, zend_ulong length)
{
    zval shape;

    array_init(&shape);
    add_assoc_string(&shape, "name", name);
    add_assoc_long(&shape, "length", (zend_long) length);
    add_assoc_zval(stage, "output_shape", &shape);
}

static void king_inference_decode_graph_debug_finish_stage(
    zval *stages,
    zval *stage,
    const char *output_name,
    zend_ulong output_length
) {
    zval validation;

    king_inference_decode_graph_debug_add_output_shape(stage, output_name, output_length);
    array_init(&validation);
    add_assoc_string(&validation, "status", "validated");
    add_assoc_string(&validation, "error_code", "none");
    add_assoc_zval(stage, "validation", &validation);
    add_next_index_zval(stages, stage);
}

static zend_result king_inference_decode_graph_debug_add_stage(
    zval *stages,
    zval *graph,
    const char *stage_name,
    const char * const *op_ids,
    const char *output_name,
    zend_ulong output_length
) {
    zval stage;
    zval ops;
    zval tensors;
    size_t index;

    array_init(&stage);
    add_assoc_string(&stage, "stage", stage_name);
    add_assoc_long(&stage, "layer", 0);
    king_inference_decode_graph_debug_add_op_ids(&ops, op_ids);
    add_assoc_zval(&stage, "ops", &ops);
    array_init(&tensors);

    for (index = 0; op_ids[index] != NULL; index++) {
        if (king_inference_decode_graph_debug_require_op(graph, stage_name, op_ids[index]) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            return FAILURE;
        }
    }

    add_assoc_zval(&stage, "tensors", &tensors);
    king_inference_decode_graph_debug_finish_stage(stages, &stage, output_name, output_length);
    return SUCCESS;
}

static zend_result king_inference_decode_graph_attach_debug(king_inference_model_object *model, zval *graph)
{
    static const char *embedding_ops[] = {"x", NULL};
    static const char *attn_norm_ops[] = {"l0_attn_norm", NULL};
    static const char *qkv_ops[] = {"l0_q", "l0_k", "l0_v", NULL};
    static const char *attention_ops[] = {"l0_h0_q_slice", "l0_h0_k_slice", "l0_h0_v_slice", "l0_h0_q_rope", "l0_h0_k_rope", "l0_h0_kv_write", "l0_h0_ctx", "l0_context", NULL};
    static const char *attn_out_ops[] = {"l0_attn_out", "l0_attn_residual", NULL};
    static const char *ffn_ops[] = {"l0_ffn_norm", "l0_ffn_gate", "l0_ffn_up", "l0_ffn_gate_silu", "l0_ffn_gated", "l0_ffn_down", "l0_output", NULL};
    static const char *final_norm_ops[] = {"final_norm", NULL};
    static const char *logits_ops[] = {"logits", NULL};
    zval *debug_layer_limit_value = king_inference_array_find(graph, "debug_layer_limit");
    zval *terminal = king_inference_array_find(graph, "terminal");
    zval debug;
    zval stages;
    zval tensors;
    zval validation;
    zend_ulong token_id = 0;
    zend_ulong position = 0;
    zend_ulong embedding = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong feed_forward = model->gguf.architecture_params[KING_INFERENCE_ARCH_FEED_FORWARD_LENGTH];
    zend_ulong vocab = model->gguf.tokenizer_token_count;
    zend_ulong heads = 0;
    zend_ulong kv_heads = 0;
    zend_ulong key_length = 0;
    zend_ulong value_length = 0;
    zend_string *tensor_name;

    if (debug_layer_limit_value == NULL
        || Z_TYPE_P(debug_layer_limit_value) != IS_LONG
        || Z_LVAL_P(debug_layer_limit_value) <= 0) {
        return SUCCESS;
    }
    if (!king_inference_decode_graph_debug_long_field(graph, "token_id", &token_id)
        || !king_inference_decode_graph_debug_long_field(graph, "position", &position)
        || !king_inference_decode_graph_debug_long_field(graph, "attention_head_count", &heads)
        || !king_inference_decode_graph_debug_long_field(graph, "attention_head_count_kv", &kv_heads)
        || !king_inference_decode_graph_debug_long_field(graph, "attention_key_length", &key_length)
        || !king_inference_decode_graph_debug_long_field(graph, "attention_value_length", &value_length)
        || terminal == NULL
        || Z_TYPE_P(terminal) != IS_ARRAY
        || embedding == 0
        || feed_forward == 0
        || vocab == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "king.graph.decode_debug_metadata_invalid: token decode graph debug requires complete layer-0 shape metadata.");
        return FAILURE;
    }

    array_init(&debug);
    add_assoc_long(&debug, "schema_version", 1);
    add_assoc_string(&debug, "scope", "single_token_cpu_decode_layer0");
    add_assoc_string(&debug, "status", "validated");
    add_assoc_string(&debug, "error_code", "none");
    add_assoc_long(&debug, "layer", 0);
    add_assoc_long(&debug, "token_id", (zend_long) token_id);
    add_assoc_long(&debug, "position", (zend_long) position);
    add_assoc_long(&debug, "hidden_size", (zend_long) embedding);
    add_assoc_long(&debug, "feed_forward_size", (zend_long) feed_forward);
    add_assoc_long(&debug, "vocab_size", (zend_long) vocab);
    array_init(&stages);

    {
        zval stage;
        zval ops;
        array_init(&stage);
        add_assoc_string(&stage, "stage", "embedding");
        add_assoc_long(&stage, "layer", 0);
        king_inference_decode_graph_debug_add_op_ids(&ops, embedding_ops);
        add_assoc_zval(&stage, "ops", &ops);
        array_init(&tensors);
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "embedding", "x", "tensor");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "embedding", "token_embedding", tensor_name, 2, embedding, vocab, true) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        add_assoc_zval(&stage, "tensors", &tensors);
        king_inference_decode_graph_debug_finish_stage(&stages, &stage, "x", embedding);
    }

    {
        zval stage;
        zval ops;
        array_init(&stage);
        add_assoc_string(&stage, "stage", "attention_norm");
        add_assoc_long(&stage, "layer", 0);
        king_inference_decode_graph_debug_add_op_ids(&ops, attn_norm_ops);
        add_assoc_zval(&stage, "ops", &ops);
        array_init(&tensors);
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "attention_norm", "l0_attn_norm", "weight");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "attention_norm", "attention_norm_weight", tensor_name, 1, embedding, 0, false) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        add_assoc_zval(&stage, "tensors", &tensors);
        king_inference_decode_graph_debug_finish_stage(&stages, &stage, "l0_attn_norm", embedding);
    }

    {
        zval stage;
        zval ops;
        array_init(&stage);
        add_assoc_string(&stage, "stage", "qkv_projection");
        add_assoc_long(&stage, "layer", 0);
        king_inference_decode_graph_debug_add_op_ids(&ops, qkv_ops);
        add_assoc_zval(&stage, "ops", &ops);
        array_init(&tensors);
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "qkv_projection", "l0_q", "weight");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "qkv_projection", "query_weight", tensor_name, 2, embedding, heads * key_length, false) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "qkv_projection", "l0_k", "weight");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "qkv_projection", "key_weight", tensor_name, 2, embedding, kv_heads * key_length, false) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "qkv_projection", "l0_v", "weight");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "qkv_projection", "value_weight", tensor_name, 2, embedding, kv_heads * value_length, false) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        add_assoc_zval(&stage, "tensors", &tensors);
        king_inference_decode_graph_debug_finish_stage(&stages, &stage, "qkv", (heads * key_length) + (kv_heads * key_length) + (kv_heads * value_length));
    }

    if (king_inference_decode_graph_debug_add_stage(&stages, graph, "multi_head_attention", attention_ops, "l0_context", heads * value_length) != SUCCESS) {
        zval_ptr_dtor(&stages);
        zval_ptr_dtor(&debug);
        return FAILURE;
    }

    {
        zval stage;
        zval ops;
        array_init(&stage);
        add_assoc_string(&stage, "stage", "attention_output_projection");
        add_assoc_long(&stage, "layer", 0);
        king_inference_decode_graph_debug_add_op_ids(&ops, attn_out_ops);
        add_assoc_zval(&stage, "ops", &ops);
        array_init(&tensors);
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "attention_output_projection", "l0_attn_out", "weight");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "attention_output_projection", "attention_output_weight", tensor_name, 2, heads * value_length, embedding, false) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        add_assoc_zval(&stage, "tensors", &tensors);
        king_inference_decode_graph_debug_finish_stage(&stages, &stage, "l0_attn_residual", embedding);
    }

    {
        zval stage;
        zval ops;
        array_init(&stage);
        add_assoc_string(&stage, "stage", "ffn_gate_up_down");
        add_assoc_long(&stage, "layer", 0);
        king_inference_decode_graph_debug_add_op_ids(&ops, ffn_ops);
        add_assoc_zval(&stage, "ops", &ops);
        array_init(&tensors);
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "ffn_gate_up_down", "l0_ffn_norm", "weight");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "ffn_gate_up_down", "ffn_norm_weight", tensor_name, 1, embedding, 0, false) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "ffn_gate_up_down", "l0_ffn_gate", "weight");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "ffn_gate_up_down", "ffn_gate_weight", tensor_name, 2, embedding, feed_forward, false) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "ffn_gate_up_down", "l0_ffn_up", "weight");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "ffn_gate_up_down", "ffn_up_weight", tensor_name, 2, embedding, feed_forward, false) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "ffn_gate_up_down", "l0_ffn_down", "weight");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "ffn_gate_up_down", "ffn_down_weight", tensor_name, 2, feed_forward, embedding, false) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        add_assoc_zval(&stage, "tensors", &tensors);
        king_inference_decode_graph_debug_finish_stage(&stages, &stage, "l0_output", embedding);
    }

    {
        zval stage;
        zval ops;
        zval *final_norm_tensor;
        array_init(&stage);
        add_assoc_string(&stage, "stage", "final_norm");
        add_assoc_long(&stage, "layer", 0);
        king_inference_decode_graph_debug_add_op_ids(&ops, final_norm_ops);
        add_assoc_zval(&stage, "ops", &ops);
        array_init(&tensors);
        final_norm_tensor = king_inference_array_find(terminal, "final_norm_tensor");
        if (final_norm_tensor == NULL
            || Z_TYPE_P(final_norm_tensor) != IS_STRING
            || king_inference_decode_graph_debug_tensor(model, &tensors, "final_norm", "final_norm_weight", Z_STR_P(final_norm_tensor), 1, embedding, 0, false) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        add_assoc_zval(&stage, "tensors", &tensors);
        king_inference_decode_graph_debug_finish_stage(&stages, &stage, "final_norm", embedding);
    }

    if (king_inference_decode_graph_debug_find_op(graph, "logits") != NULL) {
        zval stage;
        zval ops;
        array_init(&stage);
        add_assoc_string(&stage, "stage", "logits");
        add_assoc_long(&stage, "layer", 0);
        king_inference_decode_graph_debug_add_op_ids(&ops, logits_ops);
        add_assoc_zval(&stage, "ops", &ops);
        array_init(&tensors);
        tensor_name = king_inference_decode_graph_debug_op_string(graph, "logits", "logits", "weight");
        if (tensor_name == NULL
            || king_inference_decode_graph_debug_tensor(model, &tensors, "logits", "output_projection_weight", tensor_name, 2, embedding, vocab, true) != SUCCESS) {
            zval_ptr_dtor(&tensors);
            zval_ptr_dtor(&stage);
            zval_ptr_dtor(&stages);
            zval_ptr_dtor(&debug);
            return FAILURE;
        }
        add_assoc_zval(&stage, "tensors", &tensors);
        king_inference_decode_graph_debug_finish_stage(&stages, &stage, "logits", vocab);
        add_assoc_bool(&debug, "logits_ready", true);
    } else {
        add_assoc_bool(&debug, "logits_ready", false);
    }

    add_assoc_zval(&debug, "stages", &stages);
    array_init(&validation);
    add_assoc_string(&validation, "status", "validated");
    add_assoc_string(&validation, "error_code", "none");
    add_assoc_zval(&debug, "validation", &validation);
    add_assoc_zval(graph, "debug", &debug);
    return SUCCESS;
}
