/*
 * src/pipeline_orchestrator/orchestrator.c - Orchestrator Pipeline Runner
 * =========================================================================
 *
 * This module implements the core execution loop for pipelines.
 * Pipelines consist of sequential steps that currently validate tool presence,
 * snapshot run state, and either execute locally or cross the file-worker
 * backend boundary through the persisted run queue.
 */
#include "php_king.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/pipeline_orchestrator/orchestrator.h"
#include <unistd.h>

typedef struct _king_orchestrator_exec_control {
    zend_long timeout_ms;
    zend_long max_concurrency;
    uint64_t deadline_ms;
    uint64_t started_at_ms;
    zval cancel_token;
    zend_string *run_id;
} king_orchestrator_exec_control_t;

static int king_orchestrator_backend_is_file_worker(void)
{
    return king_mcp_orchestrator_config.orchestrator_execution_backend != NULL
        && strcmp(king_mcp_orchestrator_config.orchestrator_execution_backend, "file_worker") == 0;
}

static uint64_t king_orchestrator_monotonic_time_ms(void)
{
    return (uint64_t) (zend_hrtime() / 1000000ULL);
}

static void king_orchestrator_exec_control_cleanup(
    king_orchestrator_exec_control_t *control
)
{
    if (control == NULL) {
        return;
    }

    if (!Z_ISUNDEF(control->cancel_token)) {
        zval_ptr_dtor(&control->cancel_token);
        ZVAL_UNDEF(&control->cancel_token);
    }

    control->run_id = NULL;
}

static zend_result king_orchestrator_raise_error(
    const char *message,
    zend_class_entry *exception_ce,
    zend_bool throw_on_error)
{
    king_set_error(message);

    if (throw_on_error) {
        zend_throw_exception_ex(exception_ce, 0, "%s", message);
    }

    return FAILURE;
}

static zend_bool king_orchestrator_exception_message_contains(const char *needle)
{
    zval rv;
    zval *message;

    if (needle == NULL || needle[0] == '\0' || EG(exception) == NULL) {
        return 0;
    }

    message = zend_read_property(
        zend_ce_exception,
        EG(exception),
        "message",
        sizeof("message") - 1,
        1,
        &rv
    );
    if (message == NULL || Z_TYPE_P(message) != IS_STRING) {
        return 0;
    }

    return strstr(Z_STRVAL_P(message), needle) != NULL;
}

