/*
 * =========================================================================
 * FILENAME:   src/php_king.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Main extension entry point. Defines the zend_module_entry, registers
 * all PHP functions, classes, exception hierarchy, and resource types.
 *
 * RUNTIME STATUS:
 * - MINIT wires all config modules and registers their INI directives
 * - No legacy QUIC backend config is created during MINIT
 * - Exception classes register in the correct hierarchy
 * - The first OO class entries now include active Config/Session wrappers
 *   over the same Runtime resource runtime; broader method parity and the
 *   remaining object-backed classes are still pending
 * - Resource type handles bootstrap as -1 until MINIT registers them
 * - Core health/version and a small config-backed introspection surface are real
 * =========================================================================
 */

#ifdef HAVE_CONFIG_H
#  include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "zend_exceptions.h"
#include "zend_object_handlers.h"

#include "php_king.h"
#include "include/king_globals.h"
#include "include/king_init.h"

#include "php_king/state.inc"
#include "php_king/externals.inc"
#include "php_king/arginfo.inc"
#include "db_ingest/arginfo.inc"
#include "object_store/arginfo.inc"
#include "object_store/class_methods.inc"
#include "autoscaling/arginfo.inc"
#include "autoscaling/class_methods.inc"
#include "telemetry/arginfo.inc"
#include "semantic_dns/arginfo.inc"
#include "inference/arginfo.inc"
#include "xslt/arginfo.inc"
#include "mcp/php_binding/arginfo.inc"
#include "pipeline_orchestrator/arginfo.inc"
#include "pipeline_orchestrator/class_methods.inc"
#include "db_ingest/api.inc"
#include "php_king/function_table.inc"
#include "php_king/resources.inc"
#include "php_king/exceptions.inc"
#include "php_king/classes.inc"
#include "awaitable/cancel_token.inc"
#include "awaitable/cancel_token_object_handlers.inc"
#include "config/internal/object_handlers.inc"
#include "mcp/php_binding.inc"
#include "media/php_binding.inc"
#include "xslt/php_binding.inc"
#include "inference/api.inc"
#include "client/session/object_handlers.inc"
#include "client/object_handlers.inc"
#include "client/websocket/object_handlers.inc"
#include "server/http1/websocket_server_object_handlers.inc"
#include "awaitable/awaitable.inc"
#include "php_king/lifecycle.inc"
#include "php_king/module_entry.inc"
