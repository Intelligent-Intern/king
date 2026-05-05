static zend_result king_http3_runtime_open_udp_socket(
    king_http3_request_runtime_t *runtime,
    const king_http3_request_target_t *target,
    const char *function_name)
{
    struct addrinfo hints;
    struct addrinfo *result = NULL;
    struct addrinfo *candidate;
    char port_buffer[16];

    memset(runtime, 0, sizeof(*runtime));
    runtime->socket_fd = -1;

    snprintf(port_buffer, sizeof(port_buffer), ZEND_LONG_FMT, (zend_long) target->port);

    memset(&hints, 0, sizeof(hints));
    hints.ai_socktype = SOCK_DGRAM;
    hints.ai_family = AF_UNSPEC;
    hints.ai_flags = AI_ADDRCONFIG;

    if (getaddrinfo(ZSTR_VAL(target->host), port_buffer, &hints, &result) != 0) {
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to resolve the active HTTP/3 endpoint '%s'.",
            function_name,
            ZSTR_VAL(target->host)
        );
        return FAILURE;
    }

    for (candidate = result; candidate != NULL; candidate = candidate->ai_next) {
        runtime->socket_fd = socket(candidate->ai_family, candidate->ai_socktype, candidate->ai_protocol);
        if (runtime->socket_fd >= 0) {
            memcpy(&runtime->peer_addr, candidate->ai_addr, candidate->ai_addrlen);
            runtime->peer_addr_len = (socklen_t) candidate->ai_addrlen;
            break;
        }
    }

    if (runtime->socket_fd < 0) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to open a UDP socket for the active HTTP/3 runtime.",
            function_name
        );
        return FAILURE;
    }

    if (king_http3_make_socket_nonblocking(runtime->socket_fd) != SUCCESS) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to switch the active HTTP/3 socket into non-blocking mode.",
            function_name
        );
        return FAILURE;
    }

    if (candidate->ai_family == AF_INET) {
        struct sockaddr_in local4;

        memset(&local4, 0, sizeof(local4));
        local4.sin_family = AF_INET;
        local4.sin_addr.s_addr = htonl(INADDR_ANY);
        local4.sin_port = htons(0);

        if (bind(runtime->socket_fd, (struct sockaddr *) &local4, sizeof(local4)) != 0) {
            freeaddrinfo(result);
            king_http3_throw(
                king_ce_network_exception,
                "%s() failed to bind the active HTTP/3 UDP socket.",
                function_name
            );
            return FAILURE;
        }
    } else if (candidate->ai_family == AF_INET6) {
        struct sockaddr_in6 local6;

        memset(&local6, 0, sizeof(local6));
        local6.sin6_family = AF_INET6;
        local6.sin6_addr = in6addr_any;
        local6.sin6_port = htons(0);

        if (bind(runtime->socket_fd, (struct sockaddr *) &local6, sizeof(local6)) != 0) {
            freeaddrinfo(result);
            king_http3_throw(
                king_ce_network_exception,
                "%s() failed to bind the active HTTP/3 UDP socket.",
                function_name
            );
            return FAILURE;
        }
    } else {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() resolved the active HTTP/3 endpoint to an unsupported socket family.",
            function_name
        );
        return FAILURE;
    }

    runtime->local_addr_len = sizeof(runtime->local_addr);
    if (getsockname(
            runtime->socket_fd,
            (struct sockaddr *) &runtime->local_addr,
            &runtime->local_addr_len
        ) != 0) {
        freeaddrinfo(result);
        king_http3_throw(
            king_ce_network_exception,
            "%s() failed to inspect the local HTTP/3 UDP socket binding.",
            function_name
        );
        return FAILURE;
    }

    freeaddrinfo(result);
    return SUCCESS;
}
