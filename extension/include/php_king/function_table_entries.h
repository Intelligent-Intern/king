/*
 * Root function table for the King extension. Maps the public procedural
 * surface onto shared and module-local binding fragments.
 */

static const zend_function_entry king_functions[] = {
    PHP_FE(king_version, arginfo_king_no_args)
    PHP_FE(king_health, arginfo_king_no_args)
    PHP_FE(king_get_last_error, arginfo_king_no_args)
    PHP_FE(king_new_config, arginfo_king_new_config)
#include "../awaitable/function_entries.h"
#include "../client/function_entries.h"
#include "../db_ingest/function_entries.h"
#include "../server/function_entries.h"
#include "../media/function_entries.h"

#include "../iibin/function_entries.h"

#include "../mcp/function_entries.h"

#include "../pipeline_orchestrator/function_entries.h"

#include "../semantic_dns/function_entries.h"

#include "../object_store/function_entries.h"

#include "../xslt/function_entries.h"
#include "../inference/function_entries.h"

#include "../telemetry/function_entries.h"

#include "../autoscaling/function_entries.h"

#include "../integration/function_entries.h"

    PHP_FE_END
};
