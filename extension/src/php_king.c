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
 * - OO class and object-handler registration is delegated through
 *   module-owned registration hooks
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
#include "king_globals.h"
#include "king_init.h"

#include "php_king/state.inc"
#include "php_king/arginfo.h"
#include "php_king/externals.h"
#include "php_king/function_table.h"
#include "php_king/registration.h"
#include "php_king/resources.h"
#include "autoscaling/registration.h"
#include "awaitable/registration.h"
#include "client/registration.h"
#include "config/internal/registration.h"
#include "inference/registration.h"
#include "mcp/registration.h"
#include "media/registration.h"
#include "object_store/registration.h"
#include "pipeline_orchestrator/registration.h"
#include "server/registration.h"
#include "xslt/registration.h"
#include "php_king/resources.inc"
#include "php_king/exceptions.inc"
#include "php_king/classes.inc"
#include "php_king/module_bindings.inc"
#include "php_king/module_registrations.inc"
#include "php_king/class_registration.inc"
#include "php_king/lifecycle.inc"
#include "php_king/module_entry.inc"
