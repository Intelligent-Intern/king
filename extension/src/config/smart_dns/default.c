#include "include/config/smart_dns/default.h"
#include "include/config/smart_dns/base_layer.h"

void kg_config_smart_dns_defaults_load(void)
{
    king_smart_dns_config.server_enable = false;
    king_smart_dns_config.server_bind_host = pestrdup("0.0.0.0", 1);
    king_smart_dns_config.server_port = 53;
    king_smart_dns_config.server_enable_tcp = true;
    king_smart_dns_config.default_record_ttl_sec = 60;

    king_smart_dns_config.mode = pestrdup("service_discovery", 1);
    king_smart_dns_config.static_zone_file_path = pestrdup("/etc/quicpro/dns/zones.db", 1);
    king_smart_dns_config.recursive_forwarders = pestrdup("", 1);
    king_smart_dns_config.health_agent_mcp_endpoint = pestrdup("127.0.0.1:9998", 1);
    king_smart_dns_config.service_discovery_max_ips_per_response = 8;

    king_smart_dns_config.enable_dnssec_validation = true;
    king_smart_dns_config.edns_udp_payload_size = 1232;

    king_smart_dns_config.semantic_mode_enable = false;
    king_smart_dns_config.mothernode_uri = pestrdup("", 1);
    king_smart_dns_config.mothernode_sync_interval_sec = 86400;
}
