static uint64_t king_telemetry_current_memory_bytes_internal(void)
{
    return king_telemetry_saturated_add_u64(
        king_telemetry_queue_bytes,
        king_telemetry_pending_bytes
    );
}

static uint64_t king_telemetry_estimate_zval_bytes(zval *value);
static zend_bool king_telemetry_buffer_has_entries(zval *buffer);

static void king_telemetry_note_runtime_watermarks(void)
{
    uint64_t current_memory_bytes = king_telemetry_current_memory_bytes_internal();

    if (king_telemetry_queue_size > king_telemetry_queue_high_watermark) {
        king_telemetry_queue_high_watermark = king_telemetry_queue_size;
    }

    if (king_telemetry_queue_bytes > king_telemetry_queue_high_water_bytes) {
        king_telemetry_queue_high_water_bytes = king_telemetry_queue_bytes;
    }

    if (current_memory_bytes > king_telemetry_memory_high_water_bytes) {
        king_telemetry_memory_high_water_bytes = current_memory_bytes;
    }
}

static void king_telemetry_refresh_pending_accounting(void)
{
    if (!king_telemetry_pending_buffers_initialized) {
        king_telemetry_pending_bytes = 0;
        return;
    }

    king_telemetry_pending_bytes = 0;

    if (king_telemetry_buffer_has_entries(&king_telemetry_pending_spans)) {
        king_telemetry_pending_bytes = king_telemetry_saturated_add_u64(
            king_telemetry_pending_bytes,
            king_telemetry_estimate_zval_bytes(&king_telemetry_pending_spans)
        );
    }

    if (king_telemetry_buffer_has_entries(&king_telemetry_pending_logs)) {
        king_telemetry_pending_bytes = king_telemetry_saturated_add_u64(
            king_telemetry_pending_bytes,
            king_telemetry_estimate_zval_bytes(&king_telemetry_pending_logs)
        );
    }

    king_telemetry_note_runtime_watermarks();
}

uint32_t king_telemetry_get_pending_entry_limit(void)
{
    return king_telemetry_runtime_max_queue_size();
}

uint64_t king_telemetry_get_queue_bytes(void)
{
    return king_telemetry_queue_bytes;
}

uint64_t king_telemetry_get_pending_bytes(void)
{
    return king_telemetry_pending_bytes;
}

uint64_t king_telemetry_get_memory_bytes(void)
{
    return king_telemetry_current_memory_bytes_internal();
}

uint64_t king_telemetry_get_memory_byte_limit(void)
{
    return king_telemetry_runtime_memory_byte_limit_internal();
}

uint32_t king_telemetry_get_queue_high_watermark(void)
{
    return king_telemetry_queue_high_watermark;
}

uint64_t king_telemetry_get_queue_high_water_bytes(void)
{
    return king_telemetry_queue_high_water_bytes;
}

uint64_t king_telemetry_get_memory_high_water_bytes(void)
{
    return king_telemetry_memory_high_water_bytes;
}

