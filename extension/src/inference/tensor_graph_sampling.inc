typedef struct _king_inference_graph_token_candidate {
    zend_ulong index;
    double logit;
    double probability;
} king_inference_graph_token_candidate;

static int king_inference_graph_token_candidate_compare(const void *left, const void *right)
{
    const king_inference_graph_token_candidate *a = (const king_inference_graph_token_candidate *) left;
    const king_inference_graph_token_candidate *b = (const king_inference_graph_token_candidate *) right;

    if (a->probability < b->probability) {
        return 1;
    }
    if (a->probability > b->probability) {
        return -1;
    }
    if (a->index > b->index) {
        return 1;
    }
    if (a->index < b->index) {
        return -1;
    }

    return 0;
}

static zend_result king_inference_graph_sampling_seed(
    zval *op,
    bool *has_seed,
    zend_long *seed
) {
    zval *field = king_inference_array_find(op, "seed");

    *has_seed = false;
    *seed = 0;
    if (field == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(field) != IS_LONG) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph sample_token seed must be an integer.");
        return FAILURE;
    }

    *has_seed = true;
    *seed = Z_LVAL_P(field);
    return SUCCESS;
}

static double king_inference_graph_sampling_unit(zend_long seed, zend_ulong salt)
{
    zend_ulong state = (zend_ulong) seed;

    state ^= salt + 0x9e3779b9UL + (state << 6) + (state >> 2);
    state = state * 1103515245UL + 12345UL;
    return (double) (state % 1000000UL) / 1000000.0;
}

static zend_result king_inference_graph_sampling_top_k(zval *op, zend_ulong *top_k)
{
    zval *field = king_inference_array_find(op, "top_k");

    *top_k = 0;
    if (field == NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(field) != IS_LONG || Z_LVAL_P(field) < 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph sample_token top_k must be a non-negative integer.");
        return FAILURE;
    }

    *top_k = (zend_ulong) Z_LVAL_P(field);
    return SUCCESS;
}

static zend_result king_inference_graph_token_output(
    zend_ulong token_offset,
    zend_ulong token_index,
    double probability,
    double logit,
    double rank,
    double **values_out,
    zend_ulong *length_out
) {
    double *values;

    if (token_offset > ZEND_ULONG_MAX - token_index) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph token output exceeds supported token id limits.");
        return FAILURE;
    }

    values = ecalloc(4, sizeof(double));
    values[0] = (double) (token_offset + token_index);
    values[1] = probability;
    values[2] = logit;
    values[3] = rank;

    *values_out = values;
    *length_out = 4;
    return SUCCESS;
}

static zend_result king_inference_graph_op_argmax_token(
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    double **values_out,
    zend_ulong *length_out
) {
    king_inference_graph_vector *logits;
    zend_ulong token_offset = king_inference_tensor_option_ulong(op, "token_offset", 0);
    zend_ulong best_index = 0;
    double best_logit;
    zend_ulong i;

    if (king_inference_graph_lookup_vector(vectors, op, "logits", &logits) != SUCCESS) {
        return FAILURE;
    }
    if (logits->length == 0 || logits->length > max_values) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph argmax_token logits exceed configured limits.");
        return FAILURE;
    }

    best_logit = logits->values[0];
    for (i = 1; i < logits->length; i++) {
        if (logits->values[i] > best_logit) {
            best_logit = logits->values[i];
            best_index = i;
        }
    }

    return king_inference_graph_token_output(
        token_offset,
        best_index,
        1.0,
        best_logit,
        0.0,
        values_out,
        length_out
    );
}

