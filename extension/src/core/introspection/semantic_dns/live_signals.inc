/*
 * Live-probe helpers for Semantic-DNS services. Normalizes optional probe
 * host/port/path attributes, enforces the allowlist and safe-character gates,
 * performs the bounded wire probe and folds the resulting status/load snapshot
 * back into the in-memory service registry.
 */

#include <errno.h>
#include <ctype.h>
#include <limits.h>
#include <stdio.h>
#include <string.h>
#include <strings.h>

#define KING_SEMANTIC_DNS_HEALTH_LINE_MAX 2048
#define KING_SEMANTIC_DNS_HEALTH_PROBE_TIMEOUT_MS 250L

static zval *king_semantic_dns_find_service_attribute(
    const king_semantic_dns_service *service,
    const char *attribute_name,
    size_t attribute_name_len
)
{
    if (service == NULL) {
        return NULL;
    }

    return zend_hash_str_find(
        (HashTable *) &service->attributes,
        attribute_name,
        attribute_name_len
    );
}

static zend_string *king_semantic_dns_probe_service_host(
    const king_semantic_dns_service *service
)
{
    zval *attribute = king_semantic_dns_find_service_attribute(
        service,
        "health_check_host",
        sizeof("health_check_host") - 1
    );

    if (attribute != NULL
        && Z_TYPE_P(attribute) == IS_STRING
        && Z_STRLEN_P(attribute) > 0) {
        return Z_STR_P(attribute);
    }

    return service != NULL ? service->hostname : NULL;
}

static zend_long king_semantic_dns_probe_service_port(
    const king_semantic_dns_service *service
)
{
    zval *attribute = king_semantic_dns_find_service_attribute(
        service,
        "health_check_port",
        sizeof("health_check_port") - 1
    );

    if (attribute != NULL
        && Z_TYPE_P(attribute) == IS_LONG
        && Z_LVAL_P(attribute) > 0
        && Z_LVAL_P(attribute) <= 65535) {
        return Z_LVAL_P(attribute);
    }

    return service != NULL ? service->port : 0;
}

static zend_string *king_semantic_dns_probe_service_path(
    const king_semantic_dns_service *service
)
{
    zval *attribute = king_semantic_dns_find_service_attribute(
        service,
        "health_check_path",
        sizeof("health_check_path") - 1
    );

    if (attribute == NULL
        || Z_TYPE_P(attribute) != IS_STRING
        || Z_STRLEN_P(attribute) == 0) {
        return NULL;
    }

    return Z_STR_P(attribute);
}

static char *king_semantic_dns_trim_ascii_whitespace(char *value);

static bool king_semantic_dns_probe_host_has_safe_characters(zend_string *host)
{
    size_t index;

    if (host == NULL || ZSTR_LEN(host) == 0) {
        return false;
    }

    for (index = 0; index < ZSTR_LEN(host); index++) {
        unsigned char ch = (unsigned char) ZSTR_VAL(host)[index];

        if (!isalnum(ch) && ch != '.' && ch != '-' && ch != ':') {
            return false;
        }
    }

    return true;
}

static bool king_semantic_dns_probe_path_is_safe(zend_string *path)
{
    size_t index;

    if (path == NULL || ZSTR_LEN(path) == 0 || ZSTR_VAL(path)[0] != '/') {
        return false;
    }

    for (index = 0; index < ZSTR_LEN(path); index++) {
        unsigned char ch = (unsigned char) ZSTR_VAL(path)[index];

        if (ch <= 0x20 || ch == 0x7f) {
            return false;
        }
    }

    return true;
}

static bool king_semantic_dns_probe_host_matches_allowlist_entry(
    zend_string *host,
    const char *entry
)
{
    size_t entry_len;

    if (host == NULL || entry == NULL) {
        return false;
    }

    entry_len = strlen(entry);
    return entry_len == ZSTR_LEN(host)
        && strncasecmp(entry, ZSTR_VAL(host), entry_len) == 0;
}

