/* SIMD varint helpers - fallback implementation */
#include "php_king.h"
#include "include/iibin/iibin_internal.h"
#include "include/iibin/varint_simd.h"

#if defined(__AVX2__) || defined(__ARM_NEON__)
/* Placeholder SIMD implementation – currently forwards to generic functions */
void king_proto_encode_varint_simd(smart_str *buf, uint64_t value) {
    king_proto_encode_varint(buf, value);
}

zend_bool king_proto_decode_varint_simd(const unsigned char **buf_ptr, const unsigned char *buf_end, uint64_t *value_out) {
    return king_proto_decode_varint(buf_ptr, buf_end, value_out);
}
#endif
