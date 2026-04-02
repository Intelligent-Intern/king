/*
 * =========================================================================
 * FILENAME:   src/config/open_telemetry/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the OpenTelemetry config
 * family. Enablement, service naming, exporter endpoint/protocol/headers,
 * batching limits, trace sampler settings, metrics intervals and histogram
 * boundaries, and log batching all land in the single
 * `king_open_telemetry_config` snapshot.
 * =========================================================================
 */

#include "include/config/open_telemetry/base_layer.h"

kg_open_telemetry_config_t king_open_telemetry_config;