uint32_t king_telemetry_get_retry_requeue_count(void)
{
    return king_telemetry_retry_requeue_count;
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

static zend_bool king_telemetry_capture_enabled(void);
static zend_bool king_telemetry_pending_buffer_try_reserve_entry(zval *buffer, zval *entry);

static zend_bool king_telemetry_span_is_sampled(king_trace_context_t *span)
{
    return span != NULL && (span->trace_flags & 0x01u) != 0;
}

static void king_telemetry_pending_buffers_destroy(void)
{
    if (!king_telemetry_pending_buffers_initialized) {
        king_telemetry_pending_bytes = 0;
        return;
    }

    zval_ptr_dtor(&king_telemetry_pending_spans);
    zval_ptr_dtor(&king_telemetry_pending_logs);
    ZVAL_UNDEF(&king_telemetry_pending_spans);
    ZVAL_UNDEF(&king_telemetry_pending_logs);
    king_telemetry_pending_buffers_initialized = false;
    king_telemetry_pending_bytes = 0;
}

static void king_telemetry_reset_active_span(void)
{
    king_trace_context_t *span = king_current_span;
    king_trace_context_t *parent;

    while (span != NULL) {
        parent = span->parent;
        span->parent = NULL;
        king_telemetry_span_free(span);
        span = parent;
    }
    king_current_span = NULL;
}

void king_telemetry_clear_incoming_parent_context(void)
{
    memset(
        &king_telemetry_incoming_parent_context,
        0,
        sizeof(king_telemetry_incoming_parent_context)
    );
}

zend_result king_telemetry_set_incoming_parent_context(
    const char *trace_id,
    const char *parent_span_id,
    uint8_t trace_flags,
    const char *trace_state
)
{
    if (trace_id == NULL
        || parent_span_id == NULL
        || strlen(trace_id) != 32
        || strlen(parent_span_id) != 16) {
        return FAILURE;
    }

    king_telemetry_clear_incoming_parent_context();
    strncpy(
        king_telemetry_incoming_parent_context.trace_id,
        trace_id,
        sizeof(king_telemetry_incoming_parent_context.trace_id) - 1
    );
    strncpy(
        king_telemetry_incoming_parent_context.parent_span_id,
        parent_span_id,
        sizeof(king_telemetry_incoming_parent_context.parent_span_id) - 1
    );
    king_telemetry_incoming_parent_context.trace_flags = trace_flags;

    if (trace_state != NULL && trace_state[0] != '\0') {
        if (strlen(trace_state)
            >= sizeof(king_telemetry_incoming_parent_context.trace_state)) {
            king_telemetry_clear_incoming_parent_context();
            return FAILURE;
        }

        strncpy(
            king_telemetry_incoming_parent_context.trace_state,
            trace_state,
            sizeof(king_telemetry_incoming_parent_context.trace_state) - 1
        );
    }

    king_telemetry_incoming_parent_context.active = 1;
    return SUCCESS;
}

zend_result king_telemetry_set_incoming_parent_context_from_array(zval *context)
{
    zval *trace_id;
    zval *parent_span_id;
    zval *trace_flags;
    zval *trace_state;
    zend_long parsed_trace_flags = 1;

    if (context == NULL || Z_TYPE_P(context) != IS_ARRAY) {
        return FAILURE;
    }

    trace_id = zend_hash_str_find(
        Z_ARRVAL_P(context),
        "trace_id",
        sizeof("trace_id") - 1
    );
    parent_span_id = zend_hash_str_find(
        Z_ARRVAL_P(context),
        "parent_span_id",
        sizeof("parent_span_id") - 1
    );
    trace_flags = zend_hash_str_find(
        Z_ARRVAL_P(context),
        "trace_flags",
        sizeof("trace_flags") - 1
    );
    trace_state = zend_hash_str_find(
        Z_ARRVAL_P(context),
        "trace_state",
        sizeof("trace_state") - 1
    );

    if (trace_flags != NULL) {
        if (Z_TYPE_P(trace_flags) == IS_LONG) {
            parsed_trace_flags = Z_LVAL_P(trace_flags);
        } else if (Z_TYPE_P(trace_flags) == IS_STRING && Z_STRLEN_P(trace_flags) > 0) {
            parsed_trace_flags = ZEND_STRTOL(Z_STRVAL_P(trace_flags), NULL, 10);
        }
    }

    if (
        trace_id == NULL
        || Z_TYPE_P(trace_id) != IS_STRING
        || parent_span_id == NULL
        || Z_TYPE_P(parent_span_id) != IS_STRING
    ) {
        return FAILURE;
    }

    return king_telemetry_set_incoming_parent_context(
        Z_STRVAL_P(trace_id),
        Z_STRVAL_P(parent_span_id),
        (uint8_t) (parsed_trace_flags & 0xff),
        (trace_state != NULL && Z_TYPE_P(trace_state) == IS_STRING)
            ? Z_STRVAL_P(trace_state)
            : NULL
    );
}

void king_telemetry_cleanup_scope_state(void)
{
    king_telemetry_reset_active_span();
    king_telemetry_clear_incoming_parent_context();
    king_telemetry_pending_buffers_destroy();
}

static zend_bool king_telemetry_buffer_has_entries(zval *buffer)
{
    return buffer != NULL
        && Z_TYPE_P(buffer) == IS_ARRAY
        && zend_hash_num_elements(Z_ARRVAL_P(buffer)) > 0;
}

static uint32_t king_telemetry_buffer_entry_count(zval *buffer)
{
    if (buffer == NULL || Z_TYPE_P(buffer) != IS_ARRAY) {
        return 0;
    }

    return (uint32_t) zend_hash_num_elements(Z_ARRVAL_P(buffer));
}

uint32_t king_telemetry_get_pending_span_count(void)
{
    if (!king_telemetry_pending_buffers_initialized) {
        return 0;
    }

    return king_telemetry_buffer_entry_count(&king_telemetry_pending_spans);
}

uint32_t king_telemetry_get_pending_log_count(void)
{
    if (!king_telemetry_pending_buffers_initialized) {
        return 0;
    }

    return king_telemetry_buffer_entry_count(&king_telemetry_pending_logs);
}

static void king_telemetry_span_merge_attributes(king_trace_context_t *span, zval *attributes)
{
    zend_string *attr_key;
    zval *attr_value;

    if (span == NULL || attributes == NULL || Z_TYPE_P(attributes) != IS_ARRAY) {
        return;
    }

    SEPARATE_ARRAY(&span->attributes);

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(attributes), attr_key, attr_value) {
        if (attr_key == NULL) {
            continue;
        }
        Z_TRY_ADDREF_P(attr_value);
        zend_hash_update(Z_ARRVAL(span->attributes), attr_key, attr_value);
    } ZEND_HASH_FOREACH_END();
}

