static bool king_proto_runtime_validate_field_value_type(
    const king_proto_runtime_field *field,
    zval *value
)
{
    switch (field->kind) {
        case KING_PROTO_RUNTIME_FIELD_MESSAGE:
            if (Z_TYPE_P(value) != IS_ARRAY && Z_TYPE_P(value) != IS_OBJECT) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects a message object or array, but got %s.",
                    ZSTR_VAL(field->name),
                    zend_zval_type_name(value)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_DOUBLE:
        case KING_PROTO_RUNTIME_FIELD_FLOAT:
            if (Z_TYPE_P(value) != IS_DOUBLE && Z_TYPE_P(value) != IS_LONG) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects a float or integer, but got %s.",
                    ZSTR_VAL(field->name),
                    zend_zval_type_name(value)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_INT32:
        case KING_PROTO_RUNTIME_FIELD_UINT32:
        case KING_PROTO_RUNTIME_FIELD_SINT32:
        case KING_PROTO_RUNTIME_FIELD_INT64:
        case KING_PROTO_RUNTIME_FIELD_UINT64:
        case KING_PROTO_RUNTIME_FIELD_SINT64:
        case KING_PROTO_RUNTIME_FIELD_FIXED64:
        case KING_PROTO_RUNTIME_FIELD_SFIXED64:
        case KING_PROTO_RUNTIME_FIELD_FIXED32:
        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
            if (Z_TYPE_P(value) != IS_LONG) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects an integer, but got %s.",
                    ZSTR_VAL(field->name),
                    zend_zval_type_name(value)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_ENUM:
        {
            zend_long resolved_enum_value;

            return king_proto_runtime_resolve_enum_value(field, value, &resolved_enum_value);
        }

        case KING_PROTO_RUNTIME_FIELD_BOOL:
            if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects a boolean, but got %s.",
                    ZSTR_VAL(field->name),
                    zend_zval_type_name(value)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_STRING:
        case KING_PROTO_RUNTIME_FIELD_BYTES:
            if (Z_TYPE_P(value) != IS_STRING) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects a string, but got %s.",
                    ZSTR_VAL(field->name),
                    zend_zval_type_name(value)
                );
                return false;
            }
            return true;

        default:
            return false;
    }
}
