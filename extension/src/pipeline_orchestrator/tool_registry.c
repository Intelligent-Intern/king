/*
 * Pipeline-orchestrator registry and durable run-state store. Owns the tool
 * registry, logging snapshot, persisted run metadata and the recovery helpers
 * that reload orchestrator state after restart.
 */
#include "php_king.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/pipeline_orchestrator/orchestrator.h"

#include "ext/standard/base64.h"
#include "ext/standard/php_var.h"
#include "main/fopen_wrappers.h"
#include "zend_smart_str.h"

#include <dirent.h>
#include <errno.h>
#include <fcntl.h>
#include <sys/file.h>
#include <stdlib.h>
#include <stdio.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>
#include <zend_hash.h>

#define KING_ORCHESTRATOR_STATE_VERSION 5

typedef struct _king_orchestrator_run_state {
    zend_string *run_id;
    zend_string *status;
    zend_string *execution_backend;
    zend_string *queue_phase;
    time_t started_at;
    time_t finished_at;
    time_t enqueued_at;
    time_t last_claimed_at;
    time_t last_recovered_at;
    time_t last_remote_attempt_at;
    zend_bool cancel_requested;
    zend_string *initial_data_b64;
    zend_string *pipeline_b64;
    zend_string *options_b64;
    zend_string *telemetry_parent_context_b64;
    zend_string *result_b64;
    zend_string *error_b64;
    zend_string *error_category;
    zend_string *retry_disposition;
    zend_string *error_backend;
    zend_string *last_recovery_reason;
    zend_long error_step_index;
    zend_long completed_step_count;
    zend_long claim_count;
    zend_long recovery_count;
    zend_long remote_attempt_count;
    zend_long last_claimed_by_pid;
} king_orchestrator_run_state_t;

static HashTable king_orchestrator_tool_registry;
static HashTable king_orchestrator_pipeline_runs;
static zend_string *king_orchestrator_logging_config_b64 = NULL;
static zend_string *king_orchestrator_last_run_id = NULL;
static zend_string *king_orchestrator_last_run_status = NULL;
static bool king_orchestrator_registry_initialized = false;
static bool king_orchestrator_recovered_from_state = false;
static zend_long king_orchestrator_next_run_id = 1;
static king_orchestrator_run_state_t *king_orchestrator_find_run(zend_string *run_id);
static int king_orchestrator_persist_state_locked(void);
static int king_orchestrator_load_state(void);


#include "tool_registry/state_helpers.inc"
#include "tool_registry/path_and_queue_io.inc"
#include "tool_registry/state_persistence.inc"
#include "tool_registry/snapshot_helpers.inc"
#include "tool_registry/queue_control.inc"
#include "tool_registry/registry_runtime.inc"
#include "tool_registry/finish_and_api.inc"
