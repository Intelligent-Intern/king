/*
 * Core pipeline-orchestrator runner. Owns the execution loop, run-control and
 * cancel semantics, remote-peer/file-worker handoff paths, step-level error
 * classification and the persisted step snapshots used by resume/recovery.
 */
#include "php_king.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/pipeline_orchestrator/orchestrator.h"
#include "include/telemetry/telemetry.h"
#include "ext/standard/base64.h"
#include "ext/standard/php_var.h"
#include "Zend/zend_hrtime.h"
#include "zend_smart_str.h"
#include <time.h>
#include <unistd.h>

typedef struct _king_orchestrator_exec_control {
    zend_long timeout_ms;
    zend_long max_concurrency;
    uint64_t deadline_ms;
    uint64_t started_at_ms;
    zval cancel_token;
    zend_string *run_id;
} king_orchestrator_exec_control_t;

typedef struct _king_orchestrator_error_meta {
    char category[32];
    char retry_disposition[32];
    char backend[32];
    zend_long step_index;
    zend_bool has_classification;
} king_orchestrator_error_meta_t;


#include "orchestrator/control_and_errors.inc"
#include "orchestrator/remote_transport.inc"
#include "orchestrator/pipeline_execution.inc"
#include "orchestrator/runtime_api.inc"
