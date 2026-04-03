/*
 * Root CDN companion slice: implements the local cache/invalidation leaves,
 * resolves cache-entry size through the active local/distributed/cloud
 * metadata path without dragging full payload bytes when avoidable, keeps
 * explicit invalidation honest for retained stale entries, and exposes the
 * explicitly configured edge-node inventory through live health probes instead
 * of inventing synthetic delivery nodes.
 */

#include "object_store/object_store_internal.h"
#include <sys/stat.h>
#include <errno.h>
#include <fcntl.h>
#include <netdb.h>
#include <poll.h>
#include <sys/socket.h>
#include <unistd.h>

static zend_bool king_cdn_edge_node_probe_health(const king_storage_node_t *node)
{
    struct addrinfo hints;
    struct addrinfo *results = NULL;
    struct addrinfo *cursor;
    char port_buffer[16];
    int gai_status;

    if (node == NULL || node->hostname[0] == '\0' || node->port == 0) {
        return 0;
    }

    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    hints.ai_flags = AI_ADDRCONFIG | AI_NUMERICSERV;

    snprintf(port_buffer, sizeof(port_buffer), "%u", (unsigned int) node->port);
    gai_status = getaddrinfo(node->hostname, port_buffer, &hints, &results);
    if (gai_status != 0) {
        return 0;
    }

    for (cursor = results; cursor != NULL; cursor = cursor->ai_next) {
        int fd;
        int flags;
        int connect_result;

        fd = socket(cursor->ai_family, cursor->ai_socktype, cursor->ai_protocol);
        if (fd < 0) {
            continue;
        }

        flags = fcntl(fd, F_GETFL, 0);
        if (flags >= 0) {
            (void) fcntl(fd, F_SETFL, flags | O_NONBLOCK);
        }

        connect_result = connect(fd, cursor->ai_addr, cursor->ai_addrlen);
        if (connect_result == 0) {
            close(fd);
            freeaddrinfo(results);
            return 1;
        }

        if (errno == EINPROGRESS || errno == EWOULDBLOCK) {
            struct pollfd pfd;

            memset(&pfd, 0, sizeof(pfd));
            pfd.fd = fd;
            pfd.events = POLLOUT;

            if (poll(&pfd, 1, 100) == 1 && (pfd.revents & POLLOUT) != 0) {
                int socket_error = 0;
                socklen_t socket_error_size = sizeof(socket_error);

                if (getsockopt(fd, SOL_SOCKET, SO_ERROR, &socket_error, &socket_error_size) == 0
                    && socket_error == 0) {
                    close(fd);
                    freeaddrinfo(results);
                    return 1;
                }
            }
        }

        close(fd);
    }

    freeaddrinfo(results);
    return 0;
}

