/*
 * Root GCS adapter aggregator. Keeps the GCS runtime, upload and CRUD slices
 * grouped under one object-store include boundary.
 */

#include "internal/cloud_gcs_runtime.inc"
#include "internal/cloud_gcs_upload.inc"
#include "internal/cloud_gcs_crud.inc"
