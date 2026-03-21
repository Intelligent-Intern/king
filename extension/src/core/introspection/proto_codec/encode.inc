/*
 * Keeps protobuf encoding helpers grouped while splitting value append,
 * single-field, repeated-field and schema-payload paths into bounded
 * subfragments.
 */

#include "encode/append_value.inc"
#include "encode/field.inc"
#include "encode/repeated.inc"
#include "encode/schema_payload.inc"
