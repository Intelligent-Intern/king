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

static bool king_semantic_dns_scalar_zval_equals(
    const zval *left,
    const zval *right
)
{
    if (left == right) {
        return true;
    }

    if (left == NULL || right == NULL || Z_TYPE_P(left) != Z_TYPE_P(right)) {
        return false;
    }

    switch (Z_TYPE_P(left)) {
        case IS_NULL:
        case IS_FALSE:
        case IS_TRUE:
            return true;
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

static bool king_semantic_dns_attribute_tables_equal(
    const HashTable *left,
    const HashTable *right
)
{
    zend_string *attribute_name;
    zval *attribute_value;

    if (left == right) {
        return true;
    }

    if (left == NULL || right == NULL) {
        return false;
    }

    if (zend_hash_num_elements((HashTable *) left) != zend_hash_num_elements((HashTable *) right)) {
        return false;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL((HashTable *) left, attribute_name, attribute_value) {
        zval *other_value;

        if (attribute_name == NULL) {
            return false;
        }

        other_value = zend_hash_find((HashTable *) right, attribute_name);
        if (other_value == NULL || !king_semantic_dns_scalar_zval_equals(attribute_value, other_value)) {
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
}

static bool king_semantic_dns_service_equals(
    const king_semantic_dns_service *left,
    const king_semantic_dns_service *right
)
{
    if (left == right) {
        return true;
    }

    if (left == NULL || right == NULL) {
        return false;
    }

    return zend_string_equals(left->service_id, right->service_id)
        && zend_string_equals(left->service_name, right->service_name)
        && zend_string_equals(left->service_type, right->service_type)
        && zend_string_equals(left->hostname, right->hostname)
        && zend_string_equals(left->status, right->status)
        && left->port == right->port
        && left->current_load_percent == right->current_load_percent
        && left->active_connections == right->active_connections
        && left->total_requests == right->total_requests
        && king_semantic_dns_attribute_tables_equal(&left->attributes, &right->attributes);
}
