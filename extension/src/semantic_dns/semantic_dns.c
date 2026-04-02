/*
 * Core Semantic-DNS runtime slice. Owns the config-backed init/start
 * lifecycle, the process-local server/runtime state, the bounded UDP DNS
 * listener, and the internal helper surface that the routing, mother-node,
 * and durable-state modules build on.
 */

#include "php_king.h"
#include "include/config/smart_dns/base_layer.h"
#include "include/semantic_dns/semantic_dns.h"

#include <arpa/inet.h>
#include <errno.h>
#include <poll.h>
#include <signal.h>
#include <stdio.h>
#include <strings.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#include "semantic_dns/semantic_dns_internal.h"

#define KING_SEMANTIC_DNS_WIRE_HEADER_BYTES 12
#define KING_SEMANTIC_DNS_WIRE_MAX_PACKET_BYTES 512
#define KING_SEMANTIC_DNS_WIRE_MAX_NAME_BYTES 255
#define KING_SEMANTIC_DNS_WIRE_MAX_ANSWERS 64

typedef struct _king_semantic_dns_wire_query {
    uint16_t id;
    uint16_t flags;
    uint16_t qtype;
    uint16_t qclass;
    size_t question_length;
    char qname[KING_SEMANTIC_DNS_WIRE_MAX_NAME_BYTES + 1];
} king_semantic_dns_wire_query;

typedef struct _king_semantic_dns_wire_candidate {
    struct in_addr address;
    int rank;
    zend_long current_load_percent;
    zend_long active_connections;
    zend_long total_requests;
    zend_long registered_at;
} king_semantic_dns_wire_candidate;

king_semantic_dns_runtime_state king_semantic_dns_runtime;

static void king_semantic_dns_config_clear(king_semantic_dns_config_t *config)
{
    if (config == NULL) {
        return;
    }

    if (Z_TYPE(config->routing_policies) != IS_UNDEF) {
        zval_ptr_dtor(&config->routing_policies);
        ZVAL_UNDEF(&config->routing_policies);
    }

    if (config->mother_nodes != NULL) {
        pefree(config->mother_nodes, 1);
        config->mother_nodes = NULL;
        config->mother_node_count = 0;
    }
}

static void king_semantic_dns_config_assign_defaults(king_semantic_dns_config_t *config)
{
    memset(config, 0, sizeof(*config));
    ZVAL_UNDEF(&config->routing_policies);

    config->enabled = king_smart_dns_config.server_enable ? 1 : 0;
    config->dns_port = (king_smart_dns_config.server_port > 0
        && king_smart_dns_config.server_port <= 65535)
        ? (uint16_t) king_smart_dns_config.server_port
        : 5353;
    config->server_enable_tcp = 0;
    config->health_check_interval_ms = 30000;
    config->service_ttl_seconds = king_smart_dns_config.default_record_ttl_sec > 0
        ? (uint32_t) king_smart_dns_config.default_record_ttl_sec
        : 300;
    config->max_services_per_type = king_smart_dns_config.service_discovery_max_ips_per_response > 0
        ? (uint32_t) king_smart_dns_config.service_discovery_max_ips_per_response
        : 8;
    config->semantic_mode_enable = king_smart_dns_config.semantic_mode_enable ? 1 : 0;
    config->mothernode_sync_interval_sec = 0;
    config->service_discovery_max_ips_per_response = config->max_services_per_type;

    if (king_smart_dns_config.server_bind_host != NULL
        && king_smart_dns_config.server_bind_host[0] != '\0') {
        strncpy(
            config->bind_address,
            king_smart_dns_config.server_bind_host,
            sizeof(config->bind_address) - 1
        );
        config->bind_address[sizeof(config->bind_address) - 1] = '\0';
    } else {
        strncpy(config->bind_address, "0.0.0.0", sizeof(config->bind_address) - 1);
    }

    if (king_smart_dns_config.mothernode_uri != NULL
        && king_smart_dns_config.mothernode_uri[0] != '\0') {
        strncpy(
            config->mothernode_uri,
            king_smart_dns_config.mothernode_uri,
            sizeof(config->mothernode_uri) - 1
        );
        config->mothernode_uri[sizeof(config->mothernode_uri) - 1] = '\0';
    }
}

static void king_semantic_dns_config_copy(
    king_semantic_dns_config_t *target,
    const king_semantic_dns_config_t *source
)
{
    memset(target, 0, sizeof(*target));
    ZVAL_UNDEF(&target->routing_policies);

    target->enabled = source->enabled;
    target->dns_port = source->dns_port;
    target->server_enable_tcp = source->server_enable_tcp;
    target->health_check_interval_ms = source->health_check_interval_ms;
    target->service_ttl_seconds = source->service_ttl_seconds;
    target->max_services_per_type = source->max_services_per_type;
    target->semantic_mode_enable = source->semantic_mode_enable;
    target->mothernode_sync_interval_sec = source->mothernode_sync_interval_sec;
    target->service_discovery_max_ips_per_response = source->service_discovery_max_ips_per_response;
    target->mother_nodes = NULL;
    target->mother_node_count = 0;

    strncpy(target->bind_address, source->bind_address, sizeof(target->bind_address) - 1);
    target->bind_address[sizeof(target->bind_address) - 1] = '\0';
    strncpy(target->mothernode_uri, source->mothernode_uri, sizeof(target->mothernode_uri) - 1);
    target->mothernode_uri[sizeof(target->mothernode_uri) - 1] = '\0';

}

