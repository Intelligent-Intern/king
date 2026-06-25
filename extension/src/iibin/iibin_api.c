/*
 * Public IIBIN facade bindings. Declares the King\IIBIN static method table
 * so the OO surface maps directly onto the underlying king_proto_* procedural
 * entry points. Arginfo lives in the module binding fragment.
 */

#include "php.h"
#include "include/iibin/iibin_internal.h"

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

const zend_function_entry king_iibin_class_methods[] = {
    ZEND_ME_MAPPING(defineEnum, king_proto_define_enum, arginfo_class_King_IIBIN_defineEnum, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(defineSchema, king_proto_define_schema, arginfo_class_King_IIBIN_defineSchema, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(encode, king_proto_encode, arginfo_class_King_IIBIN_encode, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(encodeBatch, king_proto_encode_batch, arginfo_class_King_IIBIN_encodeBatch, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(decode, king_proto_decode, arginfo_class_King_IIBIN_decode, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(decodeBatch, king_proto_decode_batch, arginfo_class_King_IIBIN_decodeBatch, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(isDefined, king_proto_is_defined, arginfo_class_King_IIBIN_name, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(isSchemaDefined, king_proto_is_schema_defined, arginfo_class_King_IIBIN_name, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(isEnumDefined, king_proto_is_enum_defined, arginfo_class_King_IIBIN_name, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getDefinedSchemas, king_proto_get_defined_schemas, arginfo_class_King_IIBIN_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getDefinedEnums, king_proto_get_defined_enums, arginfo_class_King_IIBIN_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};
