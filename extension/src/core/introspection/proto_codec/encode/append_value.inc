static bool king_proto_runtime_append_encoded_field_value(
    smart_str *buf,
    const king_proto_runtime_field *field,
    zval *value
)
{
    float float_value;
    double double_value;
    zend_long resolved_enum_value;

    switch (field->kind) {
        case KING_PROTO_RUNTIME_FIELD_MESSAGE:
        {
            smart_str nested_payload = {0};
            king_proto_runtime_schema *nested_schema;

            nested_schema = king_proto_registry_get_runtime_schema(field->message_schema_name);
            if (nested_schema == NULL || !nested_schema->runtime_subset_supported) {
                king_throw_proto_schema_registered_but_unavailable(
                    field->message_schema_name,
                    "encoding"
                );
                return false;
            }

            if (!king_proto_runtime_encode_schema_payload(
                &nested_payload,
                field->message_schema_name,
                nested_schema,
                value
            )) {
                smart_str_free(&nested_payload);
                return false;
            }

            if (nested_payload.s == NULL) {
                king_proto_encode_varint(buf, 0);
                return true;
            }

            king_proto_encode_varint(buf, (uint64_t) ZSTR_LEN(nested_payload.s));
            smart_str_appendl(buf, ZSTR_VAL(nested_payload.s), ZSTR_LEN(nested_payload.s));
            smart_str_free(&nested_payload);
            return true;
        }

        case KING_PROTO_RUNTIME_FIELD_DOUBLE:
            double_value = zval_get_double(value);
            king_proto_encode_fixed64(buf, king_proto_double_to_bits(double_value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_FLOAT:
            float_value = (float) zval_get_double(value);
            king_proto_encode_fixed32(buf, king_proto_float_to_bits(float_value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_INT32:
            king_proto_encode_varint(buf, (uint64_t) (int64_t) (int32_t) Z_LVAL_P(value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_ENUM:
            if (!king_proto_runtime_resolve_enum_value(field, value, &resolved_enum_value)) {
                return false;
            }
            king_proto_encode_varint(buf, (uint64_t) (int64_t) (int32_t) resolved_enum_value);
            return true;

        case KING_PROTO_RUNTIME_FIELD_UINT32:
            king_proto_encode_varint(buf, (uint64_t) (uint32_t) Z_LVAL_P(value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_SINT32:
            king_proto_encode_varint(buf, king_proto_zigzag_encode32((int32_t) Z_LVAL_P(value)));
            return true;

        case KING_PROTO_RUNTIME_FIELD_INT64:
            king_proto_encode_varint(buf, (uint64_t) (int64_t) Z_LVAL_P(value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_UINT64:
            king_proto_encode_varint(buf, (uint64_t) Z_LVAL_P(value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_SINT64:
            king_proto_encode_varint(buf, king_proto_zigzag_encode64((int64_t) Z_LVAL_P(value)));
            return true;

        case KING_PROTO_RUNTIME_FIELD_FIXED64:
            king_proto_encode_fixed64(buf, (uint64_t) Z_LVAL_P(value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_SFIXED64:
            king_proto_encode_fixed64(buf, (uint64_t) (int64_t) Z_LVAL_P(value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_FIXED32:
            king_proto_encode_fixed32(buf, (uint32_t) Z_LVAL_P(value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
            king_proto_encode_fixed32(buf, (uint32_t) (int32_t) Z_LVAL_P(value));
            return true;

        case KING_PROTO_RUNTIME_FIELD_BOOL:
            king_proto_encode_varint(buf, Z_TYPE_P(value) == IS_TRUE ? 1 : 0);
            return true;

        case KING_PROTO_RUNTIME_FIELD_STRING:
        case KING_PROTO_RUNTIME_FIELD_BYTES:
            king_proto_encode_varint(buf, (uint64_t) Z_STRLEN_P(value));
            smart_str_appendl(buf, Z_STRVAL_P(value), Z_STRLEN_P(value));
            return true;

        default:
            return false;
    }
}