static void king_semantic_dns_runtime_reset(void)
{
    king_semantic_dns_config_clear(&king_semantic_dns_runtime.config);
    memset(&king_semantic_dns_runtime, 0, sizeof(king_semantic_dns_runtime));
    ZVAL_UNDEF(&king_semantic_dns_runtime.config.routing_policies);
}

static bool king_semantic_dns_listener_snapshot_path_init(void)
{
    if (king_semantic_dns_runtime.listener_state_path[0] != '\0') {
        return true;
    }

    if (snprintf(
            king_semantic_dns_runtime.listener_state_path,
            sizeof(king_semantic_dns_runtime.listener_state_path),
            "/tmp/king_semantic_dns_state/listener_state.%ld.bin",
            (long) getpid()
        ) >= (int) sizeof(king_semantic_dns_runtime.listener_state_path)) {
        king_semantic_dns_runtime.listener_state_path[0] = '\0';
        return false;
    }

    return true;
}

static int king_semantic_dns_listener_sync_snapshot(void)
{
    zval payload;
    int result;

    if (!king_semantic_dns_runtime.initialized || !king_semantic_dns_listener_snapshot_path_init()) {
        return FAILURE;
    }

    ZVAL_UNDEF(&payload);
    if (king_semantic_dns_export_state_payload(&payload) != SUCCESS) {
        return FAILURE;
    }

    result = king_semantic_dns_state_write_snapshot_file(
        king_semantic_dns_runtime.listener_state_path,
        &payload
    );
    zval_ptr_dtor(&payload);

    return result;
}

int king_semantic_dns_listener_write_runtime_snapshot(void)
{
    if (!king_semantic_dns_runtime.initialized || king_semantic_dns_runtime.listener_pid <= 0) {
        return SUCCESS;
    }

    return king_semantic_dns_listener_sync_snapshot();
}

static int king_semantic_dns_listener_load_snapshot(void)
{
    zval payload;
    int result;

    if (!king_semantic_dns_runtime.initialized || king_semantic_dns_runtime.listener_state_path[0] == '\0') {
        return FAILURE;
    }

    ZVAL_UNDEF(&payload);
    if (king_semantic_dns_state_read_snapshot_file(
            king_semantic_dns_runtime.listener_state_path,
            &payload
        ) != SUCCESS) {
        return FAILURE;
    }

    result = king_semantic_dns_import_state_payload(&payload);
    zval_ptr_dtor(&payload);

    return result;
}

static void king_semantic_dns_listener_unlink_snapshot(void)
{
    if (king_semantic_dns_runtime.listener_state_path[0] == '\0') {
        return;
    }

    (void) king_semantic_dns_state_remove_snapshot_file(king_semantic_dns_runtime.listener_state_path);
    king_semantic_dns_runtime.listener_state_path[0] = '\0';
}

static void king_semantic_dns_listener_stop(void)
{
    pid_t pid = king_semantic_dns_runtime.listener_pid;

    if (pid > 0) {
        (void) kill(pid, SIGTERM);
        (void) waitpid(pid, NULL, 0);
        king_semantic_dns_runtime.listener_pid = 0;
    }

    king_semantic_dns_listener_unlink_snapshot();
}

static bool king_semantic_dns_wire_write_u16(
    unsigned char *buffer,
    size_t buffer_size,
    size_t *offset,
    uint16_t value
)
{
    if (buffer == NULL || offset == NULL || *offset + 2 > buffer_size) {
        return false;
    }

    buffer[*offset] = (unsigned char) ((value >> 8) & 0xff);
    buffer[*offset + 1] = (unsigned char) (value & 0xff);
    *offset += 2;
    return true;
}

static bool king_semantic_dns_wire_write_u32(
    unsigned char *buffer,
    size_t buffer_size,
    size_t *offset,
    uint32_t value
)
{
    if (buffer == NULL || offset == NULL || *offset + 4 > buffer_size) {
        return false;
    }

    buffer[*offset] = (unsigned char) ((value >> 24) & 0xff);
    buffer[*offset + 1] = (unsigned char) ((value >> 16) & 0xff);
    buffer[*offset + 2] = (unsigned char) ((value >> 8) & 0xff);
    buffer[*offset + 3] = (unsigned char) (value & 0xff);
    *offset += 4;
    return true;
}

