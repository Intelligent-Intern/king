/*
 * =========================================================================
 * FILENAME:   src/config/high_perf_compute_and_ai/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the high-perf compute / AI
 * config family. Dataframe limits, GPU backend and affinity, memory
 * preallocation, tensor/p2p/storage toggles, and vendor-specific tuning all
 * land in the single `king_high_perf_compute_ai_config` snapshot.
 * =========================================================================
 */

#include "include/config/high_perf_compute_and_ai/base_layer.h"

kg_high_perf_compute_ai_config_t king_high_perf_compute_ai_config;