static zend_result king_orchestrator_validate_positive_long_option(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name,
    zend_bool throw_on_error)
{
    char message[KING_ERR_LEN];

    if (Z_TYPE_P(value) != IS_LONG) {
        snprintf(
            message,
            sizeof(message),
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    if (Z_LVAL_P(value) <= 0) {
        snprintf(
            message,
            sizeof(message),
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_orchestrator_exec_control_parse(
    zval *options,
    const char *function_name,
    zend_bool throw_on_error,
    king_orchestrator_exec_control_t *control)
{
    zval *option_value;
    zend_long deadline_ms = 0;
    char message[KING_ERR_LEN];

    if (control == NULL) {
        return FAILURE;
    }

    control->timeout_ms = king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms > 0
        ? king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms
        : 0;
    control->max_concurrency = king_mcp_orchestrator_config.orchestrator_loop_concurrency_default > 0
        ? king_mcp_orchestrator_config.orchestrator_loop_concurrency_default
        : 1;
    control->deadline_ms = 0;
    control->started_at_ms = king_orchestrator_monotonic_time_ms();
    ZVAL_UNDEF(&control->cancel_token);
    control->run_id = NULL;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "overall_timeout_ms",
        sizeof("overall_timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "overall_timeout_ms",
                &control->timeout_ms,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "timeout_ms",
        sizeof("timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "timeout_ms",
                &control->timeout_ms,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "deadline_ms",
        sizeof("deadline_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "deadline_ms",
                &deadline_ms,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
        control->deadline_ms = (uint64_t) deadline_ms;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "max_concurrency",
        sizeof("max_concurrency") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "max_concurrency",
                &control->max_concurrency,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    if (
        king_mcp_orchestrator_config.orchestrator_loop_concurrency_default > 0
        && control->max_concurrency > king_mcp_orchestrator_config.orchestrator_loop_concurrency_default
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() option 'max_concurrency' must be <= the configured orchestrator_loop_concurrency_default (%ld).",
            function_name,
            king_mcp_orchestrator_config.orchestrator_loop_concurrency_default
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "cancel",
        sizeof("cancel") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            Z_TYPE_P(option_value) != IS_OBJECT
            || !instanceof_function(Z_OBJCE_P(option_value), king_ce_cancel_token)
        ) {
            snprintf(
                message,
                sizeof(message),
                "%s() option 'cancel' must be null or King\\CancelToken.",
                function_name
            );
            return king_orchestrator_raise_error(
                message,
                king_ce_validation_exception,
                throw_on_error
            );
        }

        ZVAL_COPY(&control->cancel_token, option_value);
    }

    return SUCCESS;
}

static zend_result king_orchestrator_exec_control_check(
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error,
    zend_bool *cancelled_out)
{
    char message[KING_ERR_LEN];
    uint64_t now_ms;

    if (control == NULL) {
        return SUCCESS;
    }

    king_process_pending_interrupts();

    if (cancelled_out != NULL) {
        *cancelled_out = 0;
    }

    if (king_transport_cancel_token_is_cancelled(&control->cancel_token)) {
        snprintf(
            message,
            sizeof(message),
            "%s() cancelled the active orchestrator run via CancelToken.",
            function_name
        );
        if (cancelled_out != NULL) {
            *cancelled_out = 1;
        }
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (
        control->run_id != NULL
        && king_orchestrator_run_cancel_requested(control->run_id)
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() cancelled the active orchestrator run via the persisted file-worker cancel channel.",
            function_name
        );
        if (cancelled_out != NULL) {
            *cancelled_out = 1;
        }
        (void) king_orchestrator_pipeline_run_cancelled(control->run_id, message);
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    now_ms = king_orchestrator_monotonic_time_ms();
    if (
        control->timeout_ms > 0
        && now_ms - control->started_at_ms >= (uint64_t) control->timeout_ms
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() exceeded the active orchestrator timeout budget.",
            function_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_timeout_exception,
            throw_on_error
        );
    }

    if (control->deadline_ms > 0 && now_ms >= control->deadline_ms) {
        snprintf(
            message,
            sizeof(message),
            "%s() exceeded the active orchestrator deadline budget.",
            function_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_timeout_exception,
            throw_on_error
        );
    }

    return SUCCESS;
}

static void king_orchestrator_mark_interrupted_run(zend_string *run_id, zend_bool cancelled)
{
    if (run_id == NULL) {
        return;
    }

    if (cancelled) {
        (void) king_orchestrator_pipeline_run_cancelled(run_id, king_get_error());
    } else {
        (void) king_orchestrator_pipeline_run_fail(run_id, king_get_error());
    }
}

static zend_result king_orchestrator_enforce_max_concurrency(
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error)
{
    char message[KING_ERR_LEN];
    size_t active_runs;

    if (control == NULL || control->max_concurrency <= 0) {
        return SUCCESS;
    }

    active_runs = king_orchestrator_count_active_runs();
    if ((zend_long) active_runs < control->max_concurrency) {
        return SUCCESS;
    }

    snprintf(
        message,
        sizeof(message),
        "%s() cannot exceed the active orchestrator max_concurrency of %ld while %zu run(s) are already in flight.",
        function_name,
        control->max_concurrency,
        active_runs
    );
    return king_orchestrator_raise_error(
        message,
        king_ce_runtime_exception,
        throw_on_error
    );
}

static zval *king_orchestrator_prepare_persisted_options(zval *options, zval *sanitized_options)
{
    if (sanitized_options == NULL) {
        return options;
    }

    ZVAL_NULL(sanitized_options);
    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return options;
    }

    ZVAL_COPY(sanitized_options, options);
    SEPARATE_ARRAY(sanitized_options);
    zend_hash_str_del(
        Z_ARRVAL_P(sanitized_options),
        "cancel",
        sizeof("cancel") - 1
    );

    return sanitized_options;
}

static zend_result king_orchestrator_validate_non_negative_step_delay(
    zval *value,
    uint32_t index,
    zend_bool throw_on_error)
{
    char message[KING_ERR_LEN];

    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_run() step %u option 'delay_ms' must be provided as an integer >= 0.",
            (unsigned) index
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    return SUCCESS;
}

static zend_result king_orchestrator_execute_step_delay(
    zval *step,
    uint32_t index,
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error,
    zend_bool *cancelled_out)
{
    zval *delay_value;
    zend_long delay_ms;
    uint64_t started_at_ms;

    if (step == NULL || Z_TYPE_P(step) != IS_ARRAY) {
        return SUCCESS;
    }

    delay_value = zend_hash_str_find(Z_ARRVAL_P(step), "delay_ms", sizeof("delay_ms") - 1);
    if (king_orchestrator_validate_non_negative_step_delay(delay_value, index, throw_on_error) != SUCCESS) {
        return FAILURE;
    }
    if (delay_value == NULL || Z_TYPE_P(delay_value) == IS_NULL || Z_LVAL_P(delay_value) == 0) {
        return SUCCESS;
    }

    delay_ms = Z_LVAL_P(delay_value);
    started_at_ms = king_orchestrator_monotonic_time_ms();

    while (king_orchestrator_monotonic_time_ms() - started_at_ms < (uint64_t) delay_ms) {
        uint64_t elapsed_ms = king_orchestrator_monotonic_time_ms() - started_at_ms;
        uint64_t remaining_ms = (uint64_t) delay_ms > elapsed_ms
            ? (uint64_t) delay_ms - elapsed_ms
            : 0;
        useconds_t sleep_chunk = (useconds_t) ((remaining_ms > 10 ? 10 : remaining_ms) * 1000U);

        if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, cancelled_out) != SUCCESS) {
            return FAILURE;
        }
        if (sleep_chunk == 0) {
            break;
        }
        usleep(sleep_chunk);
    }

    return king_orchestrator_exec_control_check(control, function_name, throw_on_error, cancelled_out);
}

static zend_result king_orchestrator_validate_pipeline_step(
    zval *step,
    uint32_t index,
    zend_bool throw_on_error)
{
    zval *tool;
    char message[256];

    if (Z_TYPE_P(step) != IS_ARRAY) {
        return SUCCESS;
    }

    tool = zend_hash_str_find(Z_ARRVAL_P(step), "tool", sizeof("tool") - 1);
    if (tool == NULL || Z_TYPE_P(tool) != IS_STRING || Z_STRLEN_P(tool) == 0) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_run() step %u requires a non-empty tool name.",
            (unsigned) index
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    if (king_orchestrator_lookup_tool(Z_STRVAL_P(tool), Z_STRLEN_P(tool)) == NULL) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_run() references unknown tool '%s'.",
            Z_STRVAL_P(tool)
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    return king_orchestrator_validate_non_negative_step_delay(
        zend_hash_str_find(Z_ARRVAL_P(step), "delay_ms", sizeof("delay_ms") - 1),
        index,
        throw_on_error
    );
}

static int king_orchestrator_execute_existing_run(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline_array,
    zval *return_value,
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error)
{
    HashTable *ht;
    zval *step;
    uint32_t index = 0;
    zend_bool cancelled = 0;

    if (initial_data == NULL || pipeline_array == NULL || Z_TYPE_P(pipeline_array) != IS_ARRAY) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() requires an array pipeline definition.",
            king_ce_validation_exception,
            throw_on_error
        );
    }

    if (control != NULL) {
        control->run_id = run_id;
    }

    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        king_orchestrator_mark_interrupted_run(run_id, cancelled);
        ZVAL_FALSE(return_value);
        return FAILURE;
    }

    /* Clone initial data to return_value as the current placeholder result. */
    ZVAL_COPY(return_value, initial_data);

    ht = Z_ARRVAL_P(pipeline_array);
    ZEND_HASH_FOREACH_VAL(ht, step) {
        cancelled = 0;
        if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            king_orchestrator_mark_interrupted_run(run_id, cancelled);
            return FAILURE;
        }

        if (king_orchestrator_validate_pipeline_step(step, index, throw_on_error) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            (void) king_orchestrator_pipeline_run_fail(run_id, king_get_error());
            return FAILURE;
        }
        cancelled = 0;
        if (king_orchestrator_execute_step_delay(
                step,
                index,
                control,
                function_name,
                throw_on_error,
                &cancelled
            ) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            king_orchestrator_mark_interrupted_run(run_id, cancelled);
            return FAILURE;
        }
        index++;
    } ZEND_HASH_FOREACH_END();

    cancelled = 0;
    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_FALSE(return_value);
        king_orchestrator_mark_interrupted_run(run_id, cancelled);
        return FAILURE;
    }

    if (king_orchestrator_pipeline_run_complete(run_id, return_value) != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_FALSE(return_value);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist the completed run snapshot.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    return SUCCESS;
}

