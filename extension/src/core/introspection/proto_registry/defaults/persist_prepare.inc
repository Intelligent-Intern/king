static bool king_proto_runtime_persist_default_value_prepare(
    const king_proto_runtime_field *field,
    zval *value,
    zend_string *schema_name,
    zval *default_value_out,
    bool *completed)
{
    *completed = false;

    switch (field->kind) {
        case KING_PROTO_RUNTIME_FIELD_DOUBLE:
        case KING_PROTO_RUNTIME_FIELD_FLOAT:
            if (Z_TYPE_P(value) != IS_DOUBLE && Z_TYPE_P(value) != IS_LONG) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Field '%s' default expects a float or integer, but got %s.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    zend_zval_type_name(value)
                );
                return false;
            }
            ZVAL_DOUBLE(default_value_out, zval_get_double(value));
            *completed = true;
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
                    "Schema '%s': Field '%s' default expects an integer, but got %s.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    zend_zval_type_name(value)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_ENUM:
            *completed = true;
            return king_proto_runtime_persist_enum_default_value(
                field,
                value,
                schema_name,
                default_value_out
            );

        case KING_PROTO_RUNTIME_FIELD_BOOL:
            if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Field '%s' default expects a boolean, but got %s.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    zend_zval_type_name(value)
                );
                return false;
            }
            ZVAL_BOOL(default_value_out, Z_TYPE_P(value) == IS_TRUE);
            *completed = true;
            return true;

        case KING_PROTO_RUNTIME_FIELD_STRING:
        case KING_PROTO_RUNTIME_FIELD_BYTES:
            if (Z_TYPE_P(value) != IS_STRING) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Field '%s' default expects a string, but got %s.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    zend_zval_type_name(value)
                );
                return false;
            }
            ZVAL_STR(default_value_out, zend_string_dup(Z_STR_P(value), 1));
            *completed = true;
            return true;

        default:
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot use a runtime default.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field->name)
            );
            return false;
    }
}