static bool king_semantic_dns_wire_parse_query(
    const unsigned char *packet,
    size_t packet_len,
    king_semantic_dns_wire_query *query
)
{
    size_t offset = KING_SEMANTIC_DNS_WIRE_HEADER_BYTES;
    size_t name_length = 0;
    uint16_t qdcount;
    unsigned char label_length;

    if (packet == NULL
        || query == NULL
        || packet_len < KING_SEMANTIC_DNS_WIRE_HEADER_BYTES + 5) {
        return false;
    }

    qdcount = (uint16_t) ((packet[4] << 8) | packet[5]);
    if ((packet[2] & 0x80) != 0 || (packet[2] & 0x78) != 0 || qdcount != 1) {
        return false;
    }

    memset(query, 0, sizeof(*query));
    query->id = (uint16_t) ((packet[0] << 8) | packet[1]);
    query->flags = (uint16_t) ((packet[2] << 8) | packet[3]);

    while (offset < packet_len) {
        label_length = packet[offset++];
        if (label_length == 0) {
            break;
        }

        if ((label_length & 0xc0) != 0 || label_length > 63 || offset + label_length > packet_len) {
            return false;
        }

        if (name_length != 0) {
            if (name_length + 1 >= sizeof(query->qname)) {
                return false;
            }
            query->qname[name_length++] = '.';
        }

        if (name_length + label_length >= sizeof(query->qname)) {
            return false;
        }

        memcpy(query->qname + name_length, packet + offset, label_length);
        name_length += label_length;
        offset += label_length;
    }

    if (offset + 4 > packet_len) {
        return false;
    }

    query->qname[name_length] = '\0';
    query->qtype = (uint16_t) ((packet[offset] << 8) | packet[offset + 1]);
    query->qclass = (uint16_t) ((packet[offset + 2] << 8) | packet[offset + 3]);
    query->question_length = (offset + 4) - KING_SEMANTIC_DNS_WIRE_HEADER_BYTES;

    return true;
}

static zend_long king_semantic_dns_wire_array_long(
    zval *entry,
    const char *key,
    size_t key_len
)
{
    zval *field;

    if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
        return 0;
    }

    field = zend_hash_str_find(Z_ARRVAL_P(entry), key, key_len);
    if (field == NULL || Z_TYPE_P(field) != IS_LONG) {
        return 0;
    }

    return Z_LVAL_P(field);
}

static bool king_semantic_dns_wire_candidate_beats(
    const king_semantic_dns_wire_candidate *candidate,
    const king_semantic_dns_wire_candidate *best
)
{
    if (best == NULL) {
        return true;
    }

    if (candidate->rank != best->rank) {
        return candidate->rank < best->rank;
    }
    if (candidate->current_load_percent != best->current_load_percent) {
        return candidate->current_load_percent < best->current_load_percent;
    }
    if (candidate->active_connections != best->active_connections) {
        return candidate->active_connections < best->active_connections;
    }
    if (candidate->total_requests != best->total_requests) {
        return candidate->total_requests < best->total_requests;
    }

    return candidate->registered_at < best->registered_at;
}

