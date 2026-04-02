/*
 * Root object-store introspection aggregator: keeps the object-store and
 * CDN-facing introspection slices grouped in one logical fragment while the
 * heavier runtime details live in the dedicated subdirectory.
 */

#include "object_store/init.inc"
#include "object_store/stats.inc"
#include "object_store/registry.inc"
#include "object_store/cdn.inc"
