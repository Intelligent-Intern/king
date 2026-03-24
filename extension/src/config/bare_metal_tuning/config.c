#include "include/config/bare_metal_tuning/config.h"
#include "include/config/bare_metal_tuning/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_generic_string.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"
#include <ext/spl/spl_exceptions.h>
#include <zend_exceptions.h>

static int kg_validate_non_negative_long_local(zval *value, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid type provided. An integer is required.");
        return FAILURE;
    }

    if (Z_LVAL_P(value) < 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value provided. A non-negative integer is required.");
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

int kg_config_bare_metal_tuning_apply_userland_config(zval *config_arr)
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

        if (zend_string_equals_literal(key, "io_engine_use_uring")) {
            if (kg_validate_bool(value, "io_engine_use_uring") != SUCCESS) return FAILURE;
            king_bare_metal_config.io_engine_use_uring = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "io_uring_sq_poll_ms")) {
            if (kg_validate_non_negative_long_local(value, &king_bare_metal_config.io_uring_sq_poll_ms) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "io_max_batch_read_packets")) {
            if (kg_validate_positive_long(value, &king_bare_metal_config.io_max_batch_read_packets) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "io_max_batch_write_packets")) {
            if (kg_validate_positive_long(value, &king_bare_metal_config.io_max_batch_write_packets) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "socket_receive_buffer_size")) {
            if (kg_validate_positive_long(value, &king_bare_metal_config.socket_receive_buffer_size) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "socket_send_buffer_size")) {
            if (kg_validate_positive_long(value, &king_bare_metal_config.socket_send_buffer_size) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "socket_enable_busy_poll_us")) {
            if (kg_validate_non_negative_long_local(value, &king_bare_metal_config.socket_enable_busy_poll_us) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "socket_enable_timestamping")) {
            if (kg_validate_bool(value, "socket_enable_timestamping") != SUCCESS) return FAILURE;
            king_bare_metal_config.socket_enable_timestamping = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "io_thread_cpu_affinity")) {
            if (kg_validate_generic_string(value, &king_bare_metal_config.io_thread_cpu_affinity) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "io_thread_numa_node_policy")) {
            const char *allowed[] = {"default", "prefer", "bind", "interleave", NULL};
            if (kg_validate_string_from_allowlist(value, allowed, &king_bare_metal_config.io_thread_numa_node_policy) != SUCCESS) return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
