/*
 * Legacy IIBIN decode implementation helpers. Retains the bounded decode-mode
 * and primitive wire helpers that back the current runtime codec path and the
 * decode_as_object materialization rules.
 */

#include "php_king.h"
#include "include/iibin/iibin_internal.h"

static const char * const king_proto_primitive_types[] = {
    "double",
    "float",
    "int64",
    "uint64",
    "int32",
    "uint32",
    "sint32",
    "sint64",
    "fixed64",
    "sfixed64",
    "fixed32",
    "sfixed32",
    "bool",
    "string",
    "bytes"
};

/* Float/double functions now in shared header */

typedef struct _king_proto_decode_mode {
    bool materialize_objects;
    bool class_map_initialized;
    HashTable class_map;
} king_proto_decode_mode;

static king_proto_runtime_schema *king_proto_registry_get_runtime_schema(
    zend_string *schema_name
);

static void king_iibin_decode_mode_destroy(king_proto_decode_mode *decode_mode)
{
    if (decode_mode->class_map_initialized) {
        zend_hash_destroy(&decode_mode->class_map);
        decode_mode->class_map_initialized = false;
    }
}

static bool king_iibin_validate_decode_class(
    zend_string *schema_name,
    zend_string *class_name
)
{
    zend_class_entry *target_ce;

    if (!king_iibin_is_schema_defined(schema_name)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding failed: decode_as_object schema '%s' is not defined.",
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    target_ce = zend_lookup_class(class_name);
    if (target_ce == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding failed: decode_as_object class '%s' for schema '%s' was not found.",
            ZSTR_VAL(class_name),
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    if (target_ce != zend_standard_class_def
        && (target_ce->type != ZEND_USER_CLASS
            || (target_ce->ce_flags & (ZEND_ACC_INTERFACE
                | ZEND_ACC_TRAIT
                | ZEND_ACC_ABSTRACT
                | ZEND_ACC_ENUM)) != 0)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding failed: decode_as_object class '%s' for schema '%s' must be stdClass or a concrete userland class.",
            ZSTR_VAL(class_name),
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    if (target_ce != zend_standard_class_def
        && zend_hash_str_exists(
            &target_ce->function_table,
            "__construct",
            sizeof("__construct") - 1
        )) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding failed: decode_as_object class '%s' for schema '%s' must not declare a constructor.",
            ZSTR_VAL(class_name),
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    return true;
}

static bool king_iibin_decode_mode_add_mapping(
    king_proto_decode_mode *decode_mode,
    zend_string *schema_name,
    zend_string *class_name
)
{
    zval stored_class_name;

    if (!king_iibin_validate_decode_class(schema_name, class_name)) {
        return false;
    }

    if (!decode_mode->class_map_initialized) {
        zend_hash_init(&decode_mode->class_map, 8, NULL, ZVAL_PTR_DTOR, 0);
        decode_mode->class_map_initialized = true;
    }

    ZVAL_STR_COPY(&stored_class_name, class_name);
    zend_hash_update(&decode_mode->class_map, schema_name, &stored_class_name);
    return true;
}

static bool king_iibin_decode_mode_init(
    zend_string *root_schema_name,
    zval *decode_mode_input,
    king_proto_decode_mode *decode_mode
)
{
    zend_string *schema_name;
    zval *class_name;

    memset(decode_mode, 0, sizeof(*decode_mode));

    if (decode_mode_input == NULL || Z_TYPE_P(decode_mode_input) == IS_FALSE) {
        return true;
    }

    if (Z_TYPE_P(decode_mode_input) == IS_TRUE) {
        decode_mode->materialize_objects = true;
        return true;
    }

    if (Z_TYPE_P(decode_mode_input) == IS_STRING) {
        decode_mode->materialize_objects = true;
        return king_iibin_decode_mode_add_mapping(
            decode_mode,
            root_schema_name,
            Z_STR_P(decode_mode_input)
        );
    }

    if (Z_TYPE_P(decode_mode_input) != IS_ARRAY) {
        zend_argument_type_error(
            3,
            "must be of type bool|string|array, %s given",
            zend_zval_type_name(decode_mode_input)
        );
        return false;
    }

    decode_mode->materialize_objects = true;

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(decode_mode_input), schema_name, class_name) {
        if (schema_name == NULL || ZSTR_LEN(schema_name) == 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Decoding failed: decode_as_object maps must use non-empty schema names as string keys."
            );
            king_iibin_decode_mode_destroy(decode_mode);
            return false;
        }

        if (Z_TYPE_P(class_name) != IS_STRING || Z_STRLEN_P(class_name) == 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Decoding failed: decode_as_object class map for schema '%s' must contain non-empty string class names.",
                ZSTR_VAL(schema_name)
            );
            king_iibin_decode_mode_destroy(decode_mode);
            return false;
        }

        if (!king_iibin_decode_mode_add_mapping(
                decode_mode,
                schema_name,
                Z_STR_P(class_name)
            )) {
            king_iibin_decode_mode_destroy(decode_mode);
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
}

static zend_class_entry *king_iibin_resolve_decode_target_class(
    const king_proto_decode_mode *decode_mode,
    zend_string *schema_name
)
{
    zval *mapped_class_name;

    if (!decode_mode->materialize_objects) {
        return NULL;
    }

    if (!decode_mode->class_map_initialized) {
        return zend_standard_class_def;
    }

    mapped_class_name = zend_hash_find(&decode_mode->class_map, schema_name);
    if (mapped_class_name == NULL) {
        return zend_standard_class_def;
    }

    return zend_lookup_class(Z_STR_P(mapped_class_name));
}

static bool king_iibin_init_decoded_object(
    zend_string *schema_name,
    const king_proto_decode_mode *decode_mode,
    zval *decoded_result
)
{
    zend_class_entry *target_ce;

    target_ce = king_iibin_resolve_decode_target_class(decode_mode, schema_name);
    if (target_ce == NULL || target_ce == zend_standard_class_def) {
        object_init(decoded_result);
        return true;
    }

    object_init_ex(decoded_result, target_ce);
    if (EG(exception) != NULL) {
        zend_clear_exception();
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding failed: Schema '%s' could not instantiate class '%s'.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(target_ce->name)
        );
        return false;
    }

    return true;
}

static bool king_iibin_store_hydrated_property(
    zend_string *schema_name,
    zval *target,
    zend_string *property_name,
    zval *value
)
{
    zend_class_entry *target_ce = Z_OBJCE_P(target);

    if (target_ce == zend_standard_class_def) {
        zend_hash_update(Z_OBJPROP_P(target), property_name, value);
        return true;
    }

    Z_OBJ_HT_P(target)->write_property(Z_OBJ_P(target), property_name, value, NULL);
    if (EG(exception) != NULL) {
        zend_clear_exception();
        zval_ptr_dtor(value);
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Decoding failed: Schema '%s' could not hydrate property '%s' on class '%s'.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(property_name),
            ZSTR_VAL(target_ce->name)
        );
        return false;
    }

    zval_ptr_dtor(value);
    return true;
}

