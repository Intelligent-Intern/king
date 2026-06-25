PHP_METHOD(King_Awaitable, any)
{
    zval *awaitables;
    zval *cancel = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(awaitables)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
    ZEND_PARSE_PARAMETERS_END();

    if (king_awaitable_validate_aggregate_array(awaitables) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_awaitable_create_aggregate(
        return_value,
        "King\\Awaitable::any",
        sizeof("King\\Awaitable::any") - 1,
        awaitables,
        king_awaitable_any_runner,
        cancel
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Awaitable, all)
{
    zval *awaitables;
    zval *cancel = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(awaitables)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
    ZEND_PARSE_PARAMETERS_END();

    if (king_awaitable_validate_aggregate_array(awaitables) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_awaitable_create_aggregate(
        return_value,
        "King\\Awaitable::all",
        sizeof("King\\Awaitable::all") - 1,
        awaitables,
        king_awaitable_all_runner,
        cancel
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_awaitable_any)
{
    zval *awaitables;
    zval *cancel = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(awaitables)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
    ZEND_PARSE_PARAMETERS_END();

    if (king_awaitable_validate_aggregate_array(awaitables) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_awaitable_create_aggregate(
        return_value,
        "king_awaitable_any",
        sizeof("king_awaitable_any") - 1,
        awaitables,
        king_awaitable_any_runner,
        cancel
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_awaitable_all)
{
    zval *awaitables;
    zval *cancel = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(awaitables)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
    ZEND_PARSE_PARAMETERS_END();

    if (king_awaitable_validate_aggregate_array(awaitables) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_awaitable_create_aggregate(
        return_value,
        "king_awaitable_all",
        sizeof("king_awaitable_all") - 1,
        awaitables,
        king_awaitable_all_runner,
        cancel
    ) != SUCCESS) {
        RETURN_THROWS();
    }
}
