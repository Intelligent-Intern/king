#include "token_decode_graph_options.inc"

static zend_result king_inference_decode_graph_required_shape(
    king_inference_model_object *model,
    bool needs_logits,
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
    zend_ulong metadata_key_length = key_length;
    zend_ulong metadata_value_length = value_length;

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
    king_inference_attention_refine_runtime_shape_from_tensors(
        model,
        &heads,
        &kv_heads,
        &key_length,
        &value_length
    );
    if (metadata_key_length > 0) {
        key_length = metadata_key_length;
    }
    if (metadata_value_length > 0) {
        value_length = metadata_value_length;
    }
    if (key_length == 0 || value_length == 0 || kv_heads == 0 || heads < kv_heads) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph requires valid attention key/value dimensions.");
        return FAILURE;
    }
    if ((key_length % 2) != 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph requires an even attention key length for RoPE.");
        return FAILURE;
    }
    if (needs_logits && vocab == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph requires tokenizer vocabulary metadata when logits output is enabled.");
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

static void king_inference_decode_graph_attention_window(
    king_inference_model_object *model,
    zend_ulong position,
    zend_ulong sliding_window,
    zend_ulong *slot_start,
    zend_ulong *slot_count
) {
    zend_ulong start = sliding_window > 0 && position >= sliding_window
        ? position - sliding_window + 1
        : 0;
    *slot_start = start;
    *slot_count = position - start + 1;
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
    zend_ulong token_id,
    double scale
) {
    zval op;

    king_inference_decode_graph_start_op(&op, "x", "embedding");
    add_assoc_str(&op, "tensor", zend_string_copy(tensor));
    add_assoc_long(&op, "token_id", (zend_long) token_id);
    if (scale != 1.0) {
        add_assoc_double(&op, "scale", scale);
    }
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
    double rope_base,
    zend_ulong pairing
) {
    zval op;
    zval inv_freqs;
    zend_ulong i;

    king_inference_decode_graph_start_op(&op, id, "rope");
    add_assoc_string(&op, "input", input);
    add_assoc_long(&op, "position", (zend_long) position);
    add_assoc_long(&op, "head_dim", (zend_long) head_dim);
    add_assoc_double(&op, "rope_base", rope_base);
    add_assoc_long(&op, "pairing", (zend_long) pairing);
    array_init(&inv_freqs);
    for (i = 0; i < head_dim / 2; i++) {
        add_next_index_double(&inv_freqs, 1.0 / pow(rope_base, ((double) i * 2.0) / (double) head_dim));
    }
    add_assoc_zval(&op, "inv_freqs", &inv_freqs);
    king_inference_decode_graph_push_op(ops, &op);
}

static bool king_inference_decode_graph_gemma_profile(king_inference_model_object *model)
{
    return king_inference_gguf_architecture_is_gemma3(&model->gguf)
        || king_inference_gguf_architecture_is_gemma4(&model->gguf);
}

static double king_inference_decode_graph_layer_rope_base(
    king_inference_model_object *model,
    zend_ulong layer,
    double global_rope_base,
    double local_rope_base,
    zend_ulong model_key_length,
    zend_ulong layer_key_length
) {
    if (king_inference_decode_graph_gemma_profile(model)
        && global_rope_base > 0.0
        && local_rope_base > 0.0) {
        return ((layer + 1) % 6) == 0 ? global_rope_base : local_rope_base;
    }

    return local_rope_base > 0.0 && model_key_length > 0 && layer_key_length < model_key_length
        ? local_rope_base
        : global_rope_base;
}

static const char *king_inference_decode_graph_ffn_activation(king_inference_model_object *model)
{
    return king_inference_decode_graph_gemma_profile(model) ? "gelu_tanh" : "silu";
}

static double king_inference_decode_graph_embedding_scale(king_inference_model_object *model)
{
    zend_ulong embedding = model->gguf.architecture_params[KING_INFERENCE_ARCH_EMBEDDING_LENGTH];

    return king_inference_decode_graph_gemma_profile(model) && embedding > 0
        ? sqrt((double) embedding)
        : 1.0;
}

static void king_inference_decode_graph_add_kv_write(
    zval *ops,
    const char *id,
    const char *cache,
    zend_ulong slot,
    const char *key,
    zend_ulong key_length,
    const char *value,
    zend_ulong value_length
) {
    zval op;

    king_inference_decode_graph_start_op(&op, id, "kv_write");
    add_assoc_string(&op, "cache", cache);
    add_assoc_long(&op, "slot", (zend_long) slot);
    add_assoc_string(&op, "key", key);
    add_assoc_long(&op, "key_length", (zend_long) key_length);
    add_assoc_string(&op, "value", value);
    add_assoc_long(&op, "value_length", (zend_long) value_length);
    king_inference_decode_graph_push_op(ops, &op);
}

static void king_inference_decode_graph_add_kv_attention(
    zval *ops,
    const char *id,
    const char *cache,
    const char *query,
    zend_ulong slot_start,
    zend_ulong slot_count,
    zend_ulong key_length,
    zend_ulong value_length,
    double scale
) {
    zval op;

    king_inference_decode_graph_start_op(&op, id, "kv_attention");
    add_assoc_string(&op, "cache", cache);
    add_assoc_string(&op, "query", query);
    add_assoc_long(&op, "slot_start", (zend_long) slot_start);
    add_assoc_long(&op, "slot_count", (zend_long) slot_count);
    add_assoc_long(&op, "key_length", (zend_long) key_length);
    add_assoc_long(&op, "value_length", (zend_long) value_length);
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

#include "token_decode_graph_sampler.inc"
#include "token_decode_graph_attention_norm.inc"
#include "token_decode_graph_debug.inc"

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
    zend_ulong model_layers;
    zend_ulong sliding_window = model->gguf.architecture_params[KING_INFERENCE_ARCH_ATTENTION_SLIDING_WINDOW];
    zend_ulong slot_start;
    zend_ulong slot_count;
    zend_ulong layer;
    zend_ulong token_id;
    zend_string *token_embedding = NULL;
    zend_string *output_projection = NULL;
    zend_string *final_norm = NULL;
    const char *status = NULL;
    const char *final_norm_source = NULL;
    const char *final_norm_status = NULL;
    const char *output_projection_source = NULL;
    const char *output_projection_status = NULL;
    bool output_projection_tied = false;
    bool emit_token = king_inference_decode_graph_bool_option(options, "emit_token", true);
    bool emit_logits = king_inference_decode_graph_bool_option(options, "emit_logits", false);
    bool needs_logits = emit_token || emit_logits;
    const char *token_selection = "none";
    const char *token_selection_op = NULL;
    double token_selection_temperature = 0.0;
    zend_long token_selection_top_k = 0;
    zend_long debug_layer_limit = 0;
    double epsilon_default = model->gguf.attention_layer_norm_rms_epsilon > 0.0
        ? model->gguf.attention_layer_norm_rms_epsilon
        : 0.000001;
    double rope_base_default = model->gguf.rope_freq_base > 0.0
        ? model->gguf.rope_freq_base
        : 10000.0;
    double epsilon;
    double rope_base;
    double rope_base_swa;
    double embedding_scale;
    zend_ulong rope_pairing;
    const char *ffn_activation;
    char final_hidden_id[64];
    zval ops;
    zval *state;

    if (king_inference_decode_graph_required_shape(model, needs_logits, &layers, &heads, &kv_heads, &key_length, &value_length, &vocab) != SUCCESS
        || king_inference_decode_graph_double_option(options, "epsilon", epsilon_default, &epsilon) != SUCCESS
        || king_inference_decode_graph_double_option(options, "rope_base", rope_base_default, &rope_base) != SUCCESS
        || king_inference_decode_graph_double_option(
            options,
            "rope_base_swa",
            model->gguf.rope_freq_base_swa > 0.0 ? model->gguf.rope_freq_base_swa : rope_base,
            &rope_base_swa
        ) != SUCCESS
        || king_inference_decode_graph_non_negative_long_option(
            options,
            "debug_layer_limit",
            0,
            &debug_layer_limit
        ) != SUCCESS
        || king_inference_decode_graph_token_id_from_source(model, token_source, position, &token_id) != SUCCESS) {
        return FAILURE;
    }
    model_layers = layers;
    if (debug_layer_limit > 0) {
        if ((zend_ulong) debug_layer_limit > model_layers) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph debug_layer_limit exceeds the loaded model block count.");
            return FAILURE;
        }
        layers = (zend_ulong) debug_layer_limit;
    }
    if (epsilon <= 0.0 || rope_base <= 0.0 || rope_base_swa <= 0.0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph epsilon and rope_base must be positive.");
        return FAILURE;
    }
    rope_pairing = king_inference_decode_graph_gemma_profile(model) ? 1 : 0;
    ffn_activation = king_inference_decode_graph_ffn_activation(model);
    embedding_scale = king_inference_decode_graph_embedding_scale(model);
    if (king_inference_resolve_token_embedding_tensor_name(model, NULL, &token_embedding, NULL, &status) != SUCCESS
        || token_embedding == NULL) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph could not resolve token embedding tensor: %s.", status != NULL ? status : "not_found");
        return FAILURE;
    }
    if (king_inference_resolve_rms_norm_final_name(model, &final_norm, &final_norm_source, &final_norm_status) != SUCCESS
        || final_norm == NULL) {
        zend_string_release(token_embedding);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph could not resolve final RMSNorm tensor: %s.", final_norm_status != NULL ? final_norm_status : "not_found");
        return FAILURE;
    }
    if (needs_logits
        && (king_inference_resolve_output_projection_tensor_name(model, NULL, &output_projection, &output_projection_source, &output_projection_status, &output_projection_tied) != SUCCESS
            || output_projection == NULL)) {
        zend_string_release(token_embedding);
        zend_string_release(final_norm);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph could not resolve output projection tensor: %s.", output_projection_status != NULL ? output_projection_status : "not_found");
        return FAILURE;
    }

    king_inference_decode_graph_attention_window(
        model,
        position,
        sliding_window,
        &slot_start,
        &slot_count
    );
    array_init(&ops);
    king_inference_decode_graph_add_embedding(&ops, token_embedding, token_id, embedding_scale);
    zend_string_release(token_embedding);

    for (layer = 0; layer < layers; layer++) {
        zend_string *attn_norm = NULL;
        zend_string *attn_q = NULL;
        zend_string *attn_k = NULL;
        zend_string *attn_v = NULL;
        zend_string *attn_out = NULL;
        zend_string *attn_q_norm = NULL;
        zend_string *attn_k_norm = NULL;
        zend_string *post_attention_norm = NULL;
        zend_string *ffn_norm = NULL;
        zend_string *ffn_gate = NULL;
        zend_string *ffn_up = NULL;
        zend_string *ffn_down = NULL;
        zend_string *post_ffw_norm = NULL;
        char hidden[64];
        char attn_norm_id[64];
        char q_id[64];
        char k_id[64];
        char v_id[64];
        char context_id[64];
        char attn_out_id[64];
        char post_attention_norm_id[64];
        char attn_residual_id[64];
        char ffn_norm_id[64];
        char gate_id[64];
        char up_id[64];
        char gate_act_id[64];
        char gated_id[64];
        char down_id[64];
        char post_ffw_norm_id[64];
        char output_id[64];
        const char *attn_residual_right = attn_out_id;
        const char *ffn_residual_right = down_id;
        zval contexts;
        zend_ulong layer_heads = heads;
        zend_ulong layer_kv_heads = kv_heads;
        zend_ulong layer_key_length = key_length;
        zend_ulong layer_value_length = value_length;
        double layer_rope_base;
        zend_ulong head;

        if (king_inference_decode_graph_format_id(hidden, sizeof(hidden), layer == 0 ? "x" : "l%lu_output", layer == 0 ? 0 : layer - 1, 0) != SUCCESS
            || king_inference_decode_graph_format_id(attn_norm_id, sizeof(attn_norm_id), "l%lu_attn_norm", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(q_id, sizeof(q_id), "l%lu_q", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(k_id, sizeof(k_id), "l%lu_k", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(v_id, sizeof(v_id), "l%lu_v", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(context_id, sizeof(context_id), "l%lu_context", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(attn_out_id, sizeof(attn_out_id), "l%lu_attn_out", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(post_attention_norm_id, sizeof(post_attention_norm_id), "l%lu_post_attn_norm", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(attn_residual_id, sizeof(attn_residual_id), "l%lu_attn_residual", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(ffn_norm_id, sizeof(ffn_norm_id), "l%lu_ffn_norm", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(gate_id, sizeof(gate_id), "l%lu_ffn_gate", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(up_id, sizeof(up_id), "l%lu_ffn_up", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(gate_act_id, sizeof(gate_act_id), "l%lu_ffn_gate_silu", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(gated_id, sizeof(gated_id), "l%lu_ffn_gated", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(down_id, sizeof(down_id), "l%lu_ffn_down", layer, 0) != SUCCESS
            || king_inference_decode_graph_format_id(post_ffw_norm_id, sizeof(post_ffw_norm_id), "l%lu_post_ffw_norm", layer, 0) != SUCCESS
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
            if (attn_q_norm != NULL) zend_string_release(attn_q_norm);
            if (attn_k_norm != NULL) zend_string_release(attn_k_norm);
            if (post_attention_norm != NULL) zend_string_release(post_attention_norm);
            if (ffn_norm != NULL) zend_string_release(ffn_norm);
            if (ffn_gate != NULL) zend_string_release(ffn_gate);
            if (ffn_up != NULL) zend_string_release(ffn_up);
            if (ffn_down != NULL) zend_string_release(ffn_down);
            if (post_ffw_norm != NULL) zend_string_release(post_ffw_norm);
            if (output_projection != NULL) zend_string_release(output_projection);
            zend_string_release(final_norm);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph could not resolve layer %lu tensor: %s.", (unsigned long) layer, status != NULL ? status : "not_found");
            return FAILURE;
        }
        king_inference_attention_refine_runtime_shape_from_tensors_for_layer(
            model,
            layer,
            &layer_heads,
            &layer_kv_heads,
            &layer_key_length,
            &layer_value_length
        );
        king_inference_decode_graph_attention_norms(model, layer, &attn_q_norm, &attn_k_norm);
        post_attention_norm = king_inference_decode_graph_post_norm_name(model, layer, "post_attention_norm.weight");
        post_ffw_norm = king_inference_decode_graph_post_norm_name(model, layer, "post_ffw_norm.weight");
        if (layer_key_length == 0
            || layer_value_length == 0
            || layer_kv_heads == 0
            || layer_heads < layer_kv_heads
            || (layer_key_length % 2) != 0) {
            zval_ptr_dtor(&ops);
            zend_string_release(attn_norm);
            zend_string_release(attn_q);
            zend_string_release(attn_k);
            zend_string_release(attn_v);
            zend_string_release(attn_out);
            if (attn_q_norm != NULL) zend_string_release(attn_q_norm);
            if (attn_k_norm != NULL) zend_string_release(attn_k_norm);
            if (post_attention_norm != NULL) zend_string_release(post_attention_norm);
            zend_string_release(ffn_norm);
            zend_string_release(ffn_gate);
            zend_string_release(ffn_up);
            zend_string_release(ffn_down);
            if (post_ffw_norm != NULL) zend_string_release(post_ffw_norm);
            if (output_projection != NULL) zend_string_release(output_projection);
            zend_string_release(final_norm);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph requires valid layer %lu attention dimensions.", (unsigned long) layer);
            return FAILURE;
        }
        layer_rope_base = king_inference_decode_graph_layer_rope_base(
            model,
            layer,
            rope_base,
            rope_base_swa,
            key_length,
            layer_key_length
        );

        king_inference_decode_graph_add_rms_norm(&ops, attn_norm_id, hidden, attn_norm, epsilon);
        king_inference_decode_graph_add_linear(&ops, q_id, attn_norm_id, attn_q, 0);
        king_inference_decode_graph_add_linear(&ops, k_id, attn_norm_id, attn_k, 0);
        king_inference_decode_graph_add_linear(&ops, v_id, attn_norm_id, attn_v, 0);
        zend_string_release(attn_norm);
        zend_string_release(attn_q);
        zend_string_release(attn_k);
        zend_string_release(attn_v);

        array_init(&contexts);
        for (head = 0; head < layer_heads; head++) {
            zend_ulong kv_head = king_inference_decode_graph_kv_head(head, layer_heads, layer_kv_heads);
            char q_slice_id[64];
            char k_slice_id[64];
            char v_slice_id[64];
            char q_norm_id[64];
            char k_norm_id[64];
            char q_rope_id[64];
            char k_rope_id[64];
            char cache_id[64];
            char kv_write_id[64];
            char ctx_id[64];
            const char *q_rope_input = q_slice_id;
            const char *k_rope_input = k_slice_id;

            if (king_inference_decode_graph_format_id(q_slice_id, sizeof(q_slice_id), "l%lu_h%lu_q_slice", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(k_slice_id, sizeof(k_slice_id), "l%lu_h%lu_k_slice", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(v_slice_id, sizeof(v_slice_id), "l%lu_h%lu_v_slice", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(q_norm_id, sizeof(q_norm_id), "l%lu_h%lu_q_norm", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(k_norm_id, sizeof(k_norm_id), "l%lu_h%lu_k_norm", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(q_rope_id, sizeof(q_rope_id), "l%lu_h%lu_q_rope", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(k_rope_id, sizeof(k_rope_id), "l%lu_h%lu_k_rope", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(cache_id, sizeof(cache_id), "l%lu.h%lu", layer, kv_head) != SUCCESS
                || king_inference_decode_graph_format_id(kv_write_id, sizeof(kv_write_id), "l%lu_h%lu_kv_write", layer, head) != SUCCESS
                || king_inference_decode_graph_format_id(ctx_id, sizeof(ctx_id), "l%lu_h%lu_ctx", layer, head) != SUCCESS) {
                zval_ptr_dtor(&contexts);
                zval_ptr_dtor(&ops);
                zend_string_release(attn_out);
                if (attn_q_norm != NULL) zend_string_release(attn_q_norm);
                if (attn_k_norm != NULL) zend_string_release(attn_k_norm);
                if (post_attention_norm != NULL) zend_string_release(post_attention_norm);
                zend_string_release(ffn_norm);
                zend_string_release(ffn_gate);
                zend_string_release(ffn_up);
                zend_string_release(ffn_down);
                if (post_ffw_norm != NULL) zend_string_release(post_ffw_norm);
                if (output_projection != NULL) zend_string_release(output_projection);
                zend_string_release(final_norm);
                return FAILURE;
            }
            king_inference_decode_graph_add_slice(&ops, q_slice_id, q_id, head * layer_key_length, layer_key_length);
            king_inference_decode_graph_add_slice(&ops, k_slice_id, k_id, kv_head * layer_key_length, layer_key_length);
            king_inference_decode_graph_add_slice(&ops, v_slice_id, v_id, kv_head * layer_value_length, layer_value_length);
            if (attn_q_norm != NULL && attn_k_norm != NULL) {
                king_inference_decode_graph_add_rms_norm(&ops, q_norm_id, q_slice_id, attn_q_norm, epsilon);
                king_inference_decode_graph_add_rms_norm(&ops, k_norm_id, k_slice_id, attn_k_norm, epsilon);
                q_rope_input = q_norm_id;
                k_rope_input = k_norm_id;
            }
            king_inference_decode_graph_add_rope(&ops, q_rope_id, q_rope_input, position, layer_key_length, layer_rope_base, rope_pairing);
            king_inference_decode_graph_add_rope(&ops, k_rope_id, k_rope_input, position, layer_key_length, layer_rope_base, rope_pairing);
            king_inference_decode_graph_add_kv_write(&ops, kv_write_id, cache_id, position, k_rope_id, layer_key_length, v_slice_id, layer_value_length);
            king_inference_decode_graph_add_kv_attention(&ops, ctx_id, cache_id, q_rope_id, slot_start, slot_count, layer_key_length, layer_value_length, 1.0 / sqrt((double) layer_key_length));
            add_next_index_string(&contexts, ctx_id);
        }
        if (attn_q_norm != NULL) zend_string_release(attn_q_norm);
        if (attn_k_norm != NULL) zend_string_release(attn_k_norm);
        king_inference_decode_graph_add_stack(&ops, context_id, &contexts);
        zval_ptr_dtor(&contexts);
        king_inference_decode_graph_add_linear(&ops, attn_out_id, context_id, attn_out, 0);
        zend_string_release(attn_out);
        if (post_attention_norm != NULL) {
            king_inference_decode_graph_add_rms_norm(&ops, post_attention_norm_id, attn_out_id, post_attention_norm, epsilon);
            zend_string_release(post_attention_norm);
            attn_residual_right = post_attention_norm_id;
        }
        king_inference_decode_graph_add_binary(&ops, attn_residual_id, "add", hidden, attn_residual_right);
        king_inference_decode_graph_add_rms_norm(&ops, ffn_norm_id, attn_residual_id, ffn_norm, epsilon);
        zend_string_release(ffn_norm);
        king_inference_decode_graph_add_linear(&ops, gate_id, ffn_norm_id, ffn_gate, 0);
        king_inference_decode_graph_add_linear(&ops, up_id, ffn_norm_id, ffn_up, 0);
        zend_string_release(ffn_gate);
        zend_string_release(ffn_up);
        king_inference_decode_graph_add_unary(&ops, gate_act_id, ffn_activation, gate_id);
        king_inference_decode_graph_add_binary(&ops, gated_id, "mul", gate_act_id, up_id);
        king_inference_decode_graph_add_linear(&ops, down_id, gated_id, ffn_down, 0);
        zend_string_release(ffn_down);
        if (post_ffw_norm != NULL) {
            king_inference_decode_graph_add_rms_norm(&ops, post_ffw_norm_id, down_id, post_ffw_norm, epsilon);
            zend_string_release(post_ffw_norm);
            ffn_residual_right = post_ffw_norm_id;
        }
        king_inference_decode_graph_add_binary(&ops, output_id, "add", attn_residual_id, ffn_residual_right);
    }

    {
        if (king_inference_decode_graph_format_id(final_hidden_id, sizeof(final_hidden_id), layers == 0 ? "x" : "l%lu_output", layers == 0 ? 0 : layers - 1, 0) != SUCCESS) {
            zval_ptr_dtor(&ops);
            if (output_projection != NULL) zend_string_release(output_projection);
            zend_string_release(final_norm);
            return FAILURE;
        }
        king_inference_decode_graph_add_rms_norm(&ops, "final_norm", final_hidden_id, final_norm, epsilon);
    }

    if (needs_logits) {
        king_inference_decode_graph_add_linear(&ops, "logits", "final_norm", output_projection, vocab);
    }
    if (emit_token) {
        if (king_inference_decode_graph_add_sampler(
            &ops,
            "logits",
            position,
            options,
            &token_selection,
            &token_selection_op,
            &token_selection_temperature,
            &token_selection_top_k
        ) != SUCCESS) {
            zval_ptr_dtor(&ops);
            if (output_projection != NULL) {
                zend_string_release(output_projection);
            }
            zend_string_release(final_norm);
            return FAILURE;
        }
        if (strcmp(token_selection, "sample") == 0
            && token_selection_top_k > 0
            && (zend_ulong) token_selection_top_k > vocab) {
            token_selection_top_k = (zend_long) vocab;
        }
    }

    array_init(return_value);
    add_assoc_string(return_value, "graph_type", "king_native_cpu_token_decode");
    add_assoc_long(return_value, "token_id", (zend_long) token_id);
    add_assoc_long(return_value, "position", (zend_long) position);
    add_assoc_long(return_value, "block_count", (zend_long) layers);
    add_assoc_long(return_value, "model_block_count", (zend_long) model_layers);
    if (debug_layer_limit > 0) {
        add_assoc_long(return_value, "debug_layer_limit", debug_layer_limit);
    }
    add_assoc_long(return_value, "attention_head_count", (zend_long) heads);
    add_assoc_long(return_value, "attention_head_count_kv", (zend_long) kv_heads);
    add_assoc_long(return_value, "attention_key_length", (zend_long) key_length);
    add_assoc_long(return_value, "attention_value_length", (zend_long) value_length);
    add_assoc_string(return_value, "token_selection", token_selection);
    if (token_selection_op != NULL) {
        add_assoc_string(return_value, "token_selection_op", token_selection_op);
    }
    if (emit_token) {
        add_assoc_double(return_value, "token_selection_temperature", token_selection_temperature);
        add_assoc_long(return_value, "token_selection_top_k", token_selection_top_k);
    }
    add_assoc_zval(return_value, "ops", &ops);
    add_assoc_string(return_value, "output", emit_token ? "next_token" : (emit_logits ? "logits" : "final_norm"));
    {
        zval terminal;

        array_init(&terminal);
        add_assoc_string(&terminal, "hidden_output", final_hidden_id);
        add_assoc_string(&terminal, "final_norm", "final_norm");
        add_assoc_str(&terminal, "final_norm_tensor", zend_string_copy(final_norm));
        if (final_norm_source != NULL) {
            add_assoc_string(&terminal, "final_norm_source", final_norm_source);
        }
        if (final_norm_status != NULL) {
            add_assoc_string(&terminal, "final_norm_status", final_norm_status);
        }
        add_assoc_bool(&terminal, "emits_token", emit_token);
        add_assoc_bool(&terminal, "emits_logits", needs_logits);
        add_assoc_string(&terminal, "token_selection", token_selection);
        if (token_selection_op != NULL) {
            add_assoc_string(&terminal, "token_selection_op", token_selection_op);
        }
        if (emit_token) {
            add_assoc_double(&terminal, "token_selection_temperature", token_selection_temperature);
            add_assoc_long(&terminal, "token_selection_top_k", token_selection_top_k);
        }
        if (needs_logits) {
            add_assoc_string(&terminal, "logits", "logits");
            add_assoc_str(&terminal, "output_projection_tensor", zend_string_copy(output_projection));
            add_assoc_bool(&terminal, "output_projection_tied_token_embedding", output_projection_tied);
            if (output_projection_source != NULL) {
                add_assoc_string(&terminal, "output_projection_source", output_projection_source);
            }
            if (output_projection_status != NULL) {
                add_assoc_string(&terminal, "output_projection_status", output_projection_status);
            }
        }
        add_assoc_zval(return_value, "terminal", &terminal);
    }
    zend_string_release(final_norm);
    if (output_projection != NULL) {
        zend_string_release(output_projection);
    }
    if (king_inference_decode_graph_attach_debug(model, return_value) != SUCCESS) {
        zval_ptr_dtor(return_value);
        return FAILURE;
    }
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
