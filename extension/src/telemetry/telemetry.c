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
static zval king_telemetry_pending_spans;
static zval king_telemetry_pending_logs;
static bool king_telemetry_pending_buffers_initialized = false;

#define KING_TELEMETRY_HTTP_TIMEOUT_FALLBACK_MS 10000U
#define KING_TELEMETRY_MAX_RESPONSE_SIZE (1024 * 1024) /* 1 MiB */

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

static void king_telemetry_pending_buffers_ensure(void)
{
    if (king_telemetry_pending_buffers_initialized) {
        return;
    }

    array_init(&king_telemetry_pending_spans);
    array_init(&king_telemetry_pending_logs);
    king_telemetry_pending_buffers_initialized = true;
}

static void king_telemetry_pending_buffers_destroy(void)
{
    if (!king_telemetry_pending_buffers_initialized) {
        return;
    }

    zval_ptr_dtor(&king_telemetry_pending_spans);
    zval_ptr_dtor(&king_telemetry_pending_logs);
    ZVAL_UNDEF(&king_telemetry_pending_spans);
    ZVAL_UNDEF(&king_telemetry_pending_logs);
    king_telemetry_pending_buffers_initialized = false;
}

static zend_bool king_telemetry_buffer_has_entries(zval *buffer)
{
    return buffer != NULL
        && Z_TYPE_P(buffer) == IS_ARRAY
        && zend_hash_num_elements(Z_ARRVAL_P(buffer)) > 0;
}

static void king_telemetry_buffer_move_entries(zval *target, zval *source)
{
    zval *entry;

    if (target == NULL || source == NULL) {
        return;
    }
    if (Z_TYPE_P(target) != IS_ARRAY || Z_TYPE_P(source) != IS_ARRAY) {
        return;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(source), entry) {
        Z_TRY_ADDREF_P(entry);
        add_next_index_zval(target, entry);
    } ZEND_HASH_FOREACH_END();

    zend_hash_clean(Z_ARRVAL_P(source));
}

static const char *king_telemetry_level_name(king_telemetry_level_t level)
{
    switch (level) {
        case KING_TELEMETRY_LEVEL_ERROR:
            return "error";
        case KING_TELEMETRY_LEVEL_WARN:
            return "warn";
        case KING_TELEMETRY_LEVEL_DEBUG:
            return "debug";
        case KING_TELEMETRY_LEVEL_TRACE:
            return "trace";
        case KING_TELEMETRY_LEVEL_INFO:
        case KING_TELEMETRY_LEVEL_NONE:
        default:
            return "info";
    }
}

static uint32_t king_telemetry_runtime_timeout_ms(void)
{
    if (king_telemetry_runtime_config.batch_timeout_ms > 0) {
        return king_telemetry_runtime_config.batch_timeout_ms;
    }
    if (king_open_telemetry_config.exporter_timeout_ms > 0
        && king_open_telemetry_config.exporter_timeout_ms <= UINT32_MAX) {
        return (uint32_t) king_open_telemetry_config.exporter_timeout_ms;
    }

    return KING_TELEMETRY_HTTP_TIMEOUT_FALLBACK_MS;
}

static void king_telemetry_json_append_zval_as_string(smart_str *buffer, zval *value)
{
    zend_string *str;

    if (buffer == NULL || value == NULL) {
        return;
    }

    str = zval_get_string(value);
    smart_str_append_escaped(buffer, ZSTR_VAL(str), ZSTR_LEN(str));
    zend_string_release(str);
}

static zend_bool king_telemetry_export_array_succeeded(int result, zval *payload)
{
    return result == SUCCESS
        && payload != NULL
        && Z_TYPE_P(payload) == IS_ARRAY
        && zend_hash_num_elements(Z_ARRVAL_P(payload)) > 0;
}

