#include "include/config/cluster_and_process/ini.h"
#include "include/config/cluster_and_process/base_layer.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <zend_ini.h>

extern int king_ini_module_number;

static ZEND_INI_MH(OnUpdateClusterNonNegativeLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val < 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided for a cluster directive. A non-negative integer is required."
        );
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.cluster_workers")) {
        king_cluster_config.cluster_workers = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_graceful_shutdown_ms")) {
        king_cluster_config.cluster_graceful_shutdown_ms = val;
    } else if (zend_string_equals_literal(entry->name, "king.server_max_fd_per_worker")) {
        king_cluster_config.server_max_fd_per_worker = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_max_restarts_per_worker")) {
        king_cluster_config.cluster_max_restarts_per_worker = val;
    } else if (zend_string_equals_literal(entry->name, "king.cluster_restart_interval_sec")) {
        king_cluster_config.cluster_restart_interval_sec = val;
    }

    return SUCCESS;
}

PHP_INI_BEGIN()
    ZEND_INI_ENTRY_EX("king.cluster_workers", "0", PHP_INI_SYSTEM, OnUpdateClusterNonNegativeLong, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_graceful_shutdown_ms", "30000", PHP_INI_SYSTEM, OnUpdateClusterNonNegativeLong, NULL)
    ZEND_INI_ENTRY_EX("king.server_max_fd_per_worker", "8192", PHP_INI_SYSTEM, OnUpdateClusterNonNegativeLong, NULL)
    STD_PHP_INI_ENTRY("king.cluster_restart_crashed_workers", "1", PHP_INI_SYSTEM, OnUpdateBool, cluster_restart_crashed_workers, kg_cluster_config_t, king_cluster_config)
    ZEND_INI_ENTRY_EX("king.cluster_max_restarts_per_worker", "10", PHP_INI_SYSTEM, OnUpdateClusterNonNegativeLong, NULL)
    ZEND_INI_ENTRY_EX("king.cluster_restart_interval_sec", "60", PHP_INI_SYSTEM, OnUpdateClusterNonNegativeLong, NULL)
    STD_PHP_INI_ENTRY("king.cluster_worker_niceness", "0", PHP_INI_SYSTEM, OnUpdateLong, cluster_worker_niceness, kg_cluster_config_t, king_cluster_config)
    STD_PHP_INI_ENTRY("king.cluster_worker_scheduler_policy", "other", PHP_INI_SYSTEM, OnUpdateString, cluster_worker_scheduler_policy, kg_cluster_config_t, king_cluster_config)
    STD_PHP_INI_ENTRY("king.cluster_worker_cpu_affinity_map", "", PHP_INI_SYSTEM, OnUpdateString, cluster_worker_cpu_affinity_map, kg_cluster_config_t, king_cluster_config)
    STD_PHP_INI_ENTRY("king.cluster_worker_cgroup_path", "", PHP_INI_SYSTEM, OnUpdateString, cluster_worker_cgroup_path, kg_cluster_config_t, king_cluster_config)
    STD_PHP_INI_ENTRY("king.cluster_worker_user_id", "", PHP_INI_SYSTEM, OnUpdateString, cluster_worker_user_id, kg_cluster_config_t, king_cluster_config)
    STD_PHP_INI_ENTRY("king.cluster_worker_group_id", "", PHP_INI_SYSTEM, OnUpdateString, cluster_worker_group_id, kg_cluster_config_t, king_cluster_config)
PHP_INI_END()

void kg_config_cluster_and_process_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_cluster_and_process_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
