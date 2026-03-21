PHP_FUNCTION(king_semantic_dns_get_optimal_route)
{
    zend_string *service_name;
    zval *client_info = NULL;
    zval *service_entry;
    const king_semantic_dns_service *best_service = NULL;
    int best_rank = 3;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(service_name)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(client_info)
    ZEND_PARSE_PARAMETERS_END();

    /*
     * The current skeleton route picker uses the registered service state,
     * not deep client-side semantic matching. Keep accepting client_info for
     * forward compatibility, but do not let nested hint payloads suppress a
     * routable service outright.
     */
    (void) client_info;

    if (king_semantic_dns_registry_initialized) {
        ZEND_HASH_FOREACH_VAL(&king_semantic_dns_service_registry, service_entry) {
            king_semantic_dns_service *service;
            int route_rank;

            if (Z_TYPE_P(service_entry) != IS_PTR) {
                continue;
            }

            service = (king_semantic_dns_service *) Z_PTR_P(service_entry);
            if (service == NULL
                || !zend_string_equals(service->service_name, service_name)) {
                continue;
            }

            route_rank = king_semantic_dns_route_rank(service);
            if (route_rank >= 2) {
                continue;
            }

            if (king_semantic_dns_candidate_beats_best(
                service,
                route_rank,
                best_service,
                best_rank
            )) {
                best_service = service;
                best_rank = route_rank;
            }
        } ZEND_HASH_FOREACH_END();
    }

    if (best_service != NULL) {
        king_semantic_dns_export_service(return_value, best_service);
        add_assoc_long(return_value, "routed_at", (zend_long) time(NULL));
        return;
    }

    array_init(return_value);
    add_assoc_null(return_value, "service_id");
    add_assoc_string(return_value, "error", "No healthy service found");
    add_assoc_long(return_value, "routed_at", (zend_long) time(NULL));
}
