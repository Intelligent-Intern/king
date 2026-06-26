static zend_result king_inference_decode_graph_double_option(
    zval *options,
    const char *key,
    double fallback,
    double *value_out
) {
    zval *value = king_inference_array_find(options, key);

    *value_out = fallback;
    if (value == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(value) == IS_LONG) {
        *value_out = (double) Z_LVAL_P(value);
    } else if (Z_TYPE_P(value) == IS_DOUBLE) {
        *value_out = Z_DVAL_P(value);
    } else {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph option '%s' must be a finite number.", key);
        return FAILURE;
    }
    if (!isfinite(*value_out)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph option '%s' must be a finite number.", key);
        return FAILURE;
    }
    return SUCCESS;
}

static bool king_inference_decode_graph_bool_option(zval *options, const char *key, bool fallback)
{
    zval *value = king_inference_array_find(options, key);

    if (value == NULL) {
        return fallback;
    }
    return zend_is_true(value);
}

static zend_result king_inference_decode_graph_token_id_from_source(
    king_inference_model_object *model,
    zval *source,
    zend_ulong position,
    zend_ulong *token_id_out
) {
    zval *token = source;

    if (source != NULL && Z_TYPE_P(source) == IS_ARRAY) {
        zval *tokens = king_inference_array_find(source, "tokens");

        if (tokens == NULL) {
            tokens = source;
        }
        if (Z_TYPE_P(tokens) != IS_ARRAY) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph tokenizer output must contain a tokens array.");
            return FAILURE;
        }
        token = zend_hash_index_find(Z_ARRVAL_P(tokens), position);
        if (token == NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph tokenizer output does not contain tokens[%lu].", (unsigned long) position);
            return FAILURE;
        }
    }
    if (token == NULL || Z_TYPE_P(token) != IS_LONG || Z_LVAL_P(token) < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph token must be a non-negative integer or tokenizer output containing tokens[position].");
        return FAILURE;
    }
    if (model->gguf.tokenizer_token_count > 0
        && (zend_ulong) Z_LVAL_P(token) >= model->gguf.tokenizer_token_count) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph token id exceeds the loaded tokenizer vocabulary.");
        return FAILURE;
    }

    *token_id_out = (zend_ulong) Z_LVAL_P(token);
    return SUCCESS;
}

static zend_result king_inference_decode_graph_required_shape(
    king_inference_model_object *model,
    bool emit_token,
    zend_ulong *layers_out,
    zend_ulong *heads_out,
    zend_ulong *kv_heads_out,
    zend_ulong *key_length_out,
    zend_ulong *value_length_out,
    zend_ulong *vocab_out
) {
    zend_ulong embedding = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];
    zend_ulong layers = model->gguf.architecture_params[KING_INFERENCE_ARCH_BLOCK_COUNT];
    zend_ulong heads = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT];
    zend_ulong kv_heads = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_HEAD_COUNT_KV];
    zend_ulong key_length = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_KEY_LENGTH];
    zend_ulong value_length = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_VALUE_LENGTH];
    zend_ulong feed_forward = model->gguf.architecture_params[KING_INFERENCE_ARCH_FEED_FORWARD_LENGTH];
    zend_ulong vocab = model->gguf.tokenizer_token_count;

    if (layers == 0 || heads == 0 || embedding == 0 || feed_forward == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph requires loaded block, head, embedding, and feed-forward metadata.");
        return FAILURE;
    }
    if (kv_heads == 0) {
        kv_heads = heads;
    }
    if (key_length == 0) {
        if (embedding % heads != 0) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph cannot derive attention key length from embedding and head count.");
            return FAILURE;
        }
        key_length = embedding / heads;
    }
    if (value_length == 0) {
        value_length = key_length;
    }
    if (key_length == 0 || value_length == 0 || kv_heads == 0 || heads < kv_heads) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph requires valid attention key/value dimensions.");
        return FAILURE;
    }
    if ((key_length % 2) != 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph requires an even attention key length for RoPE.");
        return FAILURE;
    }
    if (emit_token && vocab == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph requires tokenizer vocabulary metadata when token emission is enabled.");
        return FAILURE;
    }

    *layers_out = layers;
    *heads_out = heads;
    *kv_heads_out = kv_heads;
    *key_length_out = key_length;
    *value_length_out = value_length;
    *vocab_out = vocab;
    return SUCCESS;
}

