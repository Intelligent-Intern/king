/*
 * Root function table for the King extension. Maps the public procedural
 * surface onto shared and module-local binding fragments.
 */

static const zend_function_entry king_functions[] = {
    PHP_FE(king_version, arginfo_king_no_args)
    PHP_FE(king_health, arginfo_king_no_args)
    PHP_FE(king_get_last_error, arginfo_king_no_args)
    PHP_FE(king_new_config, arginfo_king_new_config)
#include "../awaitable/function_entries.inc"
#include "../client/function_entries.inc"
#include "../db_ingest/function_entry.inc"
#include "../server/function_entries.inc"
#include "../media/function_entries.inc"

#include "../iibin/function_entries.inc"

#include "../mcp/php_binding/function_entries.inc"

#include "../pipeline_orchestrator/function_entries.inc"

#include "../semantic_dns/function_entries.inc"

#include "../object_store/function_entries.inc"

#include "../xslt/function_entries.inc"
#include "../inference/function_entries.inc"

#include "../telemetry/function_entries.inc"

#include "../autoscaling/function_entries.inc"

#include "../integration/function_entries.inc"

    PHP_FE_END
};
