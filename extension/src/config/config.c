/*
 * =========================================================================
 * FILENAME:   src/config/config.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Implements the master King\Config resource lifecycle.
 *
 * RUNTIME STATUS:
 * king_config_new_from_options() allocates a real composed king_cfg_t
 * snapshot from the active module globals. In the current runtime it
 * materializes the safe per-resource override surface that is already wired
 * for transport, TLS, HTTP/2, autoscale, MCP/orchestrator, geometry,
 * Smart-DNS, storage/CDN, telemetry, smart-contract, and SSH-gateway
 * config families. The resulting resource is consumed by the active client
 * surfaces that accept `King\Config`, including `king_connect()` and the
 * session-oriented runtime leaves.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/config/cloud_autoscale/config.h"
#include "include/config/config.h"
#include "include/config/http2/config.h"
#include "include/config/mcp_and_orchestrator/config.h"
#include "include/config/native_cdn/config.h"
#include "include/config/native_object_store/config.h"
#include "include/config/open_telemetry/config.h"
#include "include/config/quic_transport/config.h"
#include "include/config/semantic_geometry/config.h"
#include "include/config/smart_contracts/config.h"
#include "include/config/smart_dns/config.h"
#include "include/config/ssh_over_quic/config.h"
#include "include/config/tcp_transport/config.h"
#include "include/config/tls_and_crypto/config.h"
#include "include/king_globals.h"
#include <ext/spl/spl_exceptions.h>
#include "zend_exceptions.h"

extern int le_king_cfg;

typedef enum _king_config_override_module_t {
    KING_CONFIG_OVERRIDE_NONE = 0,
    KING_CONFIG_OVERRIDE_TLS,
    KING_CONFIG_OVERRIDE_QUIC,
    KING_CONFIG_OVERRIDE_HTTP2,
    KING_CONFIG_OVERRIDE_TCP,
    KING_CONFIG_OVERRIDE_AUTOSCALE,
    KING_CONFIG_OVERRIDE_MCP_ORCHESTRATOR,
    KING_CONFIG_OVERRIDE_GEOMETRY,
    KING_CONFIG_OVERRIDE_SMART_CONTRACT,
    KING_CONFIG_OVERRIDE_SSH,
    KING_CONFIG_OVERRIDE_STORAGE,
    KING_CONFIG_OVERRIDE_CDN,
    KING_CONFIG_OVERRIDE_DNS,
    KING_CONFIG_OVERRIDE_OTEL
} king_config_override_module_t;

#define KING_CONFIG_FREE_PERSISTENT(field) \
    do { \
        if ((field) != NULL) { \
            pefree((field), 1); \
            (field) = NULL; \
        } \
    } while (0)

#include "internal/owned_strings.inc"
#include "internal/snapshot.inc"
#include "internal/overrides.inc"
#include "internal/api.inc"
#include "internal/object.inc"

void king_config_release_module_globals(void)
{
    KING_CONFIG_FREE_PERSISTENT(king_bare_metal_config.io_thread_cpu_affinity);
    KING_CONFIG_FREE_PERSISTENT(king_bare_metal_config.io_thread_numa_node_policy);
    memset(&king_bare_metal_config, 0, sizeof(king_bare_metal_config));

    KING_CONFIG_FREE_PERSISTENT(king_cloud_autoscale_config.provider);
    memset(&king_cloud_autoscale_config, 0, sizeof(king_cloud_autoscale_config));

    memset(&king_cluster_config, 0, sizeof(king_cluster_config));

    KING_CONFIG_FREE_PERSISTENT(king_dynamic_admin_api_config.auth_mode);
    KING_CONFIG_FREE_PERSISTENT(king_dynamic_admin_api_config.ca_file);
    KING_CONFIG_FREE_PERSISTENT(king_dynamic_admin_api_config.cert_file);
    KING_CONFIG_FREE_PERSISTENT(king_dynamic_admin_api_config.key_file);
    memset(&king_dynamic_admin_api_config, 0, sizeof(king_dynamic_admin_api_config));

    KING_CONFIG_FREE_PERSISTENT(king_high_perf_compute_ai_config.gpu_default_backend);
    memset(&king_high_perf_compute_ai_config, 0, sizeof(king_high_perf_compute_ai_config));

    memset(&king_iibin_config, 0, sizeof(king_iibin_config));

    KING_CONFIG_FREE_PERSISTENT(king_mcp_orchestrator_config.orchestrator_execution_backend);
    memset(&king_mcp_orchestrator_config, 0, sizeof(king_mcp_orchestrator_config));

    KING_CONFIG_FREE_PERSISTENT(king_native_cdn_config.cache_mode);
    memset(&king_native_cdn_config, 0, sizeof(king_native_cdn_config));

    KING_CONFIG_FREE_PERSISTENT(king_native_object_store_config.default_redundancy_mode);
    KING_CONFIG_FREE_PERSISTENT(king_native_object_store_config.erasure_coding_shards);
    KING_CONFIG_FREE_PERSISTENT(king_native_object_store_config.node_discovery_mode);
    memset(&king_native_object_store_config, 0, sizeof(king_native_object_store_config));

    KING_CONFIG_FREE_PERSISTENT(king_open_telemetry_config.exporter_protocol);
    KING_CONFIG_FREE_PERSISTENT(king_open_telemetry_config.traces_sampler_type);
    KING_CONFIG_FREE_PERSISTENT(king_open_telemetry_config.metrics_default_histogram_boundaries);
    memset(&king_open_telemetry_config, 0, sizeof(king_open_telemetry_config));

    KING_CONFIG_FREE_PERSISTENT(king_quic_transport_config.cc_algorithm);
    memset(&king_quic_transport_config, 0, sizeof(king_quic_transport_config));

    KING_CONFIG_FREE_PERSISTENT(king_router_loadbalancer_config.hashing_algorithm);
    KING_CONFIG_FREE_PERSISTENT(king_router_loadbalancer_config.backend_discovery_mode);
    memset(&king_router_loadbalancer_config, 0, sizeof(king_router_loadbalancer_config));

    KING_CONFIG_FREE_PERSISTENT(king_security_config.cors_allowed_origins);
    memset(&king_security_config, 0, sizeof(king_security_config));

    memset(&king_semantic_geometry_config, 0, sizeof(king_semantic_geometry_config));

    king_config_free_smart_contract_strings(&king_smart_contracts_config);
    memset(&king_smart_contracts_config, 0, sizeof(king_smart_contracts_config));

    KING_CONFIG_FREE_PERSISTENT(king_smart_dns_config.mode);
    KING_CONFIG_FREE_PERSISTENT(king_smart_dns_config.live_probe_allowed_hosts);
    memset(&king_smart_dns_config, 0, sizeof(king_smart_dns_config));

    king_config_free_ssh_strings(&king_ssh_over_quic_config);
    memset(&king_ssh_over_quic_config, 0, sizeof(king_ssh_over_quic_config));

    KING_CONFIG_FREE_PERSISTENT(king_state_management_config.default_backend);
    memset(&king_state_management_config, 0, sizeof(king_state_management_config));

    KING_CONFIG_FREE_PERSISTENT(king_tcp_transport_config.tls_min_version_allowed);
    memset(&king_tcp_transport_config, 0, sizeof(king_tcp_transport_config));

    king_config_free_tls_strings(&king_tls_and_crypto_config);
    memset(&king_tls_and_crypto_config, 0, sizeof(king_tls_and_crypto_config));

    memset(&king_app_protocols_config, 0, sizeof(king_app_protocols_config));
}