static bool king_semantic_dns_probe_host_is_allowed(zend_string *host)
{
    char *allowlist_copy;
    char *entry;
    char *saveptr = NULL;
    bool allowed = false;

    if (!king_semantic_dns_probe_host_has_safe_characters(host)
        || king_smart_dns_config.live_probe_allowed_hosts == NULL
        || king_smart_dns_config.live_probe_allowed_hosts[0] == '\0') {
        return false;
    }

    allowlist_copy = estrdup(king_smart_dns_config.live_probe_allowed_hosts);
    if (allowlist_copy == NULL) {
        return false;
    }

    for (entry = strtok_r(allowlist_copy, ",", &saveptr);
         entry != NULL;
         entry = strtok_r(NULL, ",", &saveptr)) {
        char *trimmed = king_semantic_dns_trim_ascii_whitespace(entry);

        if (trimmed != NULL
            && trimmed[0] != '\0'
            && king_semantic_dns_probe_host_matches_allowlist_entry(host, trimmed)) {
            allowed = true;
            break;
        }
    }

    efree(allowlist_copy);
    return allowed;
}

static char *king_semantic_dns_trim_ascii_whitespace(char *value)
{
    char *start = value;
    size_t length;

    if (value == NULL) {
        return NULL;
    }

    while (*start == ' ' || *start == '\t' || *start == '\r' || *start == '\n') {
        start++;
    }

    length = strlen(start);
    while (length > 0
        && (start[length - 1] == ' '
            || start[length - 1] == '\t'
            || start[length - 1] == '\r'
            || start[length - 1] == '\n')) {
        start[length - 1] = '\0';
        length--;
    }

    return start;
}

static bool king_semantic_dns_parse_probe_long(
    const char *value,
    zend_long min_value,
    zend_long max_value,
    bool enforce_max,
    zend_long *target
)
{
    char *end = NULL;
    long parsed;

    if (value == NULL || target == NULL) {
        return false;
    }

    errno = 0;
    parsed = strtol(value, &end, 10);
    if (errno != 0 || end == value) {
        return false;
    }

    end = king_semantic_dns_trim_ascii_whitespace(end);
    if (end == NULL || *end != '\0') {
        return false;
    }

    if (parsed < min_value || (enforce_max && parsed > max_value)) {
        return false;
    }

    *target = (zend_long) parsed;
    return true;
}

static bool king_semantic_dns_probe_status_is_valid_literal(const char *value)
{
    size_t length;

    if (value == NULL) {
        return false;
    }

    length = strlen(value);
    return (length == sizeof("healthy") - 1 && strcasecmp(value, "healthy") == 0)
        || (length == sizeof("degraded") - 1 && strcasecmp(value, "degraded") == 0)
        || (length == sizeof("unhealthy") - 1 && strcasecmp(value, "unhealthy") == 0)
        || (length == sizeof("maintenance") - 1 && strcasecmp(value, "maintenance") == 0)
        || (length == sizeof("unknown") - 1 && strcasecmp(value, "unknown") == 0);
}

static bool king_semantic_dns_service_apply_probe_snapshot(
    king_semantic_dns_service *service,
    const char *status_literal,
    zend_long current_load_percent,
    zend_long active_connections,
    zend_long total_requests,
    bool has_load_percent,
    bool has_active_connections,
    bool has_total_requests
)
{
    bool changed = false;

    if (service == NULL || status_literal == NULL || !king_semantic_dns_probe_status_is_valid_literal(status_literal)) {
        return false;
    }

    if (!zend_string_equals_cstr(service->status, status_literal, strlen(status_literal))) {
        zend_string_release_ex(service->status, 1);
        service->status = zend_string_init(status_literal, strlen(status_literal), 1);
        changed = true;
    }

    if (has_load_percent && service->current_load_percent != current_load_percent) {
        service->current_load_percent = current_load_percent;
        changed = true;
    }

    if (has_active_connections && service->active_connections != active_connections) {
        service->active_connections = active_connections;
        changed = true;
    }

    if (has_total_requests && service->total_requests != total_requests) {
        service->total_requests = total_requests;
        changed = true;
    }

    return changed;
}

