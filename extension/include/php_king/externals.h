/*
 * include/php_king/externals.h - Module-entry function declaration aggregation
 *
 * The concrete PHP_FUNCTION declarations live under extension/include in each
 * owning subsystem. This include-side anchor feeds the extension translation
 * unit without reaching back into extension/src.
 */

#ifndef KING_PHP_KING_EXTERNALS_H
#define KING_PHP_KING_EXTERNALS_H

#include "public_functions.h"
#include "config/config.h"

#include "autoscaling/index.h"
#include "awaitable/index.h"
#include "client/index.h"
#include "db_ingest/index.h"
#include "iibin/index.h"
#include "iibin/iibin_internal.h"
#include "inference/index.h"
#include "integration/index.h"
#include "mcp/index.h"
#include "media/index.h"
#include "object_store/index.h"
#include "object_store/object_store_internal.h"
#include "pipeline_orchestrator/index.h"
#include "semantic_dns/index.h"
#include "semantic_dns/semantic_dns_internal.h"
#include "server/index.h"
#include "telemetry/index.h"
#include "xslt/index.h"

#endif /* KING_PHP_KING_EXTERNALS_H */
