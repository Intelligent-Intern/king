/*
 * Public schema-definition bridge for IIBIN. Validates registry availability,
 * compiles the userland schema into the runtime form and installs it into the
 * process-local schema registry.
 */

#include "php_king.h"
#include "include/iibin/iibin_internal.h"

zend_result king_iibin_define_schema(
    zend_string *schema_name,
    zval *schema_definition
)
{
    king_proto_runtime_schema *runtime_schema;
    zend_string *persistent_schema_name;

    if (!king_proto_registries_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Proto registries are unavailable during %s.",
            "schema definition"
        );
        return FAILURE;
    }

    if (king_iibin_is_defined(schema_name)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Schema or Enum name '%s' already defined.",
            ZSTR_VAL(schema_name)
        );
        return FAILURE;
    }

    if (king_iibin_compile_schema_definition(
            schema_name,
            schema_definition,
            &runtime_schema
        ) != SUCCESS) {
        return FAILURE;
    }

    persistent_schema_name = zend_string_dup(schema_name, 1);
    if (zend_hash_add_ptr(&king_proto_schema_registry, persistent_schema_name, runtime_schema) == NULL) {
        zend_string_release_ex(persistent_schema_name, 1);
        king_proto_runtime_schema_free(runtime_schema);
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Failed to add schema '%s' to registry.",
            ZSTR_VAL(schema_name)
        );
        return FAILURE;
    }

    return SUCCESS;
}
