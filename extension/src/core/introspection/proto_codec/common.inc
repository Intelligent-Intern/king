/*
 * Keeps shared protobuf codec helpers grouped while splitting oneof tracking,
 * field lookup, skip logic, validation and forward declarations into
 * bounded subfragments.
 */

#include "common/oneof.inc"
#include "common/field_lookup.inc"
#include "common/skip_unknown.inc"
#include "common/validate_type.inc"
#include "common/validate_range.inc"
#include "common/validate.inc"
#include "common/forward.inc"
