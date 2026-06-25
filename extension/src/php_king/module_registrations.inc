/*
 * Module-owned class/object-handler registration implementations still
 * compiled into the core bootstrap. Migrated subsystems export their
 * registration hooks from their owning translation unit.
 */

#include "../config/registration.inc"
#include "../client/registration.inc"
#include "../pipeline_orchestrator/registration.inc"
#include "../server/registration.inc"
