static bool king_proto_runtime_decode_packed_field_values(
    zval *target,
    bool decode_as_object,
    const king_proto_runtime_field *field,
    zend_string *schema_name,
    const unsigned char **buf_ptr,
    const unsigned char *buf_end,
    bool *decoded_any_values
)
{
    uint64_t length = 0;
    const unsigned char *packed_end;

    *decoded_any_values = false;

    if (!king_proto_decode_varint(buf_ptr, buf_end, &length)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding error: malformed length prefix for field '%s' in schema '%s'.",
            ZSTR_VAL(field->name),
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    if (length > (uint64_t) (buf_end - *buf_ptr)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding error: length-delimited field '%s' exceeds buffer size in schema '%s'.",
            ZSTR_VAL(field->name),
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    packed_end = *buf_ptr + (size_t) length;

    while (*buf_ptr < packed_end) {
        zval decoded_value;

        ZVAL_UNDEF(&decoded_value);
        if (!king_proto_runtime_decode_field_value(
            field,
            schema_name,
            buf_ptr,
            packed_end,
            decode_as_object,
            &decoded_value
        )) {
            return false;
        }

        king_proto_runtime_store_decoded_value(
            target,
            decode_as_object,
            field,
            &decoded_value
        );
        *decoded_any_values = true;
    }

    return true;
}
