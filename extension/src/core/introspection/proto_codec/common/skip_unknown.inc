/*
 * Common codec unknown-field slice: skips unsupported or unknown wire
 * fields according to the protobuf wire type while maintaining buffer
 * bounds.
 */

static bool king_proto_runtime_skip_unknown_field(
    const unsigned char **buf_ptr,
    const unsigned char *buf_end,
    uint32_t wire_type
)
{
    uint64_t length = 0;

    switch (wire_type) {
        case KING_WIRETYPE_VARINT:
            return king_proto_decode_varint(buf_ptr, buf_end, &length);

        case KING_WIRETYPE_FIXED64:
            if ((size_t) (buf_end - *buf_ptr) < 8) {
                return false;
            }
            *buf_ptr += 8;
            return true;

        case KING_WIRETYPE_LENGTH_DELIM:
            if (!king_proto_decode_varint(buf_ptr, buf_end, &length)) {
                return false;
            }
            if (length > (uint64_t) (buf_end - *buf_ptr)) {
                return false;
            }
            *buf_ptr += (size_t) length;
            return true;

        case KING_WIRETYPE_FIXED32:
            if ((size_t) (buf_end - *buf_ptr) < 4) {
                return false;
            }
            *buf_ptr += 4;
            return true;

        default:
            return false;
    }
}
