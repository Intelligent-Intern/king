static zend_string *king_inference_decode_graph_optional_vector_tensor_name(
    king_inference_model_object *model,
    zend_ulong layer,
    const char *suffix
) {
    char name[96];
    zend_string *tensor_name;
    zval *descriptor;
    zend_ulong rank;
    zend_ulong width;

    if (snprintf(name, sizeof(name), "blk.%lu.%s", (unsigned long) layer, suffix) >= (int) sizeof(name)) {
        return NULL;
    }

    tensor_name = zend_string_init(name, strlen(name), 0);
    descriptor = king_inference_tensor_index_descriptor(model, tensor_name);
    if (descriptor == NULL
        || !king_inference_tensor_descriptor_rank(descriptor, &rank)
        || rank != 1
        || !king_inference_tensor_descriptor_dimension(descriptor, 0, &width)
        || width == 0) {
        zend_string_release(tensor_name);
        return NULL;
    }

    return tensor_name;
}

static zend_string *king_inference_decode_graph_attention_norm_name(
    king_inference_model_object *model,
    zend_ulong layer,
    const char *suffix
) {
    return king_inference_decode_graph_optional_vector_tensor_name(model, layer, suffix);
}

static void king_inference_decode_graph_attention_norms(
    king_inference_model_object *model,
    zend_ulong layer,
    zend_string **query_norm,
    zend_string **key_norm
) {
    *query_norm = king_inference_decode_graph_attention_norm_name(model, layer, "attn_q_norm.weight");
    *key_norm = king_inference_decode_graph_attention_norm_name(model, layer, "attn_k_norm.weight");
    if (*query_norm == NULL || *key_norm == NULL) {
        if (*query_norm != NULL) {
            zend_string_release(*query_norm);
            *query_norm = NULL;
        }
        if (*key_norm != NULL) {
            zend_string_release(*key_norm);
            *key_norm = NULL;
        }
    }
}

static zend_string *king_inference_decode_graph_post_norm_name(
    king_inference_model_object *model,
    zend_ulong layer,
    const char *suffix
) {
    return king_inference_decode_graph_optional_vector_tensor_name(model, layer, suffix);
}
