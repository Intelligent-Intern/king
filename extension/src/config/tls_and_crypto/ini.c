/*
 * =========================================================================
 * FILENAME:   src/config/tls_and_crypto/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the TLS and crypto config
 * family. This file exposes the system-level verification, trust and
 * identity material, ticket / 0-RTT, cipher and curve policy, transport-
 * encryption, storage-encryption, and MCP-payload-encryption directives
 * and keeps `king_tls_and_crypto_config` aligned with validated updates.
 * =========================================================================
 */

#include "include/config/tls_and_crypto/ini.h"
#include "include/config/tls_and_crypto/base_layer.h"

#include "php.h"
#include "zend_exceptions.h"
#include <zend_ini.h>
#include <ext/spl/spl_exceptions.h>

static void tls_and_crypto_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }

    *target = pestrdup(ZSTR_VAL(value), 1);
}

/*
 * ZEND_INI_ENTRY1_EX() passes the destination field directly via mh_arg1.
 * OnUpdateString() expects an offset/base pair, so these entries must copy
 * into the persistent target manually.
 */
static ZEND_INI_MH(OnUpdateStringCopy)
{
    tls_and_crypto_replace_string((char **) mh_arg1, new_value);
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdatePositiveLong)
{
    zend_long value = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (value <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "A positive integer greater than zero is required for this directive.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.tls_verify_depth")) {
        king_tls_and_crypto_config.tls_verify_depth = value;
    } else if (zend_string_equals_literal(entry->name, "king.tls_session_ticket_lifetime_sec")) {
        king_tls_and_crypto_config.tls_session_ticket_lifetime_sec = value;
    } else if (zend_string_equals_literal(entry->name, "king.tls_server_0rtt_cache_size")) {
        king_tls_and_crypto_config.tls_server_0rtt_cache_size = value;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateTlsMinVersion)
{
    if (!zend_string_equals_literal(new_value, "TLSv1.2") &&
        !zend_string_equals_literal(new_value, "TLSv1.3")) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid tls_min_version_allowed: expected TLSv1.2 or TLSv1.3");
        return FAILURE;
    }

    /* Keep the existing backing field name until the config struct is renamed. */
    tls_and_crypto_replace_string(&king_tls_and_crypto_config.tcp_tls_min_version_allowed, new_value);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.tls_verify_peer", "1", PHP_INI_SYSTEM, OnUpdateBool,
        tls_verify_peer, kg_tls_and_crypto_config_t, king_tls_and_crypto_config)
    ZEND_INI_ENTRY_EX("king.tls_verify_depth", "10", PHP_INI_SYSTEM,
        OnUpdatePositiveLong, NULL)

    ZEND_INI_ENTRY1_EX("king.tls_default_ca_file", "", PHP_INI_SYSTEM,
        OnUpdateStringCopy, &king_tls_and_crypto_config.tls_default_ca_file, NULL)
    ZEND_INI_ENTRY1_EX("king.tls_default_cert_file", "", PHP_INI_SYSTEM,
        OnUpdateStringCopy, &king_tls_and_crypto_config.tls_default_cert_file, NULL)
    ZEND_INI_ENTRY1_EX("king.tls_default_key_file", "", PHP_INI_SYSTEM,
        OnUpdateStringCopy, &king_tls_and_crypto_config.tls_default_key_file, NULL)
    ZEND_INI_ENTRY1_EX("king.tls_ticket_key_file", "", PHP_INI_SYSTEM,
        OnUpdateStringCopy, &king_tls_and_crypto_config.tls_ticket_key_file, NULL)

    ZEND_INI_ENTRY1_EX("king.tls_ciphers_tls13",
        "TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256",
        PHP_INI_SYSTEM, OnUpdateStringCopy, &king_tls_and_crypto_config.tls_ciphers_tls13, NULL)
    ZEND_INI_ENTRY1_EX("king.tls_ciphers_tls12",
        "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384",
        PHP_INI_SYSTEM, OnUpdateStringCopy, &king_tls_and_crypto_config.tls_ciphers_tls12, NULL)
    ZEND_INI_ENTRY1_EX("king.tls_curves", "P-256:X25519", PHP_INI_SYSTEM,
        OnUpdateStringCopy, &king_tls_and_crypto_config.tls_curves, NULL)

    ZEND_INI_ENTRY_EX("king.tls_session_ticket_lifetime_sec", "7200",
        PHP_INI_SYSTEM, OnUpdatePositiveLong, NULL)
    STD_PHP_INI_ENTRY("king.tls_enable_early_data", "0", PHP_INI_SYSTEM, OnUpdateBool,
        tls_enable_early_data, kg_tls_and_crypto_config_t, king_tls_and_crypto_config)
    ZEND_INI_ENTRY_EX("king.tls_server_0rtt_cache_size", "100000",
        PHP_INI_SYSTEM, OnUpdatePositiveLong, NULL)
    STD_PHP_INI_ENTRY("king.tls_enable_ocsp_stapling", "1", PHP_INI_SYSTEM, OnUpdateBool,
        tls_enable_ocsp_stapling, kg_tls_and_crypto_config_t, king_tls_and_crypto_config)
    ZEND_INI_ENTRY_EX("king.tls_min_version_allowed", "TLSv1.2",
        PHP_INI_SYSTEM, OnUpdateTlsMinVersion, NULL)

    STD_PHP_INI_ENTRY("king.storage_encryption_at_rest_enable", "0", PHP_INI_SYSTEM, OnUpdateBool,
        storage_encryption_at_rest_enable, kg_tls_and_crypto_config_t, king_tls_and_crypto_config)
    ZEND_INI_ENTRY1_EX("king.storage_encryption_algorithm", "", PHP_INI_SYSTEM,
        OnUpdateStringCopy, &king_tls_and_crypto_config.storage_encryption_algorithm, NULL)
    ZEND_INI_ENTRY1_EX("king.storage_encryption_key_path", "", PHP_INI_SYSTEM,
        OnUpdateStringCopy, &king_tls_and_crypto_config.storage_encryption_key_path, NULL)
    STD_PHP_INI_ENTRY("king.mcp_payload_encryption_enable", "0", PHP_INI_SYSTEM, OnUpdateBool,
        mcp_payload_encryption_enable, kg_tls_and_crypto_config_t, king_tls_and_crypto_config)
    ZEND_INI_ENTRY1_EX("king.mcp_payload_encryption_psk_env_var", "", PHP_INI_SYSTEM,
        OnUpdateStringCopy, &king_tls_and_crypto_config.mcp_payload_encryption_psk_env_var, NULL)

    STD_PHP_INI_ENTRY("king.tls_enable_ech", "0", PHP_INI_SYSTEM, OnUpdateBool,
        tls_enable_ech, kg_tls_and_crypto_config_t, king_tls_and_crypto_config)
    STD_PHP_INI_ENTRY("king.tls_require_ct_policy", "0", PHP_INI_SYSTEM, OnUpdateBool,
        tls_require_ct_policy, kg_tls_and_crypto_config_t, king_tls_and_crypto_config)
    STD_PHP_INI_ENTRY("king.tls_disable_sni_validation", "0", PHP_INI_SYSTEM, OnUpdateBool,
        tls_disable_sni_validation, kg_tls_and_crypto_config_t, king_tls_and_crypto_config)
    STD_PHP_INI_ENTRY("king.transport_disable_encryption", "0", PHP_INI_SYSTEM, OnUpdateBool,
        transport_disable_encryption, kg_tls_and_crypto_config_t, king_tls_and_crypto_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_tls_and_crypto_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_tls_and_crypto_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
