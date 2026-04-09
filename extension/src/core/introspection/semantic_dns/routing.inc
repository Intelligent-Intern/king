/*
 * Public routing leaf for Semantic-DNS. Refreshes the semantic-mode shared
 * state view, reuses the native service scoring policy against the in-memory
 * registry, exports the best eligible service, and otherwise returns the
 * bounded no-route payload expected by userland.
 */

#include "include/semantic_dns/semantic_dns.h"

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

    if (!king_semantic_dns_refresh_runtime_view_or_throw("king_semantic_dns_get_optimal_route")) {
        RETURN_THROWS();
    }

    king_semantic_dns_health_check_services();

    /*
     * Build native score using the core scoring policy from routing.c
     */
    if (king_semantic_dns_registry_initialized) {
        ZEND_HASH_FOREACH_VAL(&king_semantic_dns_service_registry, service_entry) {
            king_semantic_dns_service *service;
            king_service_record_t native_record;
            int route_score;

            if (Z_TYPE_P(service_entry) != IS_PTR) {
                continue;
            }

            service = (king_semantic_dns_service *) Z_PTR_P(service_entry);
            if (service == NULL
                || !zend_string_equals(service->service_name, service_name)) {
                continue;
            }

            if (king_semantic_dns_route_rank(service) >= 2) {
                continue;
            }

            memset(&native_record, 0, sizeof(native_record));
            if (service->hostname != NULL) {
                strncpy(native_record.hostname, ZSTR_VAL(service->hostname), 255);
            }
            native_record.port = service->port;
            native_record.current_load_percent = service->current_load_percent;
            native_record.active_connections = service->active_connections;
            
            if (service->status != NULL) {
                if (zend_string_equals_literal(service->status, "healthy")) {
                    native_record.status = KING_SERVICE_STATUS_HEALTHY;
                } else if (zend_string_equals_literal(service->status, "degraded")) {
                    native_record.status = KING_SERVICE_STATUS_DEGRADED;
                } else if (zend_string_equals_literal(service->status, "unhealthy")) {
                    native_record.status = KING_SERVICE_STATUS_UNHEALTHY;
                } else if (zend_string_equals_literal(service->status, "maintenance")) {
                    native_record.status = KING_SERVICE_STATUS_MAINTENANCE;
                } else if (zend_string_equals_literal(service->status, "unknown")) {
                    native_record.status = KING_SERVICE_STATUS_UNKNOWN;
                } else {
                    native_record.status = KING_SERVICE_STATUS_UNKNOWN;
                }
            } else {
                native_record.status = KING_SERVICE_STATUS_UNKNOWN;
            }

            route_score = king_semantic_dns_calculate_service_score(&native_record, client_info);
            
            /* Score of 0 or -1 means not eligible */
            if (route_score <= 0) {
                continue;
            }

            if (best_service == NULL || route_score > best_rank) {
                best_service = service;
                best_rank = route_score;
            }
        } ZEND_HASH_FOREACH_END();
    }

    if (best_service != NULL) {
        king_semantic_dns_export_service(return_value, best_service);
        add_assoc_long(return_value, "routed_at", (zend_long) time(NULL));
        add_assoc_long(return_value, "route_score", (zend_long) best_rank);
        return;
    }

    array_init(return_value);
    add_assoc_null(return_value, "service_id");
    add_assoc_string(return_value, "error", "No healthy service found");
    add_assoc_long(return_value, "routed_at", (zend_long) time(NULL));
}