PHP_FUNCTION(king_cdn_cache_object)
{
    zend_string *object_id;
    zval *options = NULL;
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;
    king_cdn_cache_entry *cache_entry;
    king_cdn_cache_entry *existing = NULL;
    zval stored_entry;
    zend_long ttl_sec;
    time_t cached_at;
    zend_bool size_resolved = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    if (!king_cdn_cache_registry_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "CDN cache registry is unavailable."
        );
        RETURN_THROWS();
    }

    /*
     * Determine TTL: prefer runtime CDN config when the object store was
     * initialised, then fall back to the INI-level cdn config.
     */
    if (king_object_store_runtime.initialized &&
        king_object_store_runtime.config.cdn_config.default_ttl_seconds > 0) {
        ttl_sec = (zend_long) king_object_store_runtime.config.cdn_config.default_ttl_seconds;
    } else {
        ttl_sec = (zend_long) king_native_cdn_config.cache_default_ttl_sec;
    }

    if (options != NULL) {
        zval *ttl_zv = zend_hash_str_find(Z_ARRVAL_P(options), "ttl_sec", sizeof("ttl_sec") - 1);
        if (ttl_zv != NULL) {
            if (Z_TYPE_P(ttl_zv) != IS_LONG) {
                zend_throw_exception_ex(
                    king_ce_validation_exception, 0,
                    "CDN cache option 'ttl_sec' must be of type int."
                );
                RETURN_THROWS();
            }
            if (Z_LVAL_P(ttl_zv) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception, 0,
                    "CDN cache option 'ttl_sec' cannot be negative."
                );
                RETURN_THROWS();
            }
            ttl_sec = Z_LVAL_P(ttl_zv);
        }
    }

    /*
     * The CDN entry only tracks payload size and TTL metadata. Avoid a full
     * payload read when the active backend can prove object existence and
     * content length through local/distributed sidecars or cloud HEAD metadata.
     */
    if (king_object_store_runtime.initialized) {
        king_storage_backend_t primary_backend = king_object_store_runtime.config.primary_backend;

        memset(&metadata, 0, sizeof(metadata));

        if ((primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
                || primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE)
            && (king_object_store_runtime_metadata_cache_read(ZSTR_VAL(object_id), &metadata) == SUCCESS
                || king_object_store_meta_read(ZSTR_VAL(object_id), &metadata) == SUCCESS)
            && metadata.content_length <= (uint64_t) SIZE_MAX) {
            data_size = (size_t) metadata.content_length;
            size_resolved = 1;
        } else if ((primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED
                || primary_backend == KING_STORAGE_BACKEND_CLOUD_S3
                || primary_backend == KING_STORAGE_BACKEND_CLOUD_GCS
                || primary_backend == KING_STORAGE_BACKEND_CLOUD_AZURE)
            && king_object_store_backend_read_metadata(ZSTR_VAL(object_id), &metadata) == SUCCESS
            && metadata.content_length <= (uint64_t) SIZE_MAX) {
            data_size = (size_t) metadata.content_length;
            size_resolved = 1;
        }
    }

    if (!size_resolved) {
        if (king_object_store_read_object(ZSTR_VAL(object_id), &data, &data_size, NULL) != SUCCESS) {
            RETURN_FALSE;
        }
        if (data) {
            if (data_size > 0) {
                memset(data, 0, data_size);
            }
            pefree(data, 1);
        }
    }

    /*
     * If a non-expired entry is already cached, update it in place to avoid
     * double-counting. If expired, we just overwrite via zend_hash_update.
     */
    cached_at = time(NULL);

    existing = king_cdn_cache_registry_get_entry(object_id);
    if (existing != NULL) {
        /* Update existing entry in place */
        existing->payload_size = (zend_long) data_size;
        existing->cached_at    = cached_at;
        existing->expires_at   = ttl_sec > 0 ? cached_at + ttl_sec : 0;
        king_cdn_cache_entry_note_touched(existing);
        king_cdn_enforce_memory_limit();
        RETURN_BOOL(king_cdn_cache_registry_get_entry_allow_expired(object_id) != NULL);
    }

    cache_entry = pecalloc(1, sizeof(*cache_entry), 1);
    cache_entry->object_id    = zend_string_dup(object_id, 1);
    cache_entry->payload_size = (zend_long) data_size;
    cache_entry->cached_at    = cached_at;
    cache_entry->expires_at   = ttl_sec > 0 ? cached_at + ttl_sec : 0;
    king_cdn_cache_entry_note_touched(cache_entry);

    ZVAL_PTR(&stored_entry, cache_entry);
    if (zend_hash_update(&king_cdn_cache_registry, cache_entry->object_id, &stored_entry) == NULL) {
        zend_throw_exception_ex(
            king_ce_system_exception, 0,
            "Failed to cache object '%s'.",
            ZSTR_VAL(cache_entry->object_id)
        );
        king_cdn_cache_entry_free(cache_entry);
        RETURN_THROWS();
    }

    king_cdn_enforce_memory_limit();
    if (king_cdn_cache_registry_get_entry_allow_expired(object_id) != NULL
        && king_cdn_distribute_object(ZSTR_VAL(cache_entry->object_id), NULL, 1) != SUCCESS) {
         /* Ignore distribution errors in the runtime (not fatal for the local cache) */
    }
    RETURN_BOOL(king_cdn_cache_registry_get_entry_allow_expired(object_id) != NULL);
}

PHP_FUNCTION(king_cdn_invalidate_cache)
{
    zend_string *object_id = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR_OR_NULL(object_id)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_cdn_cache_registry_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception, 0,
            "CDN cache registry is unavailable."
        );
        RETURN_THROWS();
    }

    if (object_id == NULL) {
        /* Flush all (including expired) */
        zend_long removed_count = (zend_long) zend_hash_num_elements(&king_cdn_cache_registry);
        zend_hash_clean(&king_cdn_cache_registry);
        RETURN_LONG(removed_count);
    }

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    /* Single-object invalidation */
    if (king_cdn_cache_registry_get_entry_allow_expired(object_id) == NULL) {
        RETURN_LONG(0);
    }

    RETURN_LONG(zend_hash_del(&king_cdn_cache_registry, object_id) == SUCCESS ? 1 : 0);
}

/*
 * Expose the explicit edge-node inventory known to the CDN runtime. A plain
 * `edge_node_count` does not synthesize nodes; returned entries must come from
 * configured descriptors and report live TCP reachability at call time.
 */
PHP_FUNCTION(king_cdn_get_edge_nodes)
{
    uint32_t index;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    if (!king_object_store_runtime.initialized ||
        !king_object_store_runtime.config.cdn_config.enabled ||
        king_object_store_runtime.config.cdn_config.edge_nodes == NULL ||
        king_object_store_runtime.config.cdn_config.edge_node_count == 0) {
        return; /* CDN disabled or store not yet initialised */
    }

    for (index = 0; index < king_object_store_runtime.config.cdn_config.edge_node_count; index++) {
        zval node;
        const king_storage_node_t *configured_node =
            &king_object_store_runtime.config.cdn_config.edge_nodes[index];

        array_init(&node);
        add_assoc_string(&node, "node_id", configured_node->node_id);
        add_assoc_string(&node, "hostname", configured_node->hostname);
        add_assoc_long  (&node, "port", (zend_long) configured_node->port);
        add_assoc_string(
            &node,
            "backend",
            (char *) king_storage_backend_to_string(configured_node->backend_type)
        );
        add_assoc_bool  (&node, "is_cdn_edge", configured_node->is_cdn_edge ? 1 : 0);
        add_assoc_bool  (&node, "is_healthy", king_cdn_edge_node_probe_health(configured_node));
        add_assoc_long  (&node, "cache_size_mb",  (zend_long) king_object_store_runtime.config.cdn_config.cache_size_mb);
        add_assoc_long  (&node, "default_ttl_sec",(zend_long) king_object_store_runtime.config.cdn_config.default_ttl_seconds);
        add_next_index_zval(return_value, &node);
    }
}
