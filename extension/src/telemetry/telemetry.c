/*
 * src/telemetry/telemetry.c - Telemetry and Distributed Tracing Core
 * =========================================================================
 *
 * This module implements the native tracing, logging, and context management
 * for the King telemetry system.
 */
#include "php_king.h"
#include "include/telemetry/telemetry.h"
#include <zend_hash.h>
#include <uuid/uuid.h> /* assuming uuid-dev is available, otherwise simulated */
#include <curl/curl.h>
#include <ext/json/php_json.h>
#include "zend_smart_str.h"
#include "include/config/open_telemetry/base_layer.h"

static king_telemetry_config_t king_telemetry_runtime_config;
static bool king_telemetry_system_initialized = false;
static king_trace_context_t *king_current_span = NULL;

/* Export queue for batched telemetry data */
king_telemetry_batch_t *king_telemetry_export_queue_head = NULL;
king_telemetry_batch_t *king_telemetry_export_queue_tail = NULL;
uint32_t king_telemetry_queue_size = 0;
uint32_t king_telemetry_queue_drop_count = 0;
uint32_t king_telemetry_export_success_count = 0;
uint32_t king_telemetry_export_failure_count = 0;

static uint32_t king_telemetry_default_max_queue_size(void)
{
    if (king_open_telemetry_config.batch_processor_max_queue_size > 0
        && king_open_telemetry_config.batch_processor_max_queue_size <= UINT32_MAX) {
        return (uint32_t) king_open_telemetry_config.batch_processor_max_queue_size;
    }

    return 2048;
}

static uint32_t king_telemetry_runtime_max_queue_size(void)
{
    if (king_telemetry_runtime_config.max_queue_size > 0) {
        return king_telemetry_runtime_config.max_queue_size;
    }

    return king_telemetry_default_max_queue_size();
}

static void king_telemetry_span_free(king_trace_context_t *span)
{
    if (span) {
        zval_ptr_dtor(&span->attributes);
        zval_ptr_dtor(&span->events);
        zval_ptr_dtor(&span->links);
        efree(span);
    }
}

static void king_telemetry_config_apply_inline_array(
    king_telemetry_config_t *config,
    zval *config_arr)
{
    zval *value;

    if (config == NULL || config_arr == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        return;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(config_arr), "enabled", sizeof("enabled") - 1);
    if (value != NULL) {
        config->enabled = zend_is_true(value) ? 1 : 0;
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "otel_exporter_endpoint",
        sizeof("otel_exporter_endpoint") - 1
    );
    if (value != NULL && Z_TYPE_P(value) == IS_STRING) {
        snprintf(
            config->otel_exporter_endpoint,
            sizeof(config->otel_exporter_endpoint),
            "%s",
            Z_STRVAL_P(value)
        );
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "otel_exporter_protocol",
        sizeof("otel_exporter_protocol") - 1
    );
    if (value != NULL && Z_TYPE_P(value) == IS_STRING) {
        snprintf(
            config->otel_exporter_protocol,
            sizeof(config->otel_exporter_protocol),
            "%s",
            Z_STRVAL_P(value)
        );
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "service_name",
        sizeof("service_name") - 1
    );
    if (value != NULL && Z_TYPE_P(value) == IS_STRING) {
        snprintf(
            config->service_name,
            sizeof(config->service_name),
            "%s",
            Z_STRVAL_P(value)
        );
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "batch_processor_max_queue_size",
        sizeof("batch_processor_max_queue_size") - 1
    );
    if (value != NULL) {
        zend_long queue_limit = zval_get_long(value);

        if (queue_limit > 0) {
            config->max_queue_size = queue_limit > UINT32_MAX
                ? UINT32_MAX
                : (uint32_t) queue_limit;
        }
    }
}

