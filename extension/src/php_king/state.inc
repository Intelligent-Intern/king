/*
 * Shared php_king module state. Declares resource ids, core exception class
 * pointers, module class-entry storage, and the shared error buffer reused
 * across the bootstrap slices.
 */

int le_king_session = -1;
int le_king_cfg     = -1;
int le_king_perf    = -1;
int le_king_mcp     = -1;
int le_king_ws      = -1;
int le_king_request_context = -1;

zend_class_entry *king_ce_exception            = NULL;
zend_class_entry *king_ce_stream_exception     = NULL;
zend_class_entry *king_ce_invalid_state        = NULL;
zend_class_entry *king_ce_unknown_stream       = NULL;
zend_class_entry *king_ce_stream_blocked       = NULL;
zend_class_entry *king_ce_stream_limit         = NULL;
zend_class_entry *king_ce_final_size           = NULL;
zend_class_entry *king_ce_stream_stopped       = NULL;
zend_class_entry *king_ce_fin_expected         = NULL;
zend_class_entry *king_ce_invalid_fin_state    = NULL;
zend_class_entry *king_ce_done                 = NULL;
zend_class_entry *king_ce_quic_exception       = NULL;
zend_class_entry *king_ce_congestion_control   = NULL;
zend_class_entry *king_ce_too_many_streams     = NULL;
zend_class_entry *king_ce_runtime_exception    = NULL;
zend_class_entry *king_ce_system_exception     = NULL;
zend_class_entry *king_ce_validation_exception = NULL;
zend_class_entry *king_ce_timeout_exception    = NULL;
zend_class_entry *king_ce_network_exception    = NULL;
zend_class_entry *king_ce_tls_exception        = NULL;
zend_class_entry *king_ce_protocol_exception   = NULL;

#include "../autoscaling/class_entries.inc"
#include "../awaitable/class_entries.inc"
#include "../client/class_entries.inc"
#include "../config/internal/class_entries.inc"
#include "../inference/class_entries.inc"
#include "../mcp/php_binding/class_entries.inc"
#include "../media/class_entries.inc"
#include "../object_store/class_entries.inc"
#include "../pipeline_orchestrator/class_entries.inc"
#include "../server/class_entries.inc"
#include "../xslt/class_entries.inc"

char king_last_error[KING_ERR_LEN] = {0};

void king_set_error(const char *msg)
{
    if (msg) {
        strncpy(king_last_error, msg, KING_ERR_LEN - 1);
        king_last_error[KING_ERR_LEN - 1] = '\0';
    }
}

const char *king_get_error(void)
{
    return king_last_error;
}
