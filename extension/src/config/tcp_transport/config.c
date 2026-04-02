/*
 * =========================================================================
 * FILENAME:   src/config/tcp_transport/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the TCP transport config family. This
 * file validates the `King\\Config` subset that can target either a
 * temporary config snapshot or the live module-global state and applies
 * bounded transport enablement, socket-tuning, keepalive, connection-limit,
 * and TLS policy overrides.
 * =========================================================================
 */

#include "include/config/tcp_transport/config.h"
#include "include/config/tcp_transport/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

static int tcp_apply_bool(zval *value, const char *param_name, bool *target)
{
    if (kg_validate_bool(value, param_name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

static int tcp_apply_positive_long(zval *value, zend_long *target)
{
    return kg_validate_positive_long(value, target);
}

int kg_config_tcp_transport_apply_userland_config_to(
    kg_tcp_transport_config_t *target,
    zval *config_arr)
{
    if (target == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration must be provided as an array.");
        return FAILURE;
    }

    zval *value;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "tcp_enable")) {
            if (tcp_apply_bool(value, "tcp_enable", &target->enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_reuse_port_enable")) {
            if (tcp_apply_bool(value, "tcp_reuse_port_enable", &target->reuse_port_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_nodelay_enable")) {
            if (tcp_apply_bool(value, "tcp_nodelay_enable", &target->nodelay_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_cork_enable")) {
            if (tcp_apply_bool(value, "tcp_cork_enable", &target->cork_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_keepalive_enable")) {
            if (tcp_apply_bool(value, "tcp_keepalive_enable", &target->keepalive_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_max_connections")) {
            if (tcp_apply_positive_long(value, &target->max_connections) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_connect_timeout_ms")) {
            if (tcp_apply_positive_long(value, &target->connect_timeout_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_listen_backlog")) {
            if (tcp_apply_positive_long(value, &target->listen_backlog) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_keepalive_time_sec")) {
            if (tcp_apply_positive_long(value, &target->keepalive_time_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_keepalive_interval_sec")) {
            if (tcp_apply_positive_long(value, &target->keepalive_interval_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_keepalive_probes")) {
            if (tcp_apply_positive_long(value, &target->keepalive_probes) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_tls_min_version_allowed")) {
            /* The public key mirrors the INI name and stays compatible with old payloads. */
            const char *allowed[] = {"TLSv1.2", "TLSv1.3", NULL};
            if (kg_validate_string_from_allowlist(value, allowed,
                    &target->tls_min_version_allowed) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "tcp_tls_ciphers_tls12")) {
            if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
                    "tcp_tls_ciphers_tls12 must be a non-empty string");
                return FAILURE;
            }
            if (kg_validate_string(value, &target->tls_ciphers_tls12) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_tcp_transport_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    return kg_config_tcp_transport_apply_userland_config_to(
        &king_tcp_transport_config,
        config_arr
    );
}