static size_t king_semantic_dns_wire_collect_candidates(
    const char *qname,
    king_semantic_dns_wire_candidate *candidates,
    size_t candidate_capacity
)
{
    zval state_payload;
    zval *services;
    zval *service_entry;
    size_t candidate_count = 0;

    if (qname == NULL || qname[0] == '\0' || candidates == NULL || candidate_capacity == 0) {
        return 0;
    }

    ZVAL_UNDEF(&state_payload);
    if (king_semantic_dns_export_state_payload(&state_payload) != SUCCESS) {
        return 0;
    }

    services = zend_hash_str_find(Z_ARRVAL(state_payload), "services", sizeof("services") - 1);
    if (services == NULL || Z_TYPE_P(services) != IS_ARRAY) {
        zval_ptr_dtor(&state_payload);
        return 0;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(services), service_entry) {
        king_semantic_dns_wire_candidate candidate;
        struct in_addr address;
        int candidate_rank;
        size_t insert_at;
        zval *service_name;
        zval *hostname;
        zval *status;

        if (Z_TYPE_P(service_entry) != IS_ARRAY) {
            continue;
        }

        service_name = zend_hash_str_find(Z_ARRVAL_P(service_entry), "service_name", sizeof("service_name") - 1);
        hostname = zend_hash_str_find(Z_ARRVAL_P(service_entry), "hostname", sizeof("hostname") - 1);
        status = zend_hash_str_find(Z_ARRVAL_P(service_entry), "status", sizeof("status") - 1);
        if (service_name == NULL
            || Z_TYPE_P(service_name) != IS_STRING
            || hostname == NULL
            || Z_TYPE_P(hostname) != IS_STRING
            || status == NULL
            || Z_TYPE_P(status) != IS_STRING
            || strcasecmp(Z_STRVAL_P(service_name), qname) != 0
            || inet_pton(AF_INET, Z_STRVAL_P(hostname), &address) != 1) {
            continue;
        }

        if (zend_string_equals_literal(Z_STR_P(status), "healthy")) {
            candidate_rank = 0;
        } else if (zend_string_equals_literal(Z_STR_P(status), "degraded")) {
            candidate_rank = 1;
        } else {
            continue;
        }

        candidate.address = address;
        candidate.rank = candidate_rank;
        candidate.current_load_percent = king_semantic_dns_wire_array_long(
            service_entry,
            "current_load_percent",
            sizeof("current_load_percent") - 1
        );
        candidate.active_connections = king_semantic_dns_wire_array_long(
            service_entry,
            "active_connections",
            sizeof("active_connections") - 1
        );
        candidate.total_requests = king_semantic_dns_wire_array_long(
            service_entry,
            "total_requests",
            sizeof("total_requests") - 1
        );
        candidate.registered_at = king_semantic_dns_wire_array_long(
            service_entry,
            "registered_at",
            sizeof("registered_at") - 1
        );

        insert_at = candidate_count;
        while (insert_at > 0 && king_semantic_dns_wire_candidate_beats(&candidate, &candidates[insert_at - 1])) {
            if (insert_at < candidate_capacity) {
                candidates[insert_at] = candidates[insert_at - 1];
            }
            insert_at--;
        }

        if (insert_at >= candidate_capacity) {
            continue;
        }

        candidates[insert_at] = candidate;

        if (candidate_count < candidate_capacity) {
            candidate_count++;
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&state_payload);

    return candidate_count;
}

static bool king_semantic_dns_wire_build_response(
    const unsigned char *request_packet,
    size_t request_len,
    unsigned char *response_packet,
    size_t response_capacity,
    size_t *response_length_out
)
{
    king_semantic_dns_wire_query query;
    king_semantic_dns_wire_candidate candidates[KING_SEMANTIC_DNS_WIRE_MAX_ANSWERS];
    size_t response_offset = 0;
    size_t candidate_count = 0;
    size_t answer_count = 0;
    bool truncated = false;
    uint16_t flags;
    size_t limit;
    size_t i;

    if (response_length_out != NULL) {
        *response_length_out = 0;
    }

    if (!king_semantic_dns_wire_parse_query(request_packet, request_len, &query)) {
        return false;
    }

    if (response_packet == NULL || response_capacity < KING_SEMANTIC_DNS_WIRE_MAX_PACKET_BYTES) {
        return false;
    }

    limit = king_semantic_dns_runtime.config.service_discovery_max_ips_per_response;
    if (limit == 0 || limit > KING_SEMANTIC_DNS_WIRE_MAX_ANSWERS) {
        limit = KING_SEMANTIC_DNS_WIRE_MAX_ANSWERS;
    }

    flags = 0x8000 | (uint16_t) (query.flags & 0x0100);

    if (query.qclass != 1) {
        flags |= 0x0004;
    } else if (query.qtype != 1) {
        flags |= 0x0004;
    } else {
        candidate_count = king_semantic_dns_wire_collect_candidates(query.qname, candidates, limit);
        if (candidate_count == 0) {
            flags |= 0x0003;
        }
    }

    memset(response_packet, 0, response_capacity);

    if (!king_semantic_dns_wire_write_u16(response_packet, response_capacity, &response_offset, query.id)
        || !king_semantic_dns_wire_write_u16(response_packet, response_capacity, &response_offset, flags)
        || !king_semantic_dns_wire_write_u16(response_packet, response_capacity, &response_offset, 1)
        || !king_semantic_dns_wire_write_u16(response_packet, response_capacity, &response_offset, 0)
        || !king_semantic_dns_wire_write_u16(response_packet, response_capacity, &response_offset, 0)
        || !king_semantic_dns_wire_write_u16(response_packet, response_capacity, &response_offset, 0)) {
        return false;
    }

    if (KING_SEMANTIC_DNS_WIRE_HEADER_BYTES + query.question_length > request_len
        || response_offset + query.question_length > response_capacity) {
        return false;
    }

    memcpy(
        response_packet + response_offset,
        request_packet + KING_SEMANTIC_DNS_WIRE_HEADER_BYTES,
        query.question_length
    );
    response_offset += query.question_length;

    for (i = 0; i < candidate_count; i++) {
        if (response_offset + 16 > KING_SEMANTIC_DNS_WIRE_MAX_PACKET_BYTES
            || response_offset + 16 > response_capacity) {
            truncated = true;
            break;
        }

        if (!king_semantic_dns_wire_write_u16(response_packet, response_capacity, &response_offset, 0xc00c)
            || !king_semantic_dns_wire_write_u16(response_packet, response_capacity, &response_offset, 1)
            || !king_semantic_dns_wire_write_u16(response_packet, response_capacity, &response_offset, 1)
            || !king_semantic_dns_wire_write_u32(
                response_packet,
                response_capacity,
                &response_offset,
                king_semantic_dns_runtime.config.service_ttl_seconds
            )
            || !king_semantic_dns_wire_write_u16(response_packet, response_capacity, &response_offset, 4)) {
            return false;
        }

        if (response_offset + 4 > response_capacity) {
            return false;
        }

        memcpy(
            response_packet + response_offset,
            &candidates[i].address.s_addr,
            sizeof(candidates[i].address.s_addr)
        );
        response_offset += sizeof(candidates[i].address.s_addr);
        answer_count++;
    }

    if (truncated) {
        flags |= 0x0200;
        response_packet[2] = (unsigned char) ((flags >> 8) & 0xff);
        response_packet[3] = (unsigned char) (flags & 0xff);
    }

    response_packet[6] = (unsigned char) ((answer_count >> 8) & 0xff);
    response_packet[7] = (unsigned char) (answer_count & 0xff);

    if (response_length_out != NULL) {
        *response_length_out = response_offset;
    }

    return true;
}

static void king_semantic_dns_listener_loop(int socket_fd)
{
    struct pollfd listener_fd;
    unsigned char request_packet[2048];
    unsigned char response_packet[KING_SEMANTIC_DNS_WIRE_MAX_PACKET_BYTES];

    listener_fd.fd = socket_fd;
    listener_fd.events = POLLIN;
    listener_fd.revents = 0;

    for (;;) {
        struct sockaddr_storage client_address;
        socklen_t client_address_length = sizeof(client_address);
        ssize_t received_bytes;
        size_t response_length = 0;
        int poll_result;

        if (getppid() == 1) {
            break;
        }

        poll_result = poll(&listener_fd, 1, 250);
        if (poll_result < 0) {
            if (errno == EINTR) {
                continue;
            }
            break;
        }

        if (poll_result == 0) {
            continue;
        }

        if ((listener_fd.revents & (POLLERR | POLLHUP | POLLNVAL)) != 0) {
            break;
        }

        if ((listener_fd.revents & POLLIN) == 0) {
            continue;
        }

        received_bytes = recvfrom(
            socket_fd,
            request_packet,
            sizeof(request_packet),
            0,
            (struct sockaddr *) &client_address,
            &client_address_length
        );
        if (received_bytes <= 0) {
            if (received_bytes < 0 && errno == EINTR) {
                continue;
            }
            continue;
        }

        (void) king_semantic_dns_listener_load_snapshot();
        king_semantic_dns_health_check_services();

        if (!king_semantic_dns_wire_build_response(
                request_packet,
                (size_t) received_bytes,
                response_packet,
                sizeof(response_packet),
                &response_length
            )) {
            continue;
        }

        if (response_length == 0) {
            continue;
        }

        (void) sendto(
            socket_fd,
            response_packet,
            response_length,
            0,
            (const struct sockaddr *) &client_address,
            client_address_length
        );
    }

    close(socket_fd);
}

static int king_semantic_dns_listener_start(void)
{
    struct sockaddr_in bind_address;
    int socket_fd;
    int reuse_addr = 1;
    pid_t pid;

    if (!king_semantic_dns_runtime.initialized || !king_semantic_dns_listener_snapshot_path_init()) {
        return FAILURE;
    }

    if (king_semantic_dns_listener_sync_snapshot() != SUCCESS) {
        return FAILURE;
    }

    socket_fd = socket(AF_INET, SOCK_DGRAM, 0);
    if (socket_fd < 0) {
        return FAILURE;
    }

    (void) setsockopt(socket_fd, SOL_SOCKET, SO_REUSEADDR, &reuse_addr, sizeof(reuse_addr));

    memset(&bind_address, 0, sizeof(bind_address));
    bind_address.sin_family = AF_INET;
    bind_address.sin_port = htons(king_semantic_dns_runtime.config.dns_port);
    if (inet_pton(AF_INET, king_semantic_dns_runtime.config.bind_address, &bind_address.sin_addr) != 1) {
        close(socket_fd);
        return FAILURE;
    }

    if (bind(socket_fd, (const struct sockaddr *) &bind_address, sizeof(bind_address)) != 0) {
        close(socket_fd);
        return FAILURE;
    }

    pid = fork();
    if (pid < 0) {
        close(socket_fd);
        return FAILURE;
    }

    if (pid == 0) {
        king_semantic_dns_runtime.listener_pid = 0;
        king_semantic_dns_listener_loop(socket_fd);
        _exit(0);
    }

    close(socket_fd);
    king_semantic_dns_runtime.listener_pid = pid;
    return SUCCESS;
}

static zval *king_semantic_dns_find_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len
)
{
    zval *value_zv;

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), primary_name, primary_name_len);
    if (value_zv != NULL || alias_name == NULL) {
        return value_zv;
    }

    return zend_hash_str_find(Z_ARRVAL_P(config), alias_name, alias_name_len);
}

