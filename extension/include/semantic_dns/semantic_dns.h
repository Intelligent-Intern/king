/*
 * include/semantic_dns/semantic_dns.h - Public Semantic-DNS surface
 * =================================================================
 *
 * Shared Semantic-DNS types plus the exported PHP and native C entry points
 * for service registration, topology snapshots, the bounded UDP listener,
 * local bounded queries, and route selection.
 */

#ifndef KING_SEMANTIC_DNS_H
#define KING_SEMANTIC_DNS_H

#include <php.h>
#include <stdint.h>
#include <time.h>
#include <netinet/in.h>

/* --- Semantic DNS Types --- */

typedef enum {
    KING_SERVICE_TYPE_HTTP_SERVER,
    KING_SERVICE_TYPE_MCP_AGENT,
    KING_SERVICE_TYPE_PIPELINE_ORCHESTRATOR,
    KING_SERVICE_TYPE_CACHE_NODE,
    KING_SERVICE_TYPE_DATABASE,
    KING_SERVICE_TYPE_AI_MODEL,
    KING_SERVICE_TYPE_LOAD_BALANCER,
    KING_SERVICE_TYPE_MOTHER_NODE
} king_service_type_t;

typedef enum {
    KING_SERVICE_STATUS_UNKNOWN,
    KING_SERVICE_STATUS_HEALTHY,
    KING_SERVICE_STATUS_DEGRADED,
    KING_SERVICE_STATUS_UNHEALTHY,
    KING_SERVICE_STATUS_MAINTENANCE
} king_service_status_t;

typedef struct _king_service_capability_t {
    char capability_name[64];
    char capability_version[16];
    uint32_t max_concurrent_requests;
    uint32_t avg_response_time_ms;
    double cpu_requirement;
    double memory_requirement_mb;
} king_service_capability_t;

typedef struct _king_geographic_location_t {
    double latitude;
    double longitude;
    char country_code[3];
    char region[64];
    char datacenter[64];
    uint32_t network_latency_ms;
} king_geographic_location_t;

typedef struct _king_service_record_t {
    char service_id[64];
    char service_name[128];
    king_service_type_t service_type;
    king_service_status_t status;
    
    struct sockaddr_in address;
    uint16_t port;
    char hostname[256];

    king_service_capability_t *capabilities;
    uint32_t capability_count;
    king_geographic_location_t location;

    uint32_t current_load_percent;
    uint32_t active_connections;
    uint64_t total_requests;
    time_t last_health_check;
    time_t registered_at;

    double performance_weight;
    double geographic_weight;
    double load_weight;
    double reliability_weight;
} king_service_record_t;

typedef struct _king_mother_node_t {
    char node_id[64];
    struct sockaddr_in address;
    uint16_t port;
    king_geographic_location_t location;
    uint32_t managed_services_count;
    king_service_status_t status;
    time_t last_heartbeat;
    double trust_score;
} king_mother_node_t;

typedef struct _king_semantic_dns_config_t {
    zend_bool enabled;
    uint16_t dns_port;
    char bind_address[INET_ADDRSTRLEN];
    zend_bool server_enable_tcp;
    uint32_t health_check_interval_ms;
    uint32_t service_ttl_seconds;
    uint32_t max_services_per_type;
    zend_bool semantic_mode_enable;
    uint32_t mothernode_sync_interval_sec;
    uint32_t service_discovery_max_ips_per_response;
    char mothernode_uri[256];
    king_mother_node_t *mother_nodes;
    uint32_t mother_node_count;
    zval routing_policies; /* PHP array */
} king_semantic_dns_config_t;

/* --- PHP Function Prototypes --- */

/* Initializes the local Semantic-DNS runtime from a PHP config array. */
PHP_FUNCTION(king_semantic_dns_init);

/* Activates local server state, optional mother-node sync, probe refresh, and the bounded UDP listener. */
PHP_FUNCTION(king_semantic_dns_start_server);

/* Processes one bounded local DNS-shaped query against the active runtime helper surface. */
PHP_FUNCTION(king_semantic_dns_query);

/* Registers a service record. */
PHP_FUNCTION(king_semantic_dns_register_service);

/* Discovers routeable services using the current runtime registry. */
PHP_FUNCTION(king_semantic_dns_discover_service);

/* Registers a mother node. */
PHP_FUNCTION(king_semantic_dns_register_mother_node);

/* Returns the current best route (or stable no-route shape) for a service. */
PHP_FUNCTION(king_semantic_dns_get_optimal_route);

/* Updates a registered service status. */
PHP_FUNCTION(king_semantic_dns_update_service_status);

/* Returns the current topology snapshot plus local runtime counters. */
PHP_FUNCTION(king_semantic_dns_get_service_topology);

/* --- Internal C API --- */

int king_semantic_dns_init_system(king_semantic_dns_config_t *config);
void king_semantic_dns_shutdown_system(void);
void king_semantic_dns_request_shutdown(void);
int king_semantic_dns_process_query(const char *query, char *response, size_t response_size);
int king_semantic_dns_calculate_service_score(const king_service_record_t *service, const zval *criteria);
int king_semantic_dns_discover_mother_nodes(void);
int king_semantic_dns_sync_with_mother_nodes(void);
void king_semantic_dns_health_check_services(void);
const char* king_service_type_to_string(king_service_type_t type);
const char* king_service_status_to_string(king_service_status_t status);

#endif /* KING_SEMANTIC_DNS_H */
