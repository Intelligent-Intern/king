/*
 * Proto-registry default enum slice: resolves enum member names to runtime
 * numeric values for encoding and persists enum defaults into the normalized
 * int32-backed runtime default representation.
 */

static bool king_proto_runtime_resolve_enum_value(
    const king_proto_runtime_field *field,
    zval *value,
    zend_long *resolved_value_out
)
{
    king_proto_runtime_enum *runtime_enum;
    zval *member_value;

    if (Z_TYPE_P(value) == IS_LONG) {
        *resolved_value_out = Z_LVAL_P(value);
        return true;
    }

    if (Z_TYPE_P(value) != IS_STRING || field->enum_name == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Encoding failed: Field '%s' expects an integer or enum member name string, but got %s.",
            ZSTR_VAL(field->name),
            zend_zval_type_name(value)
        );
        return false;
    }

    runtime_enum = king_proto_registry_get_runtime_enum(field->enum_name);
    if (runtime_enum == NULL) {
        king_throw_proto_schema_registered_but_unavailable(field->enum_name, "encoding");
        return false;
    }

    member_value = zend_hash_find(&runtime_enum->members, Z_STR_P(value));
    if (member_value == NULL || Z_TYPE_P(member_value) != IS_LONG) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Encoding failed: Field '%s' enum '%s' has no member named '%s'.",
            ZSTR_VAL(field->name),
            ZSTR_VAL(field->enum_name),
            Z_STRVAL_P(value)
        );
        return false;
    }

    *resolved_value_out = Z_LVAL_P(member_value);
    return true;
}

static bool king_proto_runtime_persist_enum_default_value(
    const king_proto_runtime_field *field,
    zval *value,
    zend_string *schema_name,
    zval *default_value_out)
{
    king_proto_runtime_enum *runtime_enum;
    zval *member_value;
    zend_long resolved_enum_value;

    if (Z_TYPE_P(value) == IS_LONG) {
        resolved_enum_value = Z_LVAL_P(value);
    } else if (Z_TYPE_P(value) == IS_STRING && field->enum_name != NULL) {
        runtime_enum = king_proto_registry_get_runtime_enum(field->enum_name);
        if (runtime_enum == NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' enum '%s' is not available for default resolution.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field->name),
                ZSTR_VAL(field->enum_name)
            );
            return false;
        }

        member_value = zend_hash_find(&runtime_enum->members, Z_STR_P(value));
        if (member_value == NULL || Z_TYPE_P(member_value) != IS_LONG) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Field '%s' default enum '%s' has no member named '%s'.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field->name),
                ZSTR_VAL(field->enum_name),
                Z_STRVAL_P(value)
            );
            return false;
        }

        resolved_enum_value = Z_LVAL_P(member_value);
    } else {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema '%s': Field '%s' default expects an integer or enum member name string, but got %s.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(field->name),
            zend_zval_type_name(value)
        );
        return false;
    }

    if (resolved_enum_value < INT32_MIN || resolved_enum_value > INT32_MAX) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema '%s': Field '%s' default expects a 32-bit signed integer, but got %ld.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(field->name),
            resolved_enum_value
        );
        return false;
    }

    ZVAL_LONG(default_value_out, resolved_enum_value);
    return true;
}
