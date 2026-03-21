static zend_long king_client_transport_now_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_REALTIME, &ts);

    return (zend_long) ts.tv_sec * 1000L + (zend_long) (ts.tv_nsec / 1000000L);
}

static void king_client_session_set_string_bytes(
    zend_string **slot,
    const char *value,
    size_t value_len)
{
    if (*slot != NULL) {
        zend_string_release(*slot);
    }

    *slot = zend_string_init(
        value != NULL ? value : "",
        value != NULL ? value_len : 0,
        0
    );
}

static void king_client_session_set_string(
    zend_string **slot,
    const char *value)
{
    king_client_session_set_string_bytes(
        slot,
        value,
        value != NULL ? strlen(value) : 0
    );
}

static void king_client_session_throw_transport_exception(
    zend_class_entry *ce,
    const char *format,
    ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
    zend_throw_exception_ex(
        ce != NULL ? ce : king_ce_exception,
        0,
        "%s",
        message
    );
}

static const char *king_client_session_family_label(int family)
{
    switch (family) {
        case AF_INET:
            return "ipv4";
        case AF_INET6:
            return "ipv6";
        default:
            return "unknown";
    }
}

void king_client_session_close_socket(king_client_session_t *session)
{
    if (session->transport_socket_fd >= 0) {
        close(session->transport_socket_fd);
        session->transport_socket_fd = -1;
    }

    session->transport_has_socket = false;
}
