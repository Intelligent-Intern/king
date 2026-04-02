/*
 * Schema-validation runtime-resolution slice: resolves field type strings
 * into the active runtime subset, distinguishes repeated and map fields, and
 * marks schemas that are valid to define but not fully supported by the
 * current runtime field implementation.
 */

static int king_proto_validate_schema_field_runtime_resolution(
    zend_string *schema_name,
    zend_string *field_name,
    zval *field_options,
    king_proto_schema_field_validation_state *state,
    bool *runtime_subset_supported
)
{
    state->type = zend_hash_str_find(Z_ARRVAL_P(field_options), "type", sizeof("type") - 1);
    if (state->type == NULL || Z_TYPE_P(state->type) != IS_STRING
        || !king_proto_split_field_type(
            Z_STR_P(state->type),
            &state->base_type_name,
            &state->base_type_name_len
        )) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema '%s': Field '%s' has invalid or missing 'type' string.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(field_name)
        );
        return FAILURE;
    }

    state->is_repeated = king_proto_schema_field_type_is_repeated(Z_STR_P(state->type));
    state->is_map = king_proto_parse_map_type(
        state->base_type_name,
        state->base_type_name_len,
        &state->map_key_type_name,
        &state->map_key_type_name_len,
        &state->map_value_type_name,
        &state->map_value_type_name_len
    );

    if (state->is_map) {
        if (state->is_repeated) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot use 'repeated_' with map types.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name)
            );
            return FAILURE;
        }

        if (state->required != NULL && Z_TYPE_P(state->required) == IS_TRUE) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot mark map fields as 'required'.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name)
            );
            return FAILURE;
        }

        if (!king_proto_runtime_resolve_map_key_type(
            state->map_key_type_name,
            state->map_key_type_name_len,
            &state->map_key_kind
        )) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' map key type '%.*s' is not supported; only 'string', 'bool', and 32-bit integer key types are allowed.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name),
                (int) state->map_key_type_name_len,
                state->map_key_type_name
            );
            return FAILURE;
        }

        state->field_has_runtime_support = king_proto_runtime_resolve_map_value_type(
            state->map_value_type_name,
            state->map_value_type_name_len,
            &state->map_value_kind,
            NULL,
            NULL
        );
        if (!king_proto_type_is_known(state->map_value_type_name, state->map_value_type_name_len)) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' map value type '%.*s' is not a primitive, defined message, or defined enum.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name),
                (int) state->map_value_type_name_len,
                state->map_value_type_name
            );
            return FAILURE;
        }
        if (!state->field_has_runtime_support) {
            *runtime_subset_supported = false;
        }

        state->field_kind = KING_PROTO_RUNTIME_FIELD_MAP;
        return SUCCESS;
    }

    if (!king_proto_type_is_known(state->base_type_name, state->base_type_name_len)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema '%s': Field '%s' type '%.*s' is not a primitive, defined message, or defined enum.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(field_name),
            (int) state->base_type_name_len,
            state->base_type_name
        );
        return FAILURE;
    }

    state->field_has_runtime_support = king_proto_runtime_resolve_field_type(
        state->base_type_name,
        state->base_type_name_len,
        state->is_repeated,
        &state->field_kind,
        NULL
    );
    if (!state->field_has_runtime_support) {
        *runtime_subset_supported = false;
    }

    return SUCCESS;
}