static bool king_semantic_dns_require_bool_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    zend_bool *target
)
{
    zval *value_zv = king_semantic_dns_find_option(
        config,
        primary_name,
        primary_name_len,
        alias_name,
        alias_name_len
    );

    if (value_zv == NULL) {
        return true;
    }

    if (Z_TYPE_P(value_zv) != IS_TRUE && Z_TYPE_P(value_zv) != IS_FALSE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' must be of type bool.",
            primary_name
        );
        return false;
    }

    *target = (Z_TYPE_P(value_zv) == IS_TRUE);
    return true;
}

static bool king_semantic_dns_require_positive_long_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    uint32_t *target,
    uint32_t max_value
)
{
    zval *value_zv = king_semantic_dns_find_option(
        config,
        primary_name,
        primary_name_len,
        alias_name,
        alias_name_len
    );

    if (value_zv == NULL) {
        return true;
    }

    if (Z_TYPE_P(value_zv) != IS_LONG) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' must be of type int.",
            primary_name
        );
        return false;
    }

    if (Z_LVAL_P(value_zv) <= 0 || (max_value > 0 && Z_LVAL_P(value_zv) > (zend_long) max_value)) {
        if (max_value > 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Semantic-DNS init option '%s' must be between 1 and %u.",
                primary_name,
                max_value
            );
        } else {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Semantic-DNS init option '%s' must be greater than 0.",
                primary_name
            );
        }
        return false;
    }

    *target = (uint32_t) Z_LVAL_P(value_zv);
    return true;
}

