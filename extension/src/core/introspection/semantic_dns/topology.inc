PHP_FUNCTION(king_semantic_dns_get_service_topology)
{
    zval services;
    zval mother_nodes;
    zval statistics;
    zval *service_entry;
    zval *mother_node_entry;
    king_semantic_dns_service *service;
    king_semantic_dns_mother_node *mother_node;
    zend_long healthy_services = 0;
    zend_long degraded_services = 0;
    zend_long unhealthy_services = 0;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    array_init(&services);
    if (king_semantic_dns_registry_initialized) {
        ZEND_HASH_FOREACH_VAL(&king_semantic_dns_service_registry, service_entry) {
            zval exported_service;

            if (Z_TYPE_P(service_entry) != IS_PTR) {
                continue;
            }

            service = (king_semantic_dns_service *) Z_PTR_P(service_entry);
            if (service == NULL) {
                continue;
            }

            king_semantic_dns_export_service(&exported_service, service);
            add_next_index_zval(&services, &exported_service);

            if (zend_string_equals_literal(service->status, "healthy")) {
                healthy_services++;
            } else if (zend_string_equals_literal(service->status, "degraded")) {
                degraded_services++;
            } else {
                unhealthy_services++;
            }
        } ZEND_HASH_FOREACH_END();
    }
    add_assoc_zval(return_value, "services", &services);

    array_init(&mother_nodes);
    if (king_semantic_dns_registry_initialized) {
        ZEND_HASH_FOREACH_VAL(&king_semantic_dns_mother_node_registry, mother_node_entry) {
            zval exported_mother_node;

            if (Z_TYPE_P(mother_node_entry) != IS_PTR) {
                continue;
            }

            mother_node = (king_semantic_dns_mother_node *) Z_PTR_P(mother_node_entry);
            if (mother_node == NULL) {
                continue;
            }

            king_semantic_dns_export_mother_node(&exported_mother_node, mother_node);
            add_next_index_zval(&mother_nodes, &exported_mother_node);
        } ZEND_HASH_FOREACH_END();
    }
    add_assoc_zval(return_value, "mother_nodes", &mother_nodes);

    array_init(&statistics);
    add_assoc_long(&statistics, "total_services", zend_hash_num_elements(Z_ARRVAL(services)));
    add_assoc_long(&statistics, "healthy_services", healthy_services);
    add_assoc_long(&statistics, "degraded_services", degraded_services);
    add_assoc_long(&statistics, "unhealthy_services", unhealthy_services);
    add_assoc_long(&statistics, "mother_nodes", zend_hash_num_elements(Z_ARRVAL(mother_nodes)));
    add_assoc_zval(return_value, "statistics", &statistics);

    add_assoc_long(return_value, "topology_generated_at", (zend_long) time(NULL));
}