static const char *king_telemetry_metric_type_from_zval(zval *type_zval)
{
    if (type_zval == NULL) {
        return NULL;
    }

    if (Z_TYPE_P(type_zval) == IS_LONG) {
        return king_metric_type_to_string((king_metric_type_t) Z_LVAL_P(type_zval));
    }

    if (Z_TYPE_P(type_zval) == IS_STRING) {
        const char *type_str = Z_STRVAL_P(type_zval);

        if (strcmp(type_str, "counter") == 0
            || strcmp(type_str, "gauge") == 0
            || strcmp(type_str, "histogram") == 0
            || strcmp(type_str, "summary") == 0) {
            return type_str;
        }
    }

    return NULL;
}

void king_telemetry_free_batch(king_telemetry_batch_t *batch)
{
    if (batch) {
        zval_ptr_dtor(&batch->metrics);
        zval_ptr_dtor(&batch->spans);
        zval_ptr_dtor(&batch->logs);
        efree(batch);
    }
}

king_telemetry_batch_t* king_telemetry_create_batch(void)
{
    king_telemetry_batch_t *batch = emalloc(sizeof(king_telemetry_batch_t));
    memset(batch, 0, sizeof(king_telemetry_batch_t));
    
    array_init(&batch->metrics);
    array_init(&batch->spans);
    array_init(&batch->logs);
    batch->created_at = time(NULL);
    batch->next = NULL;
    
    return batch;
}

int king_telemetry_queue_batch(king_telemetry_batch_t *batch)
{
    uint32_t max_queue_size;

    if (!batch) return FAILURE;

    /* Always detach before enqueue so retries cannot retain stale links. */
    batch->next = NULL;

    max_queue_size = king_telemetry_runtime_max_queue_size();
    if (max_queue_size > 0 && king_telemetry_queue_size >= max_queue_size) {
        king_telemetry_queue_drop_count++;
        return FAILURE;
    }
    
    if (!king_telemetry_export_queue_head) {
        king_telemetry_export_queue_head = batch;
        king_telemetry_export_queue_tail = batch;
    } else {
        king_telemetry_export_queue_tail->next = batch;
        king_telemetry_export_queue_tail = batch;
    }
    
    king_telemetry_queue_size++;
    return SUCCESS;
}

static king_telemetry_batch_t* king_telemetry_dequeue_batch(void)
{
    king_telemetry_batch_t *batch;

    if (!king_telemetry_export_queue_head) return NULL;
    
    batch = king_telemetry_export_queue_head;
    king_telemetry_export_queue_head = batch->next;
    batch->next = NULL;
    
    if (!king_telemetry_export_queue_head) {
        king_telemetry_export_queue_tail = NULL;
    }
    
    king_telemetry_queue_size--;
    return batch;
}

void king_telemetry_cleanup_export_queue(void)
{
    king_telemetry_batch_t *batch;
    while ((batch = king_telemetry_dequeue_batch()) != NULL) {
        king_telemetry_free_batch(batch);
    }
}

int king_telemetry_process_export_queue(void)
{
    king_telemetry_batch_t *batch;
    int total_processed = 0;
    int total_success = 0;
    
    while ((batch = king_telemetry_dequeue_batch()) != NULL) {
        total_processed++;
        
        /* Export metrics if present */
        if (zend_hash_num_elements(Z_ARRVAL(batch->metrics)) > 0) {
            if (king_telemetry_export_metrics_otlp(&batch->metrics) == SUCCESS) {
                total_success++;
            } else {
                king_telemetry_export_failure_count++;
            }
        }
        
        /* Export spans if present */
        if (zend_hash_num_elements(Z_ARRVAL(batch->spans)) > 0) {
            if (king_telemetry_export_spans_otlp(&batch->spans) == SUCCESS) {
                total_success++;
            } else {
                king_telemetry_export_failure_count++;
            }
        }
        
        /* Export logs if present */
        if (zend_hash_num_elements(Z_ARRVAL(batch->logs)) > 0) {
            if (king_telemetry_export_logs_otlp(&batch->logs) == SUCCESS) {
                total_success++;
            } else {
                king_telemetry_export_failure_count++;
            }
        }
        
        /* Free the batch */
        king_telemetry_free_batch(batch);
    }
    
    king_telemetry_export_success_count += total_success;
    return total_processed;
}

