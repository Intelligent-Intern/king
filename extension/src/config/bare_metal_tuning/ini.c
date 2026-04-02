/*
 * =========================================================================
 * FILENAME:   src/config/bare_metal_tuning/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the bare-metal tuning
 * config family. This file exposes the system-level io_uring, socket,
 * busy-poll, timestamping, CPU-affinity, and NUMA directives and keeps the
 * shared `king_bare_metal_config` snapshot aligned with validated updates.
 * =========================================================================
 */

#include "include/config/bare_metal_tuning/ini.h"
#include "include/config/bare_metal_tuning/base_layer.h"
#include "include/validation/config_param/validate_cpu_affinity_map_string.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <zend_ini.h>

static ZEND_INI_MH(OnUpdateBareMetalNonNegativeLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val < 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value provided for a bare-metal directive. A non-negative integer is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.io_uring_sq_poll_ms")) {
        king_bare_metal_config.io_uring_sq_poll_ms = val;
    } else if (zend_string_equals_literal(entry->name, "king.socket_enable_busy_poll_us")) {
        king_bare_metal_config.socket_enable_busy_poll_us = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateBareMetalPositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value provided for a bare-metal directive. A positive integer (greater than zero) is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.io_max_batch_read_packets")) {
        king_bare_metal_config.io_max_batch_read_packets = val;
    } else if (zend_string_equals_literal(entry->name, "king.io_max_batch_write_packets")) {
        king_bare_metal_config.io_max_batch_write_packets = val;
    } else if (zend_string_equals_literal(entry->name, "king.socket_receive_buffer_size")) {
        king_bare_metal_config.socket_receive_buffer_size = val;
    } else if (zend_string_equals_literal(entry->name, "king.socket_send_buffer_size")) {
        king_bare_metal_config.socket_send_buffer_size = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateNumaPolicyString)
{
    const char *policy = ZSTR_VAL(new_value);

    if (strcasecmp(policy, "default") != 0 && strcasecmp(policy, "prefer") != 0 &&
        strcasecmp(policy, "bind") != 0 && strcasecmp(policy, "interleave") != 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for NUMA policy. Must be one of 'default', 'prefer', 'bind', or 'interleave'.");
        return FAILURE;
    }

    if (king_bare_metal_config.io_thread_numa_node_policy) {
        pefree(king_bare_metal_config.io_thread_numa_node_policy, 1);
    }
    king_bare_metal_config.io_thread_numa_node_policy = pestrdup(policy, 1);
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateCpuAffinityString)
{
    zval zv;

    ZVAL_STR_COPY(&zv, new_value);
    if (kg_validate_cpu_affinity_map_string(&zv, &king_bare_metal_config.io_thread_cpu_affinity) != SUCCESS) {
        zval_ptr_dtor(&zv);
        return FAILURE;
    }

    zval_ptr_dtor(&zv);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.io_engine_use_uring", "1", PHP_INI_SYSTEM, OnUpdateBool, io_engine_use_uring, kg_bare_metal_config_t, king_bare_metal_config)
    ZEND_INI_ENTRY_EX("king.io_uring_sq_poll_ms", "0", PHP_INI_SYSTEM, OnUpdateBareMetalNonNegativeLong, NULL)
    ZEND_INI_ENTRY_EX("king.io_max_batch_read_packets", "64", PHP_INI_SYSTEM, OnUpdateBareMetalPositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.io_max_batch_write_packets", "64", PHP_INI_SYSTEM, OnUpdateBareMetalPositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.socket_receive_buffer_size", "2097152", PHP_INI_SYSTEM, OnUpdateBareMetalPositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.socket_send_buffer_size", "2097152", PHP_INI_SYSTEM, OnUpdateBareMetalPositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.socket_enable_busy_poll_us", "0", PHP_INI_SYSTEM, OnUpdateBareMetalNonNegativeLong, NULL)
    STD_PHP_INI_ENTRY("king.socket_enable_timestamping", "1", PHP_INI_SYSTEM, OnUpdateBool, socket_enable_timestamping, kg_bare_metal_config_t, king_bare_metal_config)
    ZEND_INI_ENTRY_EX("king.io_thread_cpu_affinity", "", PHP_INI_SYSTEM, OnUpdateCpuAffinityString, NULL)
    ZEND_INI_ENTRY_EX("king.io_thread_numa_node_policy", "default", PHP_INI_SYSTEM, OnUpdateNumaPolicyString, NULL)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_bare_metal_tuning_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_bare_metal_tuning_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
