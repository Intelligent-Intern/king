static zend_result king_http1_send_all(
    int fd,
    const char *buffer,
    size_t buffer_len,
    const king_http1_request_options_t *options,
    const char *function_name)
{
    size_t offset = 0;

    while (offset < buffer_len) {
        ssize_t sent = send(fd, buffer + offset, buffer_len - offset, MSG_NOSIGNAL);

        if (sent > 0) {
            offset += (size_t) sent;
            continue;
        }

        if (sent < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) {
            if (king_http1_wait_fd(
                    fd,
                    POLLOUT,
                    options->timeout_ms,
                    options->cancel_token,
                    options->cancel_function_name,
                    options->cancel_exception_ce,
                    function_name,
                    "send"
                ) != SUCCESS) {
                return FAILURE;
            }
            continue;
        }

        king_http1_throw(
            king_ce_network_exception,
            "%s() failed while sending the HTTP/1 request body (errno %d).",
            function_name,
            errno
        );
        return FAILURE;
    }

    return SUCCESS;
}