int king_telemetry_init_system(king_telemetry_config_t *config)
{
    if (config) {
        memcpy(&king_telemetry_runtime_config, config, sizeof(king_telemetry_config_t));
        if (king_telemetry_runtime_config.max_queue_size == 0) {
            king_telemetry_runtime_config.max_queue_size = king_telemetry_default_max_queue_size();
        }
        king_telemetry_system_initialized = true;
    }
    return SUCCESS;
}

void king_telemetry_shutdown_system(void)
{
    king_telemetry_system_initialized = false;
    if (king_current_span) {
        king_telemetry_span_free(king_current_span);
        king_current_span = NULL;
    }
    
    /* Flush any remaining batches in queue */
    king_telemetry_batch_t *batch;
    while ((batch = king_telemetry_dequeue_batch()) != NULL) {
        king_telemetry_free_batch(batch);
    }
}

char* king_telemetry_generate_trace_id(void)
{
    static char trace_id[33];
    /* Simulation: static trace ID for runtime */
    strncpy(trace_id, "4bf92f3577b34da6a3ce929d0e0e4736", 32);
    trace_id[32] = '\0';
    return trace_id;
}

char* king_telemetry_generate_span_id(void)
{
    static char span_id[17];
    /* Simulation: static span ID for runtime */
    strncpy(span_id, "00f067aa0ba902b7", 16);
    span_id[16] = '\0';
    return span_id;
}

king_trace_context_t* king_telemetry_create_span(const char *operation_name, king_span_kind_t span_kind, const char *parent_span_id)
{
    king_trace_context_t *span = emalloc(sizeof(king_trace_context_t));
    memset(span, 0, sizeof(king_trace_context_t));
    
    strncpy(span->operation_name, operation_name, sizeof(span->operation_name) - 1);
    span->span_kind = span_kind;
    
    strncpy(span->trace_id, king_telemetry_generate_trace_id(), 32);
    strncpy(span->span_id, king_telemetry_generate_span_id(), 16);
    
    if (parent_span_id) {
        strncpy(span->parent_span_id, parent_span_id, 16);
    }
    
    span->start_time = time(NULL);
    array_init(&span->attributes);
    array_init(&span->events);
    array_init(&span->links);
    
    return span;
}

int king_telemetry_finish_span(king_trace_context_t *span_context)
{
    if (span_context) {
        span_context->end_time = time(NULL);
        /* In a real build, we'd export/queue the span here */
        return SUCCESS;
    }
    return FAILURE;
}

int king_telemetry_log_internal(king_telemetry_level_t level, const char *logger_name, const char *message, zval *attributes)
{
    king_log_record_t log;
    memset(&log, 0, sizeof(log));
    
    log.timestamp = time(NULL);
    log.level = level;
    strncpy(log.logger_name, logger_name, sizeof(log.logger_name) - 1);
    strncpy(log.message, message, sizeof(log.message) - 1);
    
    if (king_current_span) {
        strncpy(log.trace_id, king_current_span->trace_id, 32);
        strncpy(log.span_id, king_current_span->span_id, 16);
    }
    
    /* Simulate logging output */
    return SUCCESS;
}

/* --- PHP Entry Points --- */

#include "include/king_globals.h"

PHP_FUNCTION(king_telemetry_init)
{
    zval *config_arr;
    if (zend_parse_parameters(1, "a", &config_arr) == FAILURE) RETURN_FALSE;

    if (!king_globals.is_userland_override_allowed && zend_hash_num_elements(Z_ARRVAL_P(config_arr)) > 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Configuration override is disabled by system policy."
        );
        RETURN_THROWS();
    }

    king_telemetry_config_t config;
    memset(&config, 0, sizeof(config));
    config.enabled = true;
    config.max_queue_size = king_telemetry_default_max_queue_size();
    king_telemetry_config_apply_inline_array(&config, config_arr);
    
    king_telemetry_init_system(&config);
    RETURN_TRUE;
}

