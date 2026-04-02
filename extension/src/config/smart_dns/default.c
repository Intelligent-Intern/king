/*
 * =========================================================================
 * FILENAME:   src/config/smart_dns/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the Smart-DNS config family. This slice seeds
 * the baseline server-disabled state, bind/port, TTL, service-discovery
 * fan-out limit, semantic-mode toggle, and mothernode / live-probe
 * placeholders before INI and any allowed userland overrides refine the
 * live DNS snapshot.
 * =========================================================================
 */

#include "include/config/smart_dns/default.h"
#include "include/config/smart_dns/base_layer.h"

void kg_config_smart_dns_defaults_load(void)
{
    king_smart_dns_config.server_enable = false;
    king_smart_dns_config.server_bind_host = NULL;
    king_smart_dns_config.server_port = 53;
    king_smart_dns_config.default_record_ttl_sec = 60;

    king_smart_dns_config.mode = NULL;
    king_smart_dns_config.service_discovery_max_ips_per_response = 8;

    king_smart_dns_config.semantic_mode_enable = false;
    king_smart_dns_config.mothernode_uri = NULL;
    king_smart_dns_config.live_probe_allowed_hosts = NULL;
}
