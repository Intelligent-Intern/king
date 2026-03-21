/*
 * =========================================================================
 * FILENAME:   include/config/cluster_and_process/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the `cluster_and_process`
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher (`king_init.c`) during the
 * `MINIT` and `MSHUTDOWN` phases. These functions are responsible for
 * orchestrating the loading of all cluster and process-related settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_CLUSTER_INDEX_H
#define KING_CONFIG_CLUSTER_INDEX_H

/**
 * @brief Initializes the Cluster & Process configuration module.
 * @details This function orchestrates the loading sequence for all cluster
 * and process management settings. It loads defaults, then registers
 * the INI handlers which allow the Zend Engine to override them with
 * values from `php.ini`.
 *
 * This function is called from `king_init_modules()` at startup.
 */
void kg_config_cluster_and_process_init(void);

/**
 * @brief Shuts down the Cluster & Process configuration module.
 * @details This function unregisters the module's `php.ini` settings
 * to ensure a clean shutdown. It is called from
 * `king_shutdown_modules()` during the MSHUTDOWN phase.
 */
void kg_config_cluster_and_process_shutdown(void);

#endif /* KING_CONFIG_CLUSTER_INDEX_H */