static bool king_semantic_dns_require_port_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    uint16_t *target
)
{
    uint32_t parsed_value = 0;

    if (!king_semantic_dns_require_positive_long_option(
            config,
            primary_name,
            primary_name_len,
            alias_name,
            alias_name_len,
            &parsed_value,
            65535
        )) {
        return false;
    }

    if (parsed_value == 0) {
        return true;
    }

    *target = (uint16_t) parsed_value;
    return true;
}

static bool king_semantic_dns_require_non_empty_string_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    char *target,
    size_t target_size
)
{
    zval *value_zv = king_semantic_dns_find_option(
        config,
        primary_name,
        primary_name_len,
        alias_name,
        alias_name_len
    );

    if (value_zv == NULL) {
        return true;
    }

    if (Z_TYPE_P(value_zv) != IS_STRING) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' must be of type string.",
            primary_name
        );
        return false;
    }

    if (Z_STRLEN_P(value_zv) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' cannot be empty.",
            primary_name
        );
        return false;
    }

    strncpy(target, Z_STRVAL_P(value_zv), target_size - 1);
    target[target_size - 1] = '\0';
    return true;
}

static bool king_semantic_dns_reject_unsupported_option(
    zval *config,
    const char *option_name,
    size_t option_name_len
)
{
    if (zend_hash_str_exists(Z_ARRVAL_P(config), option_name, option_name_len)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS v1 does not support init option '%s'.",
            option_name
        );
        return false;
    }

    return true;
}

static bool king_semantic_dns_parse_init_config(
    zval *config,
    king_semantic_dns_config_t *parsed
)
{
    zval *value_zv;

    king_semantic_dns_config_assign_defaults(parsed);

    if (!king_semantic_dns_reject_unsupported_option(
            config,
            "server_enable_tcp",
            sizeof("server_enable_tcp") - 1
        )
        || !king_semantic_dns_reject_unsupported_option(
            config,
            "health_check_interval_ms",
            sizeof("health_check_interval_ms") - 1
        )
        || !king_semantic_dns_reject_unsupported_option(
            config,
            "mothernode_sync_interval_sec",
            sizeof("mothernode_sync_interval_sec") - 1
        )) {
        king_semantic_dns_config_clear(parsed);
        return false;
    }

    if (!king_semantic_dns_require_bool_option(
            config,
            "enabled",
            sizeof("enabled") - 1,
            "server_enable",
            sizeof("server_enable") - 1,
            &parsed->enabled
        )
        || !king_semantic_dns_require_port_option(
            config,
            "dns_port",
            sizeof("dns_port") - 1,
            "server_port",
            sizeof("server_port") - 1,
            &parsed->dns_port
        )
        || !king_semantic_dns_require_non_empty_string_option(
            config,
            "bind_address",
            sizeof("bind_address") - 1,
            "server_bind_host",
            sizeof("server_bind_host") - 1,
            parsed->bind_address,
            sizeof(parsed->bind_address)
        )
        || !king_semantic_dns_require_positive_long_option(
            config,
            "default_record_ttl_sec",
            sizeof("default_record_ttl_sec") - 1,
            "service_ttl_seconds",
            sizeof("service_ttl_seconds") - 1,
            &parsed->service_ttl_seconds,
            0
        )
        || !king_semantic_dns_require_positive_long_option(
            config,
            "service_discovery_max_ips_per_response",
            sizeof("service_discovery_max_ips_per_response") - 1,
            "max_services_per_type",
            sizeof("max_services_per_type") - 1,
            &parsed->service_discovery_max_ips_per_response,
            0
        )
        || !king_semantic_dns_require_bool_option(
            config,
            "semantic_mode_enable",
            sizeof("semantic_mode_enable") - 1,
            NULL,
            0,
            &parsed->semantic_mode_enable
        )
        || !king_semantic_dns_require_non_empty_string_option(
            config,
            "mothernode_uri",
            sizeof("mothernode_uri") - 1,
            NULL,
            0,
            parsed->mothernode_uri,
            sizeof(parsed->mothernode_uri)
        )) {
        king_semantic_dns_config_clear(parsed);
        return false;
    }

    parsed->max_services_per_type = parsed->service_discovery_max_ips_per_response;

    value_zv = zend_hash_str_find(
        Z_ARRVAL_P(config),
        "routing_policies",
        sizeof("routing_policies") - 1
    );
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_ARRAY) {
            king_semantic_dns_config_clear(parsed);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Semantic-DNS init option 'routing_policies' must be of type array."
            );
            return false;
        }

        /* The current core/server-state slice validates routing hints but does
         * not persist request-owned zvals across module lifetime yet. */
    }

    return true;
}

