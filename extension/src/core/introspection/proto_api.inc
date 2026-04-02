/*
 * Root proto API aggregator: keeps the public schema/model/codec predicate
 * surfaces grouped in one logical fragment while the implementation detail
 * stays in the dedicated proto_api subdirectory.
 */

#include "proto_api/read_model.inc"
#include "proto_api/enum_definition.inc"
#include "proto_api/schema_definition.inc"
#include "proto_api/codec.inc"
#include "proto_api/predicates.inc"
