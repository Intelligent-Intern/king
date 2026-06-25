/*
 * External declarations shared across php_king bootstrap slices. Core entry
 * points stay here; module-owned lifecycle hooks and PHP functions are pulled
 * through module-local externals fragments.
 */

PHP_FUNCTION(king_version);
PHP_FUNCTION(king_health);
PHP_FUNCTION(king_get_last_error);

PHP_FUNCTION(king_new_config);

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
