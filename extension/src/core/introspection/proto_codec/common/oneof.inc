/*
 * Common codec oneof slice: tracks selected oneof members during encoding
 * and fails closed when multiple fields from the same oneof group are set.
 */

static bool king_proto_runtime_track_oneof_selection(
    HashTable *selected_oneofs,
    zend_string *schema_name,
    const king_proto_runtime_field *field
)
{
    zval *existing_field_name;
    zval selected_field_name;

    if (field->oneof_name == NULL) {
        return true;
    }

    existing_field_name = zend_hash_find(selected_oneofs, field->oneof_name);
    if (existing_field_name != NULL && Z_TYPE_P(existing_field_name) == IS_STRING) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Encoding failed: oneof group '%s' in schema '%s' has multiple fields set ('%s' and '%s').",
            ZSTR_VAL(field->oneof_name),
            ZSTR_VAL(schema_name),
            Z_STRVAL_P(existing_field_name),
            ZSTR_VAL(field->name)
        );
        return false;
    }

    ZVAL_STR_COPY(&selected_field_name, field->name);
    zend_hash_update(selected_oneofs, field->oneof_name, &selected_field_name);
    return true;
}
