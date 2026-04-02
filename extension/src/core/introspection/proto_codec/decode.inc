/*
 * Keeps protobuf decoding helpers grouped while splitting temporary storage,
 * field-value decode, and schema-payload reconstruction into bounded
 * subfragments.
 */

#include "decode/storage.inc"
#include "decode/field_value.inc"
#include "decode/schema_payload.inc"
