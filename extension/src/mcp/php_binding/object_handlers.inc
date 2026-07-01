/*
 * King MCP PHP object handlers.
 */

static zend_object_handlers king_mcp_object_handlers;
static zend_object_handlers king_mcp_server_object_handlers;

static void king_mcp_object_free(zend_object *object)
{
    king_mcp_object *intern = php_king_mcp_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
        ZVAL_UNDEF(&intern->resource);
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_mcp_object_create(zend_class_entry *ce)
{
    king_mcp_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->resource);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_mcp_object_handlers;

    return &intern->std;
}

static void king_mcp_server_object_free(zend_object *object)
{
    king_mcp_server_object *intern = php_king_mcp_server_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->definition)) {
        zval_ptr_dtor(&intern->definition);
        ZVAL_UNDEF(&intern->definition);
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_mcp_server_object_create(zend_class_entry *ce)
{
    king_mcp_server_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->definition);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_mcp_server_object_handlers;

    return &intern->std;
}

static void king_mcp_object_handlers_init(void)
{
    memcpy(
        &king_mcp_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_mcp_object_handlers.offset = XtOffsetOf(king_mcp_object, std);
    king_mcp_object_handlers.free_obj = king_mcp_object_free;
    king_mcp_object_handlers.clone_obj = NULL;
    king_ce_mcp->create_object = king_mcp_object_create;
}

static void king_mcp_server_object_handlers_init(void)
{
    memcpy(
        &king_mcp_server_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_mcp_server_object_handlers.offset = XtOffsetOf(king_mcp_server_object, std);
    king_mcp_server_object_handlers.free_obj = king_mcp_server_object_free;
    king_mcp_server_object_handlers.clone_obj = NULL;
    king_ce_mcp_server->create_object = king_mcp_server_object_create;
}
