/*
 * =========================================================================
 * FILENAME:   src/config/high_perf_compute_and_ai/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the high-perf compute / AI config family. This
 * file wires together default loading and INI registration during module
 * init and unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/high_perf_compute_and_ai/index.h"
#include "include/config/high_perf_compute_and_ai/default.h"
#include "include/config/high_perf_compute_and_ai/ini.h"

void kg_config_high_perf_compute_and_ai_init(void)
{
    kg_config_high_perf_compute_and_ai_defaults_load();
    kg_config_high_perf_compute_and_ai_ini_register();
}

void kg_config_high_perf_compute_and_ai_shutdown(void)
{
    kg_config_high_perf_compute_and_ai_ini_unregister();
}