int king_orchestrator_resume_run(zend_string *run_id, zval *return_value)
{
    zval initial_data;
    zval pipeline;
    zval options;
    king_orchestrator_exec_control_t control;
    int rc;

    if (run_id == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(&initial_data);
    ZVAL_NULL(&pipeline);
    ZVAL_NULL(&options);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    if (king_orchestrator_load_run_payload(run_id, &initial_data, &pipeline, &options) != SUCCESS) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not load the persisted run payload.",
            king_ce_runtime_exception,
            0
        );
    }

    if (king_orchestrator_exec_control_parse(
            &options,
            "king_pipeline_orchestrator_worker_run_next",
            1,
            &control
        ) != SUCCESS) {
        zval_ptr_dtor(&initial_data);
        zval_ptr_dtor(&pipeline);
        zval_ptr_dtor(&options);
        king_orchestrator_exec_control_cleanup(&control);
        return FAILURE;
    }

    rc = king_orchestrator_execute_existing_run(
        run_id,
        &initial_data,
        &pipeline,
        return_value,
        &control,
        "king_pipeline_orchestrator_worker_run_next",
        1
    );

    zval_ptr_dtor(&initial_data);
    zval_ptr_dtor(&pipeline);
    zval_ptr_dtor(&options);
    king_orchestrator_exec_control_cleanup(&control);

    return rc;
}

