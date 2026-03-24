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

static king_telemetry_config_t king_telemetry_runtime_config;
static bool king_telemetry_system_initialized = false;
static king_trace_context_t *king_current_span = NULL;

static void king_telemetry_span_free(king_trace_context_t *span)
{
    if (span) {
        zval_ptr_dtor(&span->attributes);
        zval_ptr_dtor(&span->events);
        zval_ptr_dtor(&span->links);
        efree(span);
    }
}

int king_telemetry_init_system(king_telemetry_config_t *config)
{
    if (config) {
        memcpy(&king_telemetry_runtime_config, config, sizeof(king_telemetry_config_t));
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