PHP_FUNCTION(king_telemetry_start_span)
{
    char *op_name;
    size_t op_name_len;
    zval *attrs = NULL;
    char *parent_id = NULL;
    size_t parent_id_len = 0;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(op_name, op_name_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(attrs)
        Z_PARAM_STRING_OR_NULL(parent_id, parent_id_len)
    ZEND_PARSE_PARAMETERS_END();
    
    if (op_name_len == 0 || op_name_len > 127) {
        /* Truncate op_name or throw? Truncating is already handled by strncpy in create_span, 
           but returning false for empty or obviously malicious long names is better. */
        if (op_name_len == 0) RETURN_FALSE;
    }

    king_trace_context_t *span = king_telemetry_create_span(op_name, KING_SPAN_KIND_INTERNAL, parent_id);
    if (!span) RETURN_FALSE;
    
    if (king_current_span) {
        /* Support a simple stack of one span for now in the runtime */
        king_telemetry_span_free(king_current_span);
    }
    king_current_span = span;
    
    RETURN_STRING(span->span_id);
}

PHP_FUNCTION(king_telemetry_end_span)
{
    char *span_id;
    size_t span_id_len;
    zval *final_attrs = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STRING(span_id, span_id_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(final_attrs)
    ZEND_PARSE_PARAMETERS_END();

    if (king_current_span && strcmp(king_current_span->span_id, span_id) == 0) {
        king_telemetry_finish_span(king_current_span);
        king_telemetry_span_free(king_current_span);
        king_current_span = NULL;
        RETURN_TRUE;
    }
    
    RETURN_FALSE;
}

PHP_FUNCTION(king_telemetry_log)
{
    char *level_str, *msg;
    size_t level_len, msg_len;
    zval *attrs = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(level_str, level_len)
        Z_PARAM_STRING(msg, msg_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(attrs)
    ZEND_PARSE_PARAMETERS_END();

    king_telemetry_log_internal(KING_TELEMETRY_LEVEL_INFO, "php-king", msg, attrs);
    RETURN_TRUE;
}

/* --- Internal Export Functions --- */

#define KING_TELEMETRY_HTTP_TIMEOUT_MS 10000L
#define KING_TELEMETRY_MAX_RESPONSE_SIZE (1024 * 1024) /* 1 MiB */

typedef struct _king_telemetry_http_response_t {
    smart_str data;
    size_t bytes;
    long status_code;
} king_telemetry_http_response_t;

static size_t king_telemetry_http_write_callback(void *ptr, size_t size, size_t nmemb, void *userdata)
{
    king_telemetry_http_response_t *response = (king_telemetry_http_response_t *)userdata;
    size_t total_size = size * nmemb;
    
    if (response->bytes + total_size > KING_TELEMETRY_MAX_RESPONSE_SIZE) {
        return 0; /* Reject response that's too large */
    }
    
    smart_str_appendl(&response->data, ptr, total_size);
    response->bytes += total_size;
    
    return total_size;
}

static int king_telemetry_http_post(const char *url, const char *json_payload, king_telemetry_http_response_t *response)
{
    CURL *curl = NULL;
    CURLcode res = CURLE_FAILED_INIT;
    struct curl_slist *headers = NULL;
    
    curl = curl_easy_init();
    if (!curl) {
        return FAILURE;
    }
    
    /* Set up headers for OTLP HTTP/JSON */
    headers = curl_slist_append(headers, "Content-Type: application/json");
    headers = curl_slist_append(headers, "User-Agent: king-telemetry/1.0");
    
    /* Initialize response buffer */
    memset(response, 0, sizeof(king_telemetry_http_response_t));
    smart_str_alloc(&response->data, 4096, 0);
    
    /* Configure curl request */
    curl_easy_setopt(curl, CURLOPT_URL, url);
    curl_easy_setopt(curl, CURLOPT_POST, 1L);
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, json_payload);
    curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE, strlen(json_payload));
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, king_telemetry_http_write_callback);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, response);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT_MS, KING_TELEMETRY_HTTP_TIMEOUT_MS);
    curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L); /* Prevent signals in multi-threaded apps */
    
    /* Perform the request */
    res = curl_easy_perform(curl);
    
    if (res == CURLE_OK) {
        curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &response->status_code);
    }
    
    /* Clean up */
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);
    
    if (res != CURLE_OK) {
        smart_str_free(&response->data);
        return FAILURE;
    }
    
    /* Null-terminate the response data */
    smart_str_0(&response->data);
    
    return SUCCESS;
}

