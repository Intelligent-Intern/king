/*
 * include/php_king/class_entries.h - Core class-entry externs and module aggregation
 */

#ifndef KING_PHP_KING_CLASS_ENTRIES_H
#define KING_PHP_KING_CLASS_ENTRIES_H

#include <php.h>

extern zend_class_entry
    *king_ce_exception,
    *king_ce_stream_exception,
    *king_ce_invalid_state,
    *king_ce_unknown_stream,
    *king_ce_stream_blocked,
    *king_ce_stream_limit,
    *king_ce_final_size,
    *king_ce_stream_stopped,
    *king_ce_fin_expected,
    *king_ce_invalid_fin_state,
    *king_ce_done,
    *king_ce_quic_exception,
    *king_ce_congestion_control,
    *king_ce_too_many_streams,
    *king_ce_runtime_exception,
    *king_ce_system_exception,
    *king_ce_validation_exception,
    *king_ce_timeout_exception,
    *king_ce_network_exception,
    *king_ce_tls_exception,
    *king_ce_protocol_exception;

#include "autoscaling/class_entries.h"
#include "awaitable/class_entries.h"
#include "client/class_entries.h"
#include "config/internal/class_entries.h"
#include "inference/class_entries.h"
#include "mcp/class_entries.h"
#include "media/class_entries.h"
#include "object_store/class_entries.h"
#include "pipeline_orchestrator/class_entries.h"
#include "server/class_entries.h"
#include "xslt/class_entries.h"

#endif /* KING_PHP_KING_CLASS_ENTRIES_H */
