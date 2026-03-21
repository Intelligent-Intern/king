static int king_proto_validate_schema_field_constraints(
    zend_string *schema_name,
    zend_string *field_name,
    const king_proto_schema_field_validation_state *state
)
{
    if (state->oneof != NULL) {
        if (state->is_repeated) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot use 'oneof' with repeated fields.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name)
            );
            return FAILURE;
        }

        if (state->is_map) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot use 'oneof' with map fields.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name)
            );
            return FAILURE;
        }

        if (state->required != NULL && Z_TYPE_P(state->required) == IS_TRUE) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot mark oneof fields as 'required'.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name)
            );
            return FAILURE;
        }
    }

    if (state->packed != NULL && Z_TYPE_P(state->packed) == IS_TRUE) {
        if (state->is_map) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot use 'packed' with type '%s'.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name),
                ZSTR_VAL(Z_STR_P(state->type))
            );
            return FAILURE;
        }

        if (!state->is_repeated) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' can only set 'packed' on repeated fields.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name)
            );
            return FAILURE;
        }

        if (!state->field_has_runtime_support
            || !king_proto_runtime_field_kind_supports_packed(state->field_kind)) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot use 'packed' with type '%s'.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name),
                ZSTR_VAL(Z_STR_P(state->type))
            );
            return FAILURE;
        }
    }

    if (state->default_value != NULL) {
        if (state->oneof != NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot set 'default' on oneof fields.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name)
            );
            return FAILURE;
        }

        if (state->is_map) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot set 'default' on map fields.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name)
            );
            return FAILURE;
        }

        if (state->required != NULL && Z_TYPE_P(state->required) == IS_TRUE) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot set 'default' on required fields.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name)
            );
            return FAILURE;
        }

        if (state->is_repeated) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot set 'default' on repeated fields.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name)
            );
            return FAILURE;
        }

        if (!state->field_has_runtime_support
            || !king_proto_runtime_field_kind_supports_default(state->field_kind)) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' cannot use 'default' with type '%s'.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field_name),
                ZSTR_VAL(Z_STR_P(state->type))
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}
