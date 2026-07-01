/*
 * include/object_store/lifecycle.h - Object-store module bootstrap hooks
 */

#ifndef KING_OBJECT_STORE_LIFECYCLE_H
#define KING_OBJECT_STORE_LIFECYCLE_H

int king_cdn_cache_registry_minit(void);
void king_cdn_cache_registry_mshutdown(void);
void king_object_store_request_shutdown(void);

#endif /* KING_OBJECT_STORE_LIFECYCLE_H */
