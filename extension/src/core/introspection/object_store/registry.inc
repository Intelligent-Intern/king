PHP_FUNCTION(king_object_store_put)
{
    zend_string *object_id;
    zend_string *payload;
    zval *options = NULL;
    king_object_store_entry *entry;
    zval stored_entry;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(payload)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_object_store_registry_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    (void) options;

    if (king_object_store_runtime.initialized &&
        king_object_store_runtime.max_storage_size_bytes > 0) {
        king_object_store_entry *existing_entry;
        zend_long object_count = 0;
        zend_long stored_bytes = 0;
        zend_long latest_object_at = 0;
        zend_long effective_stored_bytes;

        existing_entry = king_object_store_registry_get_entry(object_id);
        king_object_store_collect_live_stats(
            &object_count,
            &stored_bytes,
            &latest_object_at
        );

        effective_stored_bytes = stored_bytes + (zend_long) ZSTR_LEN(payload);
        if (existing_entry != NULL && existing_entry->payload != NULL) {
            effective_stored_bytes -= (zend_long) ZSTR_LEN(existing_entry->payload);
        }

        if (effective_stored_bytes > king_object_store_runtime.max_storage_size_bytes) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Object-store runtime capacity exceeded."
            );
            RETURN_THROWS();
        }
    }

    entry = pecalloc(1, sizeof(*entry), 1);
    entry->object_id = zend_string_dup(object_id, 1);
    entry->payload = zend_string_dup(payload, 1);
    entry->stored_at = time(NULL);

    ZVAL_PTR(&stored_entry, entry);
    if (zend_hash_update(&king_object_store_registry, entry->object_id, &stored_entry) == NULL) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Failed to store object '%s'.",
            ZSTR_VAL(entry->object_id)
        );
        king_object_store_entry_free(entry);
        RETURN_THROWS();
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_object_store_get)
{
    zend_string *object_id;
    zval *options = NULL;
    king_object_store_entry *entry;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    (void) options;
    entry = king_object_store_registry_get_entry(object_id);
    if (entry == NULL) {
        RETURN_FALSE;
    }

    RETURN_STR_COPY(entry->payload);
}

PHP_FUNCTION(king_object_store_delete)
{
    zend_string *object_id;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(object_id)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    if (!king_object_store_registry_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(zend_hash_del(&king_object_store_registry, object_id) == SUCCESS);
}

PHP_FUNCTION(king_object_store_list)
{
    zval *entry_zv;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    if (!king_object_store_registry_initialized) {
        return;
    }

    ZEND_HASH_FOREACH_VAL(&king_object_store_registry, entry_zv) {
        king_object_store_entry *entry;
        zval exported_entry;

        if (Z_TYPE_P(entry_zv) != IS_PTR) {
            continue;
        }

        entry = (king_object_store_entry *) Z_PTR_P(entry_zv);
        if (entry == NULL) {
            continue;
        }

        king_object_store_export_entry(&exported_entry, entry);
        add_next_index_zval(return_value, &exported_entry);
    } ZEND_HASH_FOREACH_END();
}
