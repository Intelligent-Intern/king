/*
 * Keeps schema-validation logic grouped while splitting metadata, runtime
 * resolution and constraint checks into bounded subfragments.
 */

#include "schema_validation/state.inc"
#include "schema_validation/metadata.inc"
#include "schema_validation/runtime_resolution.inc"
#include "schema_validation/constraints.inc"
#include "schema_validation/field_definition.inc"
#include "schema_validation/schema_definition.inc"
