static bool king_proto_runtime_decode_field_value(
    const king_proto_runtime_field *field,
    zend_string *schema_name,
    const unsigned char **buf_ptr,
    const unsigned char *buf_end,
    bool decode_as_object,
    zval *decoded_value
)
{
    uint64_t raw_value = 0;
    uint64_t raw_fixed64 = 0;
    uint32_t raw_fixed32 = 0;
    uint64_t length = 0;

    if (!king_proto_runtime_decode_field_value_read(
        field,
        schema_name,
        buf_ptr,
        buf_end,
        &raw_value,
        &raw_fixed64,
        &raw_fixed32,
        &length
    )) {
        return false;
    }

    if (!king_proto_runtime_decode_field_value_validate(
        field,
        schema_name,
        raw_value,
        raw_fixed64
    )) {
        return false;
    }

    switch (field->kind) {
        case KING_PROTO_RUNTIME_FIELD_MESSAGE:
        {
            king_proto_runtime_schema *nested_schema;

            nested_schema = king_proto_registry_get_runtime_schema(field->message_schema_name);
            if (nested_schema == NULL || !nested_schema->runtime_subset_supported) {
                king_throw_proto_schema_registered_but_unavailable(
                    field->message_schema_name,
                    "decoding"
                );
                return false;
            }

            if (!king_proto_runtime_decode_schema_payload(
                field->message_schema_name,
                nested_schema,
                *buf_ptr,
                (size_t) length,
                decode_as_object,
                decoded_value
            )) {
                return false;
            }

            *buf_ptr += (size_t) length;
            return true;
        }

        case KING_PROTO_RUNTIME_FIELD_DOUBLE:
            ZVAL_DOUBLE(decoded_value, king_proto_bits_to_double(raw_fixed64));
            return true;

        case KING_PROTO_RUNTIME_FIELD_FLOAT:
            ZVAL_DOUBLE(decoded_value, (double) king_proto_bits_to_float(raw_fixed32));
            return true;

        case KING_PROTO_RUNTIME_FIELD_INT32:
            ZVAL_LONG(decoded_value, king_proto_runtime_varint_to_int32(raw_value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_ENUM:
            ZVAL_LONG(decoded_value, king_proto_runtime_varint_to_int32(raw_value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_UINT32:
            ZVAL_LONG(decoded_value, (zend_long) (uint32_t) raw_value);
            return true;

        case KING_PROTO_RUNTIME_FIELD_SINT32:
            ZVAL_LONG(decoded_value, (zend_long) king_proto_zigzag_decode32((uint32_t) raw_value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_INT64:
            ZVAL_LONG(decoded_value, (zend_long) (int64_t) raw_value);
            return true;

        case KING_PROTO_RUNTIME_FIELD_UINT64:
            ZVAL_LONG(decoded_value, (zend_long) raw_value);
            return true;

        case KING_PROTO_RUNTIME_FIELD_SINT64:
            ZVAL_LONG(decoded_value, (zend_long) king_proto_zigzag_decode64(raw_value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_FIXED64:
            ZVAL_LONG(decoded_value, (zend_long) raw_fixed64);
            return true;

        case KING_PROTO_RUNTIME_FIELD_SFIXED64:
            ZVAL_LONG(decoded_value, (zend_long) (int64_t) raw_fixed64);
            return true;

        case KING_PROTO_RUNTIME_FIELD_FIXED32:
            ZVAL_LONG(decoded_value, (zend_long) raw_fixed32);
            return true;

        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
            ZVAL_LONG(decoded_value, (zend_long) (int32_t) raw_fixed32);
            return true;

        case KING_PROTO_RUNTIME_FIELD_BOOL:
            ZVAL_BOOL(decoded_value, raw_value != 0);
            return true;

        case KING_PROTO_RUNTIME_FIELD_STRING:
        case KING_PROTO_RUNTIME_FIELD_BYTES:
            ZVAL_STRINGL(decoded_value, (const char *) *buf_ptr, (size_t) length);
            *buf_ptr += (size_t) length;
            return true;

        default:
            return false;
    }
}
