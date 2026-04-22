/* URL encoding replacement */
#ifndef KING_URL_H
#define KING_URL_H

#include <php.h>
#include <string.h>

static zend_string *king_url_encode(const char *str, size_t len) {
    static const char hex[] = "0123456789ABCDEF";
    if (!str || !*str) return zend_string_init_interned("", 0, 0);
    if (!len) len = strlen(str);
    
    zend_string *out = zend_string_alloc(len * 3 + 1, 0);
    char *p = ZSTR_VAL(out);
    
    for (size_t i = 0; i < len; i++) {
        unsigned c = (unsigned char)str[i];
        if ((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || 
            (c >= '0' && c <= '9') || c == '-' || c == '_' || c == '.' || c == '~') {
            *p++ = c;
        } else {
            *p++ = '%';
            *p++ = hex[(c >> 4) & 15];
            *p++ = hex[c & 15];
        }
    }
    *p = '\0';
    ZSTR_LEN(out) = p - ZSTR_VAL(out);
    return out;
}

static zend_string *king_url_decode(const char *str, size_t len) {
    if (!str || !*str) return zend_string_init_interned("", 0, 0);
    if (!len) len = strlen(str);
    
    zend_string *out = zend_string_alloc(len + 1, 0);
    char *p = ZSTR_VAL(out);
    
    for (size_t i = 0; i < len; i++) {
        if (str[i] == '%' && i + 2 < len) {
            int hi = str[i+1], lo = str[i+2];
            hi = (hi >= 'A' && hi <= 'Z') ? hi - 'A' + 10 : (hi >= 'a' && hi <= 'z') ? hi - 'a' + 10 : (hi >= '0' && hi <= '9') ? hi - '0' : 0;
            lo = (lo >= 'A' && lo <= 'Z') ? lo - 'A' + 10 : (lo >= 'a' && lo <= 'z') ? lo - 'a' + 10 : (lo >= '0' && lo <= '9') ? lo - '0' : 0;
            *p++ = (hi << 4) | lo;
            i += 2;
        } else if (str[i] == '+') {
            *p++ = ' ';
        } else {
            *p++ = str[i];
        }
    }
    *p = '\0';
    ZSTR_LEN(out) = p - ZSTR_VAL(out);
    return out;
}

#define php_raw_url_encode(str, len, new_len) king_url_encode((str), (len))
#define php_raw_url_decode(str, len, new_len) king_url_decode((str), (len))

#endif