/*
 * Keeps service-registry entry points grouped while splitting shared registry
 * checks, payload assembly and status/metric updates into subfragments.
 */

#include "service_registry/common.inc"
#include "service_registry/register_payload.inc"
#include "service_registry/register.inc"
#include "service_registry/update.inc"
