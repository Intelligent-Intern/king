/*
 * Process-local IIBIN registry runtime. Owns schema and enum registries,
 * module lifecycle, runtime destructor hooks and the public enum-definition
 * path used by the active proto surface.
 */

#include "php_king.h"
#include "include/iibin/iibin_internal.h"

HashTable king_proto_schema_registry;
HashTable king_proto_enum_registry;
bool king_proto_registries_initialized = false;

static void king_iibin_return_registered_names(zval *return_value, HashTable *registry)
{
    zend_string *name;
    zval *entry;

    array_init(return_value);

    ZEND_HASH_FOREACH_STR_KEY_VAL(registry, name, entry) {
        if (name != NULL) {
            add_next_index_str(return_value, zend_string_copy(name));
        }
        (void) entry;
    } ZEND_HASH_FOREACH_END();
}

static bool king_iibin_registry_has_name(zend_string *name)
{
    return king_iibin_is_schema_defined(name)
        || king_iibin_is_enum_defined(name);
}

void king_proto_runtime_schema_free(king_proto_runtime_schema *schema)
{
    size_t i;

    if (schema == NULL) {
        return;
    }

    if (schema->fields != NULL) {
        for (i = 0; i < schema->field_count; i++) {
            if (schema->fields[i].name != NULL) {
                zend_string_release_ex(schema->fields[i].name, 1);
            }
            if (schema->fields[i].message_schema_name != NULL) {
                zend_string_release_ex(schema->fields[i].message_schema_name, 1);
            }
            if (schema->fields[i].enum_name != NULL) {
                zend_string_release_ex(schema->fields[i].enum_name, 1);
            }
            if (schema->fields[i].map_value_message_schema_name != NULL) {
                zend_string_release_ex(schema->fields[i].map_value_message_schema_name, 1);
            }
            if (schema->fields[i].map_value_enum_name != NULL) {
                zend_string_release_ex(schema->fields[i].map_value_enum_name, 1);
            }
            if (schema->fields[i].oneof_name != NULL) {
                zend_string_release_ex(schema->fields[i].oneof_name, 1);
            }
            if (Z_TYPE(schema->fields[i].default_value) == IS_STRING) {
                zend_string_release_ex(Z_STR(schema->fields[i].default_value), 1);
            }
        }
        pefree(schema->fields, 1);
    }

    if (schema->fields_by_name_initialized) {
        zend_hash_destroy(&schema->fields_by_name);
    }

    if (schema->required_fields != NULL) {
        pefree(schema->required_fields, 1);
    }

    if (schema->default_fields != NULL) {
        pefree(schema->default_fields, 1);
    }

    pefree(schema, 1);
}

void king_proto_runtime_schema_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_proto_runtime_schema_free((king_proto_runtime_schema *) Z_PTR_P(zv));
    }
}

void king_proto_runtime_enum_free(king_proto_runtime_enum *runtime_enum)
{
    if (runtime_enum == NULL) {
        return;
    }

    zend_hash_destroy(&runtime_enum->members);
    pefree(runtime_enum, 1);
}

void king_proto_runtime_enum_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_proto_runtime_enum_free((king_proto_runtime_enum *) Z_PTR_P(zv));
    }
}

int king_proto_registry_minit(void)
{
    if (king_proto_registries_initialized) {
        return SUCCESS;
    }

    zend_hash_init(&king_proto_schema_registry, 8, NULL, king_proto_runtime_schema_zval_dtor, 1);
    zend_hash_init(&king_proto_enum_registry, 8, NULL, king_proto_runtime_enum_zval_dtor, 1);
    king_proto_registries_initialized = true;

    return SUCCESS;
}

void king_proto_registry_mshutdown(void)
{
    if (!king_proto_registries_initialized) {
        return;
    }

    zend_hash_destroy(&king_proto_schema_registry);
    zend_hash_destroy(&king_proto_enum_registry);
    king_proto_registries_initialized = false;
}

