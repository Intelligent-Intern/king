/*
 * Shared php_king module state. Declares core exception class pointers,
 * includes state storage for modules that still compile through the core
 * bootstrap, and owns the shared error buffer reused across bootstrap slices.
 */

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

#include "../server/state.inc"

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
