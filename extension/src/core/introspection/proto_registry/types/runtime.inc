/*
 * Proto-registry runtime-helper slice: defines the runtime wire-type,
 * packed-field, int32 conversion, and zero-default helpers shared by the
 * active proto codec and registry logic.
 */

static uint32_t king_proto_runtime_wire_type_for_kind(
    king_proto_runtime_field_kind kind
)
{
    switch (kind) {
        case KING_PROTO_RUNTIME_FIELD_DOUBLE:
        case KING_PROTO_RUNTIME_FIELD_FIXED64:
        case KING_PROTO_RUNTIME_FIELD_SFIXED64:
            return KING_WIRETYPE_FIXED64;

        case KING_PROTO_RUNTIME_FIELD_FLOAT:
        case KING_PROTO_RUNTIME_FIELD_FIXED32:
        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
            return KING_WIRETYPE_FIXED32;

        case KING_PROTO_RUNTIME_FIELD_STRING:
        case KING_PROTO_RUNTIME_FIELD_BYTES:
        case KING_PROTO_RUNTIME_FIELD_MESSAGE:
        case KING_PROTO_RUNTIME_FIELD_MAP:
            return KING_WIRETYPE_LENGTH_DELIM;

        case KING_PROTO_RUNTIME_FIELD_INT32:
        case KING_PROTO_RUNTIME_FIELD_ENUM:
        case KING_PROTO_RUNTIME_FIELD_UINT32:
        case KING_PROTO_RUNTIME_FIELD_SINT32:
        case KING_PROTO_RUNTIME_FIELD_INT64:
        case KING_PROTO_RUNTIME_FIELD_UINT64:
        case KING_PROTO_RUNTIME_FIELD_SINT64:
        case KING_PROTO_RUNTIME_FIELD_BOOL:
            return KING_WIRETYPE_VARINT;

        default:
            return UINT32_MAX;
    }
}

static bool king_proto_runtime_field_kind_supports_packed(
    king_proto_runtime_field_kind kind
)
{
    switch (kind) {
        case KING_PROTO_RUNTIME_FIELD_DOUBLE:
        case KING_PROTO_RUNTIME_FIELD_FLOAT:
        case KING_PROTO_RUNTIME_FIELD_INT32:
        case KING_PROTO_RUNTIME_FIELD_ENUM:
        case KING_PROTO_RUNTIME_FIELD_UINT32:
        case KING_PROTO_RUNTIME_FIELD_SINT32:
        case KING_PROTO_RUNTIME_FIELD_INT64:
        case KING_PROTO_RUNTIME_FIELD_UINT64:
        case KING_PROTO_RUNTIME_FIELD_SINT64:
        case KING_PROTO_RUNTIME_FIELD_FIXED64:
        case KING_PROTO_RUNTIME_FIELD_SFIXED64:
        case KING_PROTO_RUNTIME_FIELD_FIXED32:
        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
        case KING_PROTO_RUNTIME_FIELD_BOOL:
            return true;

        default:
            return false;
    }
}

static bool king_proto_runtime_field_supports_packed(
    const king_proto_runtime_field *field
)
{
    if (field == NULL || !field->repeated) {
        return false;
    }

    return king_proto_runtime_field_kind_supports_packed(field->kind);
}

static bool king_proto_runtime_varint_fits_int32(uint64_t raw_value)
{
    uint32_t low_bits = (uint32_t) raw_value;
    uint64_t sign_extended;

    if ((low_bits & 0x80000000U) != 0) {
        sign_extended = 0xFFFFFFFF00000000ULL | (uint64_t) low_bits;
    } else {
        sign_extended = (uint64_t) low_bits;
    }

    return raw_value == sign_extended;
}

static zend_long king_proto_runtime_varint_to_int32(uint64_t raw_value)
{
    uint32_t low_bits = (uint32_t) raw_value;

    if ((low_bits & 0x80000000U) == 0) {
        return (zend_long) low_bits;
    }

    return -((zend_long) ((~low_bits) + 1U));
}

static bool king_proto_runtime_init_zero_value(
    king_proto_runtime_field_kind kind,
    zval *value_out
)
{
    switch (kind) {
        case KING_PROTO_RUNTIME_FIELD_DOUBLE:
        case KING_PROTO_RUNTIME_FIELD_FLOAT:
            ZVAL_DOUBLE(value_out, 0.0);
            return true;

        case KING_PROTO_RUNTIME_FIELD_INT32:
        case KING_PROTO_RUNTIME_FIELD_ENUM:
        case KING_PROTO_RUNTIME_FIELD_UINT32:
        case KING_PROTO_RUNTIME_FIELD_SINT32:
        case KING_PROTO_RUNTIME_FIELD_INT64:
        case KING_PROTO_RUNTIME_FIELD_UINT64:
        case KING_PROTO_RUNTIME_FIELD_SINT64:
        case KING_PROTO_RUNTIME_FIELD_FIXED64:
        case KING_PROTO_RUNTIME_FIELD_SFIXED64:
        case KING_PROTO_RUNTIME_FIELD_FIXED32:
        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
            ZVAL_LONG(value_out, 0);
            return true;

        case KING_PROTO_RUNTIME_FIELD_BOOL:
            ZVAL_FALSE(value_out);
            return true;

        case KING_PROTO_RUNTIME_FIELD_STRING:
        case KING_PROTO_RUNTIME_FIELD_BYTES:
            ZVAL_EMPTY_STRING(value_out);
            return true;

        default:
            ZVAL_UNDEF(value_out);
            return false;
    }
}
