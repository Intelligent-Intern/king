static bool king_semantic_dns_registry_require_available(void)
{
    if (king_semantic_dns_registry_initialized) {
        return true;
    }

    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "Semantic-DNS registry is unavailable."
    );
    return false;
}

static bool king_semantic_dns_service_apply_optional_metric(
    HashTable *source,
    const char *key,
    size_t key_len,
    zend_long min_value,
    zend_long max_value,
    bool enforce_max,
    const char *constraint,
    const char *function_name,
    zend_long *target)
{
    zval *field = zend_hash_str_find(source, key, key_len);

    if (field == NULL) {
        return true;
    }

    if (
        Z_TYPE_P(field) != IS_LONG
        || Z_LVAL_P(field) < min_value
        || (enforce_max && Z_LVAL_P(field) > max_value)
    ) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() '%s' must be %s.",
            function_name,
            key,
            constraint
        );
        return false;
    }

    *target = Z_LVAL_P(field);
    return true;
}

static bool king_semantic_dns_service_apply_metrics(
    HashTable *source,
    const char *function_name,
    king_semantic_dns_service *service)
{
    if (!king_semantic_dns_service_apply_optional_metric(
        source,
        "current_load_percent",
        sizeof("current_load_percent") - 1,
        0,
        100,
        true,
        "between 0 and 100",
        function_name,
        &service->current_load_percent
    )) {
        return false;
    }

    if (!king_semantic_dns_service_apply_optional_metric(
        source,
        "active_connections",
        sizeof("active_connections") - 1,
        0,
        0,
        false,
        ">= 0",
        function_name,
        &service->active_connections
    )) {
        return false;
    }

    return king_semantic_dns_service_apply_optional_metric(
        source,
        "total_requests",
        sizeof("total_requests") - 1,
        0,
        0,
        false,
        ">= 0",
        function_name,
        &service->total_requests
    );
}
