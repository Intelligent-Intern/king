/*
 * Common codec range-validation slice: enforces the numeric bounds and enum
 * value constraints that the active runtime supports for scalar fields.
 */

static bool king_proto_runtime_validate_field_value_range(
    const king_proto_runtime_field *field,
    zval *value
)
{
    switch (field->kind) {
        case KING_PROTO_RUNTIME_FIELD_INT32:
        case KING_PROTO_RUNTIME_FIELD_SINT32:
            if (Z_LVAL_P(value) < INT32_MIN || Z_LVAL_P(value) > INT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects a 32-bit signed integer, but got %ld.",
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_ENUM:
        {
            zend_long resolved_enum_value;

            if (!king_proto_runtime_resolve_enum_value(field, value, &resolved_enum_value)) {
                return false;
            }

            if (resolved_enum_value < INT32_MIN || resolved_enum_value > INT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects a 32-bit signed integer, but got %ld.",
                    ZSTR_VAL(field->name),
                    resolved_enum_value
                );
                return false;
            }
            return true;
        }

        case KING_PROTO_RUNTIME_FIELD_UINT32:
            if (Z_LVAL_P(value) < 0 || (uint64_t) Z_LVAL_P(value) > UINT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects an unsigned 32-bit integer, but got %ld.",
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_UINT64:
            if (Z_LVAL_P(value) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects an unsigned 64-bit integer, but got %ld.",
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_FIXED32:
            if (Z_LVAL_P(value) < 0 || (uint64_t) Z_LVAL_P(value) > UINT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects an unsigned 32-bit fixed integer, but got %ld.",
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
            if (Z_LVAL_P(value) < INT32_MIN || Z_LVAL_P(value) > INT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects a 32-bit signed fixed integer, but got %ld.",
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_FIXED64:
            if (Z_LVAL_P(value) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects an unsigned 64-bit fixed integer, but got %ld.",
                    ZSTR_VAL(field->name),
                    Z_LVAL_P(value)
                );
                return false;
            }
            return true;

        default:
            return true;
    }
}
