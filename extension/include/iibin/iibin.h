/*
 * include/iibin/iibin.h - Public PHP entry points for IIBIN/proto
 * ===============================================================
 *
 * This header declares the exported PHP functions behind the `king_proto_*`
 * surface and the `King\IIBIN` static API. The low-level registry and
 * wire-format helpers live in `iibin_internal.h`.
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

/* Encodes multiple PHP values using a predefined schema. */
PHP_FUNCTION(king_proto_encode_batch);

/* Decodes a binary string using a predefined schema. */
PHP_FUNCTION(king_proto_decode);

/* Decodes multiple binary strings using a predefined schema. */
PHP_FUNCTION(king_proto_decode_batch);

/* Checks whether a schema or enum is registered under the given name. */
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
