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
#include "php_king/index.h"

#include "autoscaling/index.h"
#include "awaitable/index.h"
#include "client/index.h"
#include "config/index.h"
#include "db_ingest/index.h"
#include "iibin/index.h"
#include "inference/index.h"
#include "integration/index.h"
#include "king_init/ticket_ring.h"
#include "media/index.h"
#include "mcp/index.h"
#include "object_store/index.h"
#include "pipeline_orchestrator/index.h"
#include "runtime/index.h"
#include "semantic_dns/index.h"
#include "server/index.h"
#include "telemetry/index.h"
#include "validation/index.h"
#include "xslt/index.h"

#endif /* PHP_KING_H */
