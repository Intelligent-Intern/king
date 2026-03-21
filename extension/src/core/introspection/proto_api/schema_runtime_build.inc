static int king_proto_build_runtime_schema_fields(
    king_proto_runtime_schema *runtime_schema,
    zend_string *schema_name,
    zval *schema_definition)
{
    size_t field_index = 0;
    size_t i;
    zend_string *field_name;
    zval *field_options;

    runtime_schema->fields = safe_pemalloc(
        runtime_schema->field_count,
        sizeof(king_proto_runtime_field),
        0,
        1
    );
    memset(runtime_schema->fields, 0, runtime_schema->field_count * sizeof(king_proto_runtime_field));

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(schema_definition), field_name, field_options) {
        zval *tag = zend_hash_str_find(Z_ARRVAL_P(field_options), "tag", sizeof("tag") - 1);
        zval *type = zend_hash_str_find(Z_ARRVAL_P(field_options), "type", sizeof("type") - 1);
        zval *required = zend_hash_str_find(Z_ARRVAL_P(field_options), "required", sizeof("required") - 1);
        zval *packed = zend_hash_str_find(Z_ARRVAL_P(field_options), "packed", sizeof("packed") - 1);
        zval *oneof = zend_hash_str_find(Z_ARRVAL_P(field_options), "oneof", sizeof("oneof") - 1);
        zval *default_value = zend_hash_str_find(Z_ARRVAL_P(field_options), "default", sizeof("default") - 1);
        const char *base_type_name = NULL;
        size_t base_type_name_len = 0;
        const char *map_key_type_name = NULL;
        size_t map_key_type_name_len = 0;
        const char *map_value_type_name = NULL;
        size_t map_value_type_name_len = 0;
        zend_string *message_schema_name = NULL;
        zend_string *enum_name = NULL;
        zend_string *map_value_message_schema_name = NULL;
        zend_string *map_value_enum_name = NULL;
        bool is_repeated = king_proto_schema_field_type_is_repeated(Z_STR_P(type));
        bool is_map;

        king_proto_split_field_type(Z_STR_P(type), &base_type_name, &base_type_name_len);
        is_map = king_proto_parse_map_type(
            base_type_name,
            base_type_name_len,
            &map_key_type_name,
            &map_key_type_name_len,
            &map_value_type_name,
            &map_value_type_name_len
        );

        if (is_map) {
            runtime_schema->fields[field_index].kind = KING_PROTO_RUNTIME_FIELD_MAP;
            king_proto_runtime_resolve_map_key_type(
                map_key_type_name,
                map_key_type_name_len,
                &runtime_schema->fields[field_index].map_key_kind
            );
            king_proto_runtime_resolve_map_value_type(
                map_value_type_name,
                map_value_type_name_len,
                &runtime_schema->fields[field_index].map_value_kind,
                &map_value_enum_name,
                &map_value_message_schema_name
            );
        } else {
            king_proto_runtime_resolve_field_type(
                base_type_name,
                base_type_name_len,
                is_repeated,
                &runtime_schema->fields[field_index].kind,
                &message_schema_name
            );
        }

        runtime_schema->fields[field_index].name = zend_string_dup(field_name, 1);
        runtime_schema->fields[field_index].message_schema_name = message_schema_name;
        runtime_schema->fields[field_index].field_index = field_index;
        if (runtime_schema->fields[field_index].kind == KING_PROTO_RUNTIME_FIELD_ENUM
            && king_proto_registry_has_enum_type_name(base_type_name, base_type_name_len)) {
            enum_name = zend_string_init(base_type_name, base_type_name_len, 1);
        }
        runtime_schema->fields[field_index].enum_name = enum_name;
        runtime_schema->fields[field_index].map_value_message_schema_name =
            map_value_message_schema_name;
        runtime_schema->fields[field_index].map_value_enum_name = map_value_enum_name;
        runtime_schema->fields[field_index].oneof_name =
            oneof != NULL ? zend_string_dup(Z_STR_P(oneof), 1) : NULL;
        runtime_schema->fields[field_index].tag = Z_LVAL_P(tag);
        runtime_schema->fields[field_index].required =
            required != NULL && Z_TYPE_P(required) == IS_TRUE;
        runtime_schema->fields[field_index].repeated = is_map ? false : is_repeated;
        runtime_schema->fields[field_index].packed =
            is_map ? false : (packed != NULL && Z_TYPE_P(packed) == IS_TRUE);
        runtime_schema->fields[field_index].wire_type = is_map
            ? KING_WIRETYPE_LENGTH_DELIM
            : king_proto_runtime_wire_type_for_kind(runtime_schema->fields[field_index].kind);
        if (default_value != NULL
            && !king_proto_runtime_persist_default_value(
                &runtime_schema->fields[field_index],
                default_value,
                schema_name,
                &runtime_schema->fields[field_index].default_value
            )) {
            return FAILURE;
        }
        field_index++;
    } ZEND_HASH_FOREACH_END();

    qsort(
        runtime_schema->fields,
        runtime_schema->field_count,
        sizeof(king_proto_runtime_field),
        king_proto_runtime_compare_fields_by_tag
    );

    runtime_schema->required_fields = safe_pemalloc(
        runtime_schema->field_count,
        sizeof(*runtime_schema->required_fields),
        0,
        1
    );
    runtime_schema->default_fields = safe_pemalloc(
        runtime_schema->field_count,
        sizeof(*runtime_schema->default_fields),
        0,
        1
    );

    for (i = 0; i < runtime_schema->field_count; i++) {
        king_proto_runtime_field *field = &runtime_schema->fields[i];

        field->field_index = i;

        if (field->required) {
            runtime_schema->required_fields[runtime_schema->required_field_count++] = field;
        }

        if (Z_TYPE(field->default_value) != IS_UNDEF) {
            runtime_schema->default_fields[runtime_schema->default_field_count++] = field;
        }
    }

    if (runtime_schema->required_field_count == 0) {
        pefree(runtime_schema->required_fields, 1);
        runtime_schema->required_fields = NULL;
    }

    if (runtime_schema->default_field_count == 0) {
        pefree(runtime_schema->default_fields, 1);
        runtime_schema->default_fields = NULL;
    }

    return SUCCESS;
}