int king_telemetry_export_batch(void)
{
    if (!king_telemetry_system_initialized || !king_telemetry_runtime_config.enabled) {
        return FAILURE;
    }
    
    king_telemetry_batch_t *batch = king_telemetry_dequeue_batch();
    if (!batch) {
        return SUCCESS; /* No batches to export */
    }
    
    int result = FAILURE;
    
    /* Export metrics via OTLP */
    if (zend_hash_num_elements(Z_ARRVAL(batch->metrics)) > 0) {
        result = king_telemetry_export_metrics_otlp(&batch->metrics);
    }
    
    /* Export spans via OTLP */
    if (zend_hash_num_elements(Z_ARRVAL(batch->spans)) > 0) {
        result = king_telemetry_export_spans_otlp(&batch->spans);
    }
    
    /* Export logs via OTLP */
    if (zend_hash_num_elements(Z_ARRVAL(batch->logs)) > 0) {
        result = king_telemetry_export_logs_otlp(&batch->logs);
    }
    
    if (result == SUCCESS) {
        king_telemetry_export_success_count++;
    } else {
        king_telemetry_export_failure_count++;
        /* Re-queue batch for retry on failure */
        king_telemetry_queue_batch(batch);
        batch = NULL; /* Don't free, it's back in queue */
    }
    
    if (batch) {
        king_telemetry_free_batch(batch);
    }
    
    return result;
}

