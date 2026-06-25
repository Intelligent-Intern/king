/*
 * Native Awaitable surface for King async contracts. The current backend is a
 * deferred native task runner: the stored operation executes on first poll or
 * await, while the object/state contract is stable for a later non-blocking
 * scheduler backend.
 */

static zend_object_handlers king_awaitable_object_handlers;

static const char *king_awaitable_status_name(king_awaitable_status_t status)
{
    switch (status) {
        case KING_AWAITABLE_PENDING:
            return "pending";
        case KING_AWAITABLE_RESOLVED:
            return "resolved";
        case KING_AWAITABLE_REJECTED:
            return "rejected";
        case KING_AWAITABLE_CANCELLED:
            return "cancelled";
    }

    return "unknown";
}

static void king_awaitable_object_free(zend_object *object)
{
    king_awaitable_object *intern = php_king_awaitable_obj_from_zend(object);

    if (intern->operation != NULL) {
        zend_string_release(intern->operation);
        intern->operation = NULL;
    }
    if (!Z_ISUNDEF(intern->payload)) {
        zval_ptr_dtor(&intern->payload);
        ZVAL_UNDEF(&intern->payload);
    }
    if (!Z_ISUNDEF(intern->result)) {
        zval_ptr_dtor(&intern->result);
        ZVAL_UNDEF(&intern->result);
    }
    if (!Z_ISUNDEF(intern->error)) {
        zval_ptr_dtor(&intern->error);
        ZVAL_UNDEF(&intern->error);
    }
    if (!Z_ISUNDEF(intern->cancel_token)) {
        zval_ptr_dtor(&intern->cancel_token);
        ZVAL_UNDEF(&intern->cancel_token);
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_awaitable_object_create(zend_class_entry *ce)
{
    king_awaitable_object *intern = zend_object_alloc(sizeof(*intern), ce);

    intern->status = KING_AWAITABLE_PENDING;
    intern->operation = NULL;
    ZVAL_UNDEF(&intern->payload);
    ZVAL_UNDEF(&intern->result);
    ZVAL_UNDEF(&intern->error);
    ZVAL_UNDEF(&intern->cancel_token);
    intern->runner = NULL;
    intern->started = false;
    intern->cancel_requested = false;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_awaitable_object_handlers;

    return &intern->std;
}

static void king_awaitable_object_handlers_init(void)
{
    memcpy(
        &king_awaitable_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_awaitable_object_handlers.offset = XtOffsetOf(king_awaitable_object, std);
    king_awaitable_object_handlers.free_obj = king_awaitable_object_free;
    king_awaitable_object_handlers.clone_obj = NULL;
    king_ce_awaitable->create_object = king_awaitable_object_create;
}

static zend_result king_awaitable_copy_params_from_payload(
    zval *payload,
    zval **params,
    uint32_t *param_count)
{
    zval *args;
    zval *arg;
    uint32_t index = 0;

    *params = NULL;
    *param_count = 0;

    if (Z_TYPE_P(payload) != IS_ARRAY) {
        king_set_error("King\\Awaitable has an invalid native payload.");
        return FAILURE;
    }

    args = zend_hash_str_find(Z_ARRVAL_P(payload), "args", sizeof("args") - 1);
    if (args == NULL || Z_TYPE_P(args) != IS_ARRAY) {
        king_set_error("King\\Awaitable has an invalid native argument payload.");
        return FAILURE;
    }

    *param_count = (uint32_t) zend_hash_num_elements(Z_ARRVAL_P(args));
    if (*param_count == 0) {
        return SUCCESS;
    }

    *params = safe_emalloc(*param_count, sizeof(zval), 0);
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(args), arg)
    {
        ZVAL_COPY(&(*params)[index], arg);
        index++;
    }
    ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static void king_awaitable_release_params(zval *params, uint32_t param_count)
{
    uint32_t index;

    if (params == NULL) {
        return;
    }

    for (index = 0; index < param_count; index++) {
        zval_ptr_dtor(&params[index]);
    }
    efree(params);
}

static zend_result king_awaitable_function_call_runner(
    king_awaitable_object *intern,
    zval *result)
{
    zval *function_name;
    zval callable;
    zval *params = NULL;
    uint32_t param_count = 0;
    zend_result status;

    function_name = zend_hash_str_find(
        Z_ARRVAL(intern->payload),
        "function",
        sizeof("function") - 1
    );
    if (function_name == NULL || Z_TYPE_P(function_name) != IS_STRING) {
        king_set_error("King\\Awaitable has an invalid function call payload.");
        return FAILURE;
    }

    if (king_awaitable_copy_params_from_payload(&intern->payload, &params, &param_count) != SUCCESS) {
        return FAILURE;
    }

    ZVAL_COPY(&callable, function_name);
    ZVAL_UNDEF(result);
    status = call_user_function(EG(function_table), NULL, &callable, result, param_count, params);
    zval_ptr_dtor(&callable);
    king_awaitable_release_params(params, param_count);

    if (status == SUCCESS && Z_ISUNDEF_P(result)) {
        ZVAL_NULL(result);
    }

    if (status != SUCCESS) {
        if (king_get_error()[0] == '\0') {
            king_set_error("King\\Awaitable could not invoke its deferred function.");
        }
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_awaitable_method_call_runner(
    king_awaitable_object *intern,
    zval *result)
{
    zval *object;
    zval *method_name;
    zval method;
    zval object_copy;
    zval *params = NULL;
    uint32_t param_count = 0;
    zend_result status;

    object = zend_hash_str_find(
        Z_ARRVAL(intern->payload),
        "object",
        sizeof("object") - 1
    );
    method_name = zend_hash_str_find(
        Z_ARRVAL(intern->payload),
        "method",
        sizeof("method") - 1
    );
    if (object == NULL || Z_TYPE_P(object) != IS_OBJECT
        || method_name == NULL || Z_TYPE_P(method_name) != IS_STRING) {
        king_set_error("King\\Awaitable has an invalid method call payload.");
        return FAILURE;
    }

    if (king_awaitable_copy_params_from_payload(&intern->payload, &params, &param_count) != SUCCESS) {
        return FAILURE;
    }

    ZVAL_COPY(&object_copy, object);
    ZVAL_COPY(&method, method_name);
    ZVAL_UNDEF(result);
    status = call_user_function(NULL, &object_copy, &method, result, param_count, params);
    zval_ptr_dtor(&method);
    zval_ptr_dtor(&object_copy);
    king_awaitable_release_params(params, param_count);

    if (status == SUCCESS && Z_ISUNDEF_P(result)) {
        ZVAL_NULL(result);
    }

    if (status != SUCCESS) {
        if (king_get_error()[0] == '\0') {
            king_set_error("King\\Awaitable could not invoke its deferred method.");
        }
        return FAILURE;
    }

    return SUCCESS;
}

zend_result king_awaitable_create(
    zval *return_value,
    const char *operation,
    size_t operation_len,
    king_awaitable_runner runner,
    zval *payload,
    zval *cancel_token)
{
    king_awaitable_object *intern;

    if (king_ce_awaitable == NULL || runner == NULL) {
        king_set_error("King\\Awaitable runtime is not initialized.");
        return FAILURE;
    }

    object_init_ex(return_value, king_ce_awaitable);
    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(return_value));
    intern->operation = zend_string_init(operation, operation_len, 0);
    intern->runner = runner;
    intern->status = KING_AWAITABLE_PENDING;
    intern->started = false;
    intern->cancel_requested = false;

    if (payload != NULL) {
        ZVAL_COPY(&intern->payload, payload);
    } else {
        array_init(&intern->payload);
    }

    if (cancel_token != NULL && Z_TYPE_P(cancel_token) != IS_NULL) {
        ZVAL_COPY(&intern->cancel_token, cancel_token);
    } else {
        ZVAL_NULL(&intern->cancel_token);
    }

    return SUCCESS;
}

zend_result king_awaitable_create_function_call(
    zval *return_value,
    const char *operation,
    size_t operation_len,
    const char *function_name,
    size_t function_name_len,
    zval *params,
    uint32_t param_count,
    zval *cancel_token)
{
    zval payload;
    zval args;
    uint32_t index;
    zend_result status;

    array_init(&payload);
    add_assoc_stringl(&payload, "function", function_name, function_name_len);
    array_init(&args);
    for (index = 0; index < param_count; index++) {
        zval copy;

        ZVAL_COPY(&copy, &params[index]);
        add_next_index_zval(&args, &copy);
    }
    add_assoc_zval(&payload, "args", &args);

    status = king_awaitable_create(
        return_value,
        operation,
        operation_len,
        king_awaitable_function_call_runner,
        &payload,
        cancel_token
    );
    zval_ptr_dtor(&payload);

    return status;
}

zend_result king_awaitable_create_method_call(
    zval *return_value,
    const char *operation,
    size_t operation_len,
    zval *object,
    const char *method_name,
    size_t method_name_len,
    zval *params,
    uint32_t param_count,
    zval *cancel_token)
{
    zval payload;
    zval args;
    zval object_copy;
    uint32_t index;
    zend_result status;

    if (object == NULL || Z_TYPE_P(object) != IS_OBJECT) {
        king_set_error("King\\Awaitable method call requires an object target.");
        return FAILURE;
    }

    array_init(&payload);
    ZVAL_COPY(&object_copy, object);
    add_assoc_zval(&payload, "object", &object_copy);
    add_assoc_stringl(&payload, "method", method_name, method_name_len);
    array_init(&args);
    for (index = 0; index < param_count; index++) {
        zval copy;

        ZVAL_COPY(&copy, &params[index]);
        add_next_index_zval(&args, &copy);
    }
    add_assoc_zval(&payload, "args", &args);

    status = king_awaitable_create(
        return_value,
        operation,
        operation_len,
        king_awaitable_method_call_runner,
        &payload,
        cancel_token
    );
    zval_ptr_dtor(&payload);

    return status;
}

static void king_awaitable_cancel_native(king_awaitable_object *intern)
{
    intern->cancel_requested = true;
    if (!Z_ISUNDEF(intern->cancel_token)
        && Z_TYPE(intern->cancel_token) == IS_OBJECT
        && instanceof_function(Z_OBJCE(intern->cancel_token), king_ce_cancel_token)) {
        php_king_cancel_token_obj_from_zend(Z_OBJ(intern->cancel_token))->cancelled = true;
    }
    if (intern->status == KING_AWAITABLE_PENDING) {
        intern->status = KING_AWAITABLE_CANCELLED;
    }
}

static zend_result king_awaitable_reject_current_error(king_awaitable_object *intern)
{
    const char *message = king_get_error();

    if (message == NULL || message[0] == '\0') {
        message = "King\\Awaitable operation failed.";
    }

    if (!Z_ISUNDEF(intern->error)) {
        zval_ptr_dtor(&intern->error);
    }
    ZVAL_STRING(&intern->error, message);
    intern->status = KING_AWAITABLE_REJECTED;

    return SUCCESS;
}

static zend_result king_awaitable_drive(king_awaitable_object *intern, zend_long timeout_ms)
{
    zval result;
    zend_result status;

    (void) timeout_ms;

    if (intern->status != KING_AWAITABLE_PENDING) {
        return SUCCESS;
    }

    if (intern->cancel_requested) {
        king_awaitable_cancel_native(intern);
        return SUCCESS;
    }
    if (!Z_ISUNDEF(intern->cancel_token)
        && Z_TYPE(intern->cancel_token) == IS_OBJECT
        && instanceof_function(Z_OBJCE(intern->cancel_token), king_ce_cancel_token)
        && php_king_cancel_token_obj_from_zend(Z_OBJ(intern->cancel_token))->cancelled) {
        king_awaitable_cancel_native(intern);
        return SUCCESS;
    }

    if (intern->runner == NULL) {
        king_set_error("King\\Awaitable has no native runner.");
        return king_awaitable_reject_current_error(intern);
    }

    intern->started = true;
    ZVAL_UNDEF(&result);
    status = intern->runner(intern, &result);
    if (status != SUCCESS) {
        if (!Z_ISUNDEF(result)) {
            zval_ptr_dtor(&result);
        }
        if (EG(exception) != NULL) {
            return FAILURE;
        }
        return king_awaitable_reject_current_error(intern);
    }

    if (EG(exception) != NULL) {
        if (!Z_ISUNDEF(result)) {
            zval_ptr_dtor(&result);
        }
        return FAILURE;
    }

    if (Z_ISUNDEF(result)) {
        return SUCCESS;
    }
    ZVAL_COPY(&intern->result, &result);
    zval_ptr_dtor(&result);
    intern->status = KING_AWAITABLE_RESOLVED;

    return SUCCESS;
}

#include "aggregate.inc"

static void king_awaitable_return_or_throw(king_awaitable_object *intern, zval *return_value)
{
    if (intern->status == KING_AWAITABLE_RESOLVED) {
        RETURN_ZVAL(&intern->result, 1, 0);
    }

    if (intern->status == KING_AWAITABLE_CANCELLED) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King\\Awaitable operation '%s' was cancelled.",
            intern->operation != NULL ? ZSTR_VAL(intern->operation) : "unknown"
        );
        RETURN_THROWS();
    }

    if (intern->status == KING_AWAITABLE_REJECTED) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            !Z_ISUNDEF(intern->error) && Z_TYPE(intern->error) == IS_STRING
                ? Z_STRVAL(intern->error)
                : "King\\Awaitable operation failed."
        );
        RETURN_THROWS();
    }

    zend_throw_exception_ex(
        king_ce_timeout_exception,
        0,
        "King\\Awaitable operation '%s' is still pending.",
        intern->operation != NULL ? ZSTR_VAL(intern->operation) : "unknown"
    );
    RETURN_THROWS();
}

