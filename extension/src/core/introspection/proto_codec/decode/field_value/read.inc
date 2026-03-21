static bool king_proto_runtime_decode_field_value_read(
    const king_proto_runtime_field *field,
    zend_string *schema_name,
    const unsigned char **buf_ptr,
    const unsigned char *buf_end,
    uint64_t *raw_value,
    uint64_t *raw_fixed64,
    uint32_t *raw_fixed32,
    uint64_t *length)
{
    switch (field->kind) {
        case KING_PROTO_RUNTIME_FIELD_DOUBLE:
        case KING_PROTO_RUNTIME_FIELD_FIXED64:
        case KING_PROTO_RUNTIME_FIELD_SFIXED64:
            if (!king_proto_decode_fixed64(buf_ptr, buf_end, raw_fixed64)) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: malformed fixed64 value for field '%s' in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_FLOAT:
        case KING_PROTO_RUNTIME_FIELD_FIXED32:
        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
            if (!king_proto_decode_fixed32(buf_ptr, buf_end, raw_fixed32)) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: malformed fixed32 value for field '%s' in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_INT32:
        case KING_PROTO_RUNTIME_FIELD_ENUM:
        case KING_PROTO_RUNTIME_FIELD_UINT32:
        case KING_PROTO_RUNTIME_FIELD_SINT32:
        case KING_PROTO_RUNTIME_FIELD_INT64:
        case KING_PROTO_RUNTIME_FIELD_UINT64:
        case KING_PROTO_RUNTIME_FIELD_SINT64:
        case KING_PROTO_RUNTIME_FIELD_BOOL:
            if (!king_proto_decode_varint(buf_ptr, buf_end, raw_value)) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: malformed varint value for field '%s' in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            return true;

        case KING_PROTO_RUNTIME_FIELD_MESSAGE:
        case KING_PROTO_RUNTIME_FIELD_STRING:
        case KING_PROTO_RUNTIME_FIELD_BYTES:
            if (!king_proto_decode_varint(buf_ptr, buf_end, length)) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: malformed length prefix for field '%s' in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }

            if (*length > (uint64_t) (buf_end - *buf_ptr)) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: length-delimited field '%s' exceeds buffer size in schema '%s'.",
                    ZSTR_VAL(field->name),
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            return true;

        default:
            return false;
    }
}
