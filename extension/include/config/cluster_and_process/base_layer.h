/*
 * =========================================================================
 * FILENAME:   include/config/cluster_and_process/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for cluster and process settings.
 *
 * ARCHITECTURE:
 * This struct stores the cluster worker and process tuning values.
 * =========================================================================
 */
#ifndef KING_CONFIG_CLUSTER_BASE_H
#define KING_CONFIG_CLUSTER_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_cluster_config_t {
    /* --- Core Cluster Settings --- */
    zend_long cluster_workers;
    zend_long cluster_graceful_shutdown_ms;
    zend_long server_max_fd_per_worker;

    /* --- Cluster Resilience & Worker Performance Tuning (Advanced) --- */
    bool cluster_restart_crashed_workers;
    zend_long cluster_max_restarts_per_worker;
    zend_long cluster_restart_interval_sec;
    zend_long cluster_worker_niceness;
    char *cluster_worker_scheduler_policy;
    char *cluster_worker_cpu_affinity_map;
    char *cluster_worker_cgroup_path;
    char *cluster_worker_user_id;
    char *cluster_worker_group_id;

} kg_cluster_config_t;

/* Module-global configuration instance. */
extern kg_cluster_config_t king_cluster_config;

#endif /* KING_CONFIG_CLUSTER_BASE_H */
