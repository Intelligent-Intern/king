#include "include/config/smart_dns/ini.h"
#include "include/config/smart_dns/base_layer.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <zend_ini.h>
#include <zend_exceptions.h>
#include <ctype.h>
#include <string.h>

static void king_smart_dns_replace_string(char **target, zend_string *value)
{
    if (*target != NULL) {
        pefree(*target, 1);
    }

    *target = pestrdup(ZSTR_VAL(value), 1);
}

static char *king_smart_dns_trim_ascii_whitespace(char *value)
{
    char *start = value;
    size_t length;

    if (value == NULL) {
        return NULL;
    }

    while (*start == ' ' || *start == '\t' || *start == '\r' || *start == '\n') {
        start++;
    }

    length = strlen(start);
    while (length > 0
        && (start[length - 1] == ' '
            || start[length - 1] == '\t'
            || start[length - 1] == '\r'
            || start[length - 1] == '\n')) {
        start[length - 1] = '\0';
        length--;
    }

    return start;
}

static bool king_smart_dns_probe_host_entry_is_valid(const char *value)
{
    const unsigned char *cursor = (const unsigned char *) value;

    if (value == NULL || value[0] == '\0') {
        return false;
    }

    while (*cursor != '\0') {
        if (!isalnum(*cursor) && *cursor != '.' && *cursor != '-' && *cursor != ':') {
            return false;
        }
        cursor++;
    }

    return true;
}

static ZEND_INI_MH(OnUpdateDnsProbeHostAllowlist)
{
    char *allowlist_copy;
    char *saveptr = NULL;
    char *host_entry;

    allowlist_copy = estrndup(ZSTR_VAL(new_value), ZSTR_LEN(new_value));
    if (allowlist_copy == NULL) {
        return FAILURE;
    }

    for (host_entry = strtok_r(allowlist_copy, ",", &saveptr);
         host_entry != NULL;
         host_entry = strtok_r(NULL, ",", &saveptr)) {
        char *trimmed = king_smart_dns_trim_ascii_whitespace(host_entry);

        if (trimmed == NULL || !king_smart_dns_probe_host_entry_is_valid(trimmed)) {
            efree(allowlist_copy);
            zend_throw_exception_ex(
                spl_ce_InvalidArgumentException,
                0,
                "Invalid value for king.dns_live_probe_allowed_hosts. Provide a comma-separated list of hostnames or IP literals."
            );
            return FAILURE;
        }
    }

    efree(allowlist_copy);
    king_smart_dns_replace_string(&king_smart_dns_config.live_probe_allowed_hosts, new_value);
    return SUCCESS;
}

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
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateDnsModeString)
{
    if (zend_string_equals_literal(new_value, "service_discovery")) {
        king_smart_dns_replace_string(&king_smart_dns_config.mode, new_value);
        return SUCCESS;
    }

    zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
        "Smart-DNS v1 currently only supports dns_mode=service_discovery.");
    return FAILURE;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.dns_server_enable", "0", PHP_INI_SYSTEM,
        OnUpdateBool, server_enable, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_server_bind_host", "0.0.0.0", PHP_INI_SYSTEM,
        OnUpdateString, server_bind_host, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_server_port", "53", PHP_INI_SYSTEM,
        OnUpdateDnsPositiveLong, server_port, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_default_record_ttl_sec", "60", PHP_INI_SYSTEM,
        OnUpdateDnsPositiveLong, default_record_ttl_sec, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_mode", "service_discovery", PHP_INI_SYSTEM,
        OnUpdateDnsModeString, mode, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_service_discovery_max_ips_per_response", "8", PHP_INI_SYSTEM,
        OnUpdateDnsPositiveLong, service_discovery_max_ips_per_response, kg_smart_dns_config_t, king_smart_dns_config)
    STD_PHP_INI_ENTRY("king.dns_semantic_mode_enable", "0", PHP_INI_SYSTEM,
        OnUpdateBool, semantic_mode_enable, kg_smart_dns_config_t, king_smart_dns_config)
    ZEND_INI_ENTRY_EX("king.dns_live_probe_allowed_hosts", "localhost,127.0.0.1,::1", PHP_INI_SYSTEM,
        OnUpdateDnsProbeHostAllowlist, NULL)
    STD_PHP_INI_ENTRY("king.dns_mothernode_uri", "", PHP_INI_SYSTEM,
        OnUpdateString, mothernode_uri, kg_smart_dns_config_t, king_smart_dns_config)
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
