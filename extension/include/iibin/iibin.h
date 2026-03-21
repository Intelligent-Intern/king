/*
 * include/iibin/iibin.h - Public C API for IIBIN serialization
 * ============================================================
 *
 * This header exposes the low-level schema and enum registration functions,
 * plus the encode/decode helpers used by the IIBIN wire-format layer.
 */

#ifndef KING_PROTO_H
#define KING_PROTO_H

#include <php.h>

/* Defines an enum used by IIBIN schemas. */
PHP_FUNCTION(king_proto_define_enum);

/* Defines and compiles a schema for encoding and decoding. */
PHP_FUNCTION(king_proto_define_schema);

/* Encodes a PHP value using a predefined schema. */
PHP_FUNCTION(king_proto_encode);

/* Decodes a binary string using a predefined schema. */
PHP_FUNCTION(king_proto_decode);

/* Checks whether a schema is registered. */
PHP_FUNCTION(king_proto_is_defined);

/* Checks whether a message schema is registered. */
PHP_FUNCTION(king_proto_is_schema_defined);

/* Checks whether an enum is registered. */
PHP_FUNCTION(king_proto_is_enum_defined);

/* Returns the registered schema names. */
PHP_FUNCTION(king_proto_get_defined_schemas);

/* Returns the registered enum names. */
PHP_FUNCTION(king_proto_get_defined_enums);


#endif /* KING_PROTO_H */
