/*
 * =========================================================================
 * FILENAME:   src/config/cluster_and_process/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the cluster/process config family. This
 * file validates the narrow `King\\Config` subset that can tune worker
 * counts, restart policy, scheduler/niceness, affinity, cgroup, and runtime
 * identity settings on the live `king_cluster_config` snapshot.
 * =========================================================================
 */

#include "include/config/cluster_and_process/config.h"
#include "include/config/cluster_and_process/base_layer.h"
#include "include/king_globals.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <strings.h>

static int cluster_validate_bool(zval *value)
{
    return (Z_TYPE_P(value) == IS_TRUE || Z_TYPE_P(value) == IS_FALSE) ? SUCCESS : FAILURE;
}

/* These values live in persistent module storage, so replace them manually. */
static void cluster_replace_string(char **target, const char *value)
{
    if (*target) {
        pefree(*target, 1);
    }

    *target = pestrdup(value, 1);
}

static int cluster_validate_non_negative_long(zval *value, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. An integer is required.");
        return FAILURE;
    }
    if (Z_LVAL_P(value) < 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided. A non-negative integer is required.");
        return FAILURE;
    }
    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static int cluster_validate_positive_long(zval *value, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. An integer is required.");
        return FAILURE;
    }
    if (Z_LVAL_P(value) <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided. A positive integer is required.");
        return FAILURE;
    }
    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static int cluster_validate_niceness_value(zval *value, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. An integer is required.");
        return FAILURE;
    }
    if (Z_LVAL_P(value) < -20 || Z_LVAL_P(value) > 19) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided. A valid niceness value is required.");
        return FAILURE;
    }
    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static int cluster_validate_scheduler_policy(zval *value, char **target)
{
    const char *const allowed[] = {"other", "fifo", "rr", NULL};

    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. A string is required.");
        return FAILURE;
    }

    for (int i = 0; allowed[i] != NULL; i++) {
        if (strcasecmp(Z_STRVAL_P(value), allowed[i]) == 0) {
            cluster_replace_string(target, Z_STRVAL_P(value));
            return SUCCESS;
        }
    }

    zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided. The value is not one of the allowed options.");
    return FAILURE;
}

static int cluster_validate_generic_string(zval *value, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. A string is required.");
        return FAILURE;
    }

    cluster_replace_string(target, Z_STRVAL_P(value));
    return SUCCESS;
}

int kg_config_cluster_and_process_apply_userland_config(zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Configuration override from userland is disabled by system administrator in php.ini (king.security_allow_config_override).");
        return FAILURE;
    }

    if (Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Configuration must be provided as an array.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "cluster_workers")) {
            if (cluster_validate_non_negative_long(value, &king_cluster_config.cluster_workers) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cluster_graceful_shutdown_ms")) {
            if (cluster_validate_positive_long(value, &king_cluster_config.cluster_graceful_shutdown_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "server_max_fd_per_worker")) {
            if (cluster_validate_positive_long(value, &king_cluster_config.server_max_fd_per_worker) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cluster_restart_crashed_workers")) {
            if (cluster_validate_bool(value) != SUCCESS) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. A boolean is required.");
                return FAILURE;
            }
            king_cluster_config.cluster_restart_crashed_workers = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "cluster_max_restarts_per_worker")) {
            if (cluster_validate_positive_long(value, &king_cluster_config.cluster_max_restarts_per_worker) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cluster_restart_interval_sec")) {
            if (cluster_validate_positive_long(value, &king_cluster_config.cluster_restart_interval_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cluster_worker_niceness")) {
            if (cluster_validate_niceness_value(value, &king_cluster_config.cluster_worker_niceness) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cluster_worker_scheduler_policy")) {
            if (cluster_validate_scheduler_policy(value, &king_cluster_config.cluster_worker_scheduler_policy) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cluster_worker_cpu_affinity_map")) {
            if (cluster_validate_generic_string(value, &king_cluster_config.cluster_worker_cpu_affinity_map) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cluster_worker_cgroup_path")) {
            if (cluster_validate_generic_string(value, &king_cluster_config.cluster_worker_cgroup_path) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cluster_worker_user_id")) {
            if (cluster_validate_generic_string(value, &king_cluster_config.cluster_worker_user_id) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cluster_worker_group_id")) {
            if (cluster_validate_generic_string(value, &king_cluster_config.cluster_worker_group_id) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
