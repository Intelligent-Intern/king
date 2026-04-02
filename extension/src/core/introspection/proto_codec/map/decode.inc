/*
 * Map codec decode slice: decodes one length-delimited protobuf map entry,
 * applies default key/value initialization when needed, and stores the final
 * entry in the target map storage.
 */

static bool king_proto_runtime_decode_map_field(
    zval *target,
    bool decode_as_object,
    const king_proto_runtime_field *field,
    zend_string *schema_name,
    const unsigned char **buf_ptr,
    const unsigned char *buf_end
)
{
    uint64_t length = 0;
    const unsigned char *entry_ptr;
    const unsigned char *entry_end;
    bool decoded_key_present = false;
    zval decoded_key;
    bool decoded_value_present = false;
    king_proto_runtime_field map_key_field;
    zval decoded_value;
    king_proto_runtime_field map_value_field;
    zval *map_values;

    if (!king_proto_decode_varint(buf_ptr, buf_end, &length)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding error: malformed length prefix for field '%s' in schema '%s'.",
            ZSTR_VAL(field->name),
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    if (length > (uint64_t) (buf_end - *buf_ptr)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding error: length-delimited field '%s' exceeds buffer size in schema '%s'.",
            ZSTR_VAL(field->name),
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    entry_ptr = *buf_ptr;
    entry_end = entry_ptr + (size_t) length;
    *buf_ptr = entry_end;

    king_proto_runtime_init_map_key_field(field, &map_key_field);
    king_proto_runtime_init_map_value_field(field, &map_value_field);
    ZVAL_UNDEF(&decoded_key);
    ZVAL_UNDEF(&decoded_value);

    while (entry_ptr < entry_end) {
        uint64_t entry_key = 0;
        uint32_t tag;
        uint32_t wire_type;

        if (!king_proto_decode_varint(&entry_ptr, entry_end, &entry_key) || entry_key == 0) {
            if (Z_TYPE(decoded_key) != IS_UNDEF) {
                zval_ptr_dtor_nogc(&decoded_key);
            }
            if (Z_TYPE(decoded_value) != IS_UNDEF) {
                zval_ptr_dtor_nogc(&decoded_value);
            }
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Decoding error: malformed tag/wire_type varint in map field '%s' for schema '%s'.",
                ZSTR_VAL(field->name),
                ZSTR_VAL(schema_name)
            );
            return false;
        }

        tag = (uint32_t) (entry_key >> 3);
        wire_type = (uint32_t) (entry_key & 0x07U);

        if (tag == 1) {
            if (wire_type != king_proto_runtime_wire_type_for_kind(map_key_field.kind)) {
                if (Z_TYPE(decoded_key) != IS_UNDEF) {
                    zval_ptr_dtor_nogc(&decoded_key);
                    ZVAL_UNDEF(&decoded_key);
                }
                if (Z_TYPE(decoded_value) != IS_UNDEF) {
                    zval_ptr_dtor_nogc(&decoded_value);
                }
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Wire type mismatch for map field '%s' entry key. Expected wire type %u, but got %u on the wire.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    king_proto_runtime_wire_type_for_kind(map_key_field.kind),
                    wire_type
                );
                return false;
            }

            if (decoded_key_present) {
                zval_ptr_dtor_nogc(&decoded_key);
                ZVAL_UNDEF(&decoded_key);
            }

            if (!king_proto_runtime_decode_field_value(
                &map_key_field,
                schema_name,
                &entry_ptr,
                entry_end,
                false,
                &decoded_key
            )) {
                if (Z_TYPE(decoded_value) != IS_UNDEF) {
                    zval_ptr_dtor_nogc(&decoded_value);
                }
                return false;
            }

            decoded_key_present = true;
            continue;
        }

        if (tag == 2) {
            if (wire_type != king_proto_runtime_wire_type_for_kind(map_value_field.kind)) {
                if (Z_TYPE(decoded_key) != IS_UNDEF) {
                    zval_ptr_dtor_nogc(&decoded_key);
                }
                if (Z_TYPE(decoded_value) != IS_UNDEF) {
                    zval_ptr_dtor_nogc(&decoded_value);
                }
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Wire type mismatch for map field '%s' entry value. Expected wire type %u, but got %u on the wire.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    king_proto_runtime_wire_type_for_kind(map_value_field.kind),
                    wire_type
                );
                return false;
            }

            if (decoded_value_present) {
                zval_ptr_dtor_nogc(&decoded_value);
                ZVAL_UNDEF(&decoded_value);
            }

            if (!king_proto_runtime_decode_field_value(
                &map_value_field,
                schema_name,
                &entry_ptr,
                entry_end,
                decode_as_object,
                &decoded_value
            )) {
                if (Z_TYPE(decoded_key) != IS_UNDEF) {
                    zval_ptr_dtor_nogc(&decoded_key);
                }
                return false;
            }

            decoded_value_present = true;
            continue;
        }

        if (!king_proto_runtime_skip_unknown_field(&entry_ptr, entry_end, wire_type)) {
            if (Z_TYPE(decoded_key) != IS_UNDEF) {
                zval_ptr_dtor_nogc(&decoded_key);
            }
            if (Z_TYPE(decoded_value) != IS_UNDEF) {
                zval_ptr_dtor_nogc(&decoded_value);
            }
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Decoding error: failed to skip unknown map-entry field with tag %u for field '%s' in schema '%s'.",
                tag,
                ZSTR_VAL(field->name),
                ZSTR_VAL(schema_name)
            );
            return false;
        }
    }

    if (!decoded_key_present
        && !king_proto_runtime_init_zero_value(field->map_key_kind, &decoded_key)) {
        if (Z_TYPE(decoded_value) != IS_UNDEF) {
            zval_ptr_dtor_nogc(&decoded_value);
        }
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Schema '%s': Map field '%s' has an unsupported runtime key type.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(field->name)
        );
        return false;
    }

    if (!decoded_value_present
        && !king_proto_runtime_init_map_default_value(
            field,
            decode_as_object,
            &decoded_value
        )) {
        zval_ptr_dtor_nogc(&decoded_key);
        if (EG(exception) == NULL) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "Schema '%s': Map field '%s' has an unsupported runtime value type.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field->name)
            );
        }
        return false;
    }

    map_values = king_proto_runtime_fetch_or_init_map_storage(
        target,
        decode_as_object,
        field
    );
    king_proto_runtime_store_decoded_map_entry(
        map_values,
        field,
        &decoded_key,
        &decoded_value
    );
    zval_ptr_dtor_nogc(&decoded_key);

    return true;
}