static bool king_iibin_hydrate_schema_result(
    zend_string *schema_name,
    const king_proto_runtime_schema *runtime_schema,
    zval *decoded_array,
    const king_proto_decode_mode *decode_mode,
    zval *decoded_result
);

static bool king_iibin_hydrate_map_message_values(
    const king_proto_runtime_field *field,
    zval *decoded_value,
    const king_proto_decode_mode *decode_mode,
    zval *hydrated_value
)
{
    zend_string *entry_key;
    zend_ulong entry_index;
    zval *entry_value;

    array_init(hydrated_value);

    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(decoded_value), entry_index, entry_key, entry_value) {
        zval hydrated_entry;

        ZVAL_UNDEF(&hydrated_entry);
        if (!king_iibin_hydrate_schema_result(
                field->map_value_message_schema_name,
                king_proto_registry_get_runtime_schema(field->map_value_message_schema_name),
                entry_value,
                decode_mode,
                &hydrated_entry
            )) {
            zval_ptr_dtor(hydrated_value);
            return false;
        }

        if (entry_key != NULL) {
            zend_hash_update(Z_ARRVAL_P(hydrated_value), entry_key, &hydrated_entry);
        } else {
            zend_hash_index_update(Z_ARRVAL_P(hydrated_value), entry_index, &hydrated_entry);
        }
    } ZEND_HASH_FOREACH_END();

    return true;
}

