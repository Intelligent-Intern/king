/*
 * Keeps field-value decoding grouped while splitting raw wire reads,
 * range checks, PHP materialization and packed decoding into subfragments.
 */

#include "field_value/read.inc"
#include "field_value/validate.inc"
#include "field_value/materialize.inc"
#include "field_value/packed.inc"