static zend_string *king_semantic_dns_build_probe_transport_target(
    zend_string *host,
    zend_long port
)
{
    if (host == NULL || ZSTR_LEN(host) == 0 || port <= 0 || port > 65535) {
        return NULL;
    }

    if (memchr(ZSTR_VAL(host), ':', ZSTR_LEN(host)) != NULL) {
        return strpprintf(0, "tcp://[%s]:%ld", ZSTR_VAL(host), port);
    }

    return strpprintf(0, "tcp://%s:%ld", ZSTR_VAL(host), port);
}

static bool king_semantic_dns_probe_service_live_state(
    king_semantic_dns_service *service
)
{
    zend_string *probe_path = king_semantic_dns_probe_service_path(service);
    zend_string *probe_host;
    zend_string *transport_target = NULL;
    zend_string *transport_error = NULL;
    php_stream *stream = NULL;
    struct timeval timeout;
    char request_buffer[1024];
    char line[KING_SEMANTIC_DNS_HEALTH_LINE_MAX];
    char effective_status[16];
    zend_long load_percent = service->current_load_percent;
    zend_long active_connections = service->active_connections;
    zend_long total_requests = service->total_requests;
    bool has_load_percent = false;
    bool has_active_connections = false;
    bool has_total_requests = false;
    bool probe_failed = false;
    int transport_error_code = 0;
    int status_code = 0;

    if (probe_path == NULL) {
        return false;
    }

    probe_host = king_semantic_dns_probe_service_host(service);
    if (probe_host == NULL
        || !king_semantic_dns_probe_path_is_safe(probe_path)
        || !king_semantic_dns_probe_host_is_allowed(probe_host)) {
        return king_semantic_dns_service_apply_probe_snapshot(
            service,
            "unhealthy",
            service->current_load_percent,
            service->active_connections,
            service->total_requests,
            false,
            false,
            false
        );
    }

    transport_target = king_semantic_dns_build_probe_transport_target(
        probe_host,
        king_semantic_dns_probe_service_port(service)
    );
    if (transport_target == NULL) {
        return king_semantic_dns_service_apply_probe_snapshot(
            service,
            "unhealthy",
            service->current_load_percent,
            service->active_connections,
            service->total_requests,
            false,
            false,
            false
        );
    }

    timeout.tv_sec = KING_SEMANTIC_DNS_HEALTH_PROBE_TIMEOUT_MS / 1000;
    timeout.tv_usec = (KING_SEMANTIC_DNS_HEALTH_PROBE_TIMEOUT_MS % 1000) * 1000;

    stream = php_stream_xport_create(
        ZSTR_VAL(transport_target),
        ZSTR_LEN(transport_target),
        0,
        STREAM_XPORT_CLIENT | STREAM_XPORT_CONNECT,
        NULL,
        &timeout,
        NULL,
        &transport_error,
        &transport_error_code
    );
    zend_string_release(transport_target);
    transport_target = NULL;

    if (stream == NULL) {
        if (transport_error != NULL) {
            zend_string_release(transport_error);
        }
        return king_semantic_dns_service_apply_probe_snapshot(
            service,
            "unhealthy",
            service->current_load_percent,
            service->active_connections,
            service->total_requests,
            false,
            false,
            false
        );
    }

    php_stream_set_option(stream, PHP_STREAM_OPTION_BLOCKING, 1, NULL);
    php_stream_set_option(stream, PHP_STREAM_OPTION_READ_TIMEOUT, 0, &timeout);

    {
        int request_length = snprintf(
        request_buffer,
        sizeof(request_buffer),
        "GET %s HTTP/1.1\r\nHost: %s\r\nConnection: close\r\n\r\n",
        ZSTR_VAL(probe_path),
        ZSTR_VAL(probe_host)
        );
        if (request_length < 0 || (size_t) request_length >= sizeof(request_buffer)) {
            php_stream_close(stream);
            return king_semantic_dns_service_apply_probe_snapshot(
                service,
                "unhealthy",
                service->current_load_percent,
                service->active_connections,
                service->total_requests,
                false,
                false,
                false
            );
        }
    }

    if (php_stream_write(stream, request_buffer, strlen(request_buffer)) != (ssize_t) strlen(request_buffer)) {
        probe_failed = true;
    }

    if (!probe_failed) {
        if (php_stream_get_line(stream, line, sizeof(line), NULL) == NULL) {
            probe_failed = true;
        } else if (sscanf(line, "HTTP/%*d.%*d %d", &status_code) != 1) {
            probe_failed = true;
        }
    }

    if (probe_failed) {
        php_stream_close(stream);
        return king_semantic_dns_service_apply_probe_snapshot(
            service,
            "unhealthy",
            service->current_load_percent,
            service->active_connections,
            service->total_requests,
            false,
            false,
            false
        );
    }

    strncpy(
        effective_status,
        (status_code >= 200 && status_code < 300) ? "healthy" : "unhealthy",
        sizeof(effective_status) - 1
    );
    effective_status[sizeof(effective_status) - 1] = '\0';

    while (php_stream_get_line(stream, line, sizeof(line), NULL) != NULL) {
        char *separator;
        char *name;
        char *value;
        zend_long parsed_value;

        if (strcmp(line, "\n") == 0 || strcmp(line, "\r\n") == 0) {
            break;
        }

        separator = strchr(line, ':');
        if (separator == NULL) {
            continue;
        }

        *separator = '\0';
        name = king_semantic_dns_trim_ascii_whitespace(line);
        value = king_semantic_dns_trim_ascii_whitespace(separator + 1);
        if (name == NULL || value == NULL || *name == '\0') {
            continue;
        }

        if (strcasecmp(name, "X-King-Service-Status") == 0) {
            if (king_semantic_dns_probe_status_is_valid_literal(value)) {
                strncpy(effective_status, value, sizeof(effective_status) - 1);
                effective_status[sizeof(effective_status) - 1] = '\0';
            }
            continue;
        }

        if (strcasecmp(name, "X-King-Load-Percent") == 0) {
            if (king_semantic_dns_parse_probe_long(value, 0, 100, true, &parsed_value)) {
                load_percent = parsed_value;
                has_load_percent = true;
            }
            continue;
        }

        if (strcasecmp(name, "X-King-Active-Connections") == 0) {
            if (king_semantic_dns_parse_probe_long(value, 0, 0, false, &parsed_value)) {
                active_connections = parsed_value;
                has_active_connections = true;
            }
            continue;
        }

        if (strcasecmp(name, "X-King-Total-Requests") == 0) {
            if (king_semantic_dns_parse_probe_long(value, 0, LONG_MAX, true, &parsed_value)) {
                total_requests = parsed_value;
                has_total_requests = true;
            }
        }
    }

    php_stream_close(stream);

    return king_semantic_dns_service_apply_probe_snapshot(
        service,
        effective_status,
        load_percent,
        active_connections,
        total_requests,
        has_load_percent,
        has_active_connections,
        has_total_requests
    );
}

