/*
 * =========================================================================
 * FILENAME:   src/config/tls_and_crypto/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the TLS and crypto config family. This
 * file validates the `King\\Config` subset that can target either a
 * temporary config snapshot or the live module-global state and applies
 * bounded TLS policy, certificate material, ticket / 0-RTT, transport-
 * encryption, storage-encryption, and MCP-payload-encryption overrides.
 * =========================================================================
 */

#include "include/config/tls_and_crypto/config.h"
#include "include/config/tls_and_crypto/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

static int tls_apply_bool(zval *value, const char *param_name, bool *target)
{
    if (kg_validate_bool(value, param_name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

int kg_config_tls_and_crypto_apply_userland_config_to(
    kg_tls_and_crypto_config_t *target,
    zval *config_arr)
{
    if (target == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration must be provided as an associative array.");
        return FAILURE;
    }

    zval *val;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, val) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "tls_verify_peer")) {
            if (tls_apply_bool(val, "tls_verify_peer", &target->tls_verify_peer) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_enable_early_data")) {
            if (tls_apply_bool(val, "tls_enable_early_data", &target->tls_enable_early_data) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_enable_ocsp_stapling")) {
            if (tls_apply_bool(val, "tls_enable_ocsp_stapling", &target->tls_enable_ocsp_stapling) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_enable_ech")) {
            if (tls_apply_bool(val, "tls_enable_ech", &target->tls_enable_ech) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_require_ct_policy")) {
            if (tls_apply_bool(val, "tls_require_ct_policy", &target->tls_require_ct_policy) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_disable_sni_validation")) {
            if (tls_apply_bool(val, "tls_disable_sni_validation", &target->tls_disable_sni_validation) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "transport_disable_encryption")) {
            if (tls_apply_bool(val, "transport_disable_encryption", &target->transport_disable_encryption) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_verify_depth")) {
            if (kg_validate_positive_long(val, &target->tls_verify_depth) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_session_ticket_lifetime_sec")) {
            if (kg_validate_positive_long(val, &target->tls_session_ticket_lifetime_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_server_0rtt_cache_size")) {
            if (kg_validate_positive_long(val, &target->tls_server_0rtt_cache_size) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_default_ca_file")) {
            if (kg_validate_string(val, &target->tls_default_ca_file) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_default_cert_file")) {
            if (kg_validate_string(val, &target->tls_default_cert_file) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_default_key_file")) {
            if (kg_validate_string(val, &target->tls_default_key_file) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_ticket_key_file")) {
            if (kg_validate_string(val, &target->tls_ticket_key_file) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_ciphers_tls13")) {
            if (kg_validate_string(val, &target->tls_ciphers_tls13) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_ciphers_tls12")) {
            if (kg_validate_string(val, &target->tls_ciphers_tls12) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tls_curves")) {
            if (kg_validate_string(val, &target->tls_curves) != SUCCESS) {
                return FAILURE;
            }
        /* Keep both keys for compatibility with older config payloads. */
        } else if (zend_string_equals_literal(key, "tcp_tls_min_version_allowed") ||
                   zend_string_equals_literal(key, "tls_min_version_allowed")) {
            const char *allowed[] = {"TLSv1.2", "TLSv1.3", NULL};
            if (kg_validate_string_from_allowlist(val, allowed,
                    &target->tcp_tls_min_version_allowed) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "storage_encryption_at_rest_enable")) {
            if (tls_apply_bool(val, "storage_encryption_at_rest_enable", &target->storage_encryption_at_rest_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "storage_encryption_algorithm")) {
            if (kg_validate_string(val, &target->storage_encryption_algorithm) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "storage_encryption_key_path")) {
            if (kg_validate_string(val, &target->storage_encryption_key_path) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "mcp_payload_encryption_enable")) {
            if (tls_apply_bool(val, "mcp_payload_encryption_enable", &target->mcp_payload_encryption_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "mcp_payload_encryption_psk_env_var")) {
            if (kg_validate_string(val, &target->mcp_payload_encryption_psk_env_var) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_tls_and_crypto_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by administrator.");
        return FAILURE;
    }

    return kg_config_tls_and_crypto_apply_userland_config_to(
        &king_tls_and_crypto_config,
        config_arr
    );
}
