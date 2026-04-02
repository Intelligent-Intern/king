/*
 * Root proto-registry lookup slice: provides the small presence and lookup
 * helpers for schema and enum registries, type-name parsing, map-type
 * splitting, and runtime-schema access used across validation and codec
 * paths.
 */

static bool king_proto_registry_has_schema(zend_string *schema_name)
{
    return king_proto_registries_initialized
        && zend_hash_exists(&king_proto_schema_registry, schema_name);
}

static bool king_proto_registry_has_enum(zend_string *enum_name)
{
    return king_proto_registries_initialized
        && zend_hash_exists(&king_proto_enum_registry, enum_name);
}

static king_proto_runtime_enum *king_proto_registry_get_runtime_enum(
    zend_string *enum_name
)
{
    zval *enum_entry;

    if (!king_proto_registries_initialized) {
        return NULL;
    }

    enum_entry = zend_hash_find(&king_proto_enum_registry, enum_name);
    if (enum_entry == NULL || Z_TYPE_P(enum_entry) != IS_PTR) {
        return NULL;
    }

    return (king_proto_runtime_enum *) Z_PTR_P(enum_entry);
}

static bool king_proto_registry_has_schema_type_name(
    const char *type_name,
    size_t type_name_len
)
{
    return king_proto_registries_initialized
        && zend_hash_str_exists(&king_proto_schema_registry, type_name, type_name_len);
}

static bool king_proto_registry_has_name(zend_string *name)
{
    return king_proto_registry_has_schema(name)
        || king_proto_registry_has_enum(name);
}

static bool king_proto_registry_get_schema_field_count(
    zend_string *schema_name,
    zend_long *field_count_out
)
{
    zval *schema_entry;
    king_proto_runtime_schema *runtime_schema;

    if (!king_proto_registries_initialized) {
        return false;
    }

    schema_entry = zend_hash_find(&king_proto_schema_registry, schema_name);
    if (schema_entry == NULL) {
        return false;
    }

    if (Z_TYPE_P(schema_entry) == IS_PTR) {
        runtime_schema = (king_proto_runtime_schema *) Z_PTR_P(schema_entry);
        if (runtime_schema == NULL) {
            return false;
        }

        *field_count_out = (zend_long) runtime_schema->field_count;
        return true;
    }

    if (Z_TYPE_P(schema_entry) == IS_LONG) {
        *field_count_out = Z_LVAL_P(schema_entry);
        return true;
    }

    return false;
}

static king_proto_runtime_schema *king_proto_registry_get_runtime_schema(
    zend_string *schema_name
)
{
    zval *schema_entry;

    if (!king_proto_registries_initialized) {
        return NULL;
    }

    schema_entry = zend_hash_find(&king_proto_schema_registry, schema_name);
    if (schema_entry == NULL || Z_TYPE_P(schema_entry) != IS_PTR) {
        return NULL;
    }

    return (king_proto_runtime_schema *) Z_PTR_P(schema_entry);
}

static bool king_proto_is_primitive_type(const char *type_name, size_t type_name_len)
{
    size_t i;

    for (i = 0; i < sizeof(king_proto_primitive_types) / sizeof(king_proto_primitive_types[0]); i++) {
        if (strlen(king_proto_primitive_types[i]) == type_name_len
            && memcmp(king_proto_primitive_types[i], type_name, type_name_len) == 0) {
            return true;
        }
    }

    return false;
}

static bool king_proto_split_field_type(
    zend_string *type_name,
    const char **base_type_name,
    size_t *base_type_name_len
)
{
    const char *type_name_val = ZSTR_VAL(type_name);
    size_t type_name_len = ZSTR_LEN(type_name);

    if (type_name_len >= sizeof("repeated_") - 1
        && memcmp(type_name_val, "repeated_", sizeof("repeated_") - 1) == 0) {
        type_name_val += sizeof("repeated_") - 1;
        type_name_len -= sizeof("repeated_") - 1;
    }

    if (type_name_len == 0) {
        return false;
    }

    *base_type_name = type_name_val;
    *base_type_name_len = type_name_len;
    return true;
}

static bool king_proto_parse_map_type(
    const char *type_name,
    size_t type_name_len,
    const char **key_type_name,
    size_t *key_type_name_len,
    const char **value_type_name,
    size_t *value_type_name_len
)
{
    const char *comma;

    if (type_name_len < sizeof("map<a,b>") - 1
        || memcmp(type_name, "map<", sizeof("map<") - 1) != 0
        || type_name[type_name_len - 1] != '>') {
        return false;
    }

    comma = memchr(type_name + (sizeof("map<") - 1), ',', type_name_len - sizeof("map<>"));
    if (comma == NULL) {
        return false;
    }

    *key_type_name = type_name + (sizeof("map<") - 1);
    *key_type_name_len = (size_t) (comma - *key_type_name);
    *value_type_name = comma + 1;
    *value_type_name_len = (size_t) ((type_name + type_name_len - 1) - *value_type_name);

    return *key_type_name_len > 0 && *value_type_name_len > 0;
}

static bool king_proto_type_is_known(const char *type_name, size_t type_name_len)
{
    if (king_proto_is_primitive_type(type_name, type_name_len)) {
        return true;
    }

    return king_proto_registries_initialized && (
        zend_hash_str_exists(&king_proto_schema_registry, type_name, type_name_len)
        || zend_hash_str_exists(&king_proto_enum_registry, type_name, type_name_len)
    );
}

static bool king_proto_registry_has_enum_type_name(
    const char *type_name,
    size_t type_name_len
)
{
    return king_proto_registries_initialized
        && zend_hash_str_exists(&king_proto_enum_registry, type_name, type_name_len);
}

static king_proto_runtime_schema *king_proto_registry_get_runtime_schema_by_type_name(
    const char *type_name,
    size_t type_name_len
)
{
    zval *schema_entry;

    if (!king_proto_registries_initialized) {
        return NULL;
    }

    schema_entry = zend_hash_str_find(&king_proto_schema_registry, type_name, type_name_len);
    if (schema_entry == NULL || Z_TYPE_P(schema_entry) != IS_PTR) {
        return NULL;
    }

    return (king_proto_runtime_schema *) Z_PTR_P(schema_entry);
}
