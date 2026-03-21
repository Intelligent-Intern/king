static bool king_proto_runtime_encode_field(
    smart_str *buf,
    const king_proto_runtime_field *field,
    zval *value
)
{
    uint64_t key = (((uint64_t) field->tag) << 3) | field->wire_type;

    if (!king_proto_runtime_validate_field_value(field, value)) {
        return false;
    }

    king_proto_encode_varint(buf, key);
    return king_proto_runtime_append_encoded_field_value(buf, field, value);
}
