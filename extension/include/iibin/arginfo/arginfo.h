/*
 * Arginfo for the procedural king_proto_* IIBIN API and the King\IIBIN OO
 * facade. The declarations are consumed through include/iibin/arginfo/index.h.
 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_encode, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_encode_batch, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, records, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_decode, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, binary_data, IS_STRING, 0)
    ZEND_ARG_TYPE_MASK(0, decode_as_object, MAY_BE_BOOL|MAY_BE_STRING|MAY_BE_ARRAY, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_decode_batch, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, binary_records, IS_ARRAY, 0)
    ZEND_ARG_TYPE_MASK(0, decode_as_object, MAY_BE_BOOL|MAY_BE_STRING|MAY_BE_ARRAY, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_define_enum, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, enum_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, enum_values, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_proto_define_schema, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema_name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, schema_definition, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_IIBIN_defineEnum, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, values, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_IIBIN_defineSchema, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, fields, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_IIBIN_encode, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_IIBIN_encodeBatch, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, records, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_IIBIN_decode, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_TYPE_MASK(0, decodeAsObject, MAY_BE_BOOL|MAY_BE_STRING|MAY_BE_ARRAY, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_IIBIN_decodeBatch, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, schema, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, records, IS_ARRAY, 0)
    ZEND_ARG_TYPE_MASK(0, decodeAsObject, MAY_BE_BOOL|MAY_BE_STRING|MAY_BE_ARRAY, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_IIBIN_name, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_IIBIN_no_args, 0, 0, 0)
ZEND_END_ARG_INFO()
