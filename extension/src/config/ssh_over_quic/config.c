#include "include/config/ssh_over_quic/config.h"
#include "include/config/ssh_over_quic/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

static int kg_ssh_over_quic_apply_bool_field(zval *value, const char *name, bool *target)
{
    if (kg_validate_bool(value, name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

static const char *k_ssh_over_quic_auth_mode_allowed[] = {"mtls", "mcp_token", NULL};
static const char *k_ssh_over_quic_mapping_mode_allowed[] = {"static", "user_profile", NULL};

int kg_config_ssh_over_quic_apply_userland_config_to(
    kg_ssh_over_quic_config_t *target,
    zval *config_arr)
{
    if (target == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration must be provided as an associative array.");
        return FAILURE;
    }

    zval *value;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "ssh_gateway_enable")) {
            if (kg_ssh_over_quic_apply_bool_field(value, "ssh_gateway_enable", &target->gateway_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_log_session_activity")) {
            if (kg_ssh_over_quic_apply_bool_field(value, "ssh_gateway_log_session_activity", &target->gateway_log_session_activity) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_listen_host")) {
            if (kg_validate_string(value, &target->gateway_listen_host) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_default_target_host")) {
            if (kg_validate_string(value, &target->gateway_default_target_host) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_mcp_auth_agent_uri")) {
            if (kg_validate_string(value, &target->gateway_mcp_auth_agent_uri) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_user_profile_agent_uri")) {
            if (kg_validate_string(value, &target->gateway_user_profile_agent_uri) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_listen_port")) {
            if (kg_validate_positive_long(value, &target->gateway_listen_port) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_default_target_port")) {
            if (kg_validate_positive_long(value, &target->gateway_default_target_port) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_target_connect_timeout_ms")) {
            if (kg_validate_positive_long(value, &target->gateway_target_connect_timeout_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_idle_timeout_sec")) {
            if (kg_validate_positive_long(value, &target->gateway_idle_timeout_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_auth_mode")) {
            if (kg_validate_string_from_allowlist(value, k_ssh_over_quic_auth_mode_allowed,
                    &target->gateway_auth_mode) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ssh_gateway_target_mapping_mode")) {
            if (kg_validate_string_from_allowlist(value, k_ssh_over_quic_mapping_mode_allowed,
                    &target->gateway_target_mapping_mode) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_ssh_over_quic_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    return kg_config_ssh_over_quic_apply_userland_config_to(
        &king_ssh_over_quic_config,
        config_arr
    );
}
