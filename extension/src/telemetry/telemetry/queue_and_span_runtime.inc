static zend_result king_telemetry_fill_random_bytes(uint8_t *target, size_t target_len)
{
    int fd;
    ssize_t got;

    fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (fd < 0) {
        return FAILURE;
    }

    got = read(fd, target, target_len);
    close(fd);

    return got == (ssize_t) target_len ? SUCCESS : FAILURE;
}

static void king_telemetry_hex_encode(char *target, size_t target_len, const uint8_t *source, size_t source_len)
{
    static const char hex[] = "0123456789abcdef";
    size_t i;

    if (target == NULL || target_len == 0) {
        return;
    }

    if (source == NULL || (source_len * 2) + 1 > target_len) {
        target[0] = '\0';
        return;
    }

    for (i = 0; i < source_len; ++i) {
        target[i * 2] = hex[(source[i] >> 4) & 0x0f];
        target[(i * 2) + 1] = hex[source[i] & 0x0f];
    }
    target[source_len * 2] = '\0';
}

static const char *king_telemetry_runtime_sampler_type(void)
{
    if (king_telemetry_runtime_config.traces_sampler_type[0] != '\0') {
        return king_telemetry_runtime_config.traces_sampler_type;
    }

    if (king_open_telemetry_config.traces_sampler_type != NULL
        && king_open_telemetry_config.traces_sampler_type[0] != '\0') {
        return king_open_telemetry_config.traces_sampler_type;
    }

    return "parent_based_probability";
}

static double king_telemetry_runtime_sampler_ratio(void)
{
    double ratio = 1.0;

    if (king_telemetry_runtime_config.traces_sampler_ratio_configured) {
        ratio = king_telemetry_runtime_config.traces_sampler_ratio;
    } else if (king_open_telemetry_config.traces_sampler_ratio >= 0.0
        && king_open_telemetry_config.traces_sampler_ratio <= 1.0) {
        ratio = king_open_telemetry_config.traces_sampler_ratio;
    }

    if (ratio < 0.0) {
        return 0.0;
    }
    if (ratio > 1.0) {
        return 1.0;
    }

    return ratio;
}

static zend_bool king_telemetry_sample_ratio_allows_root(double ratio)
{
    uint64_t random_value = 0;
    double sample;

    if (ratio <= 0.0) {
        return 0;
    }
    if (ratio >= 1.0) {
        return 1;
    }

    if (king_telemetry_fill_random_bytes((uint8_t *) &random_value, sizeof(random_value)) != SUCCESS) {
        struct timespec ts;
        pid_t pid = getpid();

        clock_gettime(CLOCK_REALTIME, &ts);
        random_value = ((uint64_t) ts.tv_sec << 32)
            ^ (uint64_t) ts.tv_nsec
            ^ (uint64_t) pid;
    }

    sample = (double) (random_value >> 11) / 9007199254740992.0;
    return sample < ratio;
}

