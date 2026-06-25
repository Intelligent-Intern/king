/*
 * =========================================================================
 * FILENAME:   include/config/open_telemetry/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for OpenTelemetry.
 *
 * ARCHITECTURE:
 * This struct stores exporter, tracing, metrics, and logging settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_OPEN_TELEMETRY_BASE_H
#define KING_CONFIG_OPEN_TELEMETRY_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_open_telemetry_config_t {
    /* --- General & Exporter Settings --- */
    bool enable;
    char *service_name;
    char *exporter_endpoint;
    char *exporter_protocol;
    zend_long exporter_timeout_ms;
    char *exporter_headers;
    char *queue_state_path;

    /* --- Batch Processor Settings --- */
    zend_long batch_processor_max_queue_size;
    zend_long batch_processor_schedule_delay_ms;

    /* --- Tracing Settings --- */
    char *traces_sampler_type;
    double traces_sampler_ratio;
    zend_long traces_max_attributes_per_span;

    /* --- Metrics Settings --- */
    bool metrics_enable;
    zend_long metrics_export_interval_ms;
    char *metrics_default_histogram_boundaries;

    /* --- Logging Settings (Experimental) --- */
    bool logs_enable;
    zend_long logs_exporter_batch_size;

} kg_open_telemetry_config_t;

/* Module-global configuration instance. */
extern kg_open_telemetry_config_t king_open_telemetry_config;

/*
 * Exporter endpoints stay full runtime URLs internally, but public telemetry
 * surfaces must only expose a credential-safe collector origin.
 */
const char *king_open_telemetry_validate_exporter_endpoint_value(
    const char *value,
    size_t value_len
);
const char *king_open_telemetry_validate_exporter_headers_value(
    const char *value,
    size_t value_len
);
zend_string *king_open_telemetry_build_public_exporter_endpoint(
    const char *value,
    size_t value_len
);

#endif /* KING_CONFIG_OPEN_TELEMETRY_BASE_H */
