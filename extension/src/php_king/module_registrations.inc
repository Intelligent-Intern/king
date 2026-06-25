/*
 * Module-owned class/object-handler registration implementations still
 * compiled into the core bootstrap. Migrated subsystems export their
 * registration hooks from their owning translation unit.
 */

#include "../client/registration.inc"
#include "../server/registration.inc"
