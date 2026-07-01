typedef enum _king_inference_attention_role {
    KING_INFERENCE_ATTENTION_QUERY = 0,
    KING_INFERENCE_ATTENTION_KEY = 1,
    KING_INFERENCE_ATTENTION_VALUE = 2,
    KING_INFERENCE_ATTENTION_OUTPUT = 3
} king_inference_attention_role;

static const char *king_inference_attention_role_keys[] = {
    "query",
    "key",
    "value",
    "output"
};

static const char *king_inference_attention_config_fields[] = {
    "attention_query_tensor_pattern",
    "attention_key_tensor_pattern",
    "attention_value_tensor_pattern",
    "attention_output_tensor_pattern"
};

static const char *king_inference_attention_query_patterns[] = {
    "blk.{layer}.attn_q.weight",
    "blk.{layer}.attention_q.weight",
    "model.layers.{layer}.self_attn.q_proj.weight",
    "layers.{layer}.self_attn.q_proj.weight",
    "decoder.layers.{layer}.self_attn.q_proj.weight",
    "transformer.h.{layer}.attn.q_proj.weight",
    "transformer.blocks.{layer}.attn.q_proj.weight",
    NULL
};

static const char *king_inference_attention_key_patterns[] = {
    "blk.{layer}.attn_k.weight",
    "blk.{layer}.attention_k.weight",
    "model.layers.{layer}.self_attn.k_proj.weight",
    "layers.{layer}.self_attn.k_proj.weight",
    "decoder.layers.{layer}.self_attn.k_proj.weight",
    "transformer.h.{layer}.attn.k_proj.weight",
    "transformer.blocks.{layer}.attn.k_proj.weight",
    NULL
};

static const char *king_inference_attention_value_patterns[] = {
    "blk.{layer}.attn_v.weight",
    "blk.{layer}.attention_v.weight",
    "model.layers.{layer}.self_attn.v_proj.weight",
    "layers.{layer}.self_attn.v_proj.weight",
    "decoder.layers.{layer}.self_attn.v_proj.weight",
    "transformer.h.{layer}.attn.v_proj.weight",
    "transformer.blocks.{layer}.attn.v_proj.weight",
    NULL
};

static const char *king_inference_attention_output_patterns[] = {
    "blk.{layer}.attn_output.weight",
    "blk.{layer}.attn_o.weight",
    "blk.{layer}.attention_output.weight",
    "model.layers.{layer}.self_attn.o_proj.weight",
    "layers.{layer}.self_attn.o_proj.weight",
    "decoder.layers.{layer}.self_attn.o_proj.weight",
    "transformer.h.{layer}.attn.out_proj.weight",
    "transformer.blocks.{layer}.attn.out_proj.weight",
    NULL
};

static const char **king_inference_attention_role_patterns(king_inference_attention_role role)
{
    switch (role) {
        case KING_INFERENCE_ATTENTION_QUERY:
            return king_inference_attention_query_patterns;
        case KING_INFERENCE_ATTENTION_KEY:
            return king_inference_attention_key_patterns;
        case KING_INFERENCE_ATTENTION_VALUE:
            return king_inference_attention_value_patterns;
        case KING_INFERENCE_ATTENTION_OUTPUT:
            return king_inference_attention_output_patterns;
    }

    return king_inference_attention_query_patterns;
}

static zend_string *king_inference_attention_layer_pattern_name(
    const char *pattern,
    zend_ulong layer
) {
    const char *placeholder = strstr(pattern, "{layer}");
    const char *suffix;
    char layer_buf[32];
    int layer_len;
    size_t prefix_len;
    size_t suffix_len;
    size_t total_len;
    zend_string *name;

    if (placeholder == NULL) {
        return NULL;
    }

    layer_len = snprintf(layer_buf, sizeof(layer_buf), "%lu", (unsigned long) layer);
    if (layer_len <= 0 || (size_t) layer_len >= sizeof(layer_buf)) {
        return NULL;
    }

    prefix_len = (size_t) (placeholder - pattern);
    suffix = placeholder + sizeof("{layer}") - 1;
    suffix_len = strlen(suffix);
    total_len = prefix_len + (size_t) layer_len + suffix_len;
    name = zend_string_alloc(total_len, 0);
    memcpy(ZSTR_VAL(name), pattern, prefix_len);
    memcpy(ZSTR_VAL(name) + prefix_len, layer_buf, (size_t) layer_len);
    memcpy(ZSTR_VAL(name) + prefix_len + (size_t) layer_len, suffix, suffix_len);
    ZSTR_VAL(name)[total_len] = '\0';

    return name;
}

