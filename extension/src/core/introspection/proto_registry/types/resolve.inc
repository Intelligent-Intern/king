static bool king_proto_runtime_field_kind_from_type_name(
    const char *type_name,
    size_t type_name_len,
    king_proto_runtime_field_kind *kind_out
)
{
    if (type_name_len == sizeof("double") - 1
        && memcmp(type_name, "double", sizeof("double") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_DOUBLE;
        return true;
    }

    if (type_name_len == sizeof("float") - 1
        && memcmp(type_name, "float", sizeof("float") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_FLOAT;
        return true;
    }

    if (type_name_len == sizeof("int32") - 1
        && memcmp(type_name, "int32", sizeof("int32") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_INT32;
        return true;
    }

    if (type_name_len == sizeof("uint32") - 1
        && memcmp(type_name, "uint32", sizeof("uint32") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_UINT32;
        return true;
    }

    if (type_name_len == sizeof("sint32") - 1
        && memcmp(type_name, "sint32", sizeof("sint32") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_SINT32;
        return true;
    }

    if (type_name_len == sizeof("int64") - 1
        && memcmp(type_name, "int64", sizeof("int64") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_INT64;
        return true;
    }

    if (type_name_len == sizeof("uint64") - 1
        && memcmp(type_name, "uint64", sizeof("uint64") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_UINT64;
        return true;
    }

    if (type_name_len == sizeof("sint64") - 1
        && memcmp(type_name, "sint64", sizeof("sint64") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_SINT64;
        return true;
    }

    if (type_name_len == sizeof("fixed64") - 1
        && memcmp(type_name, "fixed64", sizeof("fixed64") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_FIXED64;
        return true;
    }

    if (type_name_len == sizeof("sfixed64") - 1
        && memcmp(type_name, "sfixed64", sizeof("sfixed64") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_SFIXED64;
        return true;
    }

    if (type_name_len == sizeof("fixed32") - 1
        && memcmp(type_name, "fixed32", sizeof("fixed32") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_FIXED32;
        return true;
    }

    if (type_name_len == sizeof("sfixed32") - 1
        && memcmp(type_name, "sfixed32", sizeof("sfixed32") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_SFIXED32;
        return true;
    }

    if (type_name_len == sizeof("bool") - 1
        && memcmp(type_name, "bool", sizeof("bool") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_BOOL;
        return true;
    }

    if (type_name_len == sizeof("string") - 1
        && memcmp(type_name, "string", sizeof("string") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_STRING;
        return true;
    }

    if (type_name_len == sizeof("bytes") - 1
        && memcmp(type_name, "bytes", sizeof("bytes") - 1) == 0) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_BYTES;
        return true;
    }

    return false;
}

static bool king_proto_runtime_resolve_map_value_type(
    const char *type_name,
    size_t type_name_len,
    king_proto_runtime_field_kind *kind_out,
    zend_string **enum_name_out,
    zend_string **message_schema_name_out
)
{
    king_proto_runtime_schema *nested_schema;

    if (king_proto_runtime_field_kind_from_type_name(type_name, type_name_len, kind_out)) {
        if (enum_name_out != NULL) {
            *enum_name_out = NULL;
        }
        if (message_schema_name_out != NULL) {
            *message_schema_name_out = NULL;
        }
        return true;
    }

    if (king_proto_registry_has_enum_type_name(type_name, type_name_len)) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_ENUM;
        if (enum_name_out != NULL) {
            *enum_name_out = zend_string_init(type_name, type_name_len, 1);
        }
        if (message_schema_name_out != NULL) {
            *message_schema_name_out = NULL;
        }
        return true;
    }

    if (!king_proto_registry_has_schema_type_name(type_name, type_name_len)) {
        return false;
    }

    nested_schema = king_proto_registry_get_runtime_schema_by_type_name(
        type_name,
        type_name_len
    );
    if (nested_schema == NULL || !nested_schema->runtime_subset_supported) {
        return false;
    }

    *kind_out = KING_PROTO_RUNTIME_FIELD_MESSAGE;
    if (enum_name_out != NULL) {
        *enum_name_out = NULL;
    }
    if (message_schema_name_out != NULL) {
        *message_schema_name_out = zend_string_init(type_name, type_name_len, 1);
    }

    return true;
}

static bool king_proto_runtime_field_kind_supports_map_key(
    king_proto_runtime_field_kind kind
)
{
    switch (kind) {
        case KING_PROTO_RUNTIME_FIELD_STRING:
        case KING_PROTO_RUNTIME_FIELD_BOOL:
        case KING_PROTO_RUNTIME_FIELD_INT32:
        case KING_PROTO_RUNTIME_FIELD_UINT32:
        case KING_PROTO_RUNTIME_FIELD_SINT32:
        case KING_PROTO_RUNTIME_FIELD_FIXED32:
        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
            return true;

        default:
            return false;
    }
}

static bool king_proto_runtime_resolve_map_key_type(
    const char *type_name,
    size_t type_name_len,
    king_proto_runtime_field_kind *kind_out
)
{
    if (!king_proto_runtime_field_kind_from_type_name(type_name, type_name_len, kind_out)) {
        return false;
    }

    return king_proto_runtime_field_kind_supports_map_key(*kind_out);
}

static bool king_proto_runtime_resolve_field_type(
    const char *base_type_name,
    size_t base_type_name_len,
    bool is_repeated,
    king_proto_runtime_field_kind *kind_out,
    zend_string **message_schema_name_out
)
{
    king_proto_runtime_schema *nested_schema;

    (void) is_repeated;

    if (king_proto_runtime_field_kind_from_type_name(
        base_type_name,
        base_type_name_len,
        kind_out
    )) {
        if (message_schema_name_out != NULL) {
            *message_schema_name_out = NULL;
        }
        return true;
    }

    if (king_proto_registry_has_enum_type_name(base_type_name, base_type_name_len)) {
        *kind_out = KING_PROTO_RUNTIME_FIELD_ENUM;
        if (message_schema_name_out != NULL) {
            *message_schema_name_out = NULL;
        }
        return true;
    }

    if (!king_proto_registry_has_schema_type_name(base_type_name, base_type_name_len)) {
        return false;
    }

    nested_schema = king_proto_registry_get_runtime_schema_by_type_name(
        base_type_name,
        base_type_name_len
    );
    if (nested_schema == NULL || !nested_schema->runtime_subset_supported) {
        return false;
    }

    *kind_out = KING_PROTO_RUNTIME_FIELD_MESSAGE;
    if (message_schema_name_out != NULL) {
        *message_schema_name_out = zend_string_init(
            base_type_name,
            base_type_name_len,
            1
        );
    }

    return true;
}

static int king_proto_runtime_compare_fields_by_tag(const void *left, const void *right)
{
    const king_proto_runtime_field *left_field = (const king_proto_runtime_field *) left;
    const king_proto_runtime_field *right_field = (const king_proto_runtime_field *) right;

    if (left_field->tag < right_field->tag) {
        return -1;
    }

    if (left_field->tag > right_field->tag) {
        return 1;
    }

    return 0;
}
