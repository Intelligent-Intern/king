/*
 * include/iibin/iibin_internal.h - Internal IIBIN definitions
 * ============================================================
 *
 * Shared constants, runtime structs, registry declarations, and wire-format
 * helpers used by the active proto/IIBIN slice.
 */

#ifndef KING_PROTO_INTERNAL_H
#define KING_PROTO_INTERNAL_H

#include <php.h>
#include <stdint.h>
#include <zend_hash.h>
#include <zend_smart_str.h>

/* --- Wire Format Constants --- */
#define KING_WIRETYPE_VARINT         0
#define KING_WIRETYPE_FIXED64        1
#define KING_WIRETYPE_LENGTH_DELIM   2
#define KING_WIRETYPE_FIXED32        5

/* --- Active Runtime Data Structures --- */

typedef enum _king_proto_runtime_field_kind {
    KING_PROTO_RUNTIME_FIELD_UNSUPPORTED = 0,
    KING_PROTO_RUNTIME_FIELD_MESSAGE,
    KING_PROTO_RUNTIME_FIELD_MAP,
    KING_PROTO_RUNTIME_FIELD_DOUBLE,
    KING_PROTO_RUNTIME_FIELD_FLOAT,
    KING_PROTO_RUNTIME_FIELD_INT32,
    KING_PROTO_RUNTIME_FIELD_ENUM,
    KING_PROTO_RUNTIME_FIELD_UINT32,
    KING_PROTO_RUNTIME_FIELD_SINT32,
    KING_PROTO_RUNTIME_FIELD_INT64,
    KING_PROTO_RUNTIME_FIELD_UINT64,
    KING_PROTO_RUNTIME_FIELD_SINT64,
    KING_PROTO_RUNTIME_FIELD_FIXED64,
    KING_PROTO_RUNTIME_FIELD_SFIXED64,
    KING_PROTO_RUNTIME_FIELD_FIXED32,
    KING_PROTO_RUNTIME_FIELD_SFIXED32,
    KING_PROTO_RUNTIME_FIELD_BOOL,
    KING_PROTO_RUNTIME_FIELD_STRING,
    KING_PROTO_RUNTIME_FIELD_BYTES
} king_proto_runtime_field_kind;

typedef struct _king_proto_runtime_field {
    zend_string *name;
    zend_string *message_schema_name;
    zend_string *enum_name;
    zend_string *map_value_message_schema_name;
    zend_string *map_value_enum_name;
    zend_string *oneof_name;
    zval default_value;
    zend_long tag;
    size_t field_index;
    king_proto_runtime_field_kind kind;
    king_proto_runtime_field_kind map_key_kind;
    king_proto_runtime_field_kind map_value_kind;
    uint32_t wire_type;
    bool required;
    bool repeated;
    bool packed;
} king_proto_runtime_field;

typedef struct _king_proto_runtime_schema {
    size_t field_count;
    size_t required_field_count;
    size_t default_field_count;
    bool runtime_subset_supported;
    bool fields_by_name_initialized;
    king_proto_runtime_field *fields;
    king_proto_runtime_field **required_fields;
    king_proto_runtime_field **default_fields;
    HashTable fields_by_name;
} king_proto_runtime_schema;

typedef struct _king_proto_runtime_enum {
    size_t member_count;
    HashTable members;
} king_proto_runtime_enum;

/* --- Global Registry Externs --- */
extern HashTable king_proto_schema_registry;
extern HashTable king_proto_enum_registry;
extern bool king_proto_registries_initialized;

/* --- Lifecycle / Registry Helpers --- */
int king_proto_registry_minit(void);
void king_proto_registry_mshutdown(void);
zend_result king_iibin_define_enum(
    zend_string *enum_name,
    zval *enum_values
);
zend_result king_iibin_define_schema(
    zend_string *schema_name,
    zval *schema_definition
);
zend_result king_iibin_compile_schema_definition(
    zend_string *schema_name,
    zval *schema_definition,
    king_proto_runtime_schema **runtime_schema_out
);
bool king_iibin_validate_schema_input(
    zend_string *schema_name,
    const king_proto_runtime_schema *runtime_schema,
    zval *data
);
zend_result king_iibin_encode(
    zend_string *schema_name,
    zval *data,
    smart_str *encoded_out
);
zend_result king_iibin_decode(
    zend_string *schema_name,
    zend_string *binary_data,
    zval *decode_mode,
    zval *decoded_result
);
bool king_iibin_is_defined(zend_string *name);
bool king_iibin_is_schema_defined(zend_string *schema_name);
bool king_iibin_is_enum_defined(zend_string *enum_name);
void king_iibin_get_defined_schemas(zval *return_value);
void king_iibin_get_defined_enums(zval *return_value);

void king_proto_runtime_schema_free(king_proto_runtime_schema *schema);
void king_proto_runtime_schema_zval_dtor(zval *zv);
void king_proto_runtime_enum_free(king_proto_runtime_enum *runtime_enum);
void king_proto_runtime_enum_zval_dtor(zval *zv);

int king_iibin_minit(void);
extern const zend_function_entry king_iibin_class_methods[];

/* --- Wire Helpers: Varint encode --- */

