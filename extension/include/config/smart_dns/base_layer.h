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
    zend_long default_record_ttl_sec;

    /* --- Operational Mode --- */
    char *mode;

    /* --- Service Discovery Mode Settings --- */
    zend_long service_discovery_max_ips_per_response;

    /* --- Semantic DNS & Mothernode --- */
    bool semantic_mode_enable;
    char *mothernode_uri;
} kg_smart_dns_config_t;

/* Module-global configuration instance. */
extern kg_smart_dns_config_t king_smart_dns_config;

#endif /* KING_CONFIG_SMART_DNS_BASE_H */
