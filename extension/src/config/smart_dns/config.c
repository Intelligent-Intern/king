/*
 * =========================================================================
 * FILENAME:   src/config/smart_dns/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the Smart-DNS config family. This file
 * validates the `King\\Config` subset that can target either a temporary
 * config snapshot or the live module-global state, while explicitly
 * fail-closing unsupported and system-only Smart-DNS v1 settings.
 * =========================================================================
 */

#include "include/config/smart_dns/config.h"
#include "include/config/smart_dns/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

static int kg_smart_dns_apply_bool_field(zval *value, const char *name, bool *target)
{
    if (kg_validate_bool(value, name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

static int kg_smart_dns_validate_mode(zval *value, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided. A string is required."
        );
        return FAILURE;
    }

    if (!zend_string_equals_literal(Z_STR_P(value), "service_discovery")) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Smart-DNS v1 currently only supports dns.mode=service_discovery."
        );
        return FAILURE;
    }

    if (*target != NULL) {
        pefree(*target, 1);
    }
    *target = pestrdup(Z_STRVAL_P(value), 1);
    return SUCCESS;
}

static int kg_smart_dns_reject_unsupported_setting(const char *setting_name)
{
    zend_throw_exception_ex(
        spl_ce_InvalidArgumentException,
        0,
        "Smart-DNS v1 does not support %s.",
        setting_name
    );
    return FAILURE;
}

static int kg_smart_dns_reject_system_only_setting(const char *setting_name)
{
    zend_throw_exception_ex(
        spl_ce_InvalidArgumentException,
        0,
        "Smart-DNS v1 treats %s as a system-only setting.",
        setting_name
    );
    return FAILURE;
}

int kg_config_smart_dns_apply_userland_config_to(
    kg_smart_dns_config_t *target,
    zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (target == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration must be provided as an array.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "dns_server_enable")) {
            if (kg_smart_dns_apply_bool_field(value, "dns_server_enable", &target->server_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_server_enable_tcp")) {
            return kg_smart_dns_reject_unsupported_setting("dns.server_enable_tcp");
        } else if (zend_string_equals_literal(key, "dns_enable_dnssec_validation")) {
            return kg_smart_dns_reject_unsupported_setting("dns.enable_dnssec_validation");
        } else if (zend_string_equals_literal(key, "dns_semantic_mode_enable")) {
            if (kg_smart_dns_apply_bool_field(value, "dns_semantic_mode_enable", &target->semantic_mode_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_server_port")) {
            if (kg_validate_positive_long(value, &target->server_port) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_default_record_ttl_sec")) {
            if (kg_validate_positive_long(value, &target->default_record_ttl_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_service_discovery_max_ips_per_response")) {
            if (kg_validate_positive_long(value, &target->service_discovery_max_ips_per_response) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_edns_udp_payload_size")) {
            return kg_smart_dns_reject_unsupported_setting("dns.edns_udp_payload_size");
        } else if (zend_string_equals_literal(key, "dns_mothernode_sync_interval_sec")) {
            return kg_smart_dns_reject_unsupported_setting("dns.mothernode_sync_interval_sec");
        } else if (zend_string_equals_literal(key, "dns_mode")) {
            if (kg_smart_dns_validate_mode(value, &target->mode) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_server_bind_host")) {
            if (kg_validate_string(value, &target->server_bind_host) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_static_zone_file_path")) {
            return kg_smart_dns_reject_unsupported_setting("dns.static_zone_file_path");
        } else if (zend_string_equals_literal(key, "dns_recursive_forwarders")) {
            return kg_smart_dns_reject_unsupported_setting("dns.recursive_forwarders");
        } else if (zend_string_equals_literal(key, "dns_health_agent_mcp_endpoint")) {
            return kg_smart_dns_reject_unsupported_setting("dns.health_agent_mcp_endpoint");
        } else if (zend_string_equals_literal(key, "dns_live_probe_allowed_hosts")) {
            return kg_smart_dns_reject_system_only_setting("dns.live_probe_allowed_hosts");
        } else if (zend_string_equals_literal(key, "dns_mothernode_uri")) {
            if (kg_validate_string(value, &target->mothernode_uri) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_smart_dns_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    return kg_config_smart_dns_apply_userland_config_to(
        &king_smart_dns_config,
        config_arr
    );
}