static zend_bool king_telemetry_batch_signal_complete(zval *payload, int result)
{
    if (!king_telemetry_export_array_succeeded(result, payload)) {
        return 0;
    }

    zend_hash_clean(Z_ARRVAL_P(payload));
    return 1;
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

static king_telemetry_level_t king_telemetry_level_from_string(const char *level_str)
{
    if (level_str == NULL) {
        return KING_TELEMETRY_LEVEL_INFO;
    }

    if (strcmp(level_str, "error") == 0) {
        return KING_TELEMETRY_LEVEL_ERROR;
    }
    if (strcmp(level_str, "warn") == 0 || strcmp(level_str, "warning") == 0) {
        return KING_TELEMETRY_LEVEL_WARN;
    }
    if (strcmp(level_str, "debug") == 0) {
        return KING_TELEMETRY_LEVEL_DEBUG;
    }
    if (strcmp(level_str, "trace") == 0) {
        return KING_TELEMETRY_LEVEL_TRACE;
    }

    return KING_TELEMETRY_LEVEL_INFO;
}

static zend_long king_telemetry_span_kind_to_otlp_kind(zval *kind_zval)
{
    zend_long kind;

    if (kind_zval == NULL) {
        return 1;
    }

    kind = zval_get_long(kind_zval);
    switch ((king_span_kind_t) kind) {
        case KING_SPAN_KIND_SERVER:
            return 2;
        case KING_SPAN_KIND_CLIENT:
            return 3;
        case KING_SPAN_KIND_PRODUCER:
            return 4;
        case KING_SPAN_KIND_CONSUMER:
            return 5;
        case KING_SPAN_KIND_INTERNAL:
        default:
            return 1;
    }
}

static void king_telemetry_json_append_attributes(
    smart_str *json_payload,
    zval *attributes_zval)
{
    zval *attr_value;
    zend_string *attr_key;
    int first_attr = 1;

    if (json_payload == NULL
        || attributes_zval == NULL
        || Z_TYPE_P(attributes_zval) != IS_ARRAY) {
        return;
    }

    smart_str_appends(json_payload, ",\"attributes\":[");

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(attributes_zval), attr_key, attr_value) {
        if (attr_key == NULL) {
            continue;
        }

        if (!first_attr) {
            smart_str_appends(json_payload, ",");
        }
        first_attr = 0;

        smart_str_appends(json_payload, "{\"key\":\"");
        smart_str_append_escaped(json_payload, ZSTR_VAL(attr_key), ZSTR_LEN(attr_key));
        smart_str_appends(json_payload, "\",\"value\":{\"stringValue\":\"");
        king_telemetry_json_append_zval_as_string(json_payload, attr_value);
        smart_str_appends(json_payload, "\"}}");
    } ZEND_HASH_FOREACH_END();

    smart_str_appends(json_payload, "]");
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

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "exporter_timeout_ms",
        sizeof("exporter_timeout_ms") - 1
    );
    if (value != NULL) {
        zend_long timeout_ms = zval_get_long(value);

        if (timeout_ms > 0) {
            config->batch_timeout_ms = timeout_ms > UINT32_MAX
                ? UINT32_MAX
                : (uint32_t) timeout_ms;
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

zend_bool king_telemetry_has_pending_signals(void)
{
    king_telemetry_pending_buffers_ensure();

    return king_telemetry_buffer_has_entries(&king_telemetry_pending_spans)
        || king_telemetry_buffer_has_entries(&king_telemetry_pending_logs);
}

void king_telemetry_append_pending_signals(king_telemetry_batch_t *batch)
{
    if (batch == NULL) {
        return;
    }

    king_telemetry_pending_buffers_ensure();
    king_telemetry_buffer_move_entries(&batch->spans, &king_telemetry_pending_spans);
    king_telemetry_buffer_move_entries(&batch->logs, &king_telemetry_pending_logs);
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
                zend_hash_clean(Z_ARRVAL(batch->metrics));
                total_success++;
            } else {
                king_telemetry_export_failure_count++;
            }
        }
        
        /* Export spans if present */
        if (zend_hash_num_elements(Z_ARRVAL(batch->spans)) > 0) {
            if (king_telemetry_export_spans_otlp(&batch->spans) == SUCCESS) {
                zend_hash_clean(Z_ARRVAL(batch->spans));
                total_success++;
            } else {
                king_telemetry_export_failure_count++;
            }
        }
        
        /* Export logs if present */
        if (zend_hash_num_elements(Z_ARRVAL(batch->logs)) > 0) {
            if (king_telemetry_export_logs_otlp(&batch->logs) == SUCCESS) {
                zend_hash_clean(Z_ARRVAL(batch->logs));
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
        king_telemetry_pending_buffers_ensure();
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

    king_telemetry_pending_buffers_destroy();
    memset(&king_telemetry_runtime_config, 0, sizeof(king_telemetry_runtime_config));
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
        king_telemetry_pending_buffers_ensure();
        return SUCCESS;
    }
    return FAILURE;
}

int king_telemetry_log_internal(king_telemetry_level_t level, const char *logger_name, const char *message, zval *attributes)
{
    king_log_record_t log;
    zval log_entry;
    memset(&log, 0, sizeof(log));
    
    log.timestamp = time(NULL);
    log.level = level;
    strncpy(log.logger_name, logger_name, sizeof(log.logger_name) - 1);
    strncpy(log.message, message, sizeof(log.message) - 1);
    
    if (king_current_span) {
        strncpy(log.trace_id, king_current_span->trace_id, 32);
        strncpy(log.span_id, king_current_span->span_id, 16);
    }

    king_telemetry_pending_buffers_ensure();
    array_init(&log_entry);
    add_assoc_string(&log_entry, "logger_name", log.logger_name);
    add_assoc_string(&log_entry, "message", log.message);
    add_assoc_string(&log_entry, "level", (char *) king_telemetry_level_name(level));
    add_assoc_long(&log_entry, "timestamp", (zend_long) log.timestamp);
    if (log.trace_id[0] != '\0') {
        add_assoc_string(&log_entry, "trace_id", log.trace_id);
    }
    if (log.span_id[0] != '\0') {
        add_assoc_string(&log_entry, "span_id", log.span_id);
    }
    if (attributes != NULL && Z_TYPE_P(attributes) == IS_ARRAY) {
        zval attrs_copy;
        ZVAL_COPY(&attrs_copy, attributes);
        add_assoc_zval(&log_entry, "attributes", &attrs_copy);
    }

    add_next_index_zval(&king_telemetry_pending_logs, &log_entry);
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
        zval span_entry;

        if (final_attrs != NULL && Z_TYPE_P(final_attrs) == IS_ARRAY) {
            zend_string *attr_key;
            zval *attr_value;

            ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(final_attrs), attr_key, attr_value) {
                if (attr_key == NULL) {
                    continue;
                }
                Z_TRY_ADDREF_P(attr_value);
                zend_hash_update(Z_ARRVAL(king_current_span->attributes), attr_key, attr_value);
            } ZEND_HASH_FOREACH_END();
        }

        king_telemetry_finish_span(king_current_span);
        array_init(&span_entry);
        add_assoc_string(&span_entry, "name", king_current_span->operation_name);
        add_assoc_string(&span_entry, "trace_id", king_current_span->trace_id);
        add_assoc_string(&span_entry, "span_id", king_current_span->span_id);
        if (king_current_span->parent_span_id[0] != '\0') {
            add_assoc_string(&span_entry, "parent_span_id", king_current_span->parent_span_id);
        }
        add_assoc_long(&span_entry, "start_time", (zend_long) king_current_span->start_time);
        add_assoc_long(&span_entry, "end_time", (zend_long) king_current_span->end_time);
        add_assoc_long(&span_entry, "kind", (zend_long) king_current_span->span_kind);
        if (Z_TYPE(king_current_span->attributes) == IS_ARRAY) {
            zval attrs_copy;
            ZVAL_COPY(&attrs_copy, &king_current_span->attributes);
            add_assoc_zval(&span_entry, "attributes", &attrs_copy);
        }
        king_telemetry_pending_buffers_ensure();
        add_next_index_zval(&king_telemetry_pending_spans, &span_entry);
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

    king_telemetry_log_internal(king_telemetry_level_from_string(level_str), "php-king", msg, attrs);
    RETURN_TRUE;
}

