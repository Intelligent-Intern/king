/*
 * Public IIBIN facade bindings. Declares the King\IIBIN static method table
 * so the OO surface maps directly onto the underlying king_proto_* procedural
 * entry points. Arginfo lives in the module binding fragment.
 */

#include "php.h"
#include "iibin/iibin_internal.h"

PHP_FUNCTION(king_proto_define_enum);
PHP_FUNCTION(king_proto_define_schema);
PHP_FUNCTION(king_proto_encode);
PHP_FUNCTION(king_proto_encode_batch);
PHP_FUNCTION(king_proto_decode);
PHP_FUNCTION(king_proto_decode_batch);
PHP_FUNCTION(king_proto_is_defined);
PHP_FUNCTION(king_proto_is_schema_defined);
PHP_FUNCTION(king_proto_is_enum_defined);
PHP_FUNCTION(king_proto_get_defined_schemas);
PHP_FUNCTION(king_proto_get_defined_enums);

#include "iibin/class_method_entries.h"
