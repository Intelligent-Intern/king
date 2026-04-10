/*
 * Prelude telemetry runtime slice: owns the process-local telemetry metric
 * registry lifecycle, metric-type validation, and stable export helpers that
 * the active telemetry introspection surfaces reuse.
 */

static void king_telemetry_metric_record_free(king_telemetry_metric_record *record)
{
    if (record == NULL) {
        return;
    }

    if (record->metric_name != NULL) {
        zend_string_release_ex(record->metric_name, 1);
    }
    if (record->metric_type != NULL) {
        zend_string_release_ex(record->metric_type, 1);
    }

    zend_hash_destroy(&record->labels);
    pefree(record, 1);
}

static void king_telemetry_metric_record_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_telemetry_metric_record_free((king_telemetry_metric_record *) Z_PTR_P(zv));
    }
}

int king_telemetry_registry_minit(void)
{
    if (king_telemetry_registry_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_telemetry_metric_registry,
        8,
        NULL,
        king_telemetry_metric_record_zval_dtor,
        1
    );
    king_telemetry_registry_initialized = true;

    return SUCCESS;
}

void king_telemetry_registry_mshutdown(void)
{
    if (!king_telemetry_registry_initialized) {
        return;
    }

    zend_hash_destroy(&king_telemetry_metric_registry);
    king_telemetry_registry_initialized = false;
}

static bool king_telemetry_metric_type_is_valid(zend_string *metric_type)
{
    return zend_string_equals_literal(metric_type, "counter")
        || zend_string_equals_literal(metric_type, "gauge")
        || zend_string_equals_literal(metric_type, "histogram")
        || zend_string_equals_literal(metric_type, "summary");
}

static void king_telemetry_export_metric_record(
    zval *target,
    const king_telemetry_metric_record *record
)
{
    HashTable *iterable_labels;
    zend_string *label_name;
    zval *label_value;
    zval labels;

    array_init(target);
    add_assoc_str(target, "metric_name", zend_string_copy(record->metric_name));
    add_assoc_str(target, "metric_type", zend_string_copy(record->metric_type));
    add_assoc_double(target, "value", record->value);

    array_init(&labels);
    /* Zend foreach macros are not const-correct on PHP 8.x hash APIs. */
    iterable_labels = (HashTable *) &record->labels;
    ZEND_HASH_FOREACH_STR_KEY_VAL(iterable_labels, label_name, label_value) {
        zval copy;

        if (label_name == NULL) {
            continue;
        }

        king_semantic_dns_copy_request_scalar_zval(&copy, label_value);
        zend_hash_add_new(Z_ARRVAL(labels), zend_string_copy(label_name), &copy);
    } ZEND_HASH_FOREACH_END();
    add_assoc_zval(target, "labels", &labels);
    add_assoc_long(target, "recorded_at", (zend_long) record->recorded_at);
}