int king_telemetry_export_metrics_otlp(zval *metrics)
{
    if (!king_telemetry_runtime_config.otel_exporter_endpoint[0]) {
        return FAILURE; /* No endpoint configured */
    }
    
    /* Build OTLP endpoint URL for metrics */
    char endpoint_url[1024];
    snprintf(endpoint_url, sizeof(endpoint_url), "%s/v1/metrics",
             king_telemetry_runtime_config.otel_exporter_endpoint);
    
    /* Convert metrics to OTLP JSON format */
    smart_str json_payload = {0};
    smart_str_alloc(&json_payload, 4096, 0);
    
    /* Basic OTLP JSON structure for metrics */
    smart_str_appends(&json_payload, "{\"resourceMetrics\":[{\"resource\":{},\"scopeMetrics\":[{\"scope\":{},\"metrics\":[");
    
    /* Convert each metric to OTLP format */
    zval *metric_data;
    int first_metric = 1;
    
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(metrics), metric_data) {
        zval *name_zval;
        zval *value_zval;
        zval *type_zval;
        zval *timestamp_zval;
        const char *metric_type;
        double metric_value;
        zend_long metric_timestamp = 0;

        if (Z_TYPE_P(metric_data) != IS_ARRAY) {
            continue;
        }

        name_zval = zend_hash_str_find(Z_ARRVAL_P(metric_data), "name", sizeof("name") - 1);
        value_zval = zend_hash_str_find(Z_ARRVAL_P(metric_data), "value", sizeof("value") - 1);
        type_zval = zend_hash_str_find(Z_ARRVAL_P(metric_data), "type", sizeof("type") - 1);
        timestamp_zval = zend_hash_str_find(Z_ARRVAL_P(metric_data), "timestamp", sizeof("timestamp") - 1);

        if (name_zval == NULL
            || value_zval == NULL
            || type_zval == NULL
            || Z_TYPE_P(name_zval) != IS_STRING
            || Z_STRLEN_P(name_zval) == 0) {
            continue;
        }

        metric_type = king_telemetry_metric_type_from_zval(type_zval);
        if (metric_type == NULL) {
            continue;
        }

        metric_value = zval_get_double(value_zval);
        if (timestamp_zval != NULL) {
            metric_timestamp = zval_get_long(timestamp_zval);
        }

        if (!first_metric) {
            smart_str_appends(&json_payload, ",");
        }
        first_metric = 0;

        smart_str_appends(&json_payload, "{\"name\":\"");
        smart_str_append(&json_payload, Z_STR_P(name_zval));
        smart_str_appends(&json_payload, "\",\"data\":{\"dataPoints\":[{\"timeUnixNano\":\"");
        
        /* Add timestamp */
        char timestamp_str[32];
        if (metric_timestamp > 0) {
            snprintf(
                timestamp_str,
                sizeof(timestamp_str),
                "%lld%09ld",
                (long long) metric_timestamp,
                0L
            );
        } else {
            struct timespec ts;
            clock_gettime(CLOCK_REALTIME, &ts);
            snprintf(
                timestamp_str,
                sizeof(timestamp_str),
                "%lld%09ld",
                (long long) ts.tv_sec,
                ts.tv_nsec
            );
        }
        smart_str_appends(&json_payload, timestamp_str);
        smart_str_appends(&json_payload, "\",");
        
        /* Add value based on type */
        if (strcmp(metric_type, "counter") == 0) {
            smart_str_appends(&json_payload, "\"asInt\":\"");
            smart_str_append_long(&json_payload, (zend_long) metric_value);
            smart_str_appends(&json_payload, "\"");
        } else {
            smart_str_appends(&json_payload, "\"asDouble\":\"");
            smart_str_append_double(&json_payload, metric_value, 6, 0);
            smart_str_appends(&json_payload, "\"");
        }
        
        smart_str_appends(&json_payload, "}]}}");
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appends(&json_payload, "]}]}]}");
    smart_str_0(&json_payload);
    
    /* Send HTTP request */
    king_telemetry_http_response_t response;
    int result = king_telemetry_http_post(endpoint_url, ZSTR_VAL(json_payload.s), &response);
    
    /* Clean up */
    smart_str_free(&json_payload);
    smart_str_free(&response.data);
    
    if (result == SUCCESS && response.status_code >= 200 && response.status_code < 300) {
        return SUCCESS;
    }
    
    return FAILURE;
}

