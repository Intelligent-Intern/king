#include "include/telemetry/telemetry.h"

int king_request_init(int type, int module_number)
{
    (void) type;
    (void) module_number;
    return SUCCESS;
}

int king_request_shutdown(int type, int module_number)
{
    king_secure_zero(king_last_error, sizeof(king_last_error));
    king_telemetry_metrics_shutdown();
    (void) type;
    (void) module_number;
    return SUCCESS;
}
