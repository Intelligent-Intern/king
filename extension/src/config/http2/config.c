#include "include/config/http2/config.h"
#include "include/config/http2/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

static int http2_apply_bool(zval *value, const char *param_name, bool *target)
{
    if (kg_validate_bool(value, param_name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

static int http2_apply_positive_long(zval *value, zend_long *target)
{
    return kg_validate_positive_long(value, target);
}

int kg_config_http2_apply_userland_config_to(
    kg_http2_config_t *target,
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

        if (zend_string_equals_literal(key, "enable")) {
            if (http2_apply_bool(value, "enable", &target->enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "initial_window_size")) {
            if (http2_apply_positive_long(value, &target->initial_window_size) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "max_concurrent_streams")) {
            if (http2_apply_positive_long(value, &target->max_concurrent_streams) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "max_header_list_size")) {
            if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
                    "max_header_list_size must be a non-negative integer.");
                return FAILURE;
            }
            target->max_header_list_size = Z_LVAL_P(value);
        } else if (zend_string_equals_literal(key, "enable_push")) {
            if (http2_apply_bool(value, "enable_push", &target->enable_push) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "max_frame_size")) {
            if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 16384 || Z_LVAL_P(value) > 16777215) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
                    "max_frame_size must be between 16384 and 16777215.");
                return FAILURE;
            }
            target->max_frame_size = Z_LVAL_P(value);
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_http2_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    return kg_config_http2_apply_userland_config_to(
        &king_http2_config,
        config_arr
    );
}
