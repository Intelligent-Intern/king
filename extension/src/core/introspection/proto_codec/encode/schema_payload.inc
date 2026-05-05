/*
 * Encode schema-payload slice: drives full payload encoding for one runtime
 * schema, including input-shape validation, oneof tracking, map handling,
 * repeated-field handling, and required-field enforcement.
 */

static bool king_proto_runtime_encode_schema_payload(
    smart_str *buf,
    zend_string *schema_name,
    const king_proto_runtime_schema *runtime_schema,
    zval *data
)
{
    HashTable selected_oneofs;
    size_t i;

    if (runtime_schema == NULL || !runtime_schema->runtime_subset_supported) {
        king_throw_proto_schema_registered_but_unavailable(schema_name, "encoding");
        return false;
    }

    if (Z_TYPE_P(data) != IS_ARRAY && Z_TYPE_P(data) != IS_OBJECT) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Data for message type '%s' must be an array or object.",
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    if (!king_iibin_validate_schema_input(schema_name, runtime_schema, data)) {
        return false;
    }

    zend_hash_init(&selected_oneofs, 8, NULL, ZVAL_PTR_DTOR, 0);

    for (i = 0; i < runtime_schema->field_count; i++) {
        const king_proto_runtime_field *field = &runtime_schema->fields[i];
        zval *value = king_proto_runtime_fetch_input_value(data, field->name);

        if (value != NULL) {
            ZVAL_DEREF(value);
        }

        if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
            if (field->required) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "Encoding failed: Required field '%s' (tag " ZEND_LONG_FMT ") is missing or null.",
                    ZSTR_VAL(field->name),
                    field->tag
                );
                zend_hash_destroy(&selected_oneofs);
                return false;
            }
            continue;
        }

        if (!king_proto_runtime_track_oneof_selection(&selected_oneofs, schema_name, field)) {
            zend_hash_destroy(&selected_oneofs);
            return false;
        }

        if (field->kind == KING_PROTO_RUNTIME_FIELD_MAP) {
            if (!king_proto_runtime_encode_map_field(buf, field, value)) {
                zend_hash_destroy(&selected_oneofs);
                return false;
            }
            continue;
        }

        if (field->repeated) {
            if (field->packed) {
                if (!king_proto_runtime_encode_packed_repeated_field(buf, field, value)) {
                    zend_hash_destroy(&selected_oneofs);
                    return false;
                }
                continue;
            }

            if (!king_proto_runtime_encode_repeated_field(buf, field, value)) {
                zend_hash_destroy(&selected_oneofs);
                return false;
            }
            continue;
        }

        if (!king_proto_runtime_encode_field(buf, field, value)) {
            zend_hash_destroy(&selected_oneofs);
            return false;
        }
    }

    zend_hash_destroy(&selected_oneofs);
    return true;
}
