<?php

/**
 * Data-retention TTLs (days) enforced by spidernet:compliance:enforce-retention.
 * High-volume operational data is purged; aggregates and the immutable
 * event/audit chain are never touched.
 */
return [
    'usage_records_days' => (int) env('RETENTION_USAGE_RECORDS_DAYS', 180),
    'message_bodies_days' => (int) env('RETENTION_MESSAGE_BODIES_DAYS', 365),
    'dsar_artifact_days' => (int) env('RETENTION_DSAR_ARTIFACT_DAYS', 30),
];