PHP_METHOD(King_Awaitable, __construct)
{
    ZEND_PARSE_PARAMETERS_NONE();

    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "King\\Awaitable instances are created by King async APIs."
    );
}

PHP_METHOD(King_Awaitable, await)
{
    zend_long timeout_ms = -1;
    bool timeout_is_null = true;
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_is_null) {
        timeout_ms = -1;
    }
    if (timeout_ms < -1) {
        zend_argument_value_error(1, "must be null, -1, or greater than or equal to 0");
        RETURN_THROWS();
    }

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (king_awaitable_drive(intern, timeout_ms) != SUCCESS) {
        RETURN_THROWS();
    }

    king_awaitable_return_or_throw(intern, return_value);
}

PHP_METHOD(King_Awaitable, poll)
{
    zend_long timeout_ms = 0;
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_ms < 0) {
        zend_argument_value_error(1, "must be greater than or equal to 0");
        RETURN_THROWS();
    }

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (king_awaitable_drive(intern, timeout_ms) != SUCCESS) {
        RETURN_THROWS();
    }

    RETURN_BOOL(intern->status != KING_AWAITABLE_PENDING);
}

PHP_METHOD(King_Awaitable, cancel)
{
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (intern->status != KING_AWAITABLE_PENDING) {
        RETURN_FALSE;
    }

    king_awaitable_cancel_native(intern);
    RETURN_TRUE;
}