static bool king_iibin_hydrate_repeated_message_values(
    const king_proto_runtime_field *field,
    zval *decoded_value,
    const king_proto_decode_mode *decode_mode,
    zval *hydrated_value
)
{
    zval *entry_value;

    array_init(hydrated_value);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(decoded_value), entry_value) {
        zval hydrated_entry;

        ZVAL_UNDEF(&hydrated_entry);
        if (!king_iibin_hydrate_schema_result(
                field->message_schema_name,
                king_proto_registry_get_runtime_schema(field->message_schema_name),
                entry_value,
                decode_mode,
                &hydrated_entry
            )) {
            zval_ptr_dtor(hydrated_value);
            return false;
        }

        add_next_index_zval(hydrated_value, &hydrated_entry);
    } ZEND_HASH_FOREACH_END();

    return true;
}

static bool king_iibin_hydrate_field_value(
    const king_proto_runtime_field *field,
    zval *decoded_value,
    const king_proto_decode_mode *decode_mode,
    zval *hydrated_value
)
{
    if (field->kind == KING_PROTO_RUNTIME_FIELD_MESSAGE) {
        if (field->repeated) {
            return king_iibin_hydrate_repeated_message_values(
                field,
                decoded_value,
                decode_mode,
                hydrated_value
            );
        }

        return king_iibin_hydrate_schema_result(
            field->message_schema_name,
            king_proto_registry_get_runtime_schema(field->message_schema_name),
            decoded_value,
            decode_mode,
            hydrated_value
        );
    }

    if (field->kind == KING_PROTO_RUNTIME_FIELD_MAP
        && field->map_value_kind == KING_PROTO_RUNTIME_FIELD_MESSAGE) {
        return king_iibin_hydrate_map_message_values(
            field,
            decoded_value,
            decode_mode,
            hydrated_value
        );
    }

    ZVAL_COPY(hydrated_value, decoded_value);
    return true;
}

static bool king_iibin_hydrate_schema_result(
    zend_string *schema_name,
    const king_proto_runtime_schema *runtime_schema,
    zval *decoded_array,
    const king_proto_decode_mode *decode_mode,
    zval *decoded_result
)
{
    size_t i;

    if (runtime_schema == NULL || Z_TYPE_P(decoded_array) != IS_ARRAY) {
        ZVAL_COPY(decoded_result, decoded_array);
        return true;
    }

    if (!king_iibin_init_decoded_object(schema_name, decode_mode, decoded_result)) {
        return false;
    }

    for (i = 0; i < runtime_schema->field_count; i++) {
        const king_proto_runtime_field *field = &runtime_schema->fields[i];
        zval *decoded_value;
        zval hydrated_value;

        decoded_value = zend_hash_find(Z_ARRVAL_P(decoded_array), field->name);
        if (decoded_value == NULL) {
            continue;
        }

        ZVAL_UNDEF(&hydrated_value);
        if (!king_iibin_hydrate_field_value(
                field,
                decoded_value,
                decode_mode,
                &hydrated_value
            )) {
            zval_ptr_dtor(decoded_result);
            return false;
        }

        if (!king_iibin_store_hydrated_property(
                schema_name,
                decoded_result,
                field->name,
                &hydrated_value
            )) {
            zval_ptr_dtor(decoded_result);
            return false;
        }
    }

    return true;
}

#include "../core/introspection/proto_registry.inc"
#include "../core/introspection/proto_codec.inc"

zend_result king_iibin_decode(
    zend_string *schema_name,
    zend_string *binary_data,
    zval *decode_mode_input,
    zval *decoded_result
)
{
    king_proto_decode_mode decode_mode;
    zval decoded_array;
    king_proto_runtime_schema *runtime_schema;

    if (!king_proto_registry_has_schema(schema_name)) {
        king_throw_proto_schema_not_defined(schema_name, "decoding");
        return FAILURE;
    }

    runtime_schema = king_proto_registry_get_runtime_schema(schema_name);
    if (runtime_schema == NULL) {
        king_throw_proto_schema_registered_but_unavailable(schema_name, "decoding");
        return FAILURE;
    }

    if (!king_iibin_decode_mode_init(schema_name, decode_mode_input, &decode_mode)) {
        return FAILURE;
    }

    ZVAL_UNDEF(&decoded_array);
    if (!king_proto_runtime_decode_schema_payload(
            schema_name,
            runtime_schema,
            (const unsigned char *) ZSTR_VAL(binary_data),
            ZSTR_LEN(binary_data),
            false,
            &decoded_array
        )) {
        king_iibin_decode_mode_destroy(&decode_mode);
        return FAILURE;
    }

    if (!decode_mode.materialize_objects) {
        ZVAL_COPY_VALUE(decoded_result, &decoded_array);
        king_iibin_decode_mode_destroy(&decode_mode);
        return SUCCESS;
    }

    if (!king_iibin_hydrate_schema_result(
            schema_name,
            runtime_schema,
            &decoded_array,
            &decode_mode,
            decoded_result
        )) {
        zval_ptr_dtor(&decoded_array);
        king_iibin_decode_mode_destroy(&decode_mode);
        return FAILURE;
    }

    zval_ptr_dtor(&decoded_array);
    king_iibin_decode_mode_destroy(&decode_mode);
    return SUCCESS;
}

