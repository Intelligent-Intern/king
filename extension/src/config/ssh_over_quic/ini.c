/*
 * =========================================================================
 * FILENAME:   src/config/ssh_over_quic/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the SSH-over-QUIC config
 * family. This file exposes the system-level gateway, bind/target
 * endpoints, auth and mapping modes, timeout, agent-URI, and session-log
 * directives and keeps `king_ssh_over_quic_config` aligned with validated
 * updates.
 * =========================================================================
 */

#include "include/config/ssh_over_quic/ini.h"
#include "include/config/ssh_over_quic/base_layer.h"

#include "php.h"
#include "zend_exceptions.h"
#include <zend_ini.h>
#include <ext/spl/spl_exceptions.h>
#include <string.h>

static void ssh_over_quic_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }

    *target = pestrdup(ZSTR_VAL(value), 1);
}

static ZEND_INI_MH(OnUpdateSshQuicPositiveLong)
{
    zend_long value = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (value <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for SSH-over-QUIC directive. A positive integer is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.ssh_gateway_listen_port")) {
        king_ssh_over_quic_config.gateway_listen_port = value;
    } else if (zend_string_equals_literal(entry->name, "king.ssh_gateway_default_target_port")) {
        king_ssh_over_quic_config.gateway_default_target_port = value;
    } else if (zend_string_equals_literal(entry->name, "king.ssh_gateway_target_connect_timeout_ms")) {
        king_ssh_over_quic_config.gateway_target_connect_timeout_ms = value;
    } else if (zend_string_equals_literal(entry->name, "king.ssh_gateway_idle_timeout_sec")) {
        king_ssh_over_quic_config.gateway_idle_timeout_sec = value;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateSshQuicAuthMode)
{
    const char *allowed[] = {"mtls", "mcp_token", NULL};
    for (int i = 0; allowed[i]; ++i) {
        if (strcmp(ZSTR_VAL(new_value), allowed[i]) == 0) {
            ssh_over_quic_replace_string(&king_ssh_over_quic_config.gateway_auth_mode, new_value);
            return SUCCESS;
        }
    }

    zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
        "Invalid ssh_gateway_auth_mode. Allowed values: mtls, mcp_token");
    return FAILURE;
}

static ZEND_INI_MH(OnUpdateSshQuicMappingMode)
{
    const char *allowed[] = {"static", "user_profile", NULL};
    for (int i = 0; allowed[i]; ++i) {
        if (strcmp(ZSTR_VAL(new_value), allowed[i]) == 0) {
            ssh_over_quic_replace_string(&king_ssh_over_quic_config.gateway_target_mapping_mode, new_value);
            return SUCCESS;
        }
    }

    zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
        "Invalid ssh_gateway_target_mapping_mode. Allowed: static, user_profile");
    return FAILURE;
}

/* ZEND_INI_ENTRY1_EX() stores the persistent char** target in mh_arg1. */
static ZEND_INI_MH(OnUpdateSshQuicStringCopy)
{
    ssh_over_quic_replace_string((char **) mh_arg1, new_value);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.ssh_gateway_enable", "0", PHP_INI_SYSTEM,
        OnUpdateBool, gateway_enable, kg_ssh_over_quic_config_t, king_ssh_over_quic_config)

    ZEND_INI_ENTRY1_EX("king.ssh_gateway_listen_host", "0.0.0.0", PHP_INI_SYSTEM,
        OnUpdateSshQuicStringCopy, &king_ssh_over_quic_config.gateway_listen_host, NULL)
    ZEND_INI_ENTRY_EX("king.ssh_gateway_listen_port", "2222", PHP_INI_SYSTEM,
        OnUpdateSshQuicPositiveLong, NULL)

    ZEND_INI_ENTRY1_EX("king.ssh_gateway_default_target_host", "127.0.0.1", PHP_INI_SYSTEM,
        OnUpdateSshQuicStringCopy, &king_ssh_over_quic_config.gateway_default_target_host, NULL)
    ZEND_INI_ENTRY_EX("king.ssh_gateway_default_target_port", "22", PHP_INI_SYSTEM,
        OnUpdateSshQuicPositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.ssh_gateway_target_connect_timeout_ms", "5000", PHP_INI_SYSTEM,
        OnUpdateSshQuicPositiveLong, NULL)

    ZEND_INI_ENTRY_EX("king.ssh_gateway_auth_mode", "mtls", PHP_INI_SYSTEM,
        OnUpdateSshQuicAuthMode, NULL)
    ZEND_INI_ENTRY1_EX("king.ssh_gateway_mcp_auth_agent_uri", "", PHP_INI_SYSTEM,
        OnUpdateSshQuicStringCopy, &king_ssh_over_quic_config.gateway_mcp_auth_agent_uri, NULL)
    ZEND_INI_ENTRY_EX("king.ssh_gateway_target_mapping_mode", "static", PHP_INI_SYSTEM,
        OnUpdateSshQuicMappingMode, NULL)
    ZEND_INI_ENTRY1_EX("king.ssh_gateway_user_profile_agent_uri", "", PHP_INI_SYSTEM,
        OnUpdateSshQuicStringCopy, &king_ssh_over_quic_config.gateway_user_profile_agent_uri, NULL)

    ZEND_INI_ENTRY_EX("king.ssh_gateway_idle_timeout_sec", "1800", PHP_INI_SYSTEM,
        OnUpdateSshQuicPositiveLong, NULL)
    STD_PHP_INI_ENTRY("king.ssh_gateway_log_session_activity", "1", PHP_INI_SYSTEM,
        OnUpdateBool, gateway_log_session_activity, kg_ssh_over_quic_config_t, king_ssh_over_quic_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_ssh_over_quic_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_ssh_over_quic_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
