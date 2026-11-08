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

static zend_result king_awaitable_aggregate_runner(
    king_awaitable_object *intern, zend_long timeout_ms, zval *result, bool all)
{
    zval *items = king_awaitable_aggregate_items(intern), *value;
    uint64_t start = king_awaitable_now_ms(), now;
    if (items == NULL) return FAILURE;
    ZVAL_UNDEF(result);
    do {
        if (king_awaitable_check_cancel(intern)) return SUCCESS;
        king_awaitable_object *ready = NULL, *io = NULL;
        zend_ulong key, chosen_key = 0;
        zend_string *str, *chosen_str = NULL;
        bool pending = false;
        /* Nonblocking I/O tick advances all registered requests, not just the
         * first child in this aggregate. Legacy runners retain their contract. */
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(items), value) {
            king_awaitable_object *child = php_king_awaitable_obj_from_zend(Z_OBJ_P(value));
            if (child->status == KING_AWAITABLE_PENDING && child->native_poll != NULL) {
                if (king_awaitable_drive(child, 0) != SUCCESS) return FAILURE;
            }
        } ZEND_HASH_FOREACH_END();
        ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(items), key, str, value) {
            king_awaitable_object *child = php_king_awaitable_obj_from_zend(Z_OBJ_P(value));
            if (child->status == KING_AWAITABLE_PENDING) {
                pending = true;
                if (child->native_poll != NULL) io = child;
            } else if (ready == NULL || child->completion_order < ready->completion_order) {
                ready = child; chosen_key = key; chosen_str = str;
            }
        } ZEND_HASH_FOREACH_END();
        if (!all && ready != NULL) {
            king_awaitable_build_envelope(result, chosen_key, chosen_str, ready);
            return SUCCESS;
        }
        if (all && !pending) {
            array_init(result);
            ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(items), key, str, value) {
                zval envelope;
                king_awaitable_build_envelope(&envelope, key, str,
                    php_king_awaitable_obj_from_zend(Z_OBJ_P(value)));
                if (str != NULL) zend_hash_update(Z_ARRVAL_P(result), str, &envelope);
                else zend_hash_index_update(Z_ARRVAL_P(result), key, &envelope);
            } ZEND_HASH_FOREACH_END();
            return SUCCESS;
        }
        now = king_awaitable_now_ms();
        if (io != NULL) {
            zend_long slice;
            if (timeout_ms == 0 || (timeout_ms > 0 && now - start >= (uint64_t) timeout_ms)) return SUCCESS;
            slice = timeout_ms < 0 ? 10 : (zend_long) ((uint64_t) timeout_ms - (now - start));
            if (slice > 10) slice = 10;
            if (king_awaitable_drive(io, slice) != SUCCESS) return FAILURE;
        } else {
            bool advanced = false;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(items), value) {
                king_awaitable_object *child = php_king_awaitable_obj_from_zend(Z_OBJ_P(value));
                if (child->status != KING_AWAITABLE_PENDING) continue;
                zend_long remaining = timeout_ms;
                if (timeout_ms > 0) {
                    uint64_t elapsed = king_awaitable_now_ms() - start;
                    if (elapsed >= (uint64_t) timeout_ms) return SUCCESS;
                    remaining -= elapsed;
                }
                if (king_awaitable_drive(child, remaining) != SUCCESS) return FAILURE;
                advanced = child->status != KING_AWAITABLE_PENDING;
                break;
            } ZEND_HASH_FOREACH_END();
            if (!advanced) return SUCCESS;
        }
    } while (true);
}

static zend_result king_awaitable_any_runner(king_awaitable_object *intern, zend_long timeout_ms, zval *result)
{
    return king_awaitable_aggregate_runner(intern, timeout_ms, result, false);
}

static zend_result king_awaitable_all_runner(king_awaitable_object *intern, zend_long timeout_ms, zval *result)
{
    return king_awaitable_aggregate_runner(intern, timeout_ms, result, true);
}

/* Aggregate waits are cooperative too, so nesting does not hide pending I/O. */
static zend_result king_awaitable_aggregate_poll(king_awaitable_object *intern, zend_long timeout_ms)
{
    zval result, *items, *value;
    uint64_t order = 0;
    bool all = intern->runner == king_awaitable_all_runner;
    ZVAL_UNDEF(&result);
    if (intern->runner(intern, timeout_ms, &result) != SUCCESS) return FAILURE;
    if (Z_ISUNDEF(result)) return SUCCESS;
    items = king_awaitable_aggregate_items(intern);
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(items), value) {
        king_awaitable_object *child = php_king_awaitable_obj_from_zend(Z_OBJ_P(value));
        if (child->status == KING_AWAITABLE_PENDING) continue;
        if (order == 0 || (all ? child->completion_order > order : child->completion_order < order))
            order = child->completion_order;
    } ZEND_HASH_FOREACH_END();
    king_awaitable_resolve(intern, &result);
    if (intern->status == KING_AWAITABLE_RESOLVED && order != 0) intern->completion_order = order;
    zval_ptr_dtor(&result);
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
    if (status == SUCCESS) {
        php_king_awaitable_obj_from_zend(Z_OBJ_P(return_value))->native_poll = king_awaitable_aggregate_poll;
    }
    return status;
}
