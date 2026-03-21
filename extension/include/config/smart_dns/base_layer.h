/*
 * =========================================================================
 * FILENAME:   include/config/smart_dns/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for smart DNS.
 *
 * ARCHITECTURE:
 * This struct stores the DNS server, discovery, security, and semantic
 * routing settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_SMART_DNS_BASE_H
#define KING_CONFIG_SMART_DNS_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_smart_dns_config_t {
    /* --- General Server Settings --- */
    bool server_enable;
    char *server_bind_host;
    zend_long server_port;
    bool server_enable_tcp;
    zend_long default_record_ttl_sec;

    /* --- Operational Mode --- */
    char *mode;
    char *static_zone_file_path;
    char *recursive_forwarders;

    /* --- Service Discovery Mode Settings --- */
    char *health_agent_mcp_endpoint;
    zend_long service_discovery_max_ips_per_response;

    /* --- Security & EDNS --- */
    bool enable_dnssec_validation;
    zend_long edns_udp_payload_size;

    /* --- Semantic DNS & Mothernode --- */
    bool semantic_mode_enable;
    char *mothernode_uri;
    zend_long mothernode_sync_interval_sec;

} kg_smart_dns_config_t;

/* Module-global configuration instance. */
extern kg_smart_dns_config_t king_smart_dns_config;

#endif /* KING_CONFIG_SMART_DNS_BASE_H */
