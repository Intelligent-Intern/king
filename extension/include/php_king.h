/*
 * =========================================================================
 * FILENAME:   php_king.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Central public header for the extension. It exposes the shared constants,
 * core object wrappers, and helper prototypes used across the active C
 * sources. Bootstrap-owned extern declarations live under include/php_king.
 * =========================================================================
 */

#ifndef PHP_KING_H
#define PHP_KING_H

#ifdef HAVE_CONFIG_H
#  include "config.h"
#endif

#include <php.h>
#include <zend_object_handlers.h>
#include <Zend/zend_execute.h>
#include <zend_exceptions.h>
#include <stdint.h>
#include <string.h>
#include <stdatomic.h>
#include <stdbool.h>

/* Include core headers required in every build. */
#include "php_king/constants.h"
#include "king_globals.h"
#include "king_init.h"
#include "awaitable/cancel_token.h"
#include "client/session.h"
#include "client/objects.h"
#include "client/websocket.h"
#include "config/object.h"
#include "server/websocket.h"

/*
 * Keep this header lightweight for the current v1 runtime surface so the
 * extension can compile without pulling in the full native dependency graph.
 */
#ifndef KING_RUNTIME_BUILD
#  include "client/cancel.h"
#  include "client/tls.h"
#  include "config/config.h"
#  include "connect/connect.h"
#  include "client/http3.h"
#  include "poll/poll.h"
#  include "websocket/websocket.h"
#endif /* KING_RUNTIME_BUILD */

#include "awaitable/index.h"
#include "iibin/index.h"
#include "inference/index.h"
#include "mcp/index.h"
#include "php_king/class_entries.h"
#include "php_king/method_tables.h"
#include "php_king/public_functions.h"
#include "php_king/registration.h"
#include "php_king/resource_ids.h"
#include "php_king/resources.h"
#include "php_king/runtime_contracts.h"
#include "php_king/runtime_helpers.h"
#include "xslt/index.h"

#endif /* PHP_KING_H */
