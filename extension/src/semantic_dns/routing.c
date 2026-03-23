/*
 * =========================================================================
 * FILENAME:   src/semantic_dns/routing.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Implements the native Semantic-DNS real routing and scoring based on
 * load, health, and policy.
 * =========================================================================
 */

#include "semantic_dns_internal.h"
#include <string.h>

int king_semantic_dns_calculate_service_score(
    const king_service_record_t *service,
    const zval *criteria
)
{
    zval *criteria_value;
    int score = 0;
    uint32_t load_penalty;

    if (service == NULL) {
        return -1;
    }

    switch (service->status) {
        case KING_SERVICE_STATUS_HEALTHY:
            score = 1000;
            break;
        case KING_SERVICE_STATUS_DEGRADED:
            score = 600;
            break;
        case KING_SERVICE_STATUS_MAINTENANCE:
            score = 150;
            break;
        case KING_SERVICE_STATUS_UNKNOWN:
            score = 75;
            break;
        case KING_SERVICE_STATUS_UNHEALTHY:
        default:
            score = 0;
            break;
    }

    /* Apply user criteria (hard limits) */
    if (criteria != NULL && Z_TYPE_P(criteria) == IS_ARRAY) {
        criteria_value = zend_hash_str_find(Z_ARRVAL_P(criteria), "hostname", sizeof("hostname") - 1);
        if (criteria_value != NULL
            && Z_TYPE_P(criteria_value) == IS_STRING
            && strcmp(service->hostname, Z_STRVAL_P(criteria_value)) != 0) {
            return -1;
        }

        criteria_value = zend_hash_str_find(Z_ARRVAL_P(criteria), "port", sizeof("port") - 1);
        if (criteria_value != NULL
            && Z_TYPE_P(criteria_value) == IS_LONG
            && service->port != (uint16_t) Z_LVAL_P(criteria_value)) {
            return -1;
        }

        criteria_value = zend_hash_str_find(
            Z_ARRVAL_P(criteria),
            "max_load_percent",
            sizeof("max_load_percent") - 1
        );
        if (criteria_value != NULL
            && Z_TYPE_P(criteria_value) == IS_LONG
            && service->current_load_percent > (uint32_t) Z_LVAL_P(criteria_value)) {
            return -1;
        }
    }

    /* Active penalty calculation based on load and connection count */
    load_penalty = service->current_load_percent > 100 ? 100 : service->current_load_percent;
    // Every percent of load drops the score by 4 points (max 400 penalty)
    score += (int) ((100 - load_penalty) * 4);
    
    // Every active connection drops the score by 1 point (max 1000 penalty)
    score -= (service->active_connections > 1000) ? 1000 : (int) service->active_connections;

    /* Reward factors from reliability policy */
    if (service->reliability_weight > 0.0) {
        score += (int) (service->reliability_weight * 100.0);
    }
    
    if (service->performance_weight > 0.0) {
        score += (int) (service->performance_weight * 50.0);
    }

    return score < 0 ? 0 : score;
}
