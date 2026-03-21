#include "include/config/smart_dns/ini.h"
#include "include/config/smart_dns/base_layer.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <zend_ini.h>
#include <zend_exceptions.h>

static ZEND_INI_MH(OnUpdateDnsPositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value provided for Smart-DNS directive. A positive integer is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.dns_server_port")) {
        king_smart_dns_config.server_port = val;
    } else if (zend_string_equals_literal(entry->name, "king.dns_default_record_ttl_sec")) {
        king_smart_dns_config.default_record_ttl_sec = val;
    } else if (zend_string_equals_literal(entry->name, "king.dns_service_discovery_max_ips_per_response")) {
        king_smart_dns_config.service_discovery_max_ips_per_response = val;
    } else if (zend_string_equals_literal(entry->name, "king.dns_edns_udp_payload_size")) {
        king_smart_dns_config.edns_udp_payload_size = val;
    } else if (zend_string_equals_literal(entry->name, "king.dns_mothernode_sync_interval_sec")) {
        king_smart_dns_config.mothernode_sync_interval_sec = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateDnsModeString)
{
    const char *allowed[] = {"authoritative", "recursive_resolver", "service_discovery", NULL};
    int i;

    for (i = 0; allowed[i]; ++i) {
        if (strcmp(ZSTR_VAL(new_value), allowed[i]) == 0) {
            king_smart_dns_config.mode = pestrdup(ZSTR_VAL(new_value), 1);
            return SUCCESS;
        }
    }

    zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
        "Invalid dns_mode specified for Smart-DNS module.");
    return FAILURE;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.dns_server_enable", "0", PHP_INI_SYSTEM,
        OnUpdateBool, server_enable, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_server_bind_host", "0.0.0.0", PHP_INI_SYSTEM,
        OnUpdateString, server_bind_host, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_server_port", "53", PHP_INI_SYSTEM,
        OnUpdateDnsPositiveLong, server_port, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_server_enable_tcp", "1", PHP_INI_SYSTEM,
        OnUpdateBool, server_enable_tcp, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_default_record_ttl_sec", "60", PHP_INI_SYSTEM,
        OnUpdateDnsPositiveLong, default_record_ttl_sec, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_mode", "service_discovery", PHP_INI_SYSTEM,
        OnUpdateDnsModeString, mode, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_static_zone_file_path", "/etc/quicpro/dns/zones.db", PHP_INI_SYSTEM,
        OnUpdateString, static_zone_file_path, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_recursive_forwarders", "", PHP_INI_SYSTEM,
        OnUpdateString, recursive_forwarders, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_health_agent_mcp_endpoint", "127.0.0.1:9998", PHP_INI_SYSTEM,
        OnUpdateString, health_agent_mcp_endpoint, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_service_discovery_max_ips_per_response", "8", PHP_INI_SYSTEM,
        OnUpdateDnsPositiveLong, service_discovery_max_ips_per_response, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_enable_dnssec_validation", "1", PHP_INI_SYSTEM,
        OnUpdateBool, enable_dnssec_validation, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_edns_udp_payload_size", "1232", PHP_INI_SYSTEM,
        OnUpdateDnsPositiveLong, edns_udp_payload_size, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_semantic_mode_enable", "0", PHP_INI_SYSTEM,
        OnUpdateBool, semantic_mode_enable, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_mothernode_uri", "", PHP_INI_SYSTEM,
        OnUpdateString, mothernode_uri, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_mothernode_sync_interval_sec", "86400", PHP_INI_SYSTEM,
        OnUpdateDnsPositiveLong, mothernode_sync_interval_sec, kg_smart_dns_config_t, king_smart_dns_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_smart_dns_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_smart_dns_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
