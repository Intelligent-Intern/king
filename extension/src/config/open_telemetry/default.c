/*
 * =========================================================================
 * FILENAME:   src/config/open_telemetry/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the OpenTelemetry config family. This slice
 * seeds the baseline exporter timeout, batch processor limits, trace
 * sampler ratio and attribute cap, metrics cadence, and log export
 * defaults before INI and any allowed userland overrides refine the live
 * telemetry snapshot.
 * =========================================================================
 */

#include "include/config/open_telemetry/default.h"
#include "include/config/open_telemetry/base_layer.h"

void kg_config_open_telemetry_defaults_load(void)
{
    king_open_telemetry_config.enable = true;
    king_open_telemetry_config.service_name = NULL;
    king_open_telemetry_config.exporter_endpoint = NULL;
    king_open_telemetry_config.exporter_protocol = NULL;
    king_open_telemetry_config.exporter_timeout_ms = 10000;
    king_open_telemetry_config.exporter_headers = NULL;
    king_open_telemetry_config.batch_processor_max_queue_size = 2048;
    king_open_telemetry_config.batch_processor_schedule_delay_ms = 5000;
    king_open_telemetry_config.traces_sampler_type = NULL;
    king_open_telemetry_config.traces_sampler_ratio = 1.0;
    king_open_telemetry_config.traces_max_attributes_per_span = 128;
    king_open_telemetry_config.metrics_enable = true;
    king_open_telemetry_config.metrics_export_interval_ms = 60000;
    king_open_telemetry_config.metrics_default_histogram_boundaries = NULL;
    king_open_telemetry_config.logs_enable = false;
    king_open_telemetry_config.logs_exporter_batch_size = 512;
}
