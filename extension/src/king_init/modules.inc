/*
 * Module-lifecycle bootstrap for King. Initializes and tears down the config
 * families, orchestrator registry and shared ticket-ring support in the order
 * required by the current MINIT/MSHUTDOWN surface.
 */

#include "include/config/config.h"
#include "include/pipeline_orchestrator/orchestrator.h"
#include "include/telemetry/telemetry.h"

int king_init_modules(int type, int module_number)
{
    (void) type;

    king_ini_module_number = module_number;

    /*
     * Security comes first because it establishes global policy flags such as
     * whether userland config overrides are permitted.
     */
    kg_config_security_and_traffic_init();
    kg_config_tls_and_crypto_init();
    kg_config_tcp_transport_init();
    kg_config_quic_transport_init();
    kg_config_http2_init();
    kg_config_app_http3_websockets_webtransport_init();
    kg_config_cluster_and_process_init();
    kg_config_bare_metal_tuning_init();
    kg_config_cloud_autoscale_init();
    kg_config_dynamic_admin_api_init();
    kg_config_native_cdn_init();
    kg_config_native_object_store_init();
    kg_config_open_telemetry_init();
    kg_config_router_and_loadbalancer_init();
    kg_config_state_management_init();
    kg_config_smart_dns_init();
    kg_config_iibin_init();
    kg_config_mcp_and_orchestrator_init();
    kg_config_high_perf_compute_and_ai_init();
    kg_config_semantic_geometry_init();
    kg_config_smart_contracts_init();
    kg_config_ssh_over_quic_init();

    king_orchestrator_registry_init();

    if (king_ticket_ring_init() != SUCCESS) {
        if (king_get_error()[0] == '\0') {
            king_set_error("King ticket ring initialization failed.");
        }
        return FAILURE;
    }

    return SUCCESS;
}

int king_shutdown_modules(int type, int module_number)
{
    (void) type;

    king_ini_module_number = module_number;

    king_ticket_ring_shutdown();
    king_orchestrator_registry_shutdown();

    kg_config_ssh_over_quic_shutdown();
    kg_config_smart_contracts_shutdown();
    kg_config_semantic_geometry_shutdown();
    kg_config_high_perf_compute_and_ai_shutdown();
    kg_config_mcp_and_orchestrator_shutdown();
    kg_config_iibin_shutdown();
    kg_config_smart_dns_shutdown();
    kg_config_state_management_shutdown();
    kg_config_router_and_loadbalancer_shutdown();
    kg_config_open_telemetry_shutdown();
    kg_config_native_object_store_shutdown();
    kg_config_native_cdn_shutdown();
    kg_config_dynamic_admin_api_shutdown();
    kg_config_cloud_autoscale_shutdown();
    kg_config_bare_metal_tuning_shutdown();
    kg_config_cluster_and_process_shutdown();
    kg_config_app_http3_websockets_webtransport_shutdown();
    kg_config_http2_shutdown();
    kg_config_quic_transport_shutdown();
    kg_config_tcp_transport_shutdown();
    kg_config_tls_and_crypto_shutdown();
    kg_config_security_and_traffic_shutdown();
    king_config_release_module_globals();

    king_ini_module_number = 0;

    return SUCCESS;
}
