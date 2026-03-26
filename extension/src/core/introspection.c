/*
 * =========================================================================
 * FILENAME:   src/core/introspection.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Keeps the runtime introspection runtime in one translation unit while
 * splitting the former monolith into bounded, domain-focused fragments.
 * This preserves the current runtime behavior and static helper visibility
 * without letting a single source file grow without bound.
 * =========================================================================
 */

#include "include/pipeline_orchestrator/orchestrator.h"
#include "introspection/prelude.inc"
#include "introspection/telemetry.inc"
#include "introspection/object_store.inc"
#include "introspection/semantic_dns.inc"
#include "introspection/proto_api.inc"
#include "introspection/system.inc"
