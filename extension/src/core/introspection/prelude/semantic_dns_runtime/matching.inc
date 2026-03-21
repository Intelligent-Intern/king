static bool king_semantic_dns_scalar_values_equal(const zval *left, const zval *right)
{
    if (Z_TYPE_P(left) != Z_TYPE_P(right)) {
        return false;
    }

    switch (Z_TYPE_P(left)) {
        case IS_NULL:
            return true;
        case IS_FALSE:
        case IS_TRUE:
            return Z_TYPE_P(left) == Z_TYPE_P(right);
        case IS_LONG:
            return Z_LVAL_P(left) == Z_LVAL_P(right);
        case IS_DOUBLE:
            return Z_DVAL_P(left) == Z_DVAL_P(right);
        case IS_STRING:
            return zend_string_equals(Z_STR_P(left), Z_STR_P(right));
        default:
            return false;
    }
}

static bool king_semantic_dns_service_matches_key(
    const king_semantic_dns_service *service,
    zend_string *criterion_name,
    zval *criterion_value
)
{
    zval *attribute_value;

    if (criterion_name == NULL) {
        return false;
    }

    if (zend_string_equals_literal(criterion_name, "service_id")) {
        return Z_TYPE_P(criterion_value) == IS_STRING
            && zend_string_equals(Z_STR_P(criterion_value), service->service_id);
    }
    if (zend_string_equals_literal(criterion_name, "service_name")) {
        return Z_TYPE_P(criterion_value) == IS_STRING
            && zend_string_equals(Z_STR_P(criterion_value), service->service_name);
    }
    if (zend_string_equals_literal(criterion_name, "service_type")) {
        return Z_TYPE_P(criterion_value) == IS_STRING
            && zend_string_equals(Z_STR_P(criterion_value), service->service_type);
    }
    if (zend_string_equals_literal(criterion_name, "hostname")) {
        return Z_TYPE_P(criterion_value) == IS_STRING
            && zend_string_equals(Z_STR_P(criterion_value), service->hostname);
    }
    if (zend_string_equals_literal(criterion_name, "status")) {
        return Z_TYPE_P(criterion_value) == IS_STRING
            && zend_string_equals(Z_STR_P(criterion_value), service->status);
    }
    if (zend_string_equals_literal(criterion_name, "port")) {
        return Z_TYPE_P(criterion_value) == IS_LONG
            && Z_LVAL_P(criterion_value) == service->port;
    }
    if (zend_string_equals_literal(criterion_name, "current_load_percent")) {
        return Z_TYPE_P(criterion_value) == IS_LONG
            && Z_LVAL_P(criterion_value) == service->current_load_percent;
    }
    if (zend_string_equals_literal(criterion_name, "active_connections")) {
        return Z_TYPE_P(criterion_value) == IS_LONG
            && Z_LVAL_P(criterion_value) == service->active_connections;
    }
    if (zend_string_equals_literal(criterion_name, "total_requests")) {
        return Z_TYPE_P(criterion_value) == IS_LONG
            && Z_LVAL_P(criterion_value) == service->total_requests;
    }

    attribute_value = zend_hash_find(&service->attributes, criterion_name);
    if (attribute_value == NULL) {
        return false;
    }

    return king_semantic_dns_scalar_values_equal(attribute_value, criterion_value);
}

static bool king_semantic_dns_matches_criteria(
    const king_semantic_dns_service *service,
    zval *criteria
)
{
    zend_string *criterion_name;
    zval *criterion_value;

    if (criteria == NULL) {
        return true;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(criteria), criterion_name, criterion_value) {
        if (criterion_name == NULL) {
            continue;
        }

        /*
         * The current skeleton registry only matches scalar top-level service
         * fields and scalar attributes. Deeper client/location structures are
         * intentionally ignored until locality-aware routing exists.
         */
        if (Z_TYPE_P(criterion_value) != IS_NULL
            && Z_TYPE_P(criterion_value) != IS_FALSE
            && Z_TYPE_P(criterion_value) != IS_TRUE
            && Z_TYPE_P(criterion_value) != IS_LONG
            && Z_TYPE_P(criterion_value) != IS_DOUBLE
            && Z_TYPE_P(criterion_value) != IS_STRING) {
            continue;
        }

        if (!king_semantic_dns_service_matches_key(service, criterion_name, criterion_value)) {
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
}

static bool king_semantic_dns_candidate_beats_best(
    const king_semantic_dns_service *candidate,
    int candidate_rank,
    const king_semantic_dns_service *best,
    int best_rank
)
{
    if (best == NULL) {
        return true;
    }

    if (candidate_rank != best_rank) {
        return candidate_rank < best_rank;
    }
    if (candidate->current_load_percent != best->current_load_percent) {
        return candidate->current_load_percent < best->current_load_percent;
    }
    if (candidate->active_connections != best->active_connections) {
        return candidate->active_connections < best->active_connections;
    }
    if (candidate->total_requests != best->total_requests) {
        return candidate->total_requests < best->total_requests;
    }
    if (candidate->registered_at != best->registered_at) {
        return candidate->registered_at < best->registered_at;
    }

    return strcmp(ZSTR_VAL(candidate->service_id), ZSTR_VAL(best->service_id)) < 0;
}
