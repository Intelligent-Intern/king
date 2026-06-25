/*
 * Module-owned class/object-handler registration implementations. The
 * registration call order lives in class_registration.inc; this file only
 * makes those registration functions available to the core bootstrap.
 */

#include "../awaitable/registration.inc"
#include "../config/internal/registration.inc"
#include "../client/registration.inc"
#include "../mcp/php_binding/registration.inc"
#include "../pipeline_orchestrator/registration.inc"
#include "../object_store/registration.inc"
#include "../autoscaling/registration.inc"
#include "../media/registration.inc"
#include "../xslt/registration.inc"
#include "../inference/registration.inc"
#include "../server/registration.inc"