int king_orchestrator_run(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    zend_string *run_id;
    zval sanitized_options;
    zval *persisted_options;
    king_orchestrator_exec_control_t control;
    int rc = FAILURE;

    ZVAL_UNDEF(&sanitized_options);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    if (king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() is unavailable when orchestrator_execution_backend=file_worker; use king_pipeline_orchestrator_dispatch().",
            king_ce_runtime_exception,
            1
        );
    }

    if (king_orchestrator_exec_control_parse(
            options,
            "king_pipeline_orchestrator_run",
            1,
            &control
        ) != SUCCESS) {
        goto cleanup;
    }

    if (king_orchestrator_exec_control_check(&control, "king_pipeline_orchestrator_run", 1, NULL) != SUCCESS) {
        goto cleanup;
    }

    if (king_orchestrator_enforce_max_concurrency(&control, "king_pipeline_orchestrator_run", 1) != SUCCESS) {
        goto cleanup;
    }

    persisted_options = king_orchestrator_prepare_persisted_options(options, &sanitized_options);
    run_id = king_orchestrator_pipeline_run_begin(initial_data, pipeline_array, persisted_options, "running");
    if (persisted_options == &sanitized_options) {
        zval_ptr_dtor(&sanitized_options);
        ZVAL_UNDEF(&sanitized_options);
    }
    if (run_id == NULL) {
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist the initial run snapshot.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    rc = king_orchestrator_execute_existing_run(
        run_id,
        initial_data,
        pipeline_array,
        return_value,
        &control,
        "king_pipeline_orchestrator_run",
        1
    );
    zend_string_release(run_id);
cleanup:
    if (!Z_ISUNDEF(sanitized_options)) {
        zval_ptr_dtor(&sanitized_options);
    }
    king_orchestrator_exec_control_cleanup(&control);
    return rc;
}

