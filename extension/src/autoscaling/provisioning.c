/*
 * src/autoscaling/provisioning.c - Autoscaling Provisioning and Termination
 * =========================================================================
 *
 * This module implements the provisioning and termination logic for
 * autoscaling. This interacts with Cloud backends or local resources.
 */
#include "php_king.h"
#include "include/autoscaling/autoscaling.h"

int king_autoscaling_provision_instances(uint32_t count)
{
    /* Simulation: mock instance provisioning */
    king_current_instances += count;
    /* Record scaling event in telemetry if enabled */
    return SUCCESS;
}

int king_autoscaling_terminate_instances(uint32_t count)
{
    /* Simulation: mock instance termination */
    if (king_current_instances > count) {
        king_current_instances -= count;
    } else {
        king_current_instances = 1;
    }
    return SUCCESS;
}

/* --- PHP Entry Points --- */

PHP_FUNCTION(king_autoscaling_scale_up)
{
    zend_long instances = 1;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(instances)
    ZEND_PARSE_PARAMETERS_END();

    if (king_autoscaling_provision_instances((uint32_t)instances) == SUCCESS) {
        RETURN_TRUE;
    }
    
    RETURN_FALSE;
}

PHP_FUNCTION(king_autoscaling_scale_down)
{
    zend_long instances = 1;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(instances)
    ZEND_PARSE_PARAMETERS_END();

    if (king_autoscaling_terminate_instances((uint32_t)instances) == SUCCESS) {
        RETURN_TRUE;
    }
    
    RETURN_FALSE;
}
