/*
 * Schema-validation state slice: defines the per-field validation state that
 * the schema validator threads through metadata parsing, runtime type
 * resolution, and constraint checks, plus the helper that recognizes the
 * current repeated-field prefix convention.
 */

typedef struct _king_proto_schema_field_validation_state {
    zval *tag;
    zval *type;
    zval *required;
    zval *packed;
    zval *oneof;
    zval *default_value;
    const char *base_type_name;
    size_t base_type_name_len;
    const char *map_key_type_name;
    size_t map_key_type_name_len;
    const char *map_value_type_name;
    size_t map_value_type_name_len;
    king_proto_runtime_field_kind map_key_kind;
    king_proto_runtime_field_kind field_kind;
    king_proto_runtime_field_kind map_value_kind;
    bool field_has_runtime_support;
    bool is_repeated;
    bool is_map;
} king_proto_schema_field_validation_state;

static bool king_proto_schema_field_type_is_repeated(zend_string *type)
{
    return ZSTR_LEN(type) >= sizeof("repeated_") - 1
        && memcmp(ZSTR_VAL(type), "repeated_", sizeof("repeated_") - 1) == 0;
}
