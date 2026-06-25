/*
 * External declarations shared across php_king bootstrap slices. Core entry
 * points and module-owned lifecycle hooks are pulled through include-owned
 * subsystem headers.
 */

#include "php_king/public_functions.h"
#include "config/config.h"

#include "../awaitable/externals.inc"
#include "../client/externals.inc"
#include "../db_ingest/externals.inc"
#include "../iibin/externals.inc"
#include "../server/externals.inc"
#include "../media/externals.inc"
#include "../mcp/php_binding/externals.inc"
#include "../pipeline_orchestrator/externals.inc"
#include "../semantic_dns/externals.inc"
#include "../object_store/externals.inc"
#include "../xslt/externals.inc"
#include "../inference/externals.inc"
#include "../telemetry/externals.inc"
#include "../autoscaling/externals.inc"
#include "../integration/externals.inc"
