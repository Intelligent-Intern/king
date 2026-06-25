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
#include "php_king/globals.h"
#include "php_king/init.h"
#include "awaitable/index.h"
#include "client/index.h"
#include "config/index.h"
#include "server/index.h"

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
