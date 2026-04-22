#ifndef PHP_KING_BASE64_H
#define PHP_KING_BASE64_H

#include <php.h>
#include <zend_string.h>

static const char base64_chars[] = 
    "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

static zend_string *king_base64_encode(const unsigned char *data, size_t len) {
    size_t i, j, out_len = ((len + 2) / 3) * 4;
    zend_string *out = zend_string_alloc(out_len, 0);
    unsigned char *out_ptr = (unsigned char *)ZSTR_VAL(out);
    
    for (i = 0, j = 0; i < len; i += 3) {
        uint32_t n = data[i] << 16;
        if (i + 1 < len) n |= data[i + 1] << 8;
        if (i + 2 < len) n |= data[i + 2];
        
        out_ptr[j++] = base64_chars[(n >> 18) & 0x3F];
        out_ptr[j++] = base64_chars[(n >> 12) & 0x3F];
        out_ptr[j++] = (i + 1 < len) ? base64_chars[(n >> 6) & 0x3F] : '=';
        out_ptr[j++] = (i + 2 < len) ? base64_chars[n & 0x3F] : '=';
    }
    out_ptr[j] = '\0';
    ZSTR_LEN(out) = j;
    return out;
}

#define php_base64_encode(data, len) king_base64_encode((const unsigned char *)(data), (len))

#endif