zend_result king_iibin_define_enum(
    zend_string *enum_name,
    zval *enum_values
)
{
    HashTable seen_numbers;
    king_proto_runtime_enum *runtime_enum;
    zend_string *member_name;
    zval *member_number;
    zend_string *persistent_enum_name;

    if (!king_proto_registries_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Proto registries are unavailable during %s.",
            "enum definition"
        );
        return FAILURE;
    }

    if (king_iibin_registry_has_name(enum_name)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Enum or Schema name '%s' already defined.",
            ZSTR_VAL(enum_name)
        );
        return FAILURE;
    }

    zend_hash_init(&seen_numbers, 8, NULL, NULL, 0);

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(enum_values), member_name, member_number) {
        if (member_name == NULL || Z_TYPE_P(member_number) != IS_LONG) {
            zend_hash_destroy(&seen_numbers);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Enum '%s': Invalid definition.",
                ZSTR_VAL(enum_name)
            );
            return FAILURE;
        }

        if (zend_hash_index_exists(&seen_numbers, (zend_ulong) Z_LVAL_P(member_number))) {
            zend_hash_destroy(&seen_numbers);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Enum '%s': Duplicate number %ld.",
                ZSTR_VAL(enum_name),
                Z_LVAL_P(member_number)
            );
            return FAILURE;
        }

        zend_hash_index_add_empty_element(&seen_numbers, (zend_ulong) Z_LVAL_P(member_number));
    } ZEND_HASH_FOREACH_END();

    zend_hash_destroy(&seen_numbers);

    runtime_enum = pemalloc(sizeof(*runtime_enum), 1);
    runtime_enum->member_count = zend_hash_num_elements(Z_ARRVAL_P(enum_values));
    zend_hash_init(&runtime_enum->members, runtime_enum->member_count, NULL, NULL, 1);

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(enum_values), member_name, member_number) {
        zval stored_member_number;
        zend_string *persistent_member_name;

        ZVAL_LONG(&stored_member_number, Z_LVAL_P(member_number));
        persistent_member_name = zend_string_dup(member_name, 1);
        if (zend_hash_add_new(&runtime_enum->members, persistent_member_name, &stored_member_number) == NULL) {
            zend_string_release_ex(persistent_member_name, 1);
            king_proto_runtime_enum_free(runtime_enum);
            zend_throw_exception_ex(
                king_ce_system_exception,
                0,
                "Failed to register enum member '%s::%s'.",
                ZSTR_VAL(enum_name),
                ZSTR_VAL(member_name)
            );
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    persistent_enum_name = zend_string_dup(enum_name, 1);
    if (zend_hash_add_ptr(&king_proto_enum_registry, persistent_enum_name, runtime_enum) == NULL) {
        zend_string_release_ex(persistent_enum_name, 1);
        king_proto_runtime_enum_free(runtime_enum);
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Failed to add enum '%s' to registry.",
            ZSTR_VAL(enum_name)
        );
        return FAILURE;
    }

    return SUCCESS;
}

bool king_iibin_is_schema_defined(zend_string *schema_name)
{
    return king_proto_registries_initialized
        && zend_hash_exists(&king_proto_schema_registry, schema_name);
}

bool king_iibin_is_enum_defined(zend_string *enum_name)
{
    return king_proto_registries_initialized
        && zend_hash_exists(&king_proto_enum_registry, enum_name);
}

bool king_iibin_is_defined(zend_string *name)
{
    return king_iibin_is_schema_defined(name)
        || king_iibin_is_enum_defined(name);
}

void king_iibin_get_defined_schemas(zval *return_value)
{
    if (!king_proto_registries_initialized) {
        array_init(return_value);
        return;
    }

    king_iibin_return_registered_names(return_value, &king_proto_schema_registry);
}

void king_iibin_get_defined_enums(zval *return_value)
{
    if (!king_proto_registries_initialized) {
        array_init(return_value);
        return;
    }

    king_iibin_return_registered_names(return_value, &king_proto_enum_registry);
}
