/*
 * =========================================================================
 * FILENAME:   src/config/cloud_autoscale/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the cloud-autoscale config
 * family. Provider selection, credentials/endpoints, budget/quota limits,
 * scale thresholds, and instance-shape settings all land in the single
 * `king_cloud_autoscale_config` snapshot.
 * =========================================================================
 */

#include "include/config/cloud_autoscale/base_layer.h"

kg_cloud_autoscale_config_t king_cloud_autoscale_config;
