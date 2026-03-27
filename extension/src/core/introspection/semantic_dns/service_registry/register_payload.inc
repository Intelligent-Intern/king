static bool king_semantic_dns_service_assign_required_string(
    HashTable *source,
    const char *key,
    size_t key_len,
    const char *function_name,
    zend_string **target)
{
    zval *field = zend_hash_str_find(source, key, key_len);

    if (field == NULL || Z_TYPE_P(field) != IS_STRING || Z_STRLEN_P(field) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() requires a non-empty '%s'.",
            function_name,
            key
        );
        return false;
    }

    *target = king_semantic_dns_copy_persistent_string(Z_STR_P(field));
    return true;
}

static bool king_semantic_dns_service_assign_required_port(
    HashTable *source,
    const char *function_name,
    king_semantic_dns_service *service)
{
    zval *field = zend_hash_str_find(source, "port", sizeof("port") - 1);

    if (field == NULL || Z_TYPE_P(field) != IS_LONG || Z_LVAL_P(field) <= 0 || Z_LVAL_P(field) > 65535) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() requires 'port' between 1 and 65535.",
            function_name
        );
        return false;
    }

    service->port = Z_LVAL_P(field);
    return true;
}

static bool king_semantic_dns_service_assign_status(
    HashTable *source,
    const char *function_name,
    king_semantic_dns_service *service)
{
    zval *field = zend_hash_str_find(source, "status", sizeof("status") - 1);

    if (field == NULL) {
        service->status = zend_string_init("healthy", sizeof("healthy") - 1, 1);
        return true;
    }

    if (Z_TYPE_P(field) != IS_STRING || !king_semantic_dns_status_is_valid(Z_STR_P(field))) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() 'status' must be one of: healthy, degraded, unhealthy, maintenance, unknown.",
            function_name
        );
        return false;
    }

    service->status = king_semantic_dns_copy_persistent_string(Z_STR_P(field));
    return true;
}

static bool king_semantic_dns_service_assign_attributes(
    HashTable *source,
    const char *function_name,
    king_semantic_dns_service *service)
{
    zval *attributes = zend_hash_str_find(source, "attributes", sizeof("attributes") - 1);
    zend_string *attribute_name;
    zval *attribute_value;

    if (attributes == NULL) {
        return true;
    }

    if (Z_TYPE_P(attributes) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s() 'attributes' must be an array when provided.",
            function_name
        );
        return false;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(attributes), attribute_name, attribute_value) {
        zval stored_attribute_value;

        if (attribute_name == NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() attribute keys must be strings.",
                function_name
            );
            return false;
        }

        if (!king_semantic_dns_copy_persistent_scalar_zval(&stored_attribute_value, attribute_value)) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s() attribute '%s' must be scalar or null.",
                function_name,
                ZSTR_VAL(attribute_name)
            );
            return false;
        }

        zend_hash_update(
            &service->attributes,
            king_semantic_dns_copy_persistent_string(attribute_name),
            &stored_attribute_value
        );
    } ZEND_HASH_FOREACH_END();

    return true;
}

static king_semantic_dns_service *king_semantic_dns_service_create_from_payload(
    zval *service_info,
    const char *function_name)
{
    HashTable *source = Z_ARRVAL_P(service_info);
    king_semantic_dns_service *service = pecalloc(1, sizeof(*service), 1);

    zend_hash_init(&service->attributes, 4, NULL, king_semantic_dns_attribute_zval_dtor, 1);

    if (!king_semantic_dns_service_assign_required_string(
        source,
        "service_id",
        sizeof("service_id") - 1,
        function_name,
        &service->service_id
    )) {
        goto fail;
    }

    if (!king_semantic_dns_service_assign_required_string(
        source,
        "service_name",
        sizeof("service_name") - 1,
        function_name,
        &service->service_name
    )) {
        goto fail;
    }

    if (!king_semantic_dns_service_assign_required_string(
        source,
        "service_type",
        sizeof("service_type") - 1,
        function_name,
        &service->service_type
    )) {
        goto fail;
    }

    if (!king_semantic_dns_service_assign_required_string(
        source,
        "hostname",
        sizeof("hostname") - 1,
        function_name,
        &service->hostname
    )) {
        goto fail;
    }

    if (!king_semantic_dns_service_assign_required_port(source, function_name, service)) {
        goto fail;
    }

    if (!king_semantic_dns_service_assign_status(source, function_name, service)) {
        goto fail;
    }

    if (!king_semantic_dns_service_apply_metrics(source, function_name, service)) {
        goto fail;
    }

    if (!king_semantic_dns_service_assign_attributes(source, function_name, service)) {
        goto fail;
    }

    service->registered_at = time(NULL);
    return service;

fail:
    king_semantic_dns_service_free(service);
    return NULL;
}
