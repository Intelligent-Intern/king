static bool king_proto_runtime_persist_integer_default_value(
    const king_proto_runtime_field *field,
    zval *value,
    zend_string *schema_name,
    zval *default_value_out)
{
    switch (field->kind) {
        case KING_PROTO_RUNTIME_FIELD_INT32:
        case KING_PROTO_RUNTIME_FIELD_SINT32:
            if (Z_LVAL_P(value) < INT32_MIN || Z_LVAL_P(value) > INT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Field '%s' default expects a 32-bit signed integer, but got %ld.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            break;

        case KING_PROTO_RUNTIME_FIELD_UINT32:
            if (Z_LVAL_P(value) < 0 || (uint64_t) Z_LVAL_P(value) > UINT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Field '%s' default expects an unsigned 32-bit integer, but got %ld.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            break;

        case KING_PROTO_RUNTIME_FIELD_UINT64:
            if (Z_LVAL_P(value) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Field '%s' default expects an unsigned 64-bit integer, but got %ld.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            break;

        case KING_PROTO_RUNTIME_FIELD_FIXED32:
            if (Z_LVAL_P(value) < 0 || (uint64_t) Z_LVAL_P(value) > UINT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Field '%s' default expects an unsigned 32-bit fixed integer, but got %ld.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            break;

        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
            if (Z_LVAL_P(value) < INT32_MIN || Z_LVAL_P(value) > INT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Field '%s' default expects a 32-bit signed fixed integer, but got %ld.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            break;

        case KING_PROTO_RUNTIME_FIELD_FIXED64:
            if (Z_LVAL_P(value) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Field '%s' default expects an unsigned 64-bit fixed integer, but got %ld.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            break;

        default:
            break;
    }

    ZVAL_LONG(default_value_out, Z_LVAL_P(value));
    return true;
}
