static void king_add_system_build_info(zval *target)
{
    add_assoc_string(target, "status", "ok");
    add_assoc_string(target, "build", "v1");
    add_assoc_string(target, "version", PHP_KING_VERSION);
    add_assoc_string(target, "php_version", PHP_VERSION);
}

static void king_add_config_policy_info(zval *target)
{
    add_assoc_bool(
        target,
        "config_override_allowed",
        king_globals.is_userland_override_allowed ? 1 : 0
    );
}

static void king_component_info_init(
    zval *target,
    const char *name,
    const char *implementation
)
{
    array_init(target);
    add_assoc_string(target, "name", (char *) name);
    add_assoc_string(target, "build", "v1");
    add_assoc_string(target, "version", PHP_KING_VERSION);
    add_assoc_string(target, "implementation", (char *) implementation);
}

static void king_component_info_finish(zval *target, zval *configuration)
{
    add_assoc_zval(target, "configuration", configuration);
    add_assoc_long(target, "info_generated_at", (zend_long) time(NULL));
}

/*
 * The current runtime maintains a single shared error buffer today. Reuse it
 * across the generic, WebSocket, and MCP entry points until subsystem-local
 * runtime state exists.
 */
static void king_return_shared_error(zval *return_value)
{
    RETVAL_STRING(king_get_error());
}
