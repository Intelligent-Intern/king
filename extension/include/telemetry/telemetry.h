/*
 * include/telemetry/telemetry.h - Public C API for telemetry
 * ===========================================================
 *
 * This header exposes the telemetry and observability types and PHP entry
 * points used by the extension.
 */

#ifndef KING_TELEMETRY_H
#define KING_TELEMETRY_H

#include <php.h>
#include <stdint.h>
#include <time.h>

/* --- Telemetry Types --- */

typedef enum {
    KING_TELEMETRY_LEVEL_NONE,
    KING_TELEMETRY_LEVEL_ERROR,
    KING_TELEMETRY_LEVEL_WARN,
    KING_TELEMETRY_LEVEL_INFO,
    KING_TELEMETRY_LEVEL_DEBUG,
    KING_TELEMETRY_LEVEL_TRACE
} king_telemetry_level_t;

typedef enum {
    KING_METRIC_TYPE_COUNTER,
    KING_METRIC_TYPE_GAUGE,
    KING_METRIC_TYPE_HISTOGRAM,
    KING_METRIC_TYPE_SUMMARY
} king_metric_type_t;

typedef enum {
    KING_SPAN_KIND_INTERNAL,
    KING_SPAN_KIND_SERVER,
    KING_SPAN_KIND_CLIENT,
    KING_SPAN_KIND_PRODUCER,
    KING_SPAN_KIND_CONSUMER
} king_span_kind_t;

typedef struct _king_telemetry_config_t {
    zend_bool enabled;
    king_telemetry_level_t level;
    char service_name[128];
    char service_version[32];
    char service_namespace[64];
    char deployment_environment[32];
    char otel_exporter_endpoint[256];
    char otel_exporter_protocol[16]; /* grpc, http/protobuf, http/json */
    uint32_t batch_timeout_ms;
    uint32_t max_batch_size;
    uint32_t max_export_batch_size;
    zend_bool enable_auto_instrumentation;
    zend_bool enable_distributed_tracing;
    zend_bool enable_metrics_collection;
    zend_bool enable_log_correlation;
    zend_bool enable_resource_detection;
    zval custom_resource_attributes; /* PHP array */
} king_telemetry_config_t;

typedef struct _king_trace_context_t {
    char trace_id[33];    /* 128-bit trace ID as hex string */
    char span_id[17];     /* 64-bit span ID as hex string */
    char parent_span_id[17];
    uint8_t trace_flags;  /* Sampling flags */
    char trace_state[512]; /* W3C trace state */
    time_t start_time;
    time_t end_time;
    king_span_kind_t span_kind;
    char operation_name[128];
    char component[64];
    zval attributes;      /* PHP array of span attributes */
    zval events;          /* PHP array of span events */
    zval links;           /* PHP array of span links */
} king_trace_context_t;

typedef struct _king_metric_data_t {
    char metric_name[128];
    char metric_description[256];
    char metric_unit[32];
    king_metric_type_t metric_type;
    double value;
    uint64_t count;
    double sum;
    double min;
    double max;
    time_t timestamp;
    zval labels;          /* PHP array of metric labels */
} king_metric_data_t;

typedef struct _king_log_record_t {
    time_t timestamp;
    king_telemetry_level_t level;
    char logger_name[128];
    char message[1024];
    char trace_id[33];
    char span_id[17];
    zval attributes;      /* PHP array of log attributes */
    zval exception_info;  /* PHP array with exception details */
} king_log_record_t;

/* --- PHP Function Prototypes --- */

/* Initializes telemetry from a PHP config array. */
PHP_FUNCTION(king_telemetry_init);

/* Starts a new tracing span. */
PHP_FUNCTION(king_telemetry_start_span);

/* Ends a tracing span. */
PHP_FUNCTION(king_telemetry_end_span);

/* Records a metric value. */
PHP_FUNCTION(king_telemetry_record_metric);

/* Records a structured log entry. */
PHP_FUNCTION(king_telemetry_log);

/* Returns the current trace context. */
PHP_FUNCTION(king_telemetry_get_trace_context);

/* Injects trace context into headers. */
PHP_FUNCTION(king_telemetry_inject_context);

/* Extracts trace context from headers. */
PHP_FUNCTION(king_telemetry_extract_context);

/* Returns collected metrics. */
PHP_FUNCTION(king_telemetry_get_metrics);

/* Flushes pending telemetry data. */
PHP_FUNCTION(king_telemetry_flush);

/* Returns telemetry status. */
PHP_FUNCTION(king_telemetry_get_status);

/* --- Internal C API --- */

int king_telemetry_init_system(king_telemetry_config_t *config);
void king_telemetry_shutdown_system(void);
king_trace_context_t* king_telemetry_create_span(const char *operation_name, king_span_kind_t span_kind, const char *parent_span_id);
int king_telemetry_finish_span(king_trace_context_t *span_context);
int king_telemetry_add_span_attribute(king_trace_context_t *span_context, const char *key, const char *value);
int king_telemetry_add_span_event(king_trace_context_t *span_context, const char *event_name, const char *event_data);
int king_telemetry_record_metric_internal(const char *metric_name, king_metric_type_t metric_type, double value, zval *labels);
zend_bool king_telemetry_lookup_metric(
    const char *metric_name,
    double *value_out,
    king_metric_type_t *type_out,
    time_t *timestamp_out);
void king_telemetry_metrics_shutdown(void);
int king_telemetry_log_internal(king_telemetry_level_t level, const char *logger_name, const char *message, zval *attributes);
char* king_telemetry_generate_trace_id(void);
char* king_telemetry_generate_span_id(void);
int king_telemetry_export_batch(void);
king_trace_context_t* king_telemetry_get_current_span(void);
int king_telemetry_set_current_span(king_trace_context_t *span_context);
const char* king_telemetry_level_to_string(king_telemetry_level_t level);
const char* king_metric_type_to_string(king_metric_type_t type);
const char* king_span_kind_to_string(king_span_kind_t kind);

/* --- Auto-Instrumentation Hooks --- */
int king_telemetry_instrument_http_request(const char *method, const char *url, const char *user_agent);
int king_telemetry_instrument_database_query(const char *query, const char *database_name);
int king_telemetry_instrument_cache_operation(const char *operation, const char *key, zend_bool hit);
int king_telemetry_instrument_external_call(const char *service_name, const char *endpoint, const char *method);

#endif /* KING_TELEMETRY_H */
