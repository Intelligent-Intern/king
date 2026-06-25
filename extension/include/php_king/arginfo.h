/*
 * Aggregates the arginfo sets used by the extension entry unit.
 *
 * The concrete arginfo declarations live under extension/include in each
 * owning subsystem. This header keeps php_king.c from reaching into every
 * module directly.
 */
#ifndef KING_PHP_KING_ARGINFO_H
#define KING_PHP_KING_ARGINFO_H

#ifdef __cplusplus
extern "C" {
#endif

#include "core_arginfo.h"
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

#endif /* KING_PHP_KING_ARGINFO_H */
