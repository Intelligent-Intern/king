#include "include/config/smart_dns/config.h"
#include "include/config/smart_dns/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"
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

static const char *k_smart_dns_mode_allowed[] = {"authoritative", "recursive_resolver", "service_discovery", NULL};

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
            if (kg_smart_dns_apply_bool_field(value, "dns_server_enable_tcp", &target->server_enable_tcp) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_enable_dnssec_validation")) {
            if (kg_smart_dns_apply_bool_field(value, "dns_enable_dnssec_validation", &target->enable_dnssec_validation) != SUCCESS) {
                return FAILURE;
            }
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
            if (kg_validate_positive_long(value, &target->edns_udp_payload_size) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_mothernode_sync_interval_sec")) {
            if (kg_validate_positive_long(value, &target->mothernode_sync_interval_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_mode")) {
            if (kg_validate_string_from_allowlist(value, k_smart_dns_mode_allowed, &target->mode) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_server_bind_host")) {
            if (kg_validate_string(value, &target->server_bind_host) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_static_zone_file_path")) {
            if (kg_validate_string(value, &target->static_zone_file_path) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_recursive_forwarders")) {
            if (kg_validate_string(value, &target->recursive_forwarders) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dns_health_agent_mcp_endpoint")) {
            if (kg_validate_string(value, &target->health_agent_mcp_endpoint) != SUCCESS) {
                return FAILURE;
            }
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
