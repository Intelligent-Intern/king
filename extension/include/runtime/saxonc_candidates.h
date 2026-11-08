#ifndef KING_RUNTIME_SAXONC_CANDIDATES_H
#define KING_RUNTIME_SAXONC_CANDIDATES_H

/* The structured error contract used by King is the SaxonC core 13 ABI. */
#if defined(__APPLE__)
# define KING_SAXONC_RUNTIME_CANDIDATES "libsaxonc-core-he.dylib", NULL
# define KING_SAXONC_RUNTIME_CANDIDATE_NAMES "libsaxonc-core-he.dylib (SaxonC 13)"
#else
# define KING_SAXONC_RUNTIME_CANDIDATES "libsaxonc-core-he.so", "libsaxonc-core-he.so.13", NULL
# define KING_SAXONC_RUNTIME_CANDIDATE_NAMES "libsaxonc-core-he.so (SaxonC 13)"
#endif

#endif
