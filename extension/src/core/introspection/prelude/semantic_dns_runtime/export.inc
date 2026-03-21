static void king_semantic_dns_export_attributes(zval *target, const HashTable *attributes)
{
    zend_string *attribute_name;
    zval *attribute_value;

    array_init(target);

    ZEND_HASH_FOREACH_STR_KEY_VAL(attributes, attribute_name, attribute_value) {
        zval copy;

        if (attribute_name == NULL) {
            continue;
        }

        king_semantic_dns_copy_request_scalar_zval(&copy, attribute_value);
        zend_hash_add_new(Z_ARRVAL_P(target), zend_string_copy(attribute_name), &copy);
    } ZEND_HASH_FOREACH_END();
}

static void king_semantic_dns_export_service(
    zval *target,
    const king_semantic_dns_service *service
)
{
    zval attributes;

    array_init(target);
    add_assoc_str(target, "service_id", zend_string_copy(service->service_id));
    add_assoc_str(target, "service_name", zend_string_copy(service->service_name));
    add_assoc_str(target, "service_type", zend_string_copy(service->service_type));
    add_assoc_str(target, "hostname", zend_string_copy(service->hostname));
    add_assoc_long(target, "port", service->port);
    add_assoc_str(target, "status", zend_string_copy(service->status));
    add_assoc_long(target, "current_load_percent", service->current_load_percent);
    add_assoc_long(target, "active_connections", service->active_connections);
    add_assoc_long(target, "total_requests", service->total_requests);
    king_semantic_dns_export_attributes(&attributes, &service->attributes);
    add_assoc_zval(target, "attributes", &attributes);
    add_assoc_long(target, "registered_at", (zend_long) service->registered_at);
}

static void king_semantic_dns_export_mother_node(
    zval *target,
    const king_semantic_dns_mother_node *mother_node
)
{
    array_init(target);
    add_assoc_str(target, "node_id", zend_string_copy(mother_node->node_id));
    add_assoc_str(target, "hostname", zend_string_copy(mother_node->hostname));
    add_assoc_long(target, "port", mother_node->port);
    add_assoc_str(target, "status", zend_string_copy(mother_node->status));
    add_assoc_long(target, "managed_services_count", mother_node->managed_services_count);
    add_assoc_double(target, "trust_score", mother_node->trust_score);
    add_assoc_long(target, "registered_at", (zend_long) mother_node->registered_at);
}
