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

        if (king_semantic_dns_runtime.config.semantic_mode_enable) {
            if (king_semantic_dns_refresh_distributed_state() != SUCCESS) {
                continue;
            }
        } else {
            (void) king_semantic_dns_listener_load_snapshot();
        }
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
#if defined(__APPLE__) || defined(__FreeBSD__) || defined(__OpenBSD__) || defined(__NetBSD__) || defined(__DragonFly__)
    (void) setsockopt(socket_fd, SOL_SOCKET, SO_REUSEPORT, &reuse_addr, sizeof(reuse_addr));
#endif

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