static uint8_t king_telemetry_choose_root_trace_flags(void)
{
    const char *sampler_type = king_telemetry_runtime_sampler_type();

    if (strcmp(sampler_type, "always_off") == 0) {
        return 0x00;
    }

    if (strcmp(sampler_type, "always_on") == 0) {
        return 0x01;
    }

    return king_telemetry_sample_ratio_allows_root(
        king_telemetry_runtime_sampler_ratio()
    ) ? 0x01 : 0x00;
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

void king_telemetry_assign_batch_identity(king_telemetry_batch_t *batch)
{
    char *generated_id;

    if (batch == NULL) {
        return;
    }

    generated_id = king_telemetry_generate_trace_id();
    if (generated_id == NULL || generated_id[0] == '\0') {
        batch->batch_id[0] = '\0';
        return;
    }

    memcpy(batch->batch_id, generated_id, sizeof(batch->batch_id) - 1);
    batch->batch_id[sizeof(batch->batch_id) - 1] = '\0';
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
    king_telemetry_assign_batch_identity(batch);
    batch->next = NULL;
    
    return batch;
}

int king_telemetry_queue_batch(king_telemetry_batch_t *batch)
{
    return king_telemetry_queue_batch_internal(batch, 1);
}

void king_telemetry_cleanup_export_queue(void)
{
    king_telemetry_free_export_queue_in_memory();
    (void) king_telemetry_persist_queue_state();
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
    uint32_t attempts;
    int total_processed = 0;
    int total_success = 0;

    attempts = king_telemetry_queue_size;
    while (attempts-- > 0 && king_telemetry_peek_batch() != NULL) {
        total_processed++;
        if (king_telemetry_export_batch() == SUCCESS) {
            total_success++;
        }
    }

    (void) total_success;
    return total_processed;
}

int king_telemetry_init_system(king_telemetry_config_t *config)
{
    zend_bool should_rehydrate = 0;

    if (config) {
        /*
         * Config re-initialization starts a fresh request/worker scope but must
         * not tear down the process-local retry queue that already carries
         * explicitly flushed batches.
         */
        should_rehydrate = king_telemetry_system_initialized ? 0 : 1;
        king_telemetry_cleanup_scope_state();
        memcpy(&king_telemetry_runtime_config, config, sizeof(king_telemetry_config_t));
        if (king_telemetry_runtime_config.max_queue_size == 0) {
            king_telemetry_runtime_config.max_queue_size = king_telemetry_default_max_queue_size();
        }
        king_telemetry_pending_buffers_ensure();
        if (should_rehydrate) {
            (void) king_telemetry_rehydrate_queue_state();
        }
        king_telemetry_system_initialized = true;
    }
    return SUCCESS;
}

void king_telemetry_shutdown_system(void)
{
    king_telemetry_system_initialized = false;
    king_telemetry_cleanup_scope_state();

    /*
     * A configured durable queue path already carries the persisted retry
     * queue. Free the in-memory list without mutating the persisted state so a
     * fresh process can replay it.
     */
    king_telemetry_free_export_queue_in_memory();

    king_telemetry_shutdown_libcurl_runtime();
    memset(&king_telemetry_runtime_config, 0, sizeof(king_telemetry_runtime_config));
}

char* king_telemetry_generate_trace_id(void)
{
    static char trace_id[33];
    uint8_t random_bytes[16];
    struct timespec ts;
    pid_t pid;

    if (king_telemetry_fill_random_bytes(random_bytes, sizeof(random_bytes)) == SUCCESS) {
        king_telemetry_hex_encode(trace_id, sizeof(trace_id), random_bytes, sizeof(random_bytes));
        return trace_id;
    }

    clock_gettime(CLOCK_REALTIME, &ts);
    pid = getpid();
    snprintf(
        trace_id,
        sizeof(trace_id),
        "%016llx%08x%08lx",
        (unsigned long long) ts.tv_sec,
        (unsigned int) pid,
        (unsigned long) (ts.tv_nsec & 0xffffffffUL)
    );

    return trace_id;
}

char* king_telemetry_generate_span_id(void)
{
    static char span_id[17];
    uint8_t random_bytes[8];
    struct timespec ts;

    if (king_telemetry_fill_random_bytes(random_bytes, sizeof(random_bytes)) == SUCCESS) {
        king_telemetry_hex_encode(span_id, sizeof(span_id), random_bytes, sizeof(random_bytes));
        return span_id;
    }

    clock_gettime(CLOCK_REALTIME, &ts);
    snprintf(
        span_id,
        sizeof(span_id),
        "%08llx%08lx",
        (unsigned long long) ts.tv_sec,
        (unsigned long) (ts.tv_nsec & 0xffffffffUL)
    );

    return span_id;
}

king_trace_context_t* king_telemetry_create_span(const char *operation_name, king_span_kind_t span_kind, const char *parent_span_id)
{
    king_trace_context_t *span = emalloc(sizeof(king_trace_context_t));
    memset(span, 0, sizeof(king_trace_context_t));
    
    strncpy(span->operation_name, operation_name, sizeof(span->operation_name) - 1);
    span->span_kind = span_kind;

    if (king_current_span != NULL) {
        strncpy(span->trace_id, king_current_span->trace_id, sizeof(span->trace_id) - 1);
        span->trace_flags = king_current_span->trace_flags;
        if (king_current_span->trace_state[0] != '\0') {
            strncpy(span->trace_state, king_current_span->trace_state, sizeof(span->trace_state) - 1);
        }
        span->parent = king_current_span;
    } else if (king_telemetry_incoming_parent_context.active) {
        strncpy(
            span->trace_id,
            king_telemetry_incoming_parent_context.trace_id,
            sizeof(span->trace_id) - 1
        );
        span->trace_flags = king_telemetry_incoming_parent_context.trace_flags;
        if (king_telemetry_incoming_parent_context.trace_state[0] != '\0') {
            strncpy(
                span->trace_state,
                king_telemetry_incoming_parent_context.trace_state,
                sizeof(span->trace_state) - 1
            );
        }
        span->parent = NULL;
    } else {
        strncpy(span->trace_id, king_telemetry_generate_trace_id(), sizeof(span->trace_id) - 1);
        span->trace_flags = king_telemetry_choose_root_trace_flags();
        span->parent = NULL;
    }

    strncpy(span->span_id, king_telemetry_generate_span_id(), sizeof(span->span_id) - 1);

    if (parent_span_id != NULL && parent_span_id[0] != '\0') {
        strncpy(span->parent_span_id, parent_span_id, 16);
    } else if (king_current_span != NULL) {
        strncpy(span->parent_span_id, king_current_span->span_id, sizeof(span->parent_span_id) - 1);
    } else if (king_telemetry_incoming_parent_context.active
        && king_telemetry_incoming_parent_context.parent_span_id[0] != '\0') {
        strncpy(
            span->parent_span_id,
            king_telemetry_incoming_parent_context.parent_span_id,
            sizeof(span->parent_span_id) - 1
        );
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
