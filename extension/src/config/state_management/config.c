/*
 * =========================================================================
 * FILENAME:   src/config/state_management/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the state-management config family.
 * This file enforces the global override policy gate and validates the
 * narrow `King\\Config` subset for the default backend identifier and its
 * companion URI in the live module-global state.
 * =========================================================================
 */

#include "include/config/state_management/config.h"
#include "include/config/state_management/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_string_from_allowlist.h"
#include "include/validation/config_param/validate_string.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

int kg_config_state_management_apply_userland_config(zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    if (Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration must be provided as an array.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "state_manager_default_backend")) {
            const char *allowed[] = {"memory", "sqlite", "redis", "postgres", NULL};
            /* Backend names are user-facing identifiers, not class names or DSNs. */
            if (kg_validate_string_from_allowlist(value, allowed,
                    &king_state_management_config.default_backend) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "state_manager_default_uri")) {
            if (kg_validate_string(value, &king_state_management_config.default_uri) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
