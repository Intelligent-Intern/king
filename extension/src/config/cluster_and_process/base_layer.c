/*
 * =========================================================================
 * FILENAME:   src/config/cluster_and_process/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the cluster/process config
 * family. Worker counts, restart policy, graceful-shutdown timing, fd
 * limits, niceness, scheduler policy, affinity, cgroup, and uid/gid knobs
 * all land in the single `king_cluster_config` snapshot.
 * =========================================================================
 */

#include "include/config/cluster_and_process/base_layer.h"

kg_cluster_config_t king_cluster_config;
