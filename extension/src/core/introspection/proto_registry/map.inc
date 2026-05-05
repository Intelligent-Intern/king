/*
 * Root proto-registry map-helper slice: prepares synthetic key/value field
 * descriptors for protobuf map entries, parses map input keys, stores
 * decoded map entries, and initializes default map values for decode paths.
 */

static void king_proto_runtime_init_map_value_field(
    const king_proto_runtime_field *field,
    king_proto_runtime_field *map_value_field
)
{
    memset(map_value_field, 0, sizeof(*map_value_field));
    map_value_field->name = field->name;
    map_value_field->message_schema_name = field->map_value_message_schema_name;
    map_value_field->enum_name = field->map_value_enum_name;
    map_value_field->kind = field->map_value_kind;
}

static void king_proto_runtime_init_map_key_field(
    const king_proto_runtime_field *field,
    king_proto_runtime_field *map_key_field
)
{
    memset(map_key_field, 0, sizeof(*map_key_field));
    map_key_field->name = field->name;
    map_key_field->kind = field->map_key_kind;
}

static bool king_proto_runtime_parse_map_input_key(
    const king_proto_runtime_field *field,
    zend_string *entry_key,
    zend_ulong entry_index,
    zval *parsed_key
)
{
    zend_long numeric_key = 0;
    double dummy_double = 0.0;

    switch (field->map_key_kind) {
        case KING_PROTO_RUNTIME_FIELD_STRING:
            if (entry_key == NULL) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects string map keys, but found integer key %lu.",
                    ZSTR_VAL(field->name),
                    (unsigned long) entry_index
                );
                return false;
            }

            ZVAL_STR_COPY(parsed_key, entry_key);
            return true;

        case KING_PROTO_RUNTIME_FIELD_BOOL:
            if (entry_key != NULL) {
                if (!is_numeric_string(
                    ZSTR_VAL(entry_key),
                    ZSTR_LEN(entry_key),
                    &numeric_key,
                    &dummy_double,
                    false
                )) {
                    zend_throw_exception_ex(
                        king_ce_validation_exception,
                        0,
                        "Encoding failed: Field '%s' expects bool map keys, but got string key '%s'.",
                        ZSTR_VAL(field->name),
                        ZSTR_VAL(entry_key)
                    );
                    return false;
                }
            } else {
                numeric_key = (zend_long) entry_index;
            }

            if (numeric_key != 0 && numeric_key != 1) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects bool map keys 0 or 1, but got " ZEND_LONG_FMT ".",
                    ZSTR_VAL(field->name),
                    numeric_key
                );
                return false;
            }

            ZVAL_BOOL(parsed_key, numeric_key != 0);
            return true;

        case KING_PROTO_RUNTIME_FIELD_INT32:
        case KING_PROTO_RUNTIME_FIELD_SINT32:
        case KING_PROTO_RUNTIME_FIELD_SFIXED32:
            if (entry_key != NULL) {
                if (!is_numeric_string(
                    ZSTR_VAL(entry_key),
                    ZSTR_LEN(entry_key),
                    &numeric_key,
                    &dummy_double,
                    false
                )) {
                    zend_throw_exception_ex(
                        king_ce_validation_exception,
                        0,
                        "Encoding failed: Field '%s' expects int32-compatible map keys, but got string key '%s'.",
                        ZSTR_VAL(field->name),
                        ZSTR_VAL(entry_key)
                    );
                    return false;
                }
            } else {
                numeric_key = (zend_long) entry_index;
            }

            if (numeric_key < INT32_MIN || numeric_key > INT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects a 32-bit signed integer map key, but got " ZEND_LONG_FMT ".",
                    ZSTR_VAL(field->name),
                    numeric_key
                );
                return false;
            }

            ZVAL_LONG(parsed_key, numeric_key);
            return true;

        case KING_PROTO_RUNTIME_FIELD_UINT32:
        case KING_PROTO_RUNTIME_FIELD_FIXED32:
            if (entry_key != NULL) {
                if (!is_numeric_string(
                    ZSTR_VAL(entry_key),
                    ZSTR_LEN(entry_key),
                    &numeric_key,
                    &dummy_double,
                    false
                )) {
                    zend_throw_exception_ex(
                        king_ce_validation_exception,
                        0,
                        "Encoding failed: Field '%s' expects uint32-compatible map keys, but got string key '%s'.",
                        ZSTR_VAL(field->name),
                        ZSTR_VAL(entry_key)
                    );
                    return false;
                }
            } else {
                numeric_key = (zend_long) entry_index;
            }

            if (numeric_key < 0 || (uint64_t) numeric_key > UINT32_MAX) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Field '%s' expects an unsigned 32-bit map key, but got " ZEND_LONG_FMT ".",
                    ZSTR_VAL(field->name),
                    numeric_key
                );
                return false;
            }

            ZVAL_LONG(parsed_key, numeric_key);
            return true;

        default:
            ZVAL_UNDEF(parsed_key);
            return false;
    }
}

static void king_proto_runtime_store_decoded_map_entry(
    zval *map_values,
    const king_proto_runtime_field *field,
    zval *decoded_key,
    zval *decoded_value
)
{
    if (field->map_key_kind == KING_PROTO_RUNTIME_FIELD_STRING) {
        zend_hash_update(Z_ARRVAL_P(map_values), Z_STR_P(decoded_key), decoded_value);
        return;
    }

    if (field->map_key_kind == KING_PROTO_RUNTIME_FIELD_BOOL) {
        zend_hash_index_update(
            Z_ARRVAL_P(map_values),
            Z_TYPE_P(decoded_key) == IS_TRUE ? 1 : 0,
            decoded_value
        );
        return;
    }

    zend_hash_index_update(
        Z_ARRVAL_P(map_values),
        (zend_ulong) Z_LVAL_P(decoded_key),
        decoded_value
    );
}

static bool king_proto_runtime_decode_schema_payload(
    zend_string *schema_name,
    const king_proto_runtime_schema *runtime_schema,
    const unsigned char *buf,
    size_t buf_len,
    bool decode_as_object,
    zval *decoded_result
);

static bool king_proto_runtime_init_map_default_value(
    const king_proto_runtime_field *field,
    bool decode_as_object,
    zval *value_out
)
{
    if (field->map_value_kind != KING_PROTO_RUNTIME_FIELD_MESSAGE) {
        return king_proto_runtime_init_zero_value(field->map_value_kind, value_out);
    }

    if (field->map_value_message_schema_name == NULL) {
        ZVAL_UNDEF(value_out);
        return false;
    }

    {
        king_proto_runtime_schema *nested_schema;

        nested_schema = king_proto_registry_get_runtime_schema(
            field->map_value_message_schema_name
        );
        if (nested_schema == NULL || !nested_schema->runtime_subset_supported) {
            ZVAL_UNDEF(value_out);
            king_throw_proto_schema_registered_but_unavailable(
                field->map_value_message_schema_name,
                "decoding"
            );
            return false;
        }

        /* Reuse child-message decode so defaults and required checks stay consistent. */
        return king_proto_runtime_decode_schema_payload(
            field->map_value_message_schema_name,
            nested_schema,
            (const unsigned char *) "",
            0,
            decode_as_object,
            value_out
        );
    }
}
