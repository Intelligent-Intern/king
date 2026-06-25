static zval *king_awaitable_aggregate_items(king_awaitable_object *intern)
{
    zval *awaitables;

    if (Z_TYPE(intern->payload) != IS_ARRAY) {
        king_set_error("King\\Awaitable aggregate has an invalid native payload.");
        return NULL;
    }

    awaitables = zend_hash_str_find(
        Z_ARRVAL(intern->payload),
        "awaitables",
        sizeof("awaitables") - 1
    );
    if (awaitables == NULL || Z_TYPE_P(awaitables) != IS_ARRAY) {
        king_set_error("King\\Awaitable aggregate has an invalid awaitables payload.");
        return NULL;
    }

    return awaitables;
}

static zend_result king_awaitable_validate_aggregate_array(zval *awaitables)
{
    zval *awaitable;

    if (zend_hash_num_elements(Z_ARRVAL_P(awaitables)) == 0) {
        zend_argument_value_error(1, "must contain at least one King\\Awaitable instance");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(awaitables), awaitable)
    {
        if (Z_TYPE_P(awaitable) != IS_OBJECT
            || !instanceof_function(Z_OBJCE_P(awaitable), king_ce_awaitable)) {
            zend_argument_type_error(1, "must contain only King\\Awaitable instances");
            return FAILURE;
        }
    }
    ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static void king_awaitable_build_envelope(
    zval *return_value,
    zend_ulong num_key,
    zend_string *str_key,
    king_awaitable_object *awaitable)
{
    zval value;

    array_init(return_value);
    if (str_key != NULL) {
        add_assoc_str(return_value, "key", zend_string_copy(str_key));
    } else {
        add_assoc_long(return_value, "key", (zend_long) num_key);
    }

    add_assoc_string(return_value, "status", king_awaitable_status_name(awaitable->status));
    if (awaitable->operation != NULL) {
        add_assoc_str(return_value, "operation", zend_string_copy(awaitable->operation));
    } else {
        add_assoc_string(return_value, "operation", "");
    }

    if (awaitable->status == KING_AWAITABLE_RESOLVED && !Z_ISUNDEF(awaitable->result)) {
        ZVAL_COPY(&value, &awaitable->result);
        add_assoc_zval(return_value, "value", &value);
    } else {
        add_assoc_null(return_value, "value");
    }

    if (awaitable->status == KING_AWAITABLE_REJECTED
        && !Z_ISUNDEF(awaitable->error)
        && Z_TYPE(awaitable->error) == IS_STRING) {
        add_assoc_str(return_value, "error", zend_string_copy(Z_STR(awaitable->error)));
    }
}

static zend_result king_awaitable_any_runner(
    king_awaitable_object *intern,
    zend_long timeout_ms,
    zval *result)
{
    zval *awaitables = king_awaitable_aggregate_items(intern);
    zval *awaitable;
    zend_ulong num_key;
    zend_string *str_key;
    king_awaitable_object *child;

    if (awaitables == NULL) {
        return FAILURE;
    }

    ZVAL_UNDEF(result);
    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(awaitables), num_key, str_key, awaitable)
    {
        if (Z_TYPE_P(awaitable) != IS_OBJECT
            || !instanceof_function(Z_OBJCE_P(awaitable), king_ce_awaitable)) {
            king_set_error("King\\Awaitable::any() received an invalid child awaitable.");
            return FAILURE;
        }

        child = php_king_awaitable_obj_from_zend(Z_OBJ_P(awaitable));
        if (child->status == KING_AWAITABLE_PENDING
            && king_awaitable_drive(child, timeout_ms) != SUCCESS) {
            return FAILURE;
        }

        if (child->status != KING_AWAITABLE_PENDING) {
            king_awaitable_build_envelope(result, num_key, str_key, child);
            return SUCCESS;
        }
    }
    ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_awaitable_all_runner(
    king_awaitable_object *intern,
    zend_long timeout_ms,
    zval *result)
{
    zval *awaitables = king_awaitable_aggregate_items(intern);
    zval *awaitable;
    zval envelope;
    zend_ulong num_key;
    zend_string *str_key;
    king_awaitable_object *child;
    bool pending = false;

    if (awaitables == NULL) {
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(awaitables), awaitable)
    {
        if (Z_TYPE_P(awaitable) != IS_OBJECT
            || !instanceof_function(Z_OBJCE_P(awaitable), king_ce_awaitable)) {
            king_set_error("King\\Awaitable::all() received an invalid child awaitable.");
            return FAILURE;
        }

        child = php_king_awaitable_obj_from_zend(Z_OBJ_P(awaitable));
        if (child->status == KING_AWAITABLE_PENDING
            && king_awaitable_drive(child, timeout_ms) != SUCCESS) {
            return FAILURE;
        }
        if (child->status == KING_AWAITABLE_PENDING) {
            pending = true;
        }
    }
    ZEND_HASH_FOREACH_END();

    if (pending) {
        ZVAL_UNDEF(result);
        return SUCCESS;
    }

    array_init(result);
    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(awaitables), num_key, str_key, awaitable)
    {
        child = php_king_awaitable_obj_from_zend(Z_OBJ_P(awaitable));
        king_awaitable_build_envelope(&envelope, num_key, str_key, child);
        if (str_key != NULL) {
            add_assoc_zval_ex(result, ZSTR_VAL(str_key), ZSTR_LEN(str_key), &envelope);
        } else {
            add_index_zval(result, num_key, &envelope);
        }
    }
    ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_awaitable_create_aggregate(
    zval *return_value,
    const char *operation,
    size_t operation_len,
    zval *awaitables,
    king_awaitable_runner runner,
    zval *cancel)
{
    zval payload;
    zval awaitables_copy;
    zend_result status;

    array_init(&payload);
    ZVAL_COPY(&awaitables_copy, awaitables);
    add_assoc_zval(&payload, "awaitables", &awaitables_copy);

    status = king_awaitable_create(
        return_value,
        operation,
        operation_len,
        runner,
        &payload,
        cancel
    );
    zval_ptr_dtor(&payload);

    return status;
}