void king_semantic_dns_refresh_live_service_signals(void)
{
    zval *service_entry;
    bool state_changed = false;
    int lock_fd = -1;
    bool use_state_transaction = false;

    if (!king_semantic_dns_runtime.initialized
        || !king_semantic_dns_runtime.server_active
        || !king_semantic_dns_registry_initialized) {
        return;
    }

    ZEND_HASH_FOREACH_VAL(&king_semantic_dns_service_registry, service_entry) {
        king_semantic_dns_service *service;

        if (Z_TYPE_P(service_entry) != IS_PTR) {
            continue;
        }

        service = (king_semantic_dns_service *) Z_PTR_P(service_entry);
        if (service == NULL) {
            continue;
        }

        if (king_semantic_dns_probe_service_path(service) == NULL) {
            continue;
        }

        if (!use_state_transaction && king_semantic_dns_runtime.config.semantic_mode_enable) {
            if (king_semantic_dns_state_transaction_begin(&lock_fd) != SUCCESS) {
                return;
            }
            use_state_transaction = true;
        }

        if (king_semantic_dns_probe_service_live_state(service)) {
            state_changed = true;
        }
    } ZEND_HASH_FOREACH_END();

    if (state_changed && use_state_transaction) {
        (void) king_semantic_dns_state_persist_locked();
    }

    if (use_state_transaction) {
        king_semantic_dns_state_transaction_end(lock_fd);
    }
}
