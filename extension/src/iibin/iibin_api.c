/*
 * Public IIBIN facade bindings. Declares the King\IIBIN static method table
 * so the OO surface maps directly onto the underlying king_proto_* procedural
 * entry points. Arginfo lives in the module binding fragment.
 */

#include "php.h"
#include "iibin/iibin.h"
#include "iibin/iibin_internal.h"

#include "iibin/class_method_entries.h"
