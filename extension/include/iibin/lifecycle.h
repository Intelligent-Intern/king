/*
 * include/iibin/lifecycle.h - IIBIN/proto module bootstrap hooks
 */

#ifndef KING_IIBIN_LIFECYCLE_H
#define KING_IIBIN_LIFECYCLE_H

int king_proto_registry_minit(void);
void king_proto_registry_mshutdown(void);
int king_iibin_minit(void);

#endif /* KING_IIBIN_LIFECYCLE_H */