PHP_METHOD(King_Awaitable, isPending)
{
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    RETURN_BOOL(intern->status == KING_AWAITABLE_PENDING);
}

PHP_METHOD(King_Awaitable, isDone)
{
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    RETURN_BOOL(intern->status != KING_AWAITABLE_PENDING);
}

PHP_METHOD(King_Awaitable, isCancelled)
{
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    RETURN_BOOL(intern->status == KING_AWAITABLE_CANCELLED);
}

PHP_METHOD(King_Awaitable, getStatus)
{
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    RETURN_STRING(king_awaitable_status_name(intern->status));
}

PHP_METHOD(King_Awaitable, getOperation)
{
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (intern->operation == NULL) {
        RETURN_EMPTY_STRING();
    }

    RETURN_STR_COPY(intern->operation);
}

PHP_FUNCTION(king_await)
{
    zval *awaitable;
    zend_long timeout_ms = -1;
    bool timeout_is_null = true;
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(awaitable, king_ce_awaitable)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_is_null) {
        timeout_ms = -1;
    }
    if (timeout_ms < -1) {
        zend_argument_value_error(2, "must be null, -1, or greater than or equal to 0");
        RETURN_THROWS();
    }

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(awaitable));
    if (king_awaitable_drive(intern, timeout_ms) != SUCCESS) {
        RETURN_THROWS();
    }

    king_awaitable_return_or_throw(intern, return_value);
}

