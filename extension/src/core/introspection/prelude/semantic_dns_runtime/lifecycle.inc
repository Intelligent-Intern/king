static void king_semantic_dns_attribute_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_STRING) {
        zend_string_release_ex(Z_STR_P(zv), 1);
    }
}

static zend_string *king_semantic_dns_copy_persistent_string(zend_string *source)
{
    if (source == NULL) {
        return NULL;
    }

    return zend_string_init(ZSTR_VAL(source), ZSTR_LEN(source), 1);
}

static zend_string *king_semantic_dns_copy_request_string(zend_string *source)
{
    if (source == NULL) {
        return NULL;
    }

    return zend_string_init(ZSTR_VAL(source), ZSTR_LEN(source), 0);
}

static bool king_semantic_dns_copy_persistent_scalar_zval(zval *target, const zval *source)
{
    switch (Z_TYPE_P(source)) {
        case IS_NULL:
            ZVAL_NULL(target);
            return true;
        case IS_FALSE:
            ZVAL_FALSE(target);
            return true;
        case IS_TRUE:
            ZVAL_TRUE(target);
            return true;
        case IS_LONG:
            ZVAL_LONG(target, Z_LVAL_P(source));
            return true;
        case IS_DOUBLE:
            ZVAL_DOUBLE(target, Z_DVAL_P(source));
            return true;
        case IS_STRING:
            ZVAL_STR(target, king_semantic_dns_copy_persistent_string(Z_STR_P(source)));
            return true;
        default:
            return false;
    }
}

static void king_semantic_dns_copy_request_scalar_zval(zval *target, const zval *source)
{
    switch (Z_TYPE_P(source)) {
        case IS_NULL:
            ZVAL_NULL(target);
            break;
        case IS_FALSE:
            ZVAL_FALSE(target);
            break;
        case IS_TRUE:
            ZVAL_TRUE(target);
            break;
        case IS_LONG:
            ZVAL_LONG(target, Z_LVAL_P(source));
            break;
        case IS_DOUBLE:
            ZVAL_DOUBLE(target, Z_DVAL_P(source));
            break;
        case IS_STRING:
            ZVAL_STR(target, king_semantic_dns_copy_request_string(Z_STR_P(source)));
            break;
        default:
            ZVAL_NULL(target);
            break;
    }
}

static void king_semantic_dns_service_free(king_semantic_dns_service *service)
{
    if (service == NULL) {
        return;
    }

    if (service->service_id != NULL) {
        zend_string_release_ex(service->service_id, 1);
    }
    if (service->service_name != NULL) {
        zend_string_release_ex(service->service_name, 1);
    }
    if (service->service_type != NULL) {
        zend_string_release_ex(service->service_type, 1);
    }
    if (service->hostname != NULL) {
        zend_string_release_ex(service->hostname, 1);
    }
    if (service->status != NULL) {
        zend_string_release_ex(service->status, 1);
    }

    zend_hash_destroy(&service->attributes);
    pefree(service, 1);
}

static void king_semantic_dns_service_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_semantic_dns_service_free((king_semantic_dns_service *) Z_PTR_P(zv));
    }
}

static void king_semantic_dns_mother_node_free(king_semantic_dns_mother_node *mother_node)
{
    if (mother_node == NULL) {
        return;
    }

    if (mother_node->node_id != NULL) {
        zend_string_release_ex(mother_node->node_id, 1);
    }
    if (mother_node->hostname != NULL) {
        zend_string_release_ex(mother_node->hostname, 1);
    }
    if (mother_node->status != NULL) {
        zend_string_release_ex(mother_node->status, 1);
    }

    pefree(mother_node, 1);
}

static void king_semantic_dns_mother_node_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_semantic_dns_mother_node_free((king_semantic_dns_mother_node *) Z_PTR_P(zv));
    }
}

int king_semantic_dns_registry_minit(void)
{
    if (king_semantic_dns_registry_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_semantic_dns_service_registry,
        8,
        NULL,
        king_semantic_dns_service_zval_dtor,
        1
    );
    zend_hash_init(
        &king_semantic_dns_mother_node_registry,
        4,
        NULL,
        king_semantic_dns_mother_node_zval_dtor,
        1
    );
    king_semantic_dns_registry_initialized = true;

    return SUCCESS;
}

void king_semantic_dns_registry_mshutdown(void)
{
    if (!king_semantic_dns_registry_initialized) {
        return;
    }

    zend_hash_destroy(&king_semantic_dns_service_registry);
    zend_hash_destroy(&king_semantic_dns_mother_node_registry);
    king_semantic_dns_registry_initialized = false;
}

static bool king_semantic_dns_status_is_valid(zend_string *status)
{
    return zend_string_equals_literal(status, "healthy")
        || zend_string_equals_literal(status, "degraded")
        || zend_string_equals_literal(status, "unhealthy")
        || zend_string_equals_literal(status, "maintenance")
        || zend_string_equals_literal(status, "unknown");
}

static king_semantic_dns_service *king_semantic_dns_registry_get_service(
    zend_string *service_id
)
{
    zval *service_entry;

    if (!king_semantic_dns_registry_initialized) {
        return NULL;
    }

    service_entry = zend_hash_find(&king_semantic_dns_service_registry, service_id);
    if (service_entry == NULL || Z_TYPE_P(service_entry) != IS_PTR) {
        return NULL;
    }

    return (king_semantic_dns_service *) Z_PTR_P(service_entry);
}

static int king_semantic_dns_route_rank(const king_semantic_dns_service *service)
{
    if (zend_string_equals_literal(service->status, "healthy")) {
        return 0;
    }
    if (zend_string_equals_literal(service->status, "degraded")) {
        return 1;
    }

    return 2;
}
