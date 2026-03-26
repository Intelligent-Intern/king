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

static int king_orchestrator_backend_is_file_worker(void)
{
    return king_mcp_orchestrator_config.orchestrator_execution_backend != NULL
        && strcmp(king_mcp_orchestrator_config.orchestrator_execution_backend, "file_worker") == 0;
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

    return SUCCESS;
}

static int king_orchestrator_execute_existing_run(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline_array,
    zval *return_value,
    zend_bool throw_on_error)
{
    HashTable *ht;
    zval *step;
    uint32_t index = 0;

    if (initial_data == NULL || pipeline_array == NULL || Z_TYPE_P(pipeline_array) != IS_ARRAY) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() requires an array pipeline definition.",
            king_ce_validation_exception,
            throw_on_error
        );
    }

    /* Clone initial data to return_value as the current placeholder result. */
    ZVAL_COPY(return_value, initial_data);

    ht = Z_ARRVAL_P(pipeline_array);
    ZEND_HASH_FOREACH_VAL(ht, step) {
        if (king_orchestrator_validate_pipeline_step(step, index, throw_on_error) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            (void) king_orchestrator_pipeline_run_fail(run_id, king_get_error());
            return FAILURE;
        }
        index++;
    } ZEND_HASH_FOREACH_END();

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
    int rc;

    if (run_id == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(&initial_data);
    ZVAL_NULL(&pipeline);
    ZVAL_NULL(&options);

    if (king_orchestrator_load_run_payload(run_id, &initial_data, &pipeline, &options) != SUCCESS) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not load the persisted run payload.",
            king_ce_runtime_exception,
            0
        );
    }

    rc = king_orchestrator_execute_existing_run(
        run_id,
        &initial_data,
        &pipeline,
        return_value,
        0
    );

    zval_ptr_dtor(&initial_data);
    zval_ptr_dtor(&pipeline);
    zval_ptr_dtor(&options);

    return rc;
}

int king_orchestrator_run(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    zend_string *run_id;
    int rc;

    if (king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() is unavailable when orchestrator_execution_backend=file_worker; use king_pipeline_orchestrator_dispatch().",
            king_ce_runtime_exception,
            1
        );
    }

    run_id = king_orchestrator_pipeline_run_begin(initial_data, pipeline_array, options);
    if (run_id == NULL) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist the initial run snapshot.",
            king_ce_runtime_exception,
            1
        );
    }

    rc = king_orchestrator_execute_existing_run(run_id, initial_data, pipeline_array, return_value, 1);
    zend_string_release(run_id);

    return rc;
}

int king_orchestrator_dispatch(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    zend_string *run_id;
    int rc;

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

    run_id = king_orchestrator_pipeline_run_begin(initial_data, pipeline_array, options);
    if (run_id == NULL) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() failed to persist the initial run snapshot.",
            king_ce_runtime_exception,
            1
        );
    }

    rc = king_orchestrator_enqueue_run(run_id, return_value);
    if (rc != SUCCESS) {
        (void) king_orchestrator_pipeline_run_fail(
            run_id,
            "king_pipeline_orchestrator_dispatch() failed to enqueue the run for the file-worker backend."
        );
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() failed to enqueue the run for the file-worker backend.",
            king_ce_runtime_exception,
            1
        );
    }

    zend_string_release(run_id);
    return SUCCESS;
}

int king_orchestrator_worker_run_next(zval *return_value)
{
    zend_string *run_id = NULL;
    char claimed_path[1024];
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

    if (king_orchestrator_claim_next_run(&run_id, claimed_path, sizeof(claimed_path)) != SUCCESS) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not claim a queued run.",
            king_ce_runtime_exception,
            1
        );
    }

    if (run_id == NULL) {
        ZVAL_FALSE(return_value);
        return SUCCESS;
    }

    ZVAL_NULL(return_value);
    rc = king_orchestrator_resume_run(run_id, return_value);
    zval_ptr_dtor(return_value);
    ZVAL_NULL(return_value);

    if (claimed_path[0] != '\0') {
        unlink(claimed_path);
    }

    if (rc != SUCCESS) {
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