zend_bool king_telemetry_has_incoming_parent_context(void)
{
    return king_telemetry_incoming_parent_context.active
        && strlen(king_telemetry_incoming_parent_context.trace_id) == 32
        && strlen(king_telemetry_incoming_parent_context.parent_span_id) == 16;
}

zend_bool king_telemetry_build_trace_context_snapshot(zval *destination)
{
    zval attrs_copy;

    if (destination == NULL || king_current_span == NULL) {
        return 0;
    }

    array_init(destination);
    add_assoc_string(destination, "trace_id", king_current_span->trace_id);
    add_assoc_string(destination, "span_id", king_current_span->span_id);
    if (king_current_span->parent_span_id[0] != '\0') {
        add_assoc_string(destination, "parent_span_id", king_current_span->parent_span_id);
    }
    add_assoc_long(destination, "trace_flags", (zend_long) king_current_span->trace_flags);
    if (king_current_span->trace_state[0] != '\0') {
        add_assoc_string(destination, "trace_state", king_current_span->trace_state);
    }
    add_assoc_string(destination, "operation_name", king_current_span->operation_name);
    add_assoc_long(destination, "span_kind", (zend_long) king_current_span->span_kind);
    add_assoc_long(destination, "start_time", (zend_long) king_current_span->start_time);
    if (Z_TYPE(king_current_span->attributes) == IS_ARRAY) {
        ZVAL_COPY(&attrs_copy, &king_current_span->attributes);
        add_assoc_zval(destination, "attributes", &attrs_copy);
    }

    return 1;
}

zend_bool king_telemetry_build_distributed_parent_context(zval *destination)
{
    if (destination == NULL || king_current_span == NULL) {
        return 0;
    }

    array_init(destination);
    add_assoc_string(destination, "trace_id", king_current_span->trace_id);
    add_assoc_string(destination, "parent_span_id", king_current_span->span_id);
    add_assoc_long(destination, "trace_flags", (zend_long) king_current_span->trace_flags);
    if (king_current_span->trace_state[0] != '\0') {
        add_assoc_string(destination, "trace_state", king_current_span->trace_state);
    }

    return 1;
}

