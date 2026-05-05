/*
 * Decode schema-payload slice: drives full payload decoding for one runtime
 * schema, including unknown-field skipping, map and packed handling, oneof
 * replacement, required-field enforcement, and default-value materialization.
 */

static bool king_proto_runtime_decode_schema_payload(
    zend_string *schema_name,
    const king_proto_runtime_schema *runtime_schema,
    const unsigned char *buf,
    size_t buf_len,
    bool decode_as_object,
    zval *decoded_result
)
{
    const unsigned char *buf_ptr;
    const unsigned char *buf_end;
    bool *seen_fields = NULL;
    size_t i;

    if (runtime_schema == NULL || !runtime_schema->runtime_subset_supported) {
        king_throw_proto_schema_registered_but_unavailable(schema_name, "decoding");
        return false;
    }

    if (runtime_schema->field_count == 0) {
        buf_ptr = buf;
        buf_end = buf + buf_len;

        if (decode_as_object) {
            object_init(decoded_result);
        } else {
            array_init(decoded_result);
        }

        while (buf_ptr < buf_end) {
            uint64_t key = 0;
            uint32_t tag;
            uint32_t wire_type;

            if (!king_proto_decode_varint(&buf_ptr, buf_end, &key) || key == 0) {
                zval_ptr_dtor(decoded_result);
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: malformed tag/wire_type varint in schema '%s'.",
                    ZSTR_VAL(schema_name)
                );
                return false;
            }

            tag = (uint32_t) (key >> 3);
            wire_type = (uint32_t) (key & 0x07U);

            if (!king_proto_runtime_skip_unknown_field(&buf_ptr, buf_end, wire_type)) {
                zval_ptr_dtor(decoded_result);
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: failed to skip unknown field with tag %u in schema '%s'.",
                    tag,
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
        }

        return true;
    }

    if (decode_as_object) {
        object_init(decoded_result);
    } else {
        array_init(decoded_result);
    }

    if (runtime_schema->required_field_count > 0
        || runtime_schema->default_field_count > 0) {
        seen_fields = ecalloc(runtime_schema->field_count, sizeof(bool));
    }
    buf_ptr = buf;
    buf_end = buf + buf_len;

    while (buf_ptr < buf_end) {
        uint64_t key = 0;
        uint32_t tag;
        uint32_t wire_type;
        const king_proto_runtime_field *field;
        bool decoded_any_values = false;
        zval decoded_value;

        if (!king_proto_decode_varint(&buf_ptr, buf_end, &key) || key == 0) {
            efree(seen_fields);
            zval_ptr_dtor(decoded_result);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Decoding error: malformed tag/wire_type varint in schema '%s'.",
                ZSTR_VAL(schema_name)
            );
            return false;
        }

        tag = (uint32_t) (key >> 3);
        wire_type = (uint32_t) (key & 0x07U);
        field = king_proto_runtime_find_field_by_tag(runtime_schema, tag);

        if (field == NULL) {
            if (!king_proto_runtime_skip_unknown_field(&buf_ptr, buf_end, wire_type)) {
                efree(seen_fields);
                zval_ptr_dtor(decoded_result);
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Decoding error: failed to skip unknown field with tag %u in schema '%s'.",
                    tag,
                    ZSTR_VAL(schema_name)
                );
                return false;
            }
            continue;
        }

        if (field->kind == KING_PROTO_RUNTIME_FIELD_MAP) {
            if (wire_type != KING_WIRETYPE_LENGTH_DELIM) {
                efree(seen_fields);
                zval_ptr_dtor(decoded_result);
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Schema '%s': Wire type mismatch for field '%s' (tag %u). Expected wire type %u, but got %u on the wire.",
                    ZSTR_VAL(schema_name),
                    ZSTR_VAL(field->name),
                    tag,
                    KING_WIRETYPE_LENGTH_DELIM,
                    wire_type
                );
                return false;
            }

            if (!king_proto_runtime_decode_map_field(
                decoded_result,
                decode_as_object,
                field,
                schema_name,
                &buf_ptr,
                buf_end
            )) {
                efree(seen_fields);
                zval_ptr_dtor(decoded_result);
                return false;
            }

            king_proto_runtime_mark_field_seen(runtime_schema, field, seen_fields);
            continue;
        }

        if (wire_type == KING_WIRETYPE_LENGTH_DELIM
            && king_proto_runtime_field_supports_packed(field)) {
            if (!king_proto_runtime_decode_packed_field_values(
                decoded_result,
                decode_as_object,
                field,
                schema_name,
                &buf_ptr,
                buf_end,
                &decoded_any_values
            )) {
                efree(seen_fields);
                zval_ptr_dtor(decoded_result);
                return false;
            }

            if (decoded_any_values) {
                king_proto_runtime_mark_field_seen(
                    runtime_schema,
                    field,
                    seen_fields
                );
            }
            continue;
        }

        if (wire_type != field->wire_type) {
            efree(seen_fields);
            zval_ptr_dtor(decoded_result);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Schema '%s': Wire type mismatch for field '%s' (tag %u). Expected wire type %u, but got %u on the wire.",
                ZSTR_VAL(schema_name),
                ZSTR_VAL(field->name),
                tag,
                field->wire_type,
                wire_type
            );
            return false;
        }

        ZVAL_UNDEF(&decoded_value);
        if (!king_proto_runtime_decode_field_value(
            field,
            schema_name,
            &buf_ptr,
            buf_end,
            decode_as_object,
            &decoded_value
        )) {
            efree(seen_fields);
            zval_ptr_dtor(decoded_result);
            return false;
        }

        king_proto_runtime_clear_oneof_group(
            decoded_result,
            decode_as_object,
            runtime_schema,
            field
        );
        king_proto_runtime_store_decoded_value(
            decoded_result,
            decode_as_object,
            field,
            &decoded_value
        );
        king_proto_runtime_mark_field_seen(runtime_schema, field, seen_fields);
    }

    for (i = 0; i < runtime_schema->required_field_count; i++) {
        const king_proto_runtime_field *field = runtime_schema->required_fields[i];

        if (seen_fields != NULL && seen_fields[field->field_index]) {
            continue;
        }

        efree(seen_fields);
        zval_ptr_dtor(decoded_result);
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding error: Required field '%s' (tag " ZEND_LONG_FMT ") not found in payload for schema '%s'.",
            ZSTR_VAL(field->name),
            field->tag,
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    for (i = 0; i < runtime_schema->default_field_count; i++) {
        const king_proto_runtime_field *field = runtime_schema->default_fields[i];

        if (seen_fields != NULL && seen_fields[field->field_index]) {
            continue;
        }

        king_proto_runtime_store_default_value(
            decoded_result,
            decode_as_object,
            field
        );
    }

    efree(seen_fields);
    return true;
}
