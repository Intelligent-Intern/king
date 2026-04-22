/* JSON replacement - don't need PHP's json */
#ifndef KING_JSON_H
#define KING_JSON_H

#include <php.h>
#include <string.h>

/* Simple JSON string escape - returns new string */
static zend_string *king_json_escape(const char *str) {
    if (!str || !*str) return zend_string_init_interned("\"\"", 2, 0);
    
    size_t len = strlen(str), out_len = len * 2 + 3;
    zend_string *out = zend_string_alloc(out_len, 0);
    char *p = ZSTR_VAL(out);
    *p++ = '"';
    
    for (size_t i = 0; i < len; i++) {
        switch (str[i]) {
            case '"':  *p++ = '\\'; *p++ = '"'; break;
            case '\\': *p++ = '\\'; *p++ = '\\'; break;
            case '\b': *p++ = '\\'; *p++ = 'b'; break;
            case '\f': *p++ = '\\'; *p++ = 'f'; break;
            case '\n': *p++ = '\\'; *p++ = 'n'; break;
            case '\r': *p++ = '\\'; *p++ = 'r'; break;
            case '\t': *p++ = '\\'; *p++ = 't'; break;
            default:   *p++ = str[i]; break;
        }
    }
    *p++ = '"';
    *p = '\0';
    ZSTR_LEN(out) = p - ZSTR_VAL(out);
    return out;
}

/* Stub replacements */
#define php_json_encode(str, flags) king_json_escape((str) ? ZSTR_VAL(str) : "")
#define php_json_decode_ex(str, len, assoc, d, depth, opts) NULL
#define php_json_decode(str, len, assoc, depth) NULL

#endif