static zend_result king_inference_graph_op_sample_token(
    zval *op,
    king_inference_graph_vector *vectors,
    zend_ulong max_values,
    zend_ulong max_operations,
    double **values_out,
    zend_ulong *length_out
) {
    king_inference_graph_vector *logits;
    king_inference_graph_token_candidate *candidates;
    zend_ulong token_offset = king_inference_tensor_option_ulong(op, "token_offset", 0);
    zend_ulong top_k;
    zend_ulong sample_index = king_inference_tensor_option_ulong(op, "sample_index", 0);
    zend_ulong active_count;
    zend_ulong selected_rank = 0;
    zend_ulong i;
    double temperature;
    double top_p;
    double max_score = 0.0;
    double probability_sum = 0.0;
    double active_sum = 0.0;
    bool has_seed;
    zend_long seed;

    if (king_inference_graph_finite_double_option(op, "temperature", 1.0, &temperature) != SUCCESS
        || king_inference_graph_finite_double_option(op, "top_p", 1.0, &top_p) != SUCCESS) {
        return FAILURE;
    }
    if (king_inference_graph_lookup_vector(vectors, op, "logits", &logits) != SUCCESS
        || king_inference_graph_sampling_seed(op, &has_seed, &seed) != SUCCESS
        || king_inference_graph_sampling_top_k(op, &top_k) != SUCCESS) {
        return FAILURE;
    }
    if (logits->length == 0
        || logits->length > max_values
        || logits->length > max_operations
        || logits->length > (zend_ulong) (SIZE_MAX / sizeof(king_inference_graph_token_candidate))) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph sample_token logits exceed configured limits.");
        return FAILURE;
    }
    if (temperature < 0.0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph sample_token temperature must be non-negative.");
        return FAILURE;
    }
    if (top_p <= 0.0 || top_p > 1.0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference graph sample_token top_p must be greater than zero and at most one.");
        return FAILURE;
    }
    if (temperature == 0.0) {
        return king_inference_graph_op_argmax_token(op, vectors, max_values, values_out, length_out);
    }

    candidates = ecalloc((size_t) logits->length, sizeof(*candidates));
    for (i = 0; i < logits->length; i++) {
        double score = logits->values[i] / temperature;
        candidates[i].index = i;
        candidates[i].logit = logits->values[i];
        candidates[i].probability = score;
        if (i == 0 || score > max_score) {
            max_score = score;
        }
    }

    for (i = 0; i < logits->length; i++) {
        candidates[i].probability = king_inference_graph_exp(candidates[i].probability - max_score);
        probability_sum += candidates[i].probability;
    }
    if (probability_sum <= 0.0) {
        efree(candidates);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference graph sample_token produced an invalid probability sum.");
        return FAILURE;
    }
    for (i = 0; i < logits->length; i++) {
        candidates[i].probability /= probability_sum;
    }

    qsort(candidates, (size_t) logits->length, sizeof(*candidates), king_inference_graph_token_candidate_compare);
    active_count = top_k == 0 || top_k > logits->length ? logits->length : top_k;
    probability_sum = 0.0;
    for (i = 0; i < active_count; i++) {
        probability_sum += candidates[i].probability;
        if (probability_sum >= top_p) {
            active_count = i + 1;
            break;
        }
    }

    for (i = 0; i < active_count; i++) {
        active_sum += candidates[i].probability;
    }
    if (active_sum <= 0.0) {
        efree(candidates);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference graph sample_token active probability sum is invalid.");
        return FAILURE;
    }

    if (has_seed) {
        double target = king_inference_graph_sampling_unit(seed, logits->length ^ (active_count << 7) ^ sample_index) * active_sum;
        double cumulative = 0.0;

        selected_rank = active_count - 1;
        for (i = 0; i < active_count; i++) {
            cumulative += candidates[i].probability;
            if (target <= cumulative) {
                selected_rank = i;
                break;
            }
        }
    }

    {
        king_inference_graph_token_candidate selected = candidates[selected_rank];
        zend_result status = king_inference_graph_token_output(
            token_offset,
            selected.index,
            selected.probability / active_sum,
            selected.logit,
            (double) selected_rank,
            values_out,
            length_out
        );
        efree(candidates);
        return status;
    }
}
