/*
 * src/pipeline_orchestrator/orchestrator.c - Orchestrator Pipeline Runner
 * =========================================================================
 *
 * This module implements the core execution loop for pipelines.
 * Pipelines consist of sequential steps that currently validate tool presence,
 * snapshot run state, and return the transformed payload placeholder.
 */
#include "php_king.h"
#include "include/pipeline_orchestrator/orchestrator.h"

static zend_result king_orchestrator_validate_pipeline_step(zval *step, uint32_t index)
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
        king_set_error(message);
        zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", message);
        return FAILURE;
    }

    if (king_orchestrator_lookup_tool(Z_STRVAL_P(tool), Z_STRLEN_P(tool)) == NULL) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_run() references unknown tool '%s'.",
            Z_STRVAL_P(tool)
        );
        king_set_error(message);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", message);
        return FAILURE;
    }

    return SUCCESS;
}

int king_orchestrator_run(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    HashTable *ht;
    zval *step;
    zend_string *run_id;
    uint32_t index = 0;

    if (initial_data == NULL || pipeline_array == NULL || Z_TYPE_P(pipeline_array) != IS_ARRAY) {
        return FAILURE;
    }

    run_id = king_orchestrator_pipeline_run_begin(initial_data, pipeline_array, options);
    if (run_id == NULL) {
        king_set_error("king_pipeline_orchestrator_run() failed to persist the initial run snapshot.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_run() failed to persist the initial run snapshot."
        );
        return FAILURE;
    }

    /* Clone initial data to return_value as the current placeholder result. */
    ZVAL_COPY(return_value, initial_data);

    ht = Z_ARRVAL_P(pipeline_array);
    ZEND_HASH_FOREACH_VAL(ht, step) {
        if (king_orchestrator_validate_pipeline_step(step, index) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            (void) king_orchestrator_pipeline_run_fail(run_id, king_get_error());
            zend_string_release(run_id);
            return FAILURE;
        }
        index++;
    } ZEND_HASH_FOREACH_END();

    if (king_orchestrator_pipeline_run_complete(run_id, return_value) != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_FALSE(return_value);
        king_set_error("king_pipeline_orchestrator_run() failed to persist the completed run snapshot.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_run() failed to persist the completed run snapshot."
        );
        zend_string_release(run_id);
        return FAILURE;
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