static bool king_semantic_dns_runtime_require_initialized(const char *function_name)
{
    if (king_semantic_dns_runtime.initialized) {
        return true;
    }

    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "%s() requires prior king_semantic_dns_init().",
        function_name
    );
    return false;
}

#include "include/king_globals.h"

PHP_FUNCTION(king_semantic_dns_init)
{
    zval *config;
    king_semantic_dns_config_t parsed;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_globals.is_userland_override_allowed && zend_hash_num_elements(Z_ARRVAL_P(config)) > 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Configuration override is disabled by system policy."
        );
        RETURN_THROWS();
    }

    if (!king_semantic_dns_parse_init_config(config, &parsed)) {
        RETURN_THROWS();
    }

    if (king_semantic_dns_init_system(&parsed) != SUCCESS) {
        king_semantic_dns_config_clear(&parsed);
        if (EG(exception) == NULL) {
            zend_throw_exception_ex(
                king_ce_system_exception,
                0,
                "Semantic-DNS core initialization failed."
            );
        }
        RETURN_THROWS();
    }

    king_semantic_dns_config_clear(&parsed);
    RETURN_TRUE;
}

PHP_FUNCTION(king_semantic_dns_start_server)
{
    ZEND_PARSE_PARAMETERS_NONE();

    if (!king_semantic_dns_runtime_require_initialized("king_semantic_dns_start_server")) {
        RETURN_THROWS();
    }

    if (!king_semantic_dns_runtime.config.enabled) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_semantic_dns_start_server() semantic DNS is disabled in the active runtime config."
        );
        RETURN_THROWS();
    }

    if (king_semantic_dns_runtime.server_active) {
        RETURN_TRUE;
    }

    king_semantic_dns_runtime.server_active = true;
    king_semantic_dns_runtime.server_started_at = time(NULL);

    if (king_semantic_dns_runtime.config.semantic_mode_enable) {
        (void) king_semantic_dns_state_load();
        (void) king_semantic_dns_discover_mother_nodes();
        (void) king_semantic_dns_sync_with_mother_nodes();
    }

    king_semantic_dns_health_check_services();

    if (king_semantic_dns_listener_start() != SUCCESS) {
        king_semantic_dns_runtime.server_active = false;
        king_semantic_dns_runtime.server_started_at = 0;
        king_semantic_dns_listener_stop();
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_semantic_dns_start_server() could not bind the UDP DNS listener to %s:%u.",
            king_semantic_dns_runtime.config.bind_address,
            king_semantic_dns_runtime.config.dns_port
        );
        RETURN_THROWS();
    }

    king_semantic_dns_runtime.start_count++;
    RETURN_TRUE;
}

PHP_FUNCTION(king_semantic_dns_query)
{
    zend_string *query;
    zend_long max_response_bytes = 256;
    char *response;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(query)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(max_response_bytes)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_semantic_dns_runtime_require_initialized("king_semantic_dns_query")) {
        RETURN_THROWS();
    }

    if (ZSTR_LEN(query) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_query() requires a non-empty query."
        );
        RETURN_THROWS();
    }

    if (max_response_bytes < 1 || max_response_bytes > 4096) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_query() 'max_response_bytes' must be between 1 and 4096."
        );
        RETURN_THROWS();
    }

    response = emalloc((size_t) max_response_bytes);
    if (king_semantic_dns_process_query(ZSTR_VAL(query), response, (size_t) max_response_bytes) != SUCCESS) {
        efree(response);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_semantic_dns_query() could not produce a full response within %ld bytes.",
            max_response_bytes
        );
        RETURN_THROWS();
    }

    RETVAL_STRING(response);
    efree(response);
}

