/*
 * Root S3 adapter aggregator. Keeps the S3 runtime, metadata, multipart
 * upload, CRUD and backend-metadata slices grouped under one object-store
 * include boundary.
 */

#include "internal/cloud_s3_runtime.inc"
#include "internal/cloud_s3_metadata.inc"
#include "internal/cloud_s3_upload.inc"
#include "internal/cloud_s3_crud.inc"
#include "internal/object_store_backend_metadata.inc"
