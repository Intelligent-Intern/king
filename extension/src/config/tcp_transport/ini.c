/*
 * =========================================================================
 * FILENAME:   src/config/tcp_transport/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the TCP transport config
 * family. This file exposes the system-level transport enablement,
 * connection/backlog limits, socket tuning, keepalive, and TLS policy
 * directives and keeps `king_tcp_transport_config` aligned with validated
 * updates.
 * =========================================================================
 */

#include "include/config/tcp_transport/ini.h"
#include "include/config/tcp_transport/base_layer.h"

#include "php.h"
#include "zend_exceptions.h"
#include <zend_ini.h>
#include <ext/spl/spl_exceptions.h>

static void tcp_transport_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }

    *target = pestrdup(ZSTR_VAL(value), 1);
}

static ZEND_INI_MH(OnUpdateTcpPositiveLong)
{
    zend_long value = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (value <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "A positive integer value is required for this directive.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.tcp_max_connections")) {
        king_tcp_transport_config.max_connections = value;
    } else if (zend_string_equals_literal(entry->name, "king.tcp_connect_timeout_ms")) {
        king_tcp_transport_config.connect_timeout_ms = value;
    } else if (zend_string_equals_literal(entry->name, "king.tcp_listen_backlog")) {
        king_tcp_transport_config.listen_backlog = value;
    } else if (zend_string_equals_literal(entry->name, "king.tcp_keepalive_time_sec")) {
        king_tcp_transport_config.keepalive_time_sec = value;
    } else if (zend_string_equals_literal(entry->name, "king.tcp_keepalive_interval_sec")) {
        king_tcp_transport_config.keepalive_interval_sec = value;
    } else if (zend_string_equals_literal(entry->name, "king.tcp_keepalive_probes")) {
        king_tcp_transport_config.keepalive_probes = value;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateTlsMinVersion)
{
    if (!zend_string_equals_literal(new_value, "TLSv1.2") &&
        !zend_string_equals_literal(new_value, "TLSv1.3")) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid tcp_tls_min_version_allowed: expected TLSv1.2 or TLSv1.3");
        return FAILURE;
    }

    tcp_transport_replace_string(&king_tcp_transport_config.tls_min_version_allowed, new_value);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.tcp_enable", "1", PHP_INI_SYSTEM, OnUpdateBool,
        enable, kg_tcp_transport_config_t, king_tcp_transport_config)
    ZEND_INI_ENTRY_EX("king.tcp_max_connections", "10240", PHP_INI_SYSTEM,
        OnUpdateTcpPositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.tcp_connect_timeout_ms", "5000", PHP_INI_SYSTEM,
        OnUpdateTcpPositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.tcp_listen_backlog", "511", PHP_INI_SYSTEM,
        OnUpdateTcpPositiveLong, NULL)

    STD_PHP_INI_ENTRY("king.tcp_reuse_port_enable", "1", PHP_INI_SYSTEM, OnUpdateBool,
        reuse_port_enable, kg_tcp_transport_config_t, king_tcp_transport_config)
    STD_PHP_INI_ENTRY("king.tcp_nodelay_enable", "0", PHP_INI_SYSTEM, OnUpdateBool,
        nodelay_enable, kg_tcp_transport_config_t, king_tcp_transport_config)
    STD_PHP_INI_ENTRY("king.tcp_cork_enable", "0", PHP_INI_SYSTEM, OnUpdateBool,
        cork_enable, kg_tcp_transport_config_t, king_tcp_transport_config)

    STD_PHP_INI_ENTRY("king.tcp_keepalive_enable", "1", PHP_INI_SYSTEM, OnUpdateBool,
        keepalive_enable, kg_tcp_transport_config_t, king_tcp_transport_config)
    ZEND_INI_ENTRY_EX("king.tcp_keepalive_time_sec", "7200", PHP_INI_SYSTEM,
        OnUpdateTcpPositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.tcp_keepalive_interval_sec", "75", PHP_INI_SYSTEM,
        OnUpdateTcpPositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.tcp_keepalive_probes", "9", PHP_INI_SYSTEM,
        OnUpdateTcpPositiveLong, NULL)

    ZEND_INI_ENTRY_EX("king.tcp_tls_min_version_allowed", "TLSv1.2", PHP_INI_SYSTEM,
        OnUpdateTlsMinVersion, NULL)
    STD_PHP_INI_ENTRY("king.tcp_tls_ciphers_tls12",
        "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384",
        PHP_INI_SYSTEM, OnUpdateString, tls_ciphers_tls12, kg_tcp_transport_config_t, king_tcp_transport_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_tcp_transport_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_tcp_transport_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
