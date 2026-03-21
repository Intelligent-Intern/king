#include "include/config/open_telemetry/default.h"
#include "include/config/open_telemetry/base_layer.h"

void kg_config_open_telemetry_defaults_load(void)
{
    king_open_telemetry_config.enable = true;
    king_open_telemetry_config.service_name = pestrdup("king_application", 1);
    king_open_telemetry_config.exporter_endpoint = pestrdup("http://localhost:4317", 1);
    king_open_telemetry_config.exporter_protocol = pestrdup("grpc", 1);
    king_open_telemetry_config.exporter_timeout_ms = 10000;
    king_open_telemetry_config.exporter_headers = pestrdup("", 1);
    king_open_telemetry_config.batch_processor_max_queue_size = 2048;
    king_open_telemetry_config.batch_processor_schedule_delay_ms = 5000;
    king_open_telemetry_config.traces_sampler_type = pestrdup("parent_based_probability", 1);
    king_open_telemetry_config.traces_sampler_ratio = 1.0;
    king_open_telemetry_config.traces_max_attributes_per_span = 128;
    king_open_telemetry_config.metrics_enable = true;
    king_open_telemetry_config.metrics_export_interval_ms = 60000;
    king_open_telemetry_config.metrics_default_histogram_boundaries = pestrdup("0,5,10,25,50,75,100,250,500,1000", 1);
    king_open_telemetry_config.logs_enable = false;
    king_open_telemetry_config.logs_exporter_batch_size = 512;
}
