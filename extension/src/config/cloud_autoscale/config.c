#include "include/config/cloud_autoscale/config.h"
#include "include/config/cloud_autoscale/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_generic_string.h"
#include "include/validation/config_param/validate_long_range.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

int kg_config_cloud_autoscale_apply_userland_config_to(
    kg_cloud_autoscale_config_t *target,
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

        if (zend_string_equals_literal(key, "provider")) {
            /* Empty string means "no provider selected" in the current config model. */
            const char *allowed[] = {"aws", "azure", "hetzner", "google_cloud", "digitalocean", "", NULL};
            if (kg_validate_string_from_allowlist(value, allowed, &target->provider) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "region")) {
            if (kg_validate_generic_string(value, &target->region) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "credentials_path")) {
            if (kg_validate_generic_string(value, &target->credentials_path) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "api_endpoint")) {
            if (kg_validate_generic_string(value, &target->api_endpoint) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "state_path")) {
            if (kg_validate_generic_string(value, &target->state_path) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "server_name_prefix")) {
            if (kg_validate_generic_string(value, &target->server_name_prefix) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "bootstrap_user_data")) {
            if (kg_validate_generic_string(value, &target->bootstrap_user_data) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "firewall_ids")) {
            if (kg_validate_generic_string(value, &target->firewall_ids) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "placement_group_id")) {
            if (kg_validate_generic_string(value, &target->placement_group_id) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "prepared_release_url")) {
            if (kg_validate_generic_string(value, &target->prepared_release_url) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "join_endpoint")) {
            if (kg_validate_generic_string(value, &target->join_endpoint) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "hetzner_budget_path")) {
            if (kg_validate_generic_string(value, &target->hetzner_budget_path) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "min_nodes")) {
            if (kg_validate_positive_long(value, &target->min_nodes) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "max_nodes")) {
            if (kg_validate_positive_long(value, &target->max_nodes) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "max_scale_step")) {
            if (kg_validate_positive_long(value, &target->max_scale_step) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "scale_up_cpu_threshold_percent")) {
            if (kg_validate_long_range(value, 0, 100, &target->scale_up_cpu_threshold_percent) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "scale_down_cpu_threshold_percent")) {
            if (kg_validate_long_range(value, 0, 100, &target->scale_down_cpu_threshold_percent) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "scale_up_policy")) {
            if (kg_validate_generic_string(value, &target->scale_up_policy) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "spend_warning_threshold_percent")) {
            if (kg_validate_long_range(value, 0, 100, &target->spend_warning_threshold_percent) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "spend_hard_limit_percent")) {
            if (kg_validate_long_range(value, 0, 100, &target->spend_hard_limit_percent) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "quota_warning_threshold_percent")) {
            if (kg_validate_long_range(value, 0, 100, &target->quota_warning_threshold_percent) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "quota_hard_limit_percent")) {
            if (kg_validate_long_range(value, 0, 100, &target->quota_hard_limit_percent) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "cooldown_period_sec")) {
            if (kg_validate_positive_long(value, &target->cooldown_period_sec) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "idle_node_timeout_sec")) {
            if (kg_validate_positive_long(value, &target->idle_node_timeout_sec) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "instance_type")) {
            if (kg_validate_generic_string(value, &target->instance_type) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "instance_image_id")) {
            if (kg_validate_generic_string(value, &target->instance_image_id) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "network_config")) {
            if (kg_validate_generic_string(value, &target->network_config) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "instance_tags")) {
            if (kg_validate_generic_string(value, &target->instance_tags) != SUCCESS) return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_cloud_autoscale_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    return kg_config_cloud_autoscale_apply_userland_config_to(
        &king_cloud_autoscale_config,
        config_arr
    );
}
