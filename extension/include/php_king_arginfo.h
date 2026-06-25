/*
 * Aggregates the arginfo sets used by the extension entry unit.
 *
 * The concrete arginfo declarations stay owned by their module-local binding
 * fragments under extension/src. This header is the public include-side anchor
 * that keeps php_king.c from reaching into every module directly.
 */
#ifndef PHP_KING_ARGINFO_H
#define PHP_KING_ARGINFO_H

#ifdef __cplusplus
extern "C" {
#endif

#include "../src/php_king/arginfo.inc"
#include "client/arginfo/index.h"
#include "awaitable/arginfo/index.h"
#include "db_ingest/arginfo/index.h"
#include "iibin/arginfo/index.h"
#include "inference/arginfo/index.h"
#include "integration/arginfo/index.h"
#include "media/arginfo/index.h"
#include "mcp/arginfo/index.h"
#include "object_store/arginfo/index.h"
#include "pipeline_orchestrator/arginfo/index.h"
#include "semantic_dns/arginfo/index.h"
#include "server/arginfo/index.h"
#include "telemetry/arginfo/index.h"
#include "autoscaling/arginfo/index.h"
#include "xslt/arginfo/index.h"

#ifdef __cplusplus
}
#endif

#endif /* PHP_KING_ARGINFO_H */
