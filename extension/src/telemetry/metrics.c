/*
 * src/telemetry/metrics.c - Metrics Aggregation and Export
 * =========================================================================
 *
 * This module implements the internal registry for metrics.
 * Supports counters, gauges, histograms and summaries.
 */
#include "php_king.h"
#include "include/telemetry/telemetry.h"

static HashTable king_metrics_registry;
static bool king_metrics_initialized = false;
static uint64_t king_telemetry_flush_count = 0;

static void king_metric_dtor(zval *zv)
{
    king_metric_data_t *metric = Z_PTR_P(zv);
    if (metric) {
        zval_ptr_dtor(&metric->labels);
        efree(metric);
    }
}

int king_telemetry_record_metric_internal(const char *metric_name, king_metric_type_t metric_type, double value, zval *labels)
{
    if (!king_metrics_initialized) {
        zend_hash_init(&king_metrics_registry, 16, NULL, king_metric_dtor, 0);
        king_metrics_initialized = true;
    }

    king_metric_data_t *metric = emalloc(sizeof(king_metric_data_t));
    memset(metric, 0, sizeof(king_metric_data_t));
    
    strncpy(metric->metric_name, metric_name, sizeof(metric->metric_name) - 1);
    metric->metric_type = metric_type;
    metric->value = value;
    metric->timestamp = time(NULL);
    
    array_init(&metric->labels);
    if (labels) {
        ZVAL_COPY(&metric->labels, labels);
    }
    
    /* Aggregation logic for counters */
    zval *old_zv = zend_hash_str_find(&king_metrics_registry, metric_name, strlen(metric_name));
    if (old_zv && metric_type == KING_METRIC_TYPE_COUNTER) {
        king_metric_data_t *old_metric = Z_PTR_P(old_zv);
        metric->value += old_metric->value;
    }

    zval val;
    ZVAL_PTR(&val, metric);
    
    zend_hash_str_update(&king_metrics_registry, metric_name, strlen(metric_name), &val);
    
    return SUCCESS;
}

void king_telemetry_metrics_shutdown(void)
{
    if (king_metrics_initialized) {
        zend_hash_destroy(&king_metrics_registry);
        king_metrics_initialized = false;
    }
}

/* --- PHP Entry Points --- */

PHP_FUNCTION(king_telemetry_record_metric)
{
    char *name;
    size_t name_len;
    double value;
    zval *labels = NULL;
    char *type_str = NULL;
    size_t type_len = 0;

    ZEND_PARSE_PARAMETERS_START(2, 4)
        Z_PARAM_STRING(name, name_len)
        Z_PARAM_DOUBLE(value)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(labels)
        Z_PARAM_STRING(type_str, type_len)
    ZEND_PARSE_PARAMETERS_END();

    king_metric_type_t type = KING_METRIC_TYPE_COUNTER;
    if (type_str) {
        if (strcmp(type_str, "gauge") == 0) type = KING_METRIC_TYPE_GAUGE;
        else if (strcmp(type_str, "histogram") == 0) type = KING_METRIC_TYPE_HISTOGRAM;
        else if (strcmp(type_str, "summary") == 0) type = KING_METRIC_TYPE_SUMMARY;
    }

    if (king_telemetry_record_metric_internal(name, type, value, labels) == SUCCESS) {
        RETURN_TRUE;
    }
    
    RETURN_FALSE;
}

PHP_FUNCTION(king_telemetry_get_metrics)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    
    if (!king_metrics_initialized) {
        return;
    }

    king_metric_data_t *metric;
    zend_string *key;
    zval *entry;

    ZEND_HASH_FOREACH_STR_KEY_VAL(&king_metrics_registry, key, entry) {
        metric = Z_PTR_P(entry);
        zval m_info;
        array_init(&m_info);
        
        add_assoc_string(&m_info, "name", metric->metric_name);
        add_assoc_double(&m_info, "value", metric->value);
        add_assoc_long(&m_info, "timestamp", (zend_long)metric->timestamp);
        
        add_assoc_zval(return_value, ZSTR_VAL(key), &m_info);
    } ZEND_HASH_FOREACH_END();
}

PHP_FUNCTION(king_telemetry_flush)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    if (king_metrics_initialized) {
        /* Simulate Export by clearing the registry */
        zend_hash_clean(&king_metrics_registry);
        king_telemetry_flush_count++;
    }
    
    RETURN_TRUE;
}

PHP_FUNCTION(king_telemetry_get_status)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    add_assoc_bool(return_value, "initialized", king_metrics_initialized);
    add_assoc_long(return_value, "flush_count", (zend_long)king_telemetry_flush_count);
    add_assoc_long(return_value, "active_metrics", king_metrics_initialized ? zend_hash_num_elements(&king_metrics_registry) : 0);
}