int king_orchestrator_dispatch(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    zend_string *run_id;
    zval sanitized_options;
    zval *persisted_options;
    king_orchestrator_exec_control_t control;
    int rc = FAILURE;

    ZVAL_UNDEF(&sanitized_options);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    if (!king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() requires orchestrator_execution_backend=file_worker.",
            king_ce_runtime_exception,
            1
        );
    }

    if (
        king_mcp_orchestrator_config.orchestrator_worker_queue_path == NULL
        || king_mcp_orchestrator_config.orchestrator_worker_queue_path[0] == '\0'
    ) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() requires a non-empty orchestrator_worker_queue_path.",
            king_ce_runtime_exception,
            1
        );
    }

    if (king_orchestrator_exec_control_parse(
            options,
            "king_pipeline_orchestrator_dispatch",
            1,
            &control
        ) != SUCCESS) {
        goto cleanup;
    }

    if (!Z_ISUNDEF(control.cancel_token)) {
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() does not support live CancelToken propagation on the file_worker backend.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    if (king_orchestrator_exec_control_check(&control, "king_pipeline_orchestrator_dispatch", 1, NULL) != SUCCESS) {
        goto cleanup;
    }

    if (king_orchestrator_enforce_max_concurrency(&control, "king_pipeline_orchestrator_dispatch", 1) != SUCCESS) {
        goto cleanup;
    }

    persisted_options = king_orchestrator_prepare_persisted_options(options, &sanitized_options);
    run_id = king_orchestrator_pipeline_run_begin(initial_data, pipeline_array, persisted_options, "queued");
    if (persisted_options == &sanitized_options) {
        zval_ptr_dtor(&sanitized_options);
        ZVAL_UNDEF(&sanitized_options);
    }
    if (run_id == NULL) {
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() failed to persist the initial run snapshot.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    rc = king_orchestrator_enqueue_run(run_id, return_value);
    if (rc != SUCCESS) {
        (void) king_orchestrator_pipeline_run_fail(
            run_id,
            "king_pipeline_orchestrator_dispatch() failed to enqueue the run for the file-worker backend."
        );
        zend_string_release(run_id);
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() failed to enqueue the run for the file-worker backend.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    zend_string_release(run_id);
    rc = SUCCESS;
cleanup:
    if (!Z_ISUNDEF(sanitized_options)) {
        zval_ptr_dtor(&sanitized_options);
    }
    king_orchestrator_exec_control_cleanup(&control);
    return rc;
}

int king_orchestrator_worker_run_next(zval *return_value)
{
    zend_string *run_id = NULL;
    char claimed_path[1024];
    int claimed_fd = -1;
    int rc;

    if (!king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() requires orchestrator_execution_backend=file_worker.",
            king_ce_runtime_exception,
            1
        );
    }

    if (
        king_mcp_orchestrator_config.orchestrator_worker_queue_path == NULL
        || king_mcp_orchestrator_config.orchestrator_worker_queue_path[0] == '\0'
    ) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() requires a non-empty orchestrator_worker_queue_path.",
            king_ce_runtime_exception,
            1
        );
    }

    if (king_orchestrator_claim_next_run(&run_id, claimed_path, sizeof(claimed_path), &claimed_fd) != SUCCESS) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not claim a queued run.",
            king_ce_runtime_exception,
            1
        );
    }

    if (run_id == NULL) {
        if (claimed_fd >= 0) {
            close(claimed_fd);
        }
        ZVAL_FALSE(return_value);
        return SUCCESS;
    }

    if (king_orchestrator_pipeline_run_is_terminal(run_id)) {
        if (claimed_path[0] != '\0') {
            unlink(claimed_path);
        }
        if (claimed_fd >= 0) {
            close(claimed_fd);
        }
        if (king_orchestrator_get_run_snapshot(run_id, return_value) != SUCCESS) {
            zend_string_release(run_id);
            return king_orchestrator_raise_error(
                "king_pipeline_orchestrator_worker_run_next() could not read back the persisted terminal run snapshot.",
                king_ce_runtime_exception,
                1
            );
        }
        zend_string_release(run_id);
        return SUCCESS;
    }

    if (king_orchestrator_pipeline_run_mark_running(run_id) != SUCCESS) {
        if (claimed_path[0] != '\0') {
            unlink(claimed_path);
        }
        if (claimed_fd >= 0) {
            close(claimed_fd);
        }
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not persist the claimed running snapshot.",
            king_ce_runtime_exception,
            1
        );
    }

    ZVAL_NULL(return_value);
    rc = king_orchestrator_resume_run(run_id, return_value);
    zval_ptr_dtor(return_value);
    ZVAL_NULL(return_value);

    if (claimed_path[0] != '\0') {
        unlink(claimed_path);
    }
    if (claimed_fd >= 0) {
        close(claimed_fd);
        claimed_fd = -1;
    }

    if (rc != SUCCESS) {
        if (EG(exception) != NULL) {
            zval cancelled_snapshot;
            zval *status;
            const char *error_message = king_get_error();

            if (
                king_orchestrator_run_cancel_requested(run_id)
                || (
                    error_message != NULL
                    && strstr(error_message, "persisted file-worker cancel channel") != NULL
                )
                || king_orchestrator_exception_message_contains(
                    "persisted file-worker cancel channel"
                )
            ) {
                (void) king_orchestrator_pipeline_run_cancelled(run_id, error_message);
            }

            ZVAL_NULL(&cancelled_snapshot);
            if (king_orchestrator_get_run_snapshot(run_id, &cancelled_snapshot) == SUCCESS) {
                status = zend_hash_str_find(
                    Z_ARRVAL(cancelled_snapshot),
                    "status",
                    sizeof("status") - 1
                );
                if (
                    status != NULL
                    && Z_TYPE_P(status) == IS_STRING
                    && zend_string_equals_literal(Z_STR_P(status), "cancelled")
                ) {
                    zend_clear_exception();
                    zend_string_release(run_id);
                    ZVAL_COPY_VALUE(return_value, &cancelled_snapshot);
                    return SUCCESS;
                }
                zval_ptr_dtor(&cancelled_snapshot);
            }
            if (!king_orchestrator_pipeline_run_is_terminal(run_id)) {
                (void) king_orchestrator_pipeline_run_fail(run_id, error_message);
            }
            zend_string_release(run_id);
            return FAILURE;
        }
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() failed while executing the claimed run.",
            king_ce_runtime_exception,
            1
        );
    }

    if (king_orchestrator_get_run_snapshot(run_id, return_value) != SUCCESS) {
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not read back the persisted run snapshot.",
            king_ce_runtime_exception,
            1
        );
    }

    zend_string_release(run_id);
    return SUCCESS;
}

PHP_FUNCTION(king_pipeline_orchestrator_run)
{
    zval *initial_data;
    zval *pipeline;
    zval *exec_options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(initial_data)
        Z_PARAM_ARRAY(pipeline)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(exec_options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_orchestrator_run(initial_data, pipeline, exec_options, return_value) == SUCCESS) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_dispatch)
{
    zval *initial_data;
    zval *pipeline;
    zval *exec_options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(initial_data)
        Z_PARAM_ARRAY(pipeline)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(exec_options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_orchestrator_dispatch(initial_data, pipeline, exec_options, return_value) == SUCCESS) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_worker_run_next)
{
    ZEND_PARSE_PARAMETERS_NONE();

    if (king_orchestrator_worker_run_next(return_value) == SUCCESS) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_configure_logging)
{
    zval *config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    if (king_orchestrator_configure_logging(config) == SUCCESS) {
        RETURN_TRUE;
    }

    king_set_error("king_pipeline_orchestrator_configure_logging() failed to persist the logging snapshot.");
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "king_pipeline_orchestrator_configure_logging() failed to persist the logging snapshot."
    );
    RETURN_THROWS();
}