int king_telemetry_export_spans_otlp(zval *spans)
{
    if (!king_telemetry_runtime_config.otel_exporter_endpoint[0]) {
        return FAILURE; /* No endpoint configured */
    }
    
    /* Build OTLP endpoint URL for spans */
    char endpoint_url[1024];
    snprintf(endpoint_url, sizeof(endpoint_url), "%s/v1/traces",
             king_telemetry_runtime_config.otel_exporter_endpoint);
    
    /* Convert spans to OTLP JSON format */
    smart_str json_payload = {0};
    smart_str_alloc(&json_payload, 4096, 0);
    
    /* Basic OTLP JSON structure for spans */
    smart_str_appends(&json_payload, "{\"resourceSpans\":[{\"resource\":{},\"scopeSpans\":[{\"scope\":{},\"spans\":[");
    
    /* Convert each span to OTLP format */
    zval *span_data;
    int first_span = 1;
    
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(spans), span_data) {
        if (!first_span) {
            smart_str_appends(&json_payload, ",");
        }
        first_span = 0;
        
        /* Extract span data */
        zval *name_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "name", sizeof("name")-1);
        zval *start_time_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "start_time", sizeof("start_time")-1);
        zval *end_time_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "end_time", sizeof("end_time")-1);
        zval *attributes_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "attributes", sizeof("attributes")-1);
        
        if (!name_zval || !start_time_zval || !end_time_zval) continue;
        
        /* Generate span ID and trace ID */
        uint64_t span_id = ((uint64_t)rand() << 32) | rand();
        uint64_t trace_id = ((uint64_t)rand() << 32) | rand();
        
        /* Build span JSON */
        smart_str_appends(&json_payload, "{\"traceId\":\"");
        char trace_id_str[33];
        snprintf(trace_id_str, sizeof(trace_id_str), "%016llx%016llx", 
                (unsigned long long)(trace_id >> 32), (unsigned long long)(trace_id & 0xFFFFFFFF));
        smart_str_appends(&json_payload, trace_id_str);
        smart_str_appends(&json_payload, "\",\"spanId\":\"");
        
        char span_id_str[17];
        snprintf(span_id_str, sizeof(span_id_str), "%016llx", (unsigned long long)span_id);
        smart_str_appends(&json_payload, span_id_str);
        smart_str_appends(&json_payload, "\",\"name\":\"");
        smart_str_append(&json_payload, Z_STR_P(name_zval));
        smart_str_appends(&json_payload, "\",\"startTimeUnixNano\":\"");
        
        /* Convert start time to nanoseconds */
        char start_time_str[32];
        snprintf(start_time_str, sizeof(start_time_str), "%lld%09ld", 
                (long long)Z_LVAL_P(start_time_zval), 0L);
        smart_str_appends(&json_payload, start_time_str);
        smart_str_appends(&json_payload, "\",\"endTimeUnixNano\":\"");
        
        /* Convert end time to nanoseconds */
        char end_time_str[32];
        snprintf(end_time_str, sizeof(end_time_str), "%lld%09ld", 
                (long long)Z_LVAL_P(end_time_zval), 0L);
        smart_str_appends(&json_payload, end_time_str);
        smart_str_appends(&json_payload, "\",\"kind\":1");
        
        /* Add attributes if present */
        if (attributes_zval && Z_TYPE_P(attributes_zval) == IS_ARRAY) {
            smart_str_appends(&json_payload, ",\"attributes\":[");
            zval *attr_value;
            zend_string *attr_key;
            int first_attr = 1;
            
            ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(attributes_zval), attr_key, attr_value) {
                if (!first_attr) {
                    smart_str_appends(&json_payload, ",");
                }
                first_attr = 0;
                
                smart_str_appends(&json_payload, "{\"key\":\"");
                smart_str_append(&json_payload, attr_key);
                smart_str_appends(&json_payload, "\",\"value\":{\"stringValue\":\"");
                smart_str_append(&json_payload, Z_STR_P(attr_value));
                smart_str_appends(&json_payload, "\"}}");
            } ZEND_HASH_FOREACH_END();
            
            smart_str_appends(&json_payload, "]");
        }
        
        smart_str_appends(&json_payload, "}");
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appends(&json_payload, "]}]}]}");
    smart_str_0(&json_payload);
    
    /* Send HTTP request */
    king_telemetry_http_response_t response;
    int result = king_telemetry_http_post(endpoint_url, ZSTR_VAL(json_payload.s), &response);
    
    /* Clean up */
    smart_str_free(&json_payload);
    smart_str_free(&response.data);
    
    if (result == SUCCESS && response.status_code >= 200 && response.status_code < 300) {
        return SUCCESS;
    }
    
    return FAILURE;
}

