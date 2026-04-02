/*
 * Schema-validation metadata slice: validates the common per-field metadata
 * keys such as tag, required, packed, oneof, and default, and enforces tag
 * uniqueness before runtime type resolution happens.
 */

static int king_proto_validate_schema_field_metadata(
    zend_string *schema_name,
    zend_string *field_name,
    zval *field_options,
    HashTable *seen_tags,
    king_proto_schema_field_validation_state *state
)
{
    if (field_name == NULL || Z_TYPE_P(field_options) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema '%s': Invalid field definition.",
            ZSTR_VAL(schema_name)
        );
        return FAILURE;
    }

    state->tag = zend_hash_str_find(Z_ARRVAL_P(field_options), "tag", sizeof("tag") - 1);
    if (state->tag == NULL || Z_TYPE_P(state->tag) != IS_LONG || Z_LVAL_P(state->tag) <= 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema '%s': Field '%s' has invalid or missing 'tag'.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(field_name)
        );
        return FAILURE;
    }

    state->required = zend_hash_str_find(
        Z_ARRVAL_P(field_options),
        "required",
        sizeof("required") - 1
    );
    if (state->required != NULL
        && Z_TYPE_P(state->required) != IS_TRUE
        && Z_TYPE_P(state->required) != IS_FALSE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema '%s': Field '%s' has invalid 'required' flag.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(field_name)
        );
        return FAILURE;
    }

    state->packed = zend_hash_str_find(
        Z_ARRVAL_P(field_options),
        "packed",
        sizeof("packed") - 1
    );
    if (state->packed != NULL
        && Z_TYPE_P(state->packed) != IS_TRUE
        && Z_TYPE_P(state->packed) != IS_FALSE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema '%s': Field '%s' has invalid 'packed' flag.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(field_name)
        );
        return FAILURE;
    }

    state->oneof = zend_hash_str_find(
        Z_ARRVAL_P(field_options),
        "oneof",
        sizeof("oneof") - 1
    );
    if (state->oneof != NULL
        && (Z_TYPE_P(state->oneof) != IS_STRING || Z_STRLEN_P(state->oneof) == 0)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema '%s': Field '%s' has invalid 'oneof' group.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(field_name)
        );
        return FAILURE;
    }

    state->default_value = zend_hash_str_find(
        Z_ARRVAL_P(field_options),
        "default",
        sizeof("default") - 1
    );

    if (zend_hash_index_exists(seen_tags, (zend_ulong) Z_LVAL_P(state->tag))) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema '%s': Duplicate tag %ld.",
            ZSTR_VAL(schema_name),
            Z_LVAL_P(state->tag)
        );
        return FAILURE;
    }

    return SUCCESS;
}
