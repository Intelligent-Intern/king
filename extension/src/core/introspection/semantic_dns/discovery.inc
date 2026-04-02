/*
 * Public discovery leaf for Semantic-DNS service listings. Applies the live
 * health sweep, filters the in-memory registry by type and optional criteria,
 * and exports a bounded snapshot payload for userland.
 */

PHP_FUNCTION(king_semantic_dns_discover_service)
{
    zend_string *service_type;
    zval *criteria = NULL;
    zval services;
    zval *service_entry;
    zend_long service_count = 0;
    zend_long max_results = king_smart_dns_config.service_discovery_max_ips_per_response;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(service_type)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(criteria)
    ZEND_PARSE_PARAMETERS_END();

    king_semantic_dns_health_check_services();

    array_init(return_value);

    array_init(&services);
    if (king_semantic_dns_registry_initialized) {
        ZEND_HASH_FOREACH_VAL(&king_semantic_dns_service_registry, service_entry) {
            king_semantic_dns_service *service;
            zval exported_service;

            if (Z_TYPE_P(service_entry) != IS_PTR) {
                continue;
            }

            service = (king_semantic_dns_service *) Z_PTR_P(service_entry);
            if (service == NULL
                || (!zend_string_equals_literal(service_type, "any")
                    && !zend_string_equals(service->service_type, service_type))
                || king_semantic_dns_route_rank(service) >= 2
                || !king_semantic_dns_matches_criteria(service, criteria)) {
                continue;
            }

            king_semantic_dns_export_service(&exported_service, service);
            add_next_index_zval(&services, &exported_service);
            service_count++;

            if (max_results > 0 && service_count >= max_results) {
                break;
            }
        } ZEND_HASH_FOREACH_END();
    }
    add_assoc_zval(return_value, "services", &services);
    add_assoc_str(return_value, "service_type", zend_string_copy(service_type));
    add_assoc_long(return_value, "discovered_at", (zend_long) time(NULL));
    add_assoc_long(return_value, "service_count", service_count);
}