static zend_result king_inference_decode_graph_format_id(
    char *buffer,
    size_t buffer_size,
    const char *format,
    zend_ulong first,
    zend_ulong second
) {
    int written = snprintf(buffer, buffer_size, format, (unsigned long) first, (unsigned long) second);

    if (written <= 0 || (size_t) written >= buffer_size) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference token decode graph id buffer is too small.");
        return FAILURE;
    }
    return SUCCESS;
}

static void king_inference_decode_graph_start_op(zval *op, const char *id, const char *name)
{
    array_init(op);
    add_assoc_string(op, "id", id);
    add_assoc_string(op, "op", name);
}

static void king_inference_decode_graph_push_op(zval *ops, zval *op)
{
    add_next_index_zval(ops, op);
}

static void king_inference_decode_graph_add_embedding(
    zval *ops,
    zend_string *tensor,
    zend_ulong token_id
) {
    zval op;

    king_inference_decode_graph_start_op(&op, "x", "embedding");
    add_assoc_str(&op, "tensor", zend_string_copy(tensor));
    add_assoc_long(&op, "token_id", (zend_long) token_id);
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_rms_norm(
    zval *ops,
    const char *id,
    const char *input,
    zend_string *weight,
    double epsilon
) {
    zval op;

    king_inference_decode_graph_start_op(&op, id, "rms_norm");
    add_assoc_string(&op, "input", input);
    add_assoc_str(&op, "weight", zend_string_copy(weight));
    add_assoc_double(&op, "epsilon", epsilon);
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_linear(
    zval *ops,
    const char *id,
    const char *input,
    zend_string *weight,
    zend_ulong row_limit
) {
    zval op;

    king_inference_decode_graph_start_op(&op, id, "linear");
    add_assoc_string(&op, "input", input);
    add_assoc_str(&op, "weight", zend_string_copy(weight));
    if (row_limit > 0) {
        add_assoc_long(&op, "row_limit", (zend_long) row_limit);
    }
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_binary(
    zval *ops,
    const char *id,
    const char *op_name,
    const char *left,
    const char *right
) {
    zval op;

    king_inference_decode_graph_start_op(&op, id, op_name);
    add_assoc_string(&op, "left", left);
    add_assoc_string(&op, "right", right);
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_unary(
    zval *ops,
    const char *id,
    const char *op_name,
    const char *input
) {
    zval op;

    king_inference_decode_graph_start_op(&op, id, op_name);
    add_assoc_string(&op, "input", input);
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_slice(
    zval *ops,
    const char *id,
    const char *input,
    zend_ulong offset,
    zend_ulong count
) {
    zval op;

    king_inference_decode_graph_start_op(&op, id, "slice");
    add_assoc_string(&op, "input", input);
    add_assoc_long(&op, "offset", (zend_long) offset);
    add_assoc_long(&op, "count", (zend_long) count);
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_rope(
    zval *ops,
    const char *id,
    const char *input,
    zend_ulong position,
    zend_ulong head_dim,
    double rope_base
) {
    zval op;
    zval inv_freqs;
    zend_ulong i;

    king_inference_decode_graph_start_op(&op, id, "rope");
    add_assoc_string(&op, "input", input);
    add_assoc_long(&op, "position", (zend_long) position);
    add_assoc_long(&op, "head_dim", (zend_long) head_dim);
    array_init(&inv_freqs);
    for (i = 0; i < head_dim / 2; i++) {
        add_next_index_double(&inv_freqs, 1.0 / pow(rope_base, ((double) i * 2.0) / (double) head_dim));
    }
    add_assoc_zval(&op, "inv_freqs", &inv_freqs);
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_kv_write(
    zval *ops,
    const char *id,
    const char *cache,
    zend_ulong slot,
    const char *key,
    const char *value
) {
    zval op;

    king_inference_decode_graph_start_op(&op, id, "kv_write");
    add_assoc_string(&op, "cache", cache);
    add_assoc_long(&op, "slot", (zend_long) slot);
    add_assoc_string(&op, "key", key);
    add_assoc_string(&op, "value", value);
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_kv_attention(
    zval *ops,
    const char *id,
    const char *cache,
    const char *query,
    zend_ulong slot_start,
    zend_ulong slot_count,
    double scale
) {
    zval op;

    king_inference_decode_graph_start_op(&op, id, "kv_attention");
    add_assoc_string(&op, "cache", cache);
    add_assoc_string(&op, "query", query);
    add_assoc_long(&op, "slot_start", (zend_long) slot_start);
    add_assoc_long(&op, "slot_count", (zend_long) slot_count);
    add_assoc_double(&op, "scale", scale);
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_stack(zval *ops, const char *id, zval *inputs)
{
    zval op;
    zval copied_inputs;

    king_inference_decode_graph_start_op(&op, id, "stack");
    ZVAL_COPY(&copied_inputs, inputs);
    add_assoc_zval(&op, "inputs", &copied_inputs);
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_sampler(
    zval *ops,
    const char *logits,
    zend_ulong position,
    zval *options
) {
    zval op;
    zval *sampler = king_inference_array_find(options, "sampler");

    if (sampler != NULL && Z_TYPE_P(sampler) == IS_STRING && zend_string_equals_literal(Z_STR_P(sampler), "argmax")) {
        king_inference_decode_graph_start_op(&op, "next_token", "argmax_token");
        add_assoc_string(&op, "logits", logits);
        king_inference_decode_graph_push_op(ops, &op);
        return;
    }

    king_inference_decode_graph_start_op(&op, "next_token", "sample_token");
    add_assoc_string(&op, "logits", logits);
    add_assoc_double(&op, "temperature", king_inference_double_from_array(options, "temperature", 0.7));
    add_assoc_long(&op, "top_k", king_inference_long_from_array(options, "top_k", 40));
    add_assoc_double(&op, "top_p", king_inference_double_from_array(options, "top_p", 0.95));
    add_assoc_long(&op, "seed", king_inference_long_from_array(options, "seed", 0));
    add_assoc_long(&op, "sample_index", (zend_long) position);
    king_inference_decode_graph_push_op(ops, &op);
}

static zend_ulong king_inference_decode_graph_kv_head(zend_ulong head, zend_ulong heads, zend_ulong kv_heads)
{
    zend_ulong kv_head;

    if (kv_heads == heads) {
        return head;
    }
    kv_head = (head * kv_heads) / heads;
    return kv_head >= kv_heads ? kv_heads - 1 : kv_head;
}

static zend_result king_inference_token_decode_graph_array(
    king_inference_model_object *model,
    zval *token_source,
    zend_ulong position,
    zval *options,
    zval *return_value
) {
    zend_ulong layers;
    zend_ulong heads;
    zend_ulong kv_heads;
    zend_ulong key_length;
    zend_ulong value_length;
    zend_ulong vocab;
    zend_ulong sliding_window = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_SLIDING_WINDOW];
    zend_ulong slot_start;
    zend_ulong slot_count;
    zend_ulong layer;
    zend_ulong token_id;
    zend_string *token_embedding = NULL;
    zend_string *output_projection = NULL;
    zend_string *final_norm = NULL;
    const char *status = NULL;
    bool emit_token = king_inference_decode_graph_bool_option(options, "emit_token", true);
    double epsilon;
    double rope_base;
    zval ops;
    zval *state;

    if (king_inference_decode_graph_required_shape(model, emit_token, &layers, &heads, &kv_heads, &key_length, &value_length, &vocab) != SUCCESS
        || king_inference_decode_graph_double_option(options, "epsilon", 0.000001, &epsilon) != SUCCESS
        || king_inference_decode_graph_double_option(options, "rope_base", 10000.0, &rope_base) != SUCCESS
        || king_inference_decode_graph_token_id_from_source(model, token_source, position, &token_id) != SUCCESS) {
        return FAILURE;
    }
    if (epsilon <= 0.0 || rope_base <= 0.0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph epsilon and rope_base must be positive.");
        return FAILURE;
    }

    if (king_inference_resolve_token_embedding_tensor_name(model, NULL, &token_embedding, NULL, &status) != SUCCESS
        || token_embedding == NULL) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph could not resolve token embedding tensor: %s.", status != NULL ? status : "not_found");
        return FAILURE;
    }
    if (king_inference_resolve_rms_norm_final_name(model, &final_norm, NULL, &status) != SUCCESS
        || final_norm == NULL) {
        zend_string_release(token_embedding);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph could not resolve final RMSNorm tensor: %s.", status != NULL ? status : "not_found");
        return FAILURE;
    }
    if (emit_token
        && (king_inference_resolve_output_projection_tensor_name(model, NULL, &output_projection, NULL, &status, NULL) != SUCCESS
            || output_projection == NULL)) {
        zend_string_release(token_embedding);
        zend_string_release(final_norm);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph could not resolve output projection tensor: %s.", status != NULL ? status : "not_found");
        return FAILURE;
    }

    slot_start = sliding_window > 0 && position >= sliding_window ? position - sliding_window + 1 : 0;
    slot_count = position - slot_start + 1;
    array_init(&ops);
    king_inference_decode_graph_add_embedding(&ops, token_embedding, token_id);
    zend_string_release(token_embedding);

    for (layer = 0; layer < layers; layer++) {
        zend_string *attn_norm = NULL;
        zend_string *attn_q = NULL;
        zend_string *attn_k = NULL;
        zend_string *attn_v = NULL;
        zend_string *attn_out = NULL;
        zend_string *ffn_norm = NULL;
        zend_string *ffn_gate = NULL;
        zend_string *ffn_up = NULL;
        zend_string *ffn_down = NULL;
        char hidden[64];
        char attn_norm_id[64];
        char q_id[64];
        char k_id[64];
        char v_id[64];
        char context_id[64];
        char attn_out_id[64];
        char attn_residual_id[64];
        char ffn_norm_id[64];
        char gate_id[64];
        char up_id[64];
        char gate_act_id[64];
        char gated_id[64];
        char down_id[64];
        char output_id[64];
        zval contexts;
        zend_ulong head;

        if (king_inference_decode_graph_format_id(hidden, sizeof(hidden), layer == 0 ? "x" : "l%lu_output", layer == 0 ? 0 : layer - 1, 0) != SUCCESS
            || king_inference_decode_graph_format_id(attn_norm_id, sizeof(attn_norm_id), "l%lu_attn_norm", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(q_id, sizeof(q_id), "l%lu_q", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(k_id, sizeof(k_id), "l%lu_k", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(v_id, sizeof(v_id), "l%lu_v", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(context_id, sizeof(context_id), "l%lu_context", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(attn_out_id, sizeof(attn_out_id), "l%lu_attn_out", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(attn_residual_id, sizeof(attn_residual_id), "l%lu_attn_residual", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(ffn_norm_id, sizeof(ffn_norm_id), "l%lu_ffn_norm", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(gate_id, sizeof(gate_id), "l%lu_ffn_gate", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(up_id, sizeof(up_id), "l%lu_ffn_up", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(gate_act_id, sizeof(gate_act_id), "l%lu_ffn_gate_silu", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(gated_id, sizeof(gated_id), "l%lu_ffn_gated", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(down_id, sizeof(down_id), "l%lu_ffn_down", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(output_id, sizeof(output_id), "l%lu_output", layer, 0) != SUCCESS) {
            zval_ptr_dtor(&ops);
            if (output_projection != NULL) {
                zend_string_release(output_projection);
            }
            zend_string_release(final_norm);
            return FAILURE;
        }

        if (king_inference_resolve_rms_norm_layer_name(model, KING_INFERENCE_RMS_NORM_ATTENTION, layer, &attn_norm, NULL, &status) != SUCCESS
            || king_inference_resolve_attention_tensor_name(model, KING_INFERENCE_ATTENTION_QUERY, layer, &attn_q, NULL, &status) != SUCCESS
            || king_inference_resolve_attention_tensor_name(model, KING_INFERENCE_ATTENTION_KEY, layer, &attn_k, NULL, &status) != SUCCESS
            || king_inference_resolve_attention_tensor_name(model, KING_INFERENCE_ATTENTION_VALUE, layer, &attn_v, NULL, &status) != SUCCESS
            || king_inference_resolve_attention_tensor_name(model, KING_INFERENCE_ATTENTION_OUTPUT, layer, &attn_out, NULL, &status) != SUCCESS
            || king_inference_resolve_rms_norm_layer_name(model, KING_INFERENCE_RMS_NORM_FEED_FORWARD, layer, &ffn_norm, NULL, &status) != SUCCESS
            || king_inference_resolve_ffn_tensor_name(model, KING_INFERENCE_FFN_GATE, layer, &ffn_gate, NULL, &status) != SUCCESS
            || king_inference_resolve_ffn_tensor_name(model, KING_INFERENCE_FFN_UP, layer, &ffn_up, NULL, &status) != SUCCESS
            || king_inference_resolve_ffn_tensor_name(model, KING_INFERENCE_FFN_DOWN, layer, &ffn_down, NULL, &status) != SUCCESS) {
            zval_ptr_dtor(&ops);
            if (attn_norm != NULL) zend_string_release(attn_norm);
            if (attn_q != NULL) zend_string_release(attn_q);
            if (attn_k != NULL) zend_string_release(attn_k);
            if (attn_v != NULL) zend_string_release(attn_v);
            if (attn_out != NULL) zend_string_release(attn_out);
            if (ffn_norm != NULL) zend_string_release(ffn_norm);
            if (ffn_gate != NULL) zend_string_release(ffn_gate);
            if (ffn_up != NULL) zend_string_release(ffn_up);
            if (ffn_down != NULL) zend_string_release(ffn_down);
            if (output_projection != NULL) zend_string_release(output_projection);
            zend_string_release(final_norm);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph could not resolve layer %lu tensor: %s.", (unsigned long) layer, status != NULL ? status : "not_found");
            return FAILURE;
        }

        king_inference_decode_graph_add_rms_norm(&ops, attn_norm_id, hidden, attn_norm, epsilon);
        king_inference_decode_graph_add_linear(&ops, q_id, attn_norm_id, attn_q, 0);
        king_inference_decode_graph_add_linear(&ops, k_id, attn_norm_id, attn_k, 0);
        king_inference_decode_graph_add_linear(&ops, v_id, attn_norm_id, attn_v, 0);
        zend_string_release(attn_norm);
        zend_string_release(attn_q);
        zend_string_release(attn_k);
        zend_string_release(attn_v);

        array_init(&contexts);
        for (head = 0; head < heads; head++) {
            zend_ulong kv_head = king_inference_decode_graph_kv_head(head, heads, kv_heads);
            char q_slice_id[64];
            char k_slice_id[64];
            char v_slice_id[64];
            char q_rope_id[64];
            char k_rope_id[64];
            char cache_id[64];
            char kv_write_id[64];
            char ctx_id[64];

            if (king_inference_decode_graph_format_id(q_slice_id, sizeof(q_slice_id), "l%lu_h%lu_q_slice", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(k_slice_id, sizeof(k_slice_id), "l%lu_h%lu_k_slice", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(v_slice_id, sizeof(v_slice_id), "l%lu_h%lu_v_slice", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(q_rope_id, sizeof(q_rope_id), "l%lu_h%lu_q_rope", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(k_rope_id, sizeof(k_rope_id), "l%lu_h%lu_k_rope", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(cache_id, sizeof(cache_id), "l%lu.h%lu", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(kv_write_id, sizeof(kv_write_id), "l%lu_h%lu_kv_write", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(ctx_id, sizeof(ctx_id), "l%lu_h%lu_ctx", layer, head) != SUCCESS) {
                zval_ptr_dtor(&contexts);
                zval_ptr_dtor(&ops);
                zend_string_release(attn_out);
                zend_string_release(ffn_norm);
                zend_string_release(ffn_gate);
                zend_string_release(ffn_up);
                zend_string_release(ffn_down);
                if (output_projection != NULL) zend_string_release(output_projection);
                zend_string_release(final_norm);
                return FAILURE;
            }
            king_inference_decode_graph_add_slice(&ops, q_slice_id, q_id, head * key_length, key_length);
            king_inference_decode_graph_add_slice(&ops, k_slice_id, k_id, kv_head * key_length, key_length);
            king_inference_decode_graph_add_slice(&ops, v_slice_id, v_id, kv_head * value_length, value_length);
            king_inference_decode_graph_add_rope(&ops, q_rope_id, q_slice_id, position, key_length, rope_base);
            king_inference_decode_graph_add_rope(&ops, k_rope_id, k_slice_id, position, key_length, rope_base);
            king_inference_decode_graph_add_kv_write(&ops, kv_write_id, cache_id, position, k_rope_id, v_slice_id);
            king_inference_decode_graph_add_kv_attention(&ops, ctx_id, cache_id, q_rope_id, slot_start, slot_count, 1.0 / sqrt((double) key_length));
            add_next_index_string(&contexts, ctx_id);
        }
        king_inference_decode_graph_add_stack(&ops, context_id, &contexts);
        zval_ptr_dtor(&contexts);
        king_inference_decode_graph_add_linear(&ops, attn_out_id, context_id, attn_out, 0);
        zend_string_release(attn_out);
        king_inference_decode_graph_add_binary(&ops, attn_residual_id, "add", hidden, attn_out_id);
        king_inference_decode_graph_add_rms_norm(&ops, ffn_norm_id, attn_residual_id, ffn_norm, epsilon);
        zend_string_release(ffn_norm);
        king_inference_decode_graph_add_linear(&ops, gate_id, ffn_norm_id, ffn_gate, 0);
        king_inference_decode_graph_add_linear(&ops, up_id, ffn_norm_id, ffn_up, 0);
        zend_string_release(ffn_gate);
        zend_string_release(ffn_up);
        king_inference_decode_graph_add_unary(&ops, gate_act_id, "silu", gate_id);
        king_inference_decode_graph_add_binary(&ops, gated_id, "mul", gate_act_id, up_id);
        king_inference_decode_graph_add_linear(&ops, down_id, gated_id, ffn_down, 0);
        zend_string_release(ffn_down);
        king_inference_decode_graph_add_binary(&ops, output_id, "add", attn_residual_id, down_id);
    }

    {
        char final_input[64];
        if (king_inference_decode_graph_format_id(final_input, sizeof(final_input), layers == 0 ? "x" : "l%lu_output", layers == 0 ? 0 : layers - 1, 0) != SUCCESS) {
            zval_ptr_dtor(&ops);
            if (output_projection != NULL) zend_string_release(output_projection);
            zend_string_release(final_norm);
            return FAILURE;
        }
        king_inference_decode_graph_add_rms_norm(&ops, "final_norm", final_input, final_norm, epsilon);
    }
    zend_string_release(final_norm);

    if (emit_token) {
        king_inference_decode_graph_add_linear(&ops, "logits", "final_norm", output_projection, vocab);
        zend_string_release(output_projection);
        king_inference_decode_graph_add_sampler(&ops, "logits", position, options);
    }

    array_init(return_value);
    add_assoc_string(return_value, "graph_type", "king_native_cpu_token_decode");
    add_assoc_long(return_value, "token_id", (zend_long) token_id);
    add_assoc_long(return_value, "position", (zend_long) position);
    add_assoc_long(return_value, "block_count", (zend_long) layers);
    add_assoc_long(return_value, "attention_head_count", (zend_long) heads);
    add_assoc_long(return_value, "attention_head_count_kv", (zend_long) kv_heads);
    add_assoc_long(return_value, "attention_key_length", (zend_long) key_length);
    add_assoc_long(return_value, "attention_value_length", (zend_long) value_length);
    add_assoc_zval(return_value, "ops", &ops);
    add_assoc_string(return_value, "output", emit_token ? "next_token" : "final_norm");
    state = king_inference_array_find(options, "state");
    if (state != NULL) {
        if (Z_TYPE_P(state) != IS_ARRAY) {
            zval_ptr_dtor(return_value);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph option 'state' must be an array when provided.");
            return FAILURE;
        }
        {
            zval copied_state;
            ZVAL_COPY(&copied_state, state);
            add_assoc_zval(return_value, "state", &copied_state);
        }
    }

    return SUCCESS;
}