PHP_FUNCTION(king_awaitable_poll)
{
    zval *awaitable;
    zend_long timeout_ms = 0;
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_OBJECT_OF_CLASS(awaitable, king_ce_awaitable)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    if (timeout_ms < 0) {
        zend_argument_value_error(2, "must be greater than or equal to 0");
        RETURN_THROWS();
    }

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(awaitable));
    if (king_awaitable_drive(intern, timeout_ms) != SUCCESS) {
        RETURN_THROWS();
    }

    RETURN_BOOL(intern->status != KING_AWAITABLE_PENDING);
}

PHP_FUNCTION(king_awaitable_cancel)
{
    zval *awaitable;
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(awaitable, king_ce_awaitable)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(awaitable));
    if (intern->status != KING_AWAITABLE_PENDING) {
        RETURN_FALSE;
    }

    king_awaitable_cancel_native(intern);
    RETURN_TRUE;
}

PHP_FUNCTION(king_awaitable_status)
{
    zval *awaitable;
    king_awaitable_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(awaitable, king_ce_awaitable)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_awaitable_obj_from_zend(Z_OBJ_P(awaitable));
    RETURN_STRING(king_awaitable_status_name(intern->status));
}

#include "aggregate_api.inc"

#include "awaitable/class_method_entries.h"
