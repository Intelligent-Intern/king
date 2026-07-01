/*
 * GPU runtime power telemetry helpers.
 */

static bool king_inference_gpu_status_read_power_command(
    const char *command,
    double *power_watts
) {
    FILE *pipe;
    char buffer[64];
    char *endptr;
    double value;
    int status;

    if (!king_inference_gpu_status_non_empty(command)) {
        return false;
    }

    pipe = popen(command, "r");
    if (pipe == NULL) {
        return false;
    }

    if (fgets(buffer, sizeof(buffer), pipe) == NULL) {
        pclose(pipe);
        return false;
    }

    status = pclose(pipe);
    if (status == -1) {
        return false;
    }

    errno = 0;
    value = strtod(buffer, &endptr);
    if (errno != 0 || endptr == buffer || !isfinite(value) || value < 0.0) {
        return false;
    }

    *power_watts = value;
    return true;
}