/* --- Internal Export Functions --- */

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
    int result = FAILURE;

    if (curl_global_init(CURL_GLOBAL_DEFAULT) != CURLE_OK) {
        return FAILURE;
    }

    curl = curl_easy_init();
    if (!curl) {
        curl_global_cleanup();
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
    curl_easy_setopt(curl, CURLOPT_TIMEOUT_MS, (long) king_telemetry_runtime_timeout_ms());
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
        goto cleanup;
    }
    
    /* Null-terminate the response data */
    smart_str_0(&response->data);
    result = SUCCESS;

cleanup:
    curl_global_cleanup();
    return result;
}

int king_telemetry_export_batch(void)
{
    int metrics_result = SUCCESS;
    int spans_result = SUCCESS;
    int logs_result = SUCCESS;
    zend_bool attempted = 0;
    zend_bool failed = 0;

    if (!king_telemetry_system_initialized || !king_telemetry_runtime_config.enabled) {
        return FAILURE;
    }
    
    king_telemetry_batch_t *batch = king_telemetry_dequeue_batch();
    if (!batch) {
        return SUCCESS; /* No batches to export */
    }
    
    /* Export metrics via OTLP */
    if (zend_hash_num_elements(Z_ARRVAL(batch->metrics)) > 0) {
        attempted = 1;
        metrics_result = king_telemetry_export_metrics_otlp(&batch->metrics);
        if (!king_telemetry_batch_signal_complete(&batch->metrics, metrics_result)) {
            failed = 1;
        }
    }
    
    /* Export spans via OTLP */
    if (zend_hash_num_elements(Z_ARRVAL(batch->spans)) > 0) {
        attempted = 1;
        spans_result = king_telemetry_export_spans_otlp(&batch->spans);
        if (!king_telemetry_batch_signal_complete(&batch->spans, spans_result)) {
            failed = 1;
        }
    }
    
    /* Export logs via OTLP */
    if (zend_hash_num_elements(Z_ARRVAL(batch->logs)) > 0) {
        attempted = 1;
        logs_result = king_telemetry_export_logs_otlp(&batch->logs);
        if (!king_telemetry_batch_signal_complete(&batch->logs, logs_result)) {
            failed = 1;
        }
    }

    if (!attempted) {
        king_telemetry_free_batch(batch);
        return SUCCESS;
    }

    if (!failed) {
        king_telemetry_export_success_count++;
    } else {
        king_telemetry_export_failure_count++;
        /* Re-queue only the signals that still failed. */
        if (king_telemetry_queue_batch(batch) == SUCCESS) {
            batch = NULL; /* Don't free, it's back in queue */
        }
    }
    
    if (batch) {
        king_telemetry_free_batch(batch);
    }
    
    if (failed) {
        return FAILURE;
    }

    (void) metrics_result;
    (void) spans_result;
    (void) logs_result;
    return SUCCESS;
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
        zval *name_zval;
        zval *trace_id_zval;
        zval *span_id_zval;
        zval *parent_span_id_zval;
        zval *start_time_zval;
        zval *end_time_zval;
        zval *kind_zval;
        zval *attributes_zval;
        char start_time_str[32];
        char end_time_str[32];

        if (Z_TYPE_P(span_data) != IS_ARRAY) {
            continue;
        }

        name_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "name", sizeof("name") - 1);
        trace_id_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "trace_id", sizeof("trace_id") - 1);
        span_id_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "span_id", sizeof("span_id") - 1);
        parent_span_id_zval = zend_hash_str_find(
            Z_ARRVAL_P(span_data),
            "parent_span_id",
            sizeof("parent_span_id") - 1
        );
        start_time_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "start_time", sizeof("start_time") - 1);
        end_time_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "end_time", sizeof("end_time") - 1);
        kind_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "kind", sizeof("kind") - 1);
        attributes_zval = zend_hash_str_find(Z_ARRVAL_P(span_data), "attributes", sizeof("attributes") - 1);

        if (name_zval == NULL
            || trace_id_zval == NULL
            || span_id_zval == NULL
            || start_time_zval == NULL
            || end_time_zval == NULL
            || Z_TYPE_P(name_zval) != IS_STRING
            || Z_TYPE_P(trace_id_zval) != IS_STRING
            || Z_TYPE_P(span_id_zval) != IS_STRING
            || Z_STRLEN_P(name_zval) == 0
            || Z_STRLEN_P(trace_id_zval) == 0
            || Z_STRLEN_P(span_id_zval) == 0) {
            continue;
        }

        if (!first_span) {
            smart_str_appends(&json_payload, ",");
        }
        first_span = 0;

        snprintf(
            start_time_str,
            sizeof(start_time_str),
            "%lld%09ld",
            (long long) zval_get_long(start_time_zval),
            0L
        );
        snprintf(
            end_time_str,
            sizeof(end_time_str),
            "%lld%09ld",
            (long long) zval_get_long(end_time_zval),
            0L
        );

        smart_str_appends(&json_payload, "{\"traceId\":\"");
        smart_str_append_escaped(&json_payload, Z_STRVAL_P(trace_id_zval), Z_STRLEN_P(trace_id_zval));
        smart_str_appends(&json_payload, "\",\"spanId\":\"");
        smart_str_append_escaped(&json_payload, Z_STRVAL_P(span_id_zval), Z_STRLEN_P(span_id_zval));
        smart_str_appends(&json_payload, "\",\"name\":\"");
        smart_str_append_escaped(&json_payload, Z_STRVAL_P(name_zval), Z_STRLEN_P(name_zval));
        smart_str_appends(&json_payload, "\",\"startTimeUnixNano\":\"");
        smart_str_appends(&json_payload, start_time_str);
        smart_str_appends(&json_payload, "\",\"endTimeUnixNano\":\"");
        smart_str_appends(&json_payload, end_time_str);
        smart_str_appends(&json_payload, "\",\"kind\":");
        smart_str_append_long(&json_payload, king_telemetry_span_kind_to_otlp_kind(kind_zval));

        if (parent_span_id_zval != NULL
            && Z_TYPE_P(parent_span_id_zval) == IS_STRING
            && Z_STRLEN_P(parent_span_id_zval) > 0) {
            smart_str_appends(&json_payload, ",\"parentSpanId\":\"");
            smart_str_append_escaped(
                &json_payload,
                Z_STRVAL_P(parent_span_id_zval),
                Z_STRLEN_P(parent_span_id_zval)
            );
            smart_str_appends(&json_payload, "\"");
        }

        king_telemetry_json_append_attributes(&json_payload, attributes_zval);
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
        zval *message_zval;
        zval *level_zval;
        zval *timestamp_zval;
        zval *attributes_zval;
        zval *trace_id_zval;
        zval *span_id_zval;
        char timestamp_str[32];
        const char *level_name = "info";
        int severity_number = 9; /* INFO */

        if (Z_TYPE_P(log_data) != IS_ARRAY) {
            continue;
        }

        message_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "message", sizeof("message") - 1);
        level_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "level", sizeof("level") - 1);
        timestamp_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "timestamp", sizeof("timestamp") - 1);
        attributes_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "attributes", sizeof("attributes") - 1);
        trace_id_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "trace_id", sizeof("trace_id") - 1);
        span_id_zval = zend_hash_str_find(Z_ARRVAL_P(log_data), "span_id", sizeof("span_id") - 1);

        if (message_zval == NULL || level_zval == NULL) {
            continue;
        }

        if (Z_TYPE_P(level_zval) == IS_STRING && Z_STRLEN_P(level_zval) > 0) {
            level_name = Z_STRVAL_P(level_zval);
        }

        if (strcmp(level_name, "error") == 0) {
            severity_number = 17;
        } else if (strcmp(level_name, "warn") == 0) {
            severity_number = 13;
        } else if (strcmp(level_name, "debug") == 0) {
            severity_number = 5;
        } else if (strcmp(level_name, "trace") == 0) {
            severity_number = 1;
        }

        if (!first_log) {
            smart_str_appends(&json_payload, ",");
        }
        first_log = 0;

        smart_str_appends(&json_payload, "{\"timeUnixNano\":\"");

        if (timestamp_zval != NULL) {
            snprintf(
                timestamp_str,
                sizeof(timestamp_str),
                "%lld%09ld",
                (long long) zval_get_long(timestamp_zval),
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
        smart_str_appends(&json_payload, "\",\"severityNumber\":");
        smart_str_append_long(&json_payload, severity_number);
        smart_str_appends(&json_payload, ",\"severityText\":\"");
        smart_str_append_escaped(&json_payload, level_name, strlen(level_name));
        smart_str_appends(&json_payload, "\",\"body\":{\"stringValue\":\"");
        king_telemetry_json_append_zval_as_string(&json_payload, message_zval);
        smart_str_appends(&json_payload, "\"}");

        if (trace_id_zval != NULL
            && Z_TYPE_P(trace_id_zval) == IS_STRING
            && Z_STRLEN_P(trace_id_zval) > 0) {
            smart_str_appends(&json_payload, ",\"traceId\":\"");
            smart_str_append_escaped(
                &json_payload,
                Z_STRVAL_P(trace_id_zval),
                Z_STRLEN_P(trace_id_zval)
            );
            smart_str_appends(&json_payload, "\"");
        }

        if (span_id_zval != NULL
            && Z_TYPE_P(span_id_zval) == IS_STRING
            && Z_STRLEN_P(span_id_zval) > 0) {
            smart_str_appends(&json_payload, ",\"spanId\":\"");
            smart_str_append_escaped(
                &json_payload,
                Z_STRVAL_P(span_id_zval),
                Z_STRLEN_P(span_id_zval)
            );
            smart_str_appends(&json_payload, "\"");
        }

        king_telemetry_json_append_attributes(&json_payload, attributes_zval);
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