/* Fast branchless varint encoding */
static inline void king_proto_encode_varint(smart_str *buf, uint64_t value) {
    unsigned char temp[10];
    int len = 0;
    
    /* Fast path: single byte */
    if (value < 0x80) {
        temp[0] = (unsigned char)value;
        smart_str_appendl(buf, (char*)temp, 1);
        return;
    }
    
    /* Multi-byte: unrolled bit operations */
    temp[len++] = (unsigned char)((value) | 0x80);
    temp[len++] = (unsigned char)((value >> 7) | 0x80);
    temp[len++] = (unsigned char)((value >> 14) | 0x80);
    temp[len++] = (unsigned char)((value >> 21) | 0x80);
    
    if (value < 0x200000) {
        temp[0] &= 0x7F;
        temp[1] &= 0x7F;
        temp[2] &= 0x7F;
    } else {
        temp[len++] = (unsigned char)((value >> 28) | 0x80);
        temp[len++] = (unsigned char)((value >> 35) | 0x80);
        temp[len++] = (unsigned char)((value >> 42) | 0x80);
        temp[len++] = (unsigned char)((value >> 49) | 0x80);
        
        if (value < 0x1000000000000ULL) {
            temp[3] &= 0x7F;
            temp[4] &= 0x7F;
            temp[5] &= 0x7F;
            temp[6] &= 0x7F;
        } else {
            temp[7] = (unsigned char)((value >> 56) | 0x80);
            temp[8] = (unsigned char)((value >> 63));
            temp[3] &= 0x7F;
            temp[4] &= 0x7F;
            temp[5] &= 0x7F;
            temp[6] &= 0x7F;
            temp[7] &= 0x7F;
            len = 9;
        }
    }
    smart_str_appendl(buf, (char*)temp, len);
}

static inline zend_bool king_proto_decode_varint(const unsigned char **buf_ptr, const unsigned char *buf_end, uint64_t *value_out) {
    uint64_t result = 0;
    int shift = 0;
    const unsigned char *ptr = *buf_ptr;
    for (int i = 0; i < 10; ++i) {
        if (ptr >= buf_end) return 0;
        unsigned char byte = *ptr++;
        result |= (uint64_t)(byte & 0x7F) << shift;
        if (!(byte & 0x80U)) {
            *value_out = result;
            *buf_ptr = ptr;
            return 1;
        }
        shift += 7;
    }
    return 0;
}

static inline void king_proto_encode_fixed32(smart_str *buf, uint32_t value) {
    unsigned char temp_buf[4];
    temp_buf[0] = (unsigned char)(value);
    temp_buf[1] = (unsigned char)(value >> 8);
    temp_buf[2] = (unsigned char)(value >> 16);
    temp_buf[3] = (unsigned char)(value >> 24);
    smart_str_appendl(buf, (char*)temp_buf, 4);
}

static inline zend_bool king_proto_decode_fixed32(const unsigned char **buf_ptr, const unsigned char *buf_end, uint32_t *value_out) {
    if (*buf_ptr + 4 > buf_end) return 0;
    const unsigned char *ptr = *buf_ptr;
    *value_out = ((uint32_t)ptr[0]) |
                 ((uint32_t)ptr[1] << 8) |
                 ((uint32_t)ptr[2] << 16) |
                 ((uint32_t)ptr[3] << 24);
    *buf_ptr += 4;
    return 1;
}

static inline void king_proto_encode_fixed64(smart_str *buf, uint64_t value) {
    unsigned char temp_buf[8];
    temp_buf[0] = (unsigned char)(value);
    temp_buf[1] = (unsigned char)(value >> 8);
    temp_buf[2] = (unsigned char)(value >> 16);
    temp_buf[3] = (unsigned char)(value >> 24);
    temp_buf[4] = (unsigned char)(value >> 32);
    temp_buf[5] = (unsigned char)(value >> 40);
    temp_buf[6] = (unsigned char)(value >> 48);
    temp_buf[7] = (unsigned char)(value >> 56);
    smart_str_appendl(buf, (char*)temp_buf, 8);
}

static inline zend_bool king_proto_decode_fixed64(const unsigned char **buf_ptr, const unsigned char *buf_end, uint64_t *value_out) {
    if (*buf_ptr + 8 > buf_end) return 0;
    const unsigned char *ptr = *buf_ptr;
    *value_out = ((uint64_t)ptr[0]) |
                 ((uint64_t)ptr[1] << 8)  |
                 ((uint64_t)ptr[2] << 16) |
                 ((uint64_t)ptr[3] << 24) |
                 ((uint64_t)ptr[4] << 32) |
                 ((uint64_t)ptr[5] << 40) |
                 ((uint64_t)ptr[6] << 48) |
                 ((uint64_t)ptr[7] << 56);
    *buf_ptr += 8;
    return 1;
}

static inline uint32_t king_proto_zigzag_encode32(int32_t n) {
    return (uint32_t)((n << 1) ^ (n >> 31));
}

static inline int32_t king_proto_zigzag_decode32(uint32_t n) {
    return (int32_t)((n >> 1) ^ (-(int32_t)(n & 1)));
}

static inline uint64_t king_proto_zigzag_encode64(int64_t n) {
    return (uint64_t)((n << 1) ^ (n >> 63));
}

static inline int64_t king_proto_zigzag_decode64(uint64_t n) {
    return (int64_t)((n >> 1) ^ (-(int64_t)(n & 1)));
}

#endif /* KING_PROTO_INTERNAL_H */