/* Batch decode: decode multiple binary records */
zend_result king_iibin_decode_batch(
    zend_string *schema_name,
    zval *binary_records,
    zval *decode_mode_input,
    zval *decoded_out
) {
    king_proto_runtime_schema *runtime_schema;
    king_proto_decode_mode decode_mode;
    zval *binary_data;
    zval decoded;
    HashTable *ht;

    if (!king_proto_registry_has_schema(schema_name)) {
        king_throw_proto_schema_not_defined(schema_name, "batch decode");
        return FAILURE;
    }

    runtime_schema = king_proto_registry_get_runtime_schema(schema_name);
    if (!runtime_schema) {
        king_throw_proto_schema_registered_but_unavailable(schema_name, "batch decode");
        return FAILURE;
    }

    if (!king_iibin_decode_mode_init(schema_name, decode_mode_input, &decode_mode)) {
        return FAILURE;
    }

    // Handle both string (concatenated) and array (legacy) modes
    if (Z_TYPE_P(binary_records) == IS_STRING) {
        // Concatenated binary mode: varint-length prefixed records
        const unsigned char *data = (const unsigned char*)ZSTR_VAL(Z_STR_P(binary_records));
        size_t total_len = ZSTR_LEN(Z_STR_P(binary_records));
        
        if (total_len == 0) {
            array_init(decoded_out);
        } else {
            const unsigned char *p = data;
            const unsigned char *data_end = data + total_len;
            array_init(decoded_out);
            ht = Z_ARRVAL_P(decoded_out);

            while (p < data_end) {
                uint64_t rec_len = 0;
                if (!king_proto_decode_varint(&p, data_end, &rec_len)) {
                    break;
                }
                if (rec_len == 0 || p + rec_len > data_end) {
                    break;
                }

                // Decode payload
                ZVAL_UNDEF(&decoded);
                if (!king_proto_runtime_decode_schema_payload(
                        schema_name,
                        runtime_schema,
                        p,
                        (size_t)rec_len,
                        false,
                        &decoded)) {
                    // Payload decode failed
                    break;
                }

                // Advance past payload
                p += rec_len;

                // Add to result
                if (!decode_mode.materialize_objects) {
                    zend_hash_next_index_insert(ht, &decoded);
                } else {
                    zval hydrated;
                    if (king_iibin_hydrate_schema_result(schema_name, runtime_schema, &decoded, &decode_mode, &hydrated)) {
                        zend_hash_next_index_insert(ht, &hydrated);
                    }
                }
            }
        }
    } else {
        // Legacy array mode
        array_init(decoded_out);
        ht = Z_ARRVAL_P(decoded_out);

        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(binary_records), binary_data) {
            if (Z_TYPE_P(binary_data) != IS_STRING) continue;

            ZVAL_UNDEF(&decoded);
            if (!king_proto_runtime_decode_schema_payload(
                    schema_name,
                    runtime_schema,
                    (unsigned char*)ZSTR_VAL(Z_STR_P(binary_data)),
                    ZSTR_LEN(Z_STR_P(binary_data)),
                    false,
                    &decoded)) {
                king_iibin_decode_mode_destroy(&decode_mode);
                zend_hash_destroy(ht);
                return FAILURE;
            }

            if (!decode_mode.materialize_objects) {
                zend_hash_next_index_insert(ht, &decoded);
            } else {
                zval hydrated;
                if (!king_iibin_hydrate_schema_result(schema_name, runtime_schema, &decoded, &decode_mode, &hydrated)) {
                    king_iibin_decode_mode_destroy(&decode_mode);
                    zend_hash_destroy(ht);
                    return FAILURE;
                }
                zend_hash_next_index_insert(ht, &hydrated);
            }
        } ZEND_HASH_FOREACH_END();
    }

    king_iibin_decode_mode_destroy(&decode_mode);
    return SUCCESS;
}