static zend_bool king_telemetry_close_current_span(
    const char *expected_span_id,
    king_trace_context_t *expected_span,
    zval *final_attributes
)
{
    king_trace_context_t *finished_span;
    king_trace_context_t *parent_span;
    zval span_entry;
    zend_bool capture_span = 0;

    if (king_current_span == NULL) {
        return 0;
    }

    if (expected_span != NULL && king_current_span != expected_span) {
        return 0;
    }

    if (
        expected_span_id != NULL
        && strcmp(king_current_span->span_id, expected_span_id) != 0
    ) {
        return 0;
    }

    finished_span = king_current_span;
    parent_span = finished_span->parent;
    finished_span->parent = NULL;

    king_telemetry_span_merge_attributes(finished_span, final_attributes);
    king_telemetry_finish_span(finished_span);

    array_init(&span_entry);
    add_assoc_string(&span_entry, "name", finished_span->operation_name);
    add_assoc_string(&span_entry, "trace_id", finished_span->trace_id);
    add_assoc_string(&span_entry, "span_id", finished_span->span_id);
    if (finished_span->parent_span_id[0] != '\0') {
        add_assoc_string(&span_entry, "parent_span_id", finished_span->parent_span_id);
    }
    add_assoc_long(&span_entry, "start_time", (zend_long) finished_span->start_time);
    add_assoc_long(&span_entry, "end_time", (zend_long) finished_span->end_time);
    add_assoc_long(&span_entry, "kind", (zend_long) finished_span->span_kind);
    if (Z_TYPE(finished_span->attributes) == IS_ARRAY) {
        zval attrs_copy;
        ZVAL_COPY(&attrs_copy, &finished_span->attributes);
        add_assoc_zval(&span_entry, "attributes", &attrs_copy);
    }
    if (king_telemetry_capture_enabled() && king_telemetry_span_is_sampled(finished_span)) {
        king_telemetry_pending_buffers_ensure();
        capture_span = king_telemetry_pending_buffer_try_reserve_entry(
            &king_telemetry_pending_spans,
            &span_entry
        );
    }

    if (capture_span) {
        add_next_index_zval(&king_telemetry_pending_spans, &span_entry);
    } else {
        zval_ptr_dtor(&span_entry);
    }

    king_telemetry_span_free(finished_span);
    king_current_span = parent_span;
    return 1;
}

zend_result king_telemetry_start_internal_span(
    king_trace_context_t **span_out,
    const char *operation_name,
    king_span_kind_t span_kind,
    zval *attributes,
    const char *parent_span_id
)
{
    king_trace_context_t *span;

    if (span_out == NULL || operation_name == NULL || operation_name[0] == '\0') {
        return FAILURE;
    }

    span = king_telemetry_create_span(operation_name, span_kind, parent_span_id);
    if (span == NULL) {
        return FAILURE;
    }

    king_telemetry_span_merge_attributes(span, attributes);
    king_current_span = span;
    *span_out = span;
    return SUCCESS;
}

zend_result king_telemetry_end_internal_span(
    king_trace_context_t *span,
    zval *final_attributes
)
{
    return king_telemetry_close_current_span(NULL, span, final_attributes)
        ? SUCCESS
        : FAILURE;
}

static zend_bool king_telemetry_capture_enabled(void)
{
    return king_telemetry_system_initialized && king_telemetry_runtime_config.enabled;
}

static uint64_t king_telemetry_estimate_zval_bytes(zval *value)
{
    smart_str json_payload = {0};
    uint64_t estimated_bytes = 0;

    if (value == NULL) {
        return 0;
    }

    php_json_encode(&json_payload, value, 0);
    smart_str_0(&json_payload);
    if (json_payload.s != NULL) {
        estimated_bytes = (uint64_t) ZSTR_LEN(json_payload.s);
    }
    smart_str_free(&json_payload);

    return estimated_bytes;
}

