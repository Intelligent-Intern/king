/*
 * include/inference/registration/registration.h - Native inference MINIT hooks
 */

#ifndef KING_INFERENCE_REGISTRATION_H
#define KING_INFERENCE_REGISTRATION_H

#include <php.h>
#include "inference/classes/class_methods.h"

void king_inference_register_classes(void);
void king_inference_init_object_handlers(void);

#endif /* KING_INFERENCE_REGISTRATION_H */
