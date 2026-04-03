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
    batch->estimated_bytes = 0;
    batch->created_at = time(NULL);
    batch->next = NULL;
    
    return batch;
}

int king_telemetry_queue_batch(king_telemetry_batch_t *batch)
{
    uint32_t max_queue_size;
    uint64_t memory_limit;
    uint64_t queue_bytes_after_enqueue;

    if (!batch) return FAILURE;

    /* Always detach before enqueue so retries cannot retain stale links. */
    batch->next = NULL;
    king_telemetry_batch_refresh_estimated_bytes(batch);

    max_queue_size = king_telemetry_runtime_max_queue_size();
    if (max_queue_size > 0 && king_telemetry_queue_size >= max_queue_size) {
        king_telemetry_queue_drop_count++;
        return FAILURE;
    }

    memory_limit = king_telemetry_runtime_memory_byte_limit_internal();
    queue_bytes_after_enqueue = king_telemetry_saturated_add_u64(
        king_telemetry_queue_bytes,
        batch->estimated_bytes
    );
    if (memory_limit > 0 && queue_bytes_after_enqueue > memory_limit) {
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
    king_telemetry_queue_bytes = queue_bytes_after_enqueue;
    king_telemetry_note_runtime_watermarks();
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
    if (king_telemetry_queue_bytes >= batch->estimated_bytes) {
        king_telemetry_queue_bytes -= batch->estimated_bytes;
    } else {
        king_telemetry_queue_bytes = 0;
    }
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
    if (!king_telemetry_pending_buffers_initialized) {
        return 0;
    }

    return king_telemetry_buffer_has_entries(&king_telemetry_pending_spans)
        || king_telemetry_buffer_has_entries(&king_telemetry_pending_logs);
}

void king_telemetry_append_pending_signals(king_telemetry_batch_t *batch)
{
    if (batch == NULL) {
        return;
    }

    if (!king_telemetry_pending_buffers_initialized) {
        return;
    }

    king_telemetry_buffer_move_entries(&batch->spans, &king_telemetry_pending_spans);
    king_telemetry_buffer_move_entries(&batch->logs, &king_telemetry_pending_logs);
    king_telemetry_pending_bytes = 0;
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
        /*
         * Config re-initialization starts a fresh request/worker scope but must
         * not tear down the process-local retry queue that already carries
         * explicitly flushed batches.
         */
        king_telemetry_cleanup_scope_state();
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
    king_telemetry_cleanup_scope_state();
    
    /* Flush any remaining batches in queue */
    king_telemetry_batch_t *batch;
    while ((batch = king_telemetry_dequeue_batch()) != NULL) {
        king_telemetry_free_batch(batch);
    }

    king_telemetry_shutdown_libcurl_runtime();
    memset(&king_telemetry_runtime_config, 0, sizeof(king_telemetry_runtime_config));
}

char* king_telemetry_generate_trace_id(void)
{
    static char trace_id[33];
    /* Current runtime still uses a fixed placeholder trace ID. */
    strncpy(trace_id, "4bf92f3577b34da6a3ce929d0e0e4736", 32);
    trace_id[32] = '\0';
    return trace_id;
}

char* king_telemetry_generate_span_id(void)
{
    static char span_id[17];
    /* Current runtime still uses a fixed placeholder span ID. */
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
        return SUCCESS;
    }
    return FAILURE;
}

int king_telemetry_log_internal(king_telemetry_level_t level, const char *logger_name, const char *message, zval *attributes)
{
    king_log_record_t log;
    zval log_entry;

    if (!king_telemetry_capture_enabled()) {
        return SUCCESS;
    }

    king_telemetry_pending_buffers_ensure();

    memset(&log, 0, sizeof(log));
    
    log.timestamp = time(NULL);
    log.level = level;
    strncpy(log.logger_name, logger_name, sizeof(log.logger_name) - 1);
    strncpy(log.message, message, sizeof(log.message) - 1);
    
    if (king_current_span) {
        strncpy(log.trace_id, king_current_span->trace_id, 32);
        strncpy(log.span_id, king_current_span->span_id, 16);
    }

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

    if (!king_telemetry_pending_buffer_try_reserve_entry(&king_telemetry_pending_logs, &log_entry)) {
        zval_ptr_dtor(&log_entry);
        return SUCCESS;
    }

    add_next_index_zval(&king_telemetry_pending_logs, &log_entry);
    return SUCCESS;
}

/* --- PHP Entry Points --- */

#include "include/king_globals.h"

