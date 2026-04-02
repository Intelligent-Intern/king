/*
 * =========================================================================
 * FILENAME:   src/config/bare_metal_tuning/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the bare-metal tuning config
 * family. The active io_uring, socket-buffer, busy-poll, timestamping, CPU
 * affinity, and NUMA policy knobs all land in this single
 * `king_bare_metal_config` snapshot.
 * =========================================================================
 */

#include "include/config/bare_metal_tuning/base_layer.h"

kg_bare_metal_config_t king_bare_metal_config;
