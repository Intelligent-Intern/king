/*
 * =========================================================================
 * FILENAME:   include/config/semantic_geometry/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the Semantic Geometry
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_SEMANTIC_GEOMETRY_INDEX_H
#define KING_CONFIG_SEMANTIC_GEOMETRY_INDEX_H

/**
 * @brief Initializes the Semantic Geometry configuration module.
 */
void kg_config_semantic_geometry_init(void);

/**
 * @brief Shuts down the Semantic Geometry configuration module.
 */
void kg_config_semantic_geometry_shutdown(void);

#endif /* KING_CONFIG_SEMANTIC_GEOMETRY_INDEX_H */