int king_semantic_dns_init_system(king_semantic_dns_config_t *config)
{
    king_semantic_dns_runtime_state new_state;

    if (config == NULL || config->dns_port == 0 || config->bind_address[0] == '\0') {
        return FAILURE;
    }

    memset(&new_state, 0, sizeof(new_state));
    ZVAL_UNDEF(&new_state.config.routing_policies);
    king_semantic_dns_config_copy(&new_state.config, config);
    new_state.initialized = true;
    new_state.server_active = false;
    new_state.initialized_at = time(NULL);

    king_semantic_dns_listener_stop();
    king_semantic_dns_runtime_reset();
    king_semantic_dns_runtime = new_state;

    return SUCCESS;
}

void king_semantic_dns_shutdown_system(void)
{
    king_semantic_dns_listener_stop();
    king_semantic_dns_runtime_reset();
}

void king_semantic_dns_request_shutdown(void)
{
    int lock_fd = -1;

    if (!king_semantic_dns_runtime.initialized) {
        king_semantic_dns_listener_stop();
        return;
    }

    if (!king_semantic_dns_runtime.config.semantic_mode_enable) {
        king_semantic_dns_listener_stop();
        return;
    }

    if (king_semantic_dns_state_has_regular_snapshot()) {
        king_semantic_dns_listener_stop();
        return;
    }

    if (king_semantic_dns_state_transaction_begin(&lock_fd) != SUCCESS) {
        king_semantic_dns_listener_stop();
        return;
    }

    (void) king_semantic_dns_state_persist_locked();
    king_semantic_dns_state_transaction_end(lock_fd);
    king_semantic_dns_listener_stop();
}

int king_semantic_dns_process_query(
    const char *query,
    char *response,
    size_t response_size
)
{
    int written;

    if (!king_semantic_dns_runtime.initialized
        || query == NULL
        || response == NULL
        || response_size == 0) {
        return FAILURE;
    }

    if (strncmp(query, "discover:", sizeof("discover:") - 1) == 0) {
        written = snprintf(
            response,
            response_size,
            "discover:%s:max=%u",
            query + (sizeof("discover:") - 1),
            king_semantic_dns_runtime.config.service_discovery_max_ips_per_response
        );
    } else if (strcmp(query, "status") == 0) {
        written = snprintf(
            response,
            response_size,
            "enabled=%d;active=%d;bind=%s;port=%u",
            king_semantic_dns_runtime.config.enabled ? 1 : 0,
            king_semantic_dns_runtime.server_active ? 1 : 0,
            king_semantic_dns_runtime.config.bind_address,
            king_semantic_dns_runtime.config.dns_port
        );
    } else {
        written = snprintf(
            response,
            response_size,
            "active=%d;semantic=%d;ttl=%u",
            king_semantic_dns_runtime.server_active ? 1 : 0,
            king_semantic_dns_runtime.config.semantic_mode_enable ? 1 : 0,
            king_semantic_dns_runtime.config.service_ttl_seconds
        );
    }

    if (written < 0 || (size_t) written >= response_size) {
        if (response_size > 0) {
            response[response_size - 1] = '\0';
        }
        return FAILURE;
    }

    king_semantic_dns_runtime.processed_query_count++;
    return SUCCESS;
}


void king_semantic_dns_health_check_services(void)
{
    if (!king_semantic_dns_runtime.initialized) {
        return;
    }

    king_semantic_dns_refresh_live_service_signals();
}

const char *king_service_type_to_string(king_service_type_t type)
{
    switch (type) {
        case KING_SERVICE_TYPE_HTTP_SERVER:
            return "http_server";
        case KING_SERVICE_TYPE_MCP_AGENT:
            return "mcp_agent";
        case KING_SERVICE_TYPE_PIPELINE_ORCHESTRATOR:
            return "pipeline_orchestrator";
        case KING_SERVICE_TYPE_CACHE_NODE:
            return "cache_node";
        case KING_SERVICE_TYPE_DATABASE:
            return "database";
        case KING_SERVICE_TYPE_AI_MODEL:
            return "ai_model";
        case KING_SERVICE_TYPE_LOAD_BALANCER:
            return "load_balancer";
        case KING_SERVICE_TYPE_MOTHER_NODE:
            return "mother_node";
        default:
            return "unknown";
    }
}

const char *king_service_status_to_string(king_service_status_t status)
{
    switch (status) {
        case KING_SERVICE_STATUS_HEALTHY:
            return "healthy";
        case KING_SERVICE_STATUS_DEGRADED:
            return "degraded";
        case KING_SERVICE_STATUS_UNHEALTHY:
            return "unhealthy";
        case KING_SERVICE_STATUS_MAINTENANCE:
            return "maintenance";
        case KING_SERVICE_STATUS_UNKNOWN:
        default:
            return "unknown";
    }
}