int king_telemetry_export_logs_otlp(zval *logs)
{
    if (!king_telemetry_runtime_config.otel_exporter_endpoint[0]) {
        return FAILURE; /* No endpoint configured */
    }
    
    /* Build OTLP endpoint URL for logs */
    char endpoint_url[1024];
    snprintf(endpoint_url, sizeof(endpoint_url), "%s/v1/logs",
             king_telemetry_runtime_config.otel_exporter_endpoint);
    
    /* Convert logs to OTLP JSON format */
    smart_str json_payload = {0};
    smart_str_alloc(&json_payload, 4096, 0);
    
    /* Basic OTLP JSON structure for logs */
    smart_str_appends(&json_payload, "{\"resourceLogs\":[{\"resource\":{},\"scopeLogs\":[{\"scope\":{},\"logRecords\":[");
    
    /* Convert each log entry to OTLP format */
    zval *log_data;
    int first_log = 1;
    
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(logs), log_data) {
        if (!first_log) {
            smart_str_appends(&json_payload, ",");
        }
        first_log = 0;
        
        /* Extract log data */
        zval *message_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "message", sizeof("message")-1);
        zval *level_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "level", sizeof("level")-1);
        zval *timestamp_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "timestamp", sizeof("timestamp")-1);
        zval *attributes_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "attributes", sizeof("attributes")-1);
        
        if (!message_zval || !level_zval) continue;
        
        /* Build log record JSON */
        smart_str_appends(&json_payload, "{\"timeUnixNano\":\"");
        
        /* Add timestamp */
        if (timestamp_zval) {
            char timestamp_str[32];
            snprintf(timestamp_str, sizeof(timestamp_str), "%lld%09ld", 
                    (long long)Z_LVAL_P(timestamp_zval), 0L);
            smart_str_appends(&json_payload, timestamp_str);
        } else {
            /* Use current time if no timestamp provided */
            struct timespec ts;
            clock_gettime(CLOCK_REALTIME, &ts);
            char timestamp_str[32];
            snprintf(timestamp_str, sizeof(timestamp_str), "%lld%09ld", 
                    (long long)ts.tv_sec, ts.tv_nsec);
            smart_str_appends(&json_payload, timestamp_str);
        }
        
        smart_str_appends(&json_payload, "\",\"severityNumber\":");
        
        /* Map log level to OTLP severity number */
        int severity_number = 9; /* INFO */
        if (strcmp(Z_STRVAL_P(level_zval), "error") == 0) {
            severity_number = 17; /* ERROR */
        } else if (strcmp(Z_STRVAL_P(level_zval), "warn") == 0) {
            severity_number = 13; /* WARN */
        } else if (strcmp(Z_STRVAL_P(level_zval), "debug") == 0) {
            severity_number = 5; /* DEBUG */
        } else if (strcmp(Z_STRVAL_P(level_zval), "trace") == 0) {
            severity_number = 1; /* TRACE */
        }
        
        smart_str_append_long(&json_payload, severity_number);
        smart_str_appends(&json_payload, ",\"severityText\":\"");
        smart_str_append(&json_payload, Z_STR_P(level_zval));
        smart_str_appends(&json_payload, "\",\"body\":{\"stringValue\":\"");
        
        /* Escape JSON in message - use smart_str_append_escaped */
        smart_str escaped_msg = {0};
        smart_str_append_escaped(&escaped_msg, Z_STRVAL_P(message_zval), Z_STRLEN_P(message_zval));
        smart_str_append_smart_str(&json_payload, &escaped_msg);
        smart_str_free(&escaped_msg);
        
        smart_str_appends(&json_payload, "\"}");
        
        /* Add attributes if present */
        if (attributes_zval && Z_TYPE_P(attributes_zval) == IS_ARRAY) {
            smart_str_appends(&json_payload, ",\"attributes\":[");
            zval *attr_value;
            zend_string *attr_key;
            int first_attr = 1;
            
            ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(attributes_zval), attr_key, attr_value) {
                if (!first_attr) {
                    smart_str_appends(&json_payload, ",");
                }
                first_attr = 0;
                
                smart_str_appends(&json_payload, "{\"key\":\"");
                smart_str_append(&json_payload, attr_key);
                smart_str_appends(&json_payload, "\",\"value\":{\"stringValue\":\"");
                smart_str_append(&json_payload, Z_STR_P(attr_value));
                smart_str_appends(&json_payload, "\"}}");
            } ZEND_HASH_FOREACH_END();
            
            smart_str_appends(&json_payload, "]");
        }
        
        smart_str_appends(&json_payload, "}");
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appends(&json_payload, "]}]}]}");
    smart_str_0(&json_payload);
    
    /* Send HTTP request */
    king_telemetry_http_response_t response;
    int result = king_telemetry_http_post(endpoint_url, ZSTR_VAL(json_payload.s), &response);
    
    /* Clean up */
    smart_str_free(&json_payload);
    smart_str_free(&response.data);
    
    if (result == SUCCESS && response.status_code >= 200 && response.status_code < 300) {
        return SUCCESS;
    }
    
    return FAILURE;
}
