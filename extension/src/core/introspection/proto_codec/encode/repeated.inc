/*
 * Encode repeated-field slice: handles repeated runtime fields in unpacked
 * and packed form, including required-non-empty enforcement and per-item
 * validation before emission.
 */

static bool king_proto_runtime_encode_repeated_field(
    smart_str *buf,
    const king_proto_runtime_field *field,
    zval *values
)
{
    zval *item;

    if (Z_TYPE_P(values) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Encoding failed: Field '%s' expects an array for repeated values, but got %s.",
            ZSTR_VAL(field->name),
            zend_zval_type_name(values)
        );
        return false;
    }

    if (field->required && zend_hash_num_elements(Z_ARRVAL_P(values)) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Encoding failed: Required repeated field '%s' (tag " ZEND_LONG_FMT ") must contain at least one item.",
            ZSTR_VAL(field->name),
            field->tag
        );
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(values), item) {
        ZVAL_DEREF(item);
        if (!king_proto_runtime_encode_field(buf, field, item)) {
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
}

static bool king_proto_runtime_encode_packed_repeated_field(
    smart_str *buf,
    const king_proto_runtime_field *field,
    zval *values
)
{
    smart_str packed_payload = {0};
    zval *item;

    if (Z_TYPE_P(values) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Encoding failed: Field '%s' expects an array for repeated values, but got %s.",
            ZSTR_VAL(field->name),
            zend_zval_type_name(values)
        );
        return false;
    }

    if (field->required && zend_hash_num_elements(Z_ARRVAL_P(values)) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Encoding failed: Required repeated field '%s' (tag " ZEND_LONG_FMT ") must contain at least one item.",
            ZSTR_VAL(field->name),
            field->tag
        );
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(values), item) {
        ZVAL_DEREF(item);
        if (!king_proto_runtime_validate_field_value(field, item)
            || !king_proto_runtime_append_encoded_field_value(&packed_payload, field, item)) {
            smart_str_free(&packed_payload);
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    if (packed_payload.s == NULL) {
        return true;
    }

    king_proto_encode_varint(
        buf,
        (((uint64_t) field->tag) << 3) | KING_WIRETYPE_LENGTH_DELIM
    );
    king_proto_encode_varint(buf, (uint64_t) ZSTR_LEN(packed_payload.s));
    smart_str_appendl(buf, ZSTR_VAL(packed_payload.s), ZSTR_LEN(packed_payload.s));
    smart_str_free(&packed_payload);

    return true;
}
