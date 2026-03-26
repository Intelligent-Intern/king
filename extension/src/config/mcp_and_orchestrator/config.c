#include "include/config/mcp_and_orchestrator/config.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_generic_string.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

static int kg_mcp_orchestrator_apply_bool_field(zval *value, const char *name, bool *target)
{
    if (kg_validate_bool(value, name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

int kg_config_mcp_and_orchestrator_apply_userland_config_to(
    kg_mcp_orchestrator_config_t *target,
    zval *config_arr)
{
    zval *value;
    zend_string *key;
    static const char *const orchestrator_backend_allowed[] = {
        "local",
        "file_worker",
        NULL
    };

    if (target == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Configuration must be provided as an array."
        );
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "mcp_default_request_timeout_ms")) {
            if (kg_validate_positive_long(value, &target->mcp_default_request_timeout_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "mcp_max_message_size_bytes")) {
            if (kg_validate_positive_long(value, &target->mcp_max_message_size_bytes) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "mcp_default_retry_policy_enable")) {
            if (kg_mcp_orchestrator_apply_bool_field(
                    value,
                    "mcp_default_retry_policy_enable",
                    &target->mcp_default_retry_policy_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "mcp_default_retry_max_attempts")) {
            if (kg_validate_positive_long(value, &target->mcp_default_retry_max_attempts) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "mcp_default_retry_backoff_ms_initial")) {
            if (kg_validate_positive_long(value, &target->mcp_default_retry_backoff_ms_initial) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "mcp_enable_request_caching")) {
            if (kg_mcp_orchestrator_apply_bool_field(
                    value,
                    "mcp_enable_request_caching",
                    &target->mcp_enable_request_caching) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "mcp_request_cache_ttl_sec")) {
            if (kg_validate_positive_long(value, &target->mcp_request_cache_ttl_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "orchestrator_default_pipeline_timeout_ms")) {
            if (kg_validate_positive_long(value, &target->orchestrator_default_pipeline_timeout_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "orchestrator_max_recursion_depth")) {
            if (kg_validate_positive_long(value, &target->orchestrator_max_recursion_depth) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "orchestrator_loop_concurrency_default")) {
            if (kg_validate_positive_long(value, &target->orchestrator_loop_concurrency_default) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "orchestrator_enable_distributed_tracing")) {
            if (kg_mcp_orchestrator_apply_bool_field(
                    value,
                    "orchestrator_enable_distributed_tracing",
                    &target->orchestrator_enable_distributed_tracing) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "orchestrator_execution_backend")) {
            if (kg_validate_string_from_allowlist(
                    value,
                    orchestrator_backend_allowed,
                    &target->orchestrator_execution_backend) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "orchestrator_worker_queue_path")) {
            if (kg_validate_generic_string(value, &target->orchestrator_worker_queue_path) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "orchestrator_state_path")) {
            if (kg_validate_generic_string(value, &target->orchestrator_state_path) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_mcp_and_orchestrator_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Configuration override from userland is disabled by system administrator."
        );
        return FAILURE;
    }

    return kg_config_mcp_and_orchestrator_apply_userland_config_to(
        &king_mcp_orchestrator_config,
        config_arr
    );
}
