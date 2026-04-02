/*
 * Schema-validation field-definition slice: drives validation of one schema
 * field by composing metadata checks, runtime resolution, and constraint
 * enforcement, then records the accepted tag in the seen-tag set.
 */

static int king_proto_validate_schema_field_definition(
    zend_string *schema_name,
    zend_string *field_name,
    zval *field_options,
    HashTable *seen_tags,
    bool *runtime_subset_supported
)
{
    king_proto_schema_field_validation_state state = {
        .map_key_kind = KING_PROTO_RUNTIME_FIELD_UNSUPPORTED,
        .field_kind = KING_PROTO_RUNTIME_FIELD_UNSUPPORTED,
        .map_value_kind = KING_PROTO_RUNTIME_FIELD_UNSUPPORTED,
    };

    if (king_proto_validate_schema_field_metadata(
            schema_name,
            field_name,
            field_options,
            seen_tags,
            &state
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_proto_validate_schema_field_runtime_resolution(
            schema_name,
            field_name,
            field_options,
            &state,
            runtime_subset_supported
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_proto_validate_schema_field_constraints(
            schema_name,
            field_name,
            &state
        ) != SUCCESS) {
        return FAILURE;
    }

    zend_hash_index_add_empty_element(seen_tags, (zend_ulong) Z_LVAL_P(state.tag));
    return SUCCESS;
}
