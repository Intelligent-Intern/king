/*
 * King WebSocket server PHP object handlers.
 */

#include <unistd.h>

static void king_ws_server_object_free(zend_object *object)
{
    king_ws_server_object *intern = php_king_ws_server_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->config)) {
        zval_ptr_dtor(&intern->config);
        ZVAL_UNDEF(&intern->config);
    }
    if (intern->host != NULL) {
        zend_string_release(intern->host);
        intern->host = NULL;
    }
    if (intern->listener_fd >= 0) {
        close(intern->listener_fd);
        intern->listener_fd = -1;
    }
    if (intern->registry_initialized) {
        zval *entry;

        ZEND_HASH_FOREACH_VAL(&intern->connections, entry)
        {
            king_ws_state *state = Z_TYPE_P(entry) == IS_PTR
                ? (king_ws_state *) Z_PTR_P(entry)
                : NULL;

            if (state != NULL && state->server_owner == intern) {
                state->server_owner = NULL;
            }
        }
        ZEND_HASH_FOREACH_END();

        zend_hash_destroy(&intern->connections);
        intern->registry_initialized = false;
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_ws_server_object_create(zend_class_entry *ce)
{
    king_ws_server_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->config);
    intern->host = NULL;
    intern->port = 0;
    intern->listener_fd = -1;
    zend_hash_init(&intern->connections, 8, NULL, NULL, 0);
    intern->next_connection_sequence = 1;
    intern->registry_initialized = true;
    intern->closed = false;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_ws_server_object_handlers;

    return &intern->std;
}

static void king_ws_server_object_handlers_init(void)
{
    memcpy(
        &king_ws_server_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_ws_server_object_handlers.offset = XtOffsetOf(king_ws_server_object, std);
    king_ws_server_object_handlers.free_obj = king_ws_server_object_free;
    king_ws_server_object_handlers.clone_obj = NULL;
    king_ce_ws_server->create_object = king_ws_server_object_create;
}
