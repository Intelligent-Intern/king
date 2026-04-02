/*
 * Decode field-value validation slice: enforces the extra numeric bounds the
 * active runtime must honor after raw wire decoding, especially for int32,
 * uint32, enum, sint32, uint64, and fixed64 materialization into PHP longs.
 */

static bool king_proto_runtime_decode_field_value_validate(
    const king_proto_runtime_field *field,
    zend_string *schema_name,
    uint64_t raw_value,
    uint64_t raw_fixed64)
{
    switch (field->kind) {
        case KING_PROTO_RUNTIME_FIELD_INT32:
            if (!king_proto_runtime_varint_fits_int32(raw_value)) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: Field '%s' exceeds int32 range in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_UINT32:
            if (raw_value > UINT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: Field '%s' exceeds uint32 range in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_ENUM:
            if (!king_proto_runtime_varint_fits_int32(raw_value)) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: Field '%s' exceeds enum int32 range in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_SINT32:
            if (raw_value > UINT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: Field '%s' exceeds sint32 range in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_UINT64:
            if (raw_value > (uint64_t) ZEND_LONG_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: Field '%s' exceeds PHP integer range for uint64 in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_FIXED64:
            if (raw_fixed64 > (uint64_t) ZEND_LONG_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: Field '%s' exceeds PHP integer range for fixed64 in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            return true;

        default:
            return true;
    }
}
