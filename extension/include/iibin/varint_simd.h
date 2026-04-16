/* varint SIMD helpers */
#ifndef VARINT_SIMD_H
#define VARINT_SIMD_H

#include "php_king.h"
#include "include/iibin/iibin_internal.h"

#if defined(__AVX2__) || defined(__ARM_NEON__)
/* SIMD-accelerated varint encode/decode */
static inline void king_proto_encode_varint_simd(smart_str *buf, uint64_t value) {
    /* Simple fallback: use generic implementation */
    king_proto_encode_varint(buf, value);
}
static inline zend_bool king_proto_decode_varint_simd(const unsigned char **buf_ptr, const unsigned char *buf_end, uint64_t *value_out) {
    /* Simple fallback: use generic implementation */
    return king_proto_decode_varint(buf_ptr, buf_end, value_out);
}
#else
/* No SIMD support – alias to generic functions */
#define king_proto_encode_varint_simd(buf, val) king_proto_encode_varint(buf, val)
#define king_proto_decode_varint_simd(buf_ptr, buf_end, out) king_proto_decode_varint(buf_ptr, buf_end, out)
#endif

#endif /* VARINT_SIMD_H */
