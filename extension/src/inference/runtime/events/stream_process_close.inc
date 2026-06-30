static bool king_inference_stream_wait_child(pid_t pid, bool retry)
{
    int status;
    int attempts = retry ? 20 : 1;

    for (int attempt = 0; attempt < attempts; attempt++) {
        pid_t result = waitpid(pid, &status, WNOHANG);

        if (result == pid || (result < 0 && errno == ECHILD)) {
            return true;
        }
        if (result < 0 && errno != EINTR) {
            return false;
        }
        if (!retry) {
            return false;
        }
        usleep(10000);
    }

    return false;
}

static void king_inference_stream_close_process(king_inference_stream_object *stream, bool terminate)
{
    if (stream->stdout_fd >= 0) {
        close(stream->stdout_fd);
        stream->stdout_fd = -1;
    }
    if (stream->stderr_fd >= 0) {
        close(stream->stderr_fd);
        stream->stderr_fd = -1;
    }
    if (stream->child_pid > 0) {
        pid_t pid = (pid_t) stream->child_pid;
        bool should_terminate = terminate && !stream->done;
        bool reaped;

        if (should_terminate) {
            (void) kill(pid, SIGTERM);
        }
        reaped = king_inference_stream_wait_child(pid, should_terminate);
        if (!reaped && should_terminate) {
            (void) kill(pid, SIGKILL);
            (void) king_inference_stream_wait_child(pid, true);
        }
        stream->child_pid = -1;
    }
}
