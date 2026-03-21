static bool king_proto_runtime_encode_map_entry(
    smart_str *buf,
    const king_proto_runtime_field *field,
    zval *entry_key,
    zval *entry_value
)
{
    smart_str entry_payload = {0};
    king_proto_runtime_field map_key_field;
    king_proto_runtime_field map_value_field;

    king_proto_runtime_init_map_key_field(field, &map_key_field);
    king_proto_runtime_init_map_value_field(field, &map_value_field);

    if (!king_proto_runtime_validate_field_value(&map_key_field, entry_key)
        || !king_proto_runtime_validate_field_value(&map_value_field, entry_value)) {
        return false;
    }

    king_proto_encode_varint(
        &entry_payload,
        (((uint64_t) 1) << 3) | king_proto_runtime_wire_type_for_kind(map_key_field.kind)
    );
    if (!king_proto_runtime_append_encoded_field_value(
        &entry_payload,
        &map_key_field,
        entry_key
    )) {
        smart_str_free(&entry_payload);
        return false;
    }

    king_proto_encode_varint(
        &entry_payload,
        (((uint64_t) 2) << 3) | king_proto_runtime_wire_type_for_kind(map_value_field.kind)
    );
    if (!king_proto_runtime_append_encoded_field_value(
        &entry_payload,
        &map_value_field,
        entry_value
    )) {
        smart_str_free(&entry_payload);
        return false;
    }

    king_proto_encode_varint(
        buf,
        (((uint64_t) field->tag) << 3) | KING_WIRETYPE_LENGTH_DELIM
    );
    king_proto_encode_varint(buf, (uint64_t) ZSTR_LEN(entry_payload.s));
    smart_str_appendl(buf, ZSTR_VAL(entry_payload.s), ZSTR_LEN(entry_payload.s));
    smart_str_free(&entry_payload);

    return true;
}

static bool king_proto_runtime_encode_map_field(
    smart_str *buf,
    const king_proto_runtime_field *field,
    zval *values
)
{
    if (Z_TYPE_P(values) == IS_ARRAY) {
        zend_string *entry_key = NULL;
        zend_ulong entry_index = 0;
        zval *entry_value;

        ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(values), entry_index, entry_key, entry_value) {
            zval parsed_key;

            ZVAL_DEREF(entry_value);
            ZVAL_UNDEF(&parsed_key);
            if (!king_proto_runtime_parse_map_input_key(
                field,
                entry_key,
                entry_index,
                &parsed_key
            )) {
                return false;
            }

            if (!king_proto_runtime_encode_map_entry(buf, field, &parsed_key, entry_value)) {
                zval_ptr_dtor_nogc(&parsed_key);
                return false;
            }
            zval_ptr_dtor_nogc(&parsed_key);
        } ZEND_HASH_FOREACH_END();

        return true;
    }

    if (Z_TYPE_P(values) == IS_OBJECT) {
        zend_string *entry_key = NULL;
        zval *entry_value;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_OBJPROP_P(values), entry_key, entry_value) {
            zval parsed_key;

            if (entry_key == NULL) {
                continue;
            }

            ZVAL_DEREF(entry_value);
            ZVAL_UNDEF(&parsed_key);
            if (!king_proto_runtime_parse_map_input_key(field, entry_key, 0, &parsed_key)) {
                return false;
            }

            if (!king_proto_runtime_encode_map_entry(buf, field, &parsed_key, entry_value)) {
                zval_ptr_dtor_nogc(&parsed_key);
                return false;
            }
            zval_ptr_dtor_nogc(&parsed_key);
        } ZEND_HASH_FOREACH_END();

        return true;
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "Encoding failed: Field '%s' expects an array or object for map values, but got %s.",
        ZSTR_VAL(field->name),
        zend_zval_type_name(values)
    );
    return false;
}
