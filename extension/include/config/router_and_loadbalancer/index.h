/*
 * =========================================================================
 * FILENAME:   include/config/router_and_loadbalancer/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the Router & Load Balancer
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_ROUTER_LOADBALANCER_INDEX_H
#define KING_CONFIG_ROUTER_LOADBALANCER_INDEX_H

/**
 * @brief Initializes the Router & Load Balancer configuration module.
 */
void kg_config_router_and_loadbalancer_init(void);

/**
 * @brief Shuts down the Router & Load Balancer configuration module.
 */
void kg_config_router_and_loadbalancer_shutdown(void);

#endif /* KING_CONFIG_ROUTER_LOADBALANCER_INDEX_H */
