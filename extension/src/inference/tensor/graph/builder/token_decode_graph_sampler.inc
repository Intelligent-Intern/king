/*
 * Token decode graph sampler helpers.
 */

static zend_result king_inference_decode_graph_add_sampler(
    zval *ops,
    const char *logits,
    zend_ulong position,
    zval *options,
    const char **mode_out,
    const char **op_out,
    double *temperature_out,
    zend_long *top_k_out
) {
    zval op;
    const char *mode;
    double temperature;
    double top_p;
    zend_long top_k;
    zend_long seed = 0;
    zval *seed_value = king_inference_array_find(options, "seed");

    *mode_out = "sample";
    *op_out = NULL;
    *temperature_out = 0.7;
    *top_k_out = 0;
    if (king_inference_decode_graph_sampler_mode(options, &mode, &temperature) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_decode_graph_non_negative_long_option(options, "top_k", 40, &top_k) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_decode_graph_double_option(options, "top_p", 0.95, &top_p) != SUCCESS) {
        return FAILURE;
    }
    if (top_p <= 0.0 || top_p > 1.0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph top_p must be greater than zero and at most one.");
        return FAILURE;
    }
    if (seed_value != NULL && Z_TYPE_P(seed_value) != IS_LONG) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference token decode graph option 'seed' must be an integer.");
        return FAILURE;
    }
    if (seed_value != NULL) {
        seed = Z_LVAL_P(seed_value);
    }
    *mode_out = mode;
    *temperature_out = temperature;
    if (strcmp(mode, "argmax") == 0) {
        king_inference_decode_graph_start_op(&op, "next_token", "argmax_token");
        add_assoc_string(&op, "logits", logits);
        king_inference_decode_graph_push_op(ops, &op);
        *op_out = "argmax_token";
        *top_k_out = 1;
        return SUCCESS;
    }

    king_inference_decode_graph_start_op(&op, "next_token", "sample_token");
    add_assoc_string(&op, "logits", logits);
    add_assoc_double(&op, "temperature", temperature);
    add_assoc_long(&op, "top_k", top_k);
    add_assoc_double(&op, "top_p", top_p);
    add_assoc_long(&op, "seed", seed);
    add_assoc_long(&op, "sample_index", (zend_long) position);
    king_inference_decode_graph_push_op(ops, &op);
    *op_out = "sample_token";
    *top_k_out = top_k;
    return SUCCESS;
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
