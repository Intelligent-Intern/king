/*
 * Request-lifecycle hooks for King. The active RINIT path is intentionally
 * thin, while RSHUTDOWN clears the last-error buffer and tears down request-
 * scoped telemetry state.
 */

#include "include/pipeline_orchestrator/orchestrator.h"
#include "include/telemetry/telemetry.h"

int king_request_init(int type, int module_number)
{
    (void) type;
    (void) module_number;
    return king_orchestrator_runtime_handlers_init();
}

int king_request_shutdown(int type, int module_number)
{
    king_orchestrator_runtime_handlers_shutdown();
    king_secure_zero(king_last_error, sizeof(king_last_error));
    king_telemetry_shutdown_system();
    king_telemetry_metrics_shutdown();
    (void) type;
    (void) module_number;
    return SUCCESS;
}
