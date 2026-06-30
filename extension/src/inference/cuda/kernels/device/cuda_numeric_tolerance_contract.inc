/*
 * Internal CUDA numeric tolerance contract for inference diagnostics.
 */

static double king_inference_cuda_numeric_tolerance_relative(const char *op)
{
    if (op == NULL) {
        return 0.00001;
    }
    if (strcmp(op, "embedding") == 0) {
        return 0.00001;
    }
    if (strcmp(op, "rms_norm") == 0
        || strcmp(op, "ffn_norm") == 0
        || strcmp(op, "final_norm") == 0) {
        return 0.00005;
    }
    if (strcmp(op, "matvec") == 0
        || strcmp(op, "qkv_projection") == 0) {
        return 0.00005;
    }
    if (strcmp(op, "rope") == 0) {
        return 0.0000015;
    }
    if (strcmp(op, "attention_score") == 0) {
        return 0.00005;
    }
    if (strcmp(op, "attention_softmax") == 0) {
        return 0.000001;
    }
    if (strcmp(op, "attention_value") == 0) {
        return 0.00002;
    }
    if (strcmp(op, "ffn") == 0
        || strcmp(op, "ffn_gate_up") == 0
        || strcmp(op, "ffn_swiglu") == 0
        || strcmp(op, "ffn_down") == 0) {
        return 0.00005;
    }
    if (strcmp(op, "logits_projection") == 0
        || strcmp(op, "sampler_candidate_ranking") == 0) {
        return 0.0002;
    }
    return 0.00001;
}

static const char *king_inference_cuda_numeric_tolerance_top_k_rule(const char *op)
{
    if (op != NULL
        && (strcmp(op, "logits_projection") == 0
            || strcmp(op, "sampler_candidate_ranking") == 0)) {
        return "candidate_token_id_and_rank_logit_must_match_with_token_id_tie_break";
    }
    return "not_applicable";
}

static double king_inference_cuda_numeric_relative_diff(double actual, double expected)
{
    double denominator = fmax(fabs(actual), fabs(expected));

    if (denominator == 0.0) {
        return actual == expected ? 0.0 : 1.0e308;
    }
    return fabs(actual - expected) / denominator;
}

static const char *king_inference_cuda_numeric_sample_classification(
    double actual,
    double expected,
    double abs_diff,
    double rel_diff,
    double max_abs,
    double max_rel
) {
    if (!isfinite(actual) || !isfinite(expected) || !isfinite(abs_diff)) {
        return "non_finite_numeric_mismatch";
    }
    if (abs_diff <= max_abs) {
        return "float_noise_abs_tolerance";
    }
    if (isfinite(rel_diff) && rel_diff <= max_rel) {
        return "float_noise_relative_tolerance";
    }
    return "numeric_mismatch";
}

static bool king_inference_cuda_numeric_sample_matches(const char *classification)
{
    return classification != NULL
        && (strcmp(classification, "float_noise_abs_tolerance") == 0
            || strcmp(classification, "float_noise_relative_tolerance") == 0);
}

static void king_inference_cuda_numeric_tolerance_contract_add_entry(
    zval *entries,
    const char *op,
    double max_abs,
    double max_rel,
    const char *scope
) {
    zval entry;

    array_init(&entry);
    add_assoc_string(&entry, "op", op);
    add_assoc_double(&entry, "max_abs_diff", max_abs);
    add_assoc_double(&entry, "max_relative_diff", max_rel);
    add_assoc_string(&entry, "match_rule", "abs_or_relative");
    add_assoc_string(&entry, "top_k_equivalence", king_inference_cuda_numeric_tolerance_top_k_rule(op));
    add_assoc_string(&entry, "scope", scope);
    add_next_index_zval(entries, &entry);
}

static void king_inference_cuda_numeric_tolerance_contract_table(zval *return_value)
{
    zval entries;

    array_init(return_value);
    add_assoc_long(return_value, "schema_version", 1);
    add_assoc_string(return_value, "match_rule", "abs_or_relative");
    add_assoc_string(return_value, "noise_status", "float_noise_abs_tolerance_or_float_noise_relative_tolerance");
    add_assoc_string(return_value, "failure_status", "numeric_mismatch");
    add_assoc_string(return_value, "sample_context_fields", "op,layer,tensor,sample_index");
    array_init(&entries);
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "embedding", 0.00001, 0.00001, "token_embedding_row");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "rms_norm", 0.0002, 0.00005, "rms_norm_and_first_norm");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "matvec", 0.003, 0.00005, "quantized_matvec");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "qkv_projection", 0.003, 0.00005, "attention_qkv_projection");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "rope", 0.0005, 0.0000015, "rope_rotation");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "attention_score", 0.00005, 0.00005, "qk_score");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "attention_softmax", 0.000001, 0.000001, "attention_probability");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "attention_value", 0.00002, 0.00002, "weighted_value_context");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "ffn", 0.003, 0.00005, "ffn_gate_up_swiglu_down");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "final_norm", 0.0002, 0.00005, "final_rms_norm");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "logits_projection", 0.01, 0.0002, "bounded_logits");
    king_inference_cuda_numeric_tolerance_contract_add_entry(&entries, "sampler_candidate_ranking", 0.01, 0.0002, "top_k_candidate_order");
    add_assoc_zval(return_value, "operations", &entries);
}
