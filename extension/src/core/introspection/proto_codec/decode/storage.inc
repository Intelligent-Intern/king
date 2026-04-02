/*
 * Decode storage slice: stores decoded scalar/message values into arrays or
 * objects, clears competing oneof members, applies persisted defaults, and
 * tracks which runtime fields were observed during decoding.
 */

static void king_proto_runtime_store_decoded_value(
    zval *target,
    bool decode_as_object,
    const king_proto_runtime_field *field,
    zval *decoded_value
)
{
    zval *existing_values;

    if (field->repeated) {
        if (decode_as_object) {
            existing_values = zend_hash_find(Z_OBJPROP_P(target), field->name);
        } else {
            existing_values = zend_hash_find(Z_ARRVAL_P(target), field->name);
        }

        if (existing_values == NULL) {
            zval values;

            array_init(&values);
            add_next_index_zval(&values, decoded_value);

            if (decode_as_object) {
                zend_hash_update(Z_OBJPROP_P(target), field->name, &values);
            } else {
                zend_hash_update(Z_ARRVAL_P(target), field->name, &values);
            }
            return;
        }

        add_next_index_zval(existing_values, decoded_value);
        return;
    }

    if (decode_as_object) {
        zend_hash_update(Z_OBJPROP_P(target), field->name, decoded_value);
        return;
    }

    zend_hash_update(Z_ARRVAL_P(target), field->name, decoded_value);
}

static void king_proto_runtime_store_default_value(
    zval *target,
    bool decode_as_object,
    const king_proto_runtime_field *field
)
{
    zval default_value;

    ZVAL_COPY(&default_value, &field->default_value);

    if (decode_as_object) {
        zend_hash_update(Z_OBJPROP_P(target), field->name, &default_value);
        return;
    }

    zend_hash_update(Z_ARRVAL_P(target), field->name, &default_value);
}

static void king_proto_runtime_clear_oneof_group(
    zval *target,
    bool decode_as_object,
    const king_proto_runtime_schema *runtime_schema,
    const king_proto_runtime_field *active_field
)
{
    HashTable *target_table;
    size_t i;

    if (active_field->oneof_name == NULL) {
        return;
    }

    target_table = decode_as_object ? Z_OBJPROP_P(target) : Z_ARRVAL_P(target);

    for (i = 0; i < runtime_schema->field_count; i++) {
        const king_proto_runtime_field *field = &runtime_schema->fields[i];

        if (field == active_field || field->oneof_name == NULL) {
            continue;
        }

        if (!zend_string_equals(field->oneof_name, active_field->oneof_name)) {
            continue;
        }

        zend_hash_del(target_table, field->name);
    }
}

static void king_proto_runtime_mark_field_seen(
    const king_proto_runtime_schema *runtime_schema,
    const king_proto_runtime_field *field,
    bool *seen_required_fields
)
{
    (void) runtime_schema;

    if (seen_required_fields != NULL) {
        seen_required_fields[field->field_index] = true;
    }
}