static zend_bool king_telemetry_pending_buffer_try_reserve_entry(zval *buffer, zval *entry)
{
    uint32_t limit;
    uint64_t entry_bytes;
    uint64_t memory_limit;
    uint64_t next_memory_bytes;

    if (buffer == NULL || Z_TYPE_P(buffer) != IS_ARRAY) {
        return 0;
    }

    limit = king_telemetry_get_pending_entry_limit();
    if (limit > 0 && king_telemetry_buffer_entry_count(buffer) >= limit) {
        king_telemetry_pending_drop_count++;
        return 0;
    }

    entry_bytes = king_telemetry_estimate_zval_bytes(entry);
    memory_limit = king_telemetry_runtime_memory_byte_limit_internal();
    next_memory_bytes = king_telemetry_saturated_add_u64(
        king_telemetry_current_memory_bytes_internal(),
        entry_bytes
    );
    if (memory_limit > 0 && next_memory_bytes > memory_limit) {
        king_telemetry_pending_drop_count++;
        return 0;
    }

    king_telemetry_pending_bytes = king_telemetry_saturated_add_u64(
        king_telemetry_pending_bytes,
        entry_bytes
    );
    king_telemetry_note_runtime_watermarks();
    return 1;
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

static uint32_t king_telemetry_buffer_move_entries_limited(
    zval *target,
    zval *source,
    uint32_t limit
)
{
    zval *entry;
    zval remaining;
    uint32_t moved = 0;

    if (target == NULL || source == NULL) {
        return 0;
    }
    if (Z_TYPE_P(target) != IS_ARRAY || Z_TYPE_P(source) != IS_ARRAY) {
        return 0;
    }

    if (limit == 0) {
        limit = UINT32_MAX;
    }

    array_init(&remaining);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(source), entry) {
        Z_TRY_ADDREF_P(entry);
        if (moved < limit) {
            add_next_index_zval(target, entry);
            moved++;
        } else {
            add_next_index_zval(&remaining, entry);
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(source);
    ZVAL_COPY_VALUE(source, &remaining);

    return moved;
}

static uint64_t king_telemetry_batch_refresh_estimated_bytes(king_telemetry_batch_t *batch)
{
    uint64_t estimated_bytes = 0;

    if (batch == NULL) {
        return 0;
    }

    estimated_bytes = king_telemetry_saturated_add_u64(
        estimated_bytes,
        king_telemetry_estimate_zval_bytes(&batch->metrics)
    );
    estimated_bytes = king_telemetry_saturated_add_u64(
        estimated_bytes,
        king_telemetry_estimate_zval_bytes(&batch->spans)
    );
    estimated_bytes = king_telemetry_saturated_add_u64(
        estimated_bytes,
        king_telemetry_estimate_zval_bytes(&batch->logs)
    );

    batch->estimated_bytes = estimated_bytes;
    return estimated_bytes;
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

static zend_bool king_telemetry_export_array_dropped(int result, zval *payload)
{
    return result == KING_TELEMETRY_EXPORT_RESULT_DROP
        && payload != NULL
        && Z_TYPE_P(payload) == IS_ARRAY
        && zend_hash_num_elements(Z_ARRVAL_P(payload)) > 0;
}

static zend_bool king_telemetry_batch_signal_complete(zval *payload, int result)
{
    if (!king_telemetry_export_array_succeeded(result, payload)
        && !king_telemetry_export_array_dropped(result, payload)) {
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
        "traces_sampler_type",
        sizeof("traces_sampler_type") - 1
    );
    if (value != NULL
        && Z_TYPE_P(value) == IS_STRING
        && (
            strcmp(Z_STRVAL_P(value), "parent_based_probability") == 0
            || strcmp(Z_STRVAL_P(value), "always_on") == 0
            || strcmp(Z_STRVAL_P(value), "always_off") == 0
        )) {
        snprintf(
            config->traces_sampler_type,
            sizeof(config->traces_sampler_type),
            "%s",
            Z_STRVAL_P(value)
        );
    }

    value = zend_hash_str_find(
        Z_ARRVAL_P(config_arr),
        "traces_sampler_ratio",
        sizeof("traces_sampler_ratio") - 1
    );
    if (value != NULL) {
        double sampler_ratio = zval_get_double(value);

        if (sampler_ratio >= 0.0 && sampler_ratio <= 1.0) {
            config->traces_sampler_ratio = sampler_ratio;
            config->traces_sampler_ratio_configured = 1;
        }
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
        "logs_exporter_batch_size",
        sizeof("logs_exporter_batch_size") - 1
    );
    if (value != NULL) {
        zend_long batch_size = zval_get_long(value);

        if (batch_size > 0) {
            config->max_export_batch_size = batch_size > UINT32_MAX
                ? UINT32_MAX
                : (uint32_t) batch_size;
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
