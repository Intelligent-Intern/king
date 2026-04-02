/*
 * =========================================================================
 * FILENAME:   include/config/config.h
 * MODULE:     king: Master Configuration System
 * =========================================================================
 *
 * OVERVIEW
 *
 * This header defines the master configuration snapshot, `king_cfg_t`, which
 * backs the PHP-level `King\Config` resource. In the current runtime it is the
 * composed per-resource override surface built from module-global defaults and
 * optional userland overrides accepted through `king_new_config()`.
 *
 * RUNTIME MODEL
 *
 * The active build keeps a simpler ownership line than the older design notes
 * implied:
 *
 * 1. module-global defaults and system `php.ini` policy establish the process
 *    baseline
 * 2. `King\Config` may add the namespaced runtime-override slice when
 *    `king.security_allow_config_override` permits it
 * 3. once attached to a live runtime surface, the snapshot can be frozen and
 *    inspected but not mutated
 *
 * Admin-listener and TLS-reload flows exist elsewhere in the runtime, but they
 * are not a fourth generic layer applied through this header.
 *
 * MODULAR STRUCTURE
 *
 * The config implementation stays split by subsystem. This header includes the
 * per-domain base-layer and lifecycle headers, and `king_cfg_t` is the
 * composed struct-of-structs that those modules fill.
 *
 * =========================================================================
 */

#ifndef KING_CONFIG_H
#define KING_CONFIG_H

#include <php.h>

#if defined(KING_RUNTIME_BUILD) && !defined(QUICHE_H)
typedef void quiche_config;
#else
#  include <quiche.h>
#endif

/*
 * =========================================================================
 * == Modular Configuration Includes
 * =========================================================================
 * The `base_layer.h` headers define the concrete config structs.
 * The `index.h` headers provide the module lifecycle hooks.
 */

#include "include/config/app_http3_websockets_webtransport/base_layer.h"
#include "include/config/app_http3_websockets_webtransport/index.h"
#include "include/config/bare_metal_tuning/base_layer.h"
#include "include/config/bare_metal_tuning/index.h"
#include "include/config/cloud_autoscale/base_layer.h"
#include "include/config/cloud_autoscale/index.h"
#include "include/config/cluster_and_process/base_layer.h"
#include "include/config/cluster_and_process/index.h"
#include "include/config/dynamic_admin_api/base_layer.h"
#include "include/config/dynamic_admin_api/index.h"
#include "include/config/high_perf_compute_and_ai/base_layer.h"
#include "include/config/high_perf_compute_and_ai/index.h"
#include "include/config/http2/base_layer.h"
#include "include/config/http2/index.h"
#include "include/config/iibin/base_layer.h"
#include "include/config/iibin/index.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/config/mcp_and_orchestrator/index.h"
#include "include/config/native_cdn/base_layer.h"
#include "include/config/native_cdn/index.h"
#include "include/config/native_object_store/base_layer.h"
#include "include/config/native_object_store/index.h"
#include "include/config/open_telemetry/base_layer.h"
#include "include/config/open_telemetry/index.h"
#include "include/config/quic_transport/base_layer.h"
#include "include/config/quic_transport/index.h"
#include "include/config/router_and_loadbalancer/base_layer.h"
#include "include/config/router_and_loadbalancer/index.h"
#include "include/config/security_and_traffic/base_layer.h"
#include "include/config/security_and_traffic/index.h"
#include "include/config/semantic_geometry/base_layer.h"
#include "include/config/semantic_geometry/index.h"
#include "include/config/smart_contracts/base_layer.h"
#include "include/config/smart_contracts/index.h"
#include "include/config/smart_dns/base_layer.h"
#include "include/config/smart_dns/index.h"
#include "include/config/ssh_over_quic/base_layer.h"
#include "include/config/ssh_over_quic/index.h"
#include "include/config/state_management/base_layer.h"
#include "include/config/state_management/index.h"
#include "include/config/tcp_transport/base_layer.h"
#include "include/config/tcp_transport/index.h"
#include "include/config/tls_and_crypto/base_layer.h"
#include "include/config/tls_and_crypto/index.h"

/**
 * @brief The master C-level representation of a `King\Config` snapshot.
 *
 * This struct is a composed snapshot of the individual configuration modules
 * defined in the various sub-module headers.
 */
typedef struct king_cfg_s {
    /* The underlying raw config handle from the quiche library. */
    quiche_config *quiche_cfg;

    /* Set once the config is consumed by a live session. */
    zend_bool frozen;
    zend_bool userland_overrides_applied;
    zend_long override_count;
    zend_bool owns_autoscale_strings;
    zend_bool owns_storage_strings;
    zend_bool owns_cdn_strings;
    zend_bool owns_dns_strings;
    zend_bool owns_otel_strings;
    zend_bool owns_geometry_strings;
    zend_bool owns_smart_contract_strings;
    zend_bool owns_ssh_strings;
    zend_bool owns_tls_strings;
    zend_bool owns_tcp_strings;
    zend_bool owns_quic_cc_algorithm;
    zend_bool owns_mcp_orchestrator_strings;

    /* Composed configuration modules. */
    kg_app_protocols_config_t      app_protocols;
    kg_bare_metal_config_t         bare_metal;
    kg_cloud_autoscale_config_t    autoscale;
    kg_cluster_config_t            cluster;
    kg_dynamic_admin_api_config_t  admin_api;
    kg_high_perf_compute_ai_config_t compute_ai;
    kg_http2_config_t              http2;
    kg_iibin_config_t              serialization;
    kg_mcp_orchestrator_config_t   mcp;
    kg_native_cdn_config_t         cdn;
    kg_native_object_store_config_t storage;
    kg_open_telemetry_config_t     observability;
    kg_quic_transport_config_t     quic;
    kg_router_loadbalancer_config_t router;
    kg_security_config_t           security;
    kg_semantic_geometry_config_t  semantic_geometry;
    kg_smart_contracts_config_t    smart_contract;
    kg_smart_dns_config_t          dns;
    kg_ssh_over_quic_config_t      ssh;
    kg_state_management_config_t   state;
    kg_tcp_transport_config_t      tcp;
    kg_tls_and_crypto_config_t     tls;

} king_cfg_t;


/*
 * =========================================================================
 * == Public C-API for the Config Module
 * =========================================================================
 */

/**
 * @brief The main public PHP function to create a new configuration resource.
 *
 * PHP Signature: `king_new_config(?array $options = null): resource`
 */
PHP_FUNCTION(king_new_config);

/**
 * @brief Creates a new `king_cfg_t` snapshot from a PHP options array.
 *
 * This is the primary internal entry point. It allocates a new config snapshot
 * from the active module globals and then applies the accepted userland
 * override slice when present.
 *
 * @param zopts A zval pointing to a PHP associative array, or NULL.
 * @return A pointer to a newly allocated and fully populated `king_cfg_t`.
 */
king_cfg_t* king_config_new_from_options(zval *zopts);

/**
 * @brief Frees a `king_cfg_t` and its owned resources.
 *
 * Frees the `king_cfg_t` struct itself and all underlying resources.
 *
 * @param cfg The configuration object to free.
 */
void king_config_free(king_cfg_t *cfg);

/**
 * @brief Releases persistent module-global configuration strings at MSHUTDOWN.
 */
void king_config_release_module_globals(void);

/**
 * @brief Marks a configuration object as immutable.
 *
 * @param cfg The configuration object to freeze.
 */
void king_config_mark_frozen(king_cfg_t *cfg);

#endif /* KING_CONFIG_H */
