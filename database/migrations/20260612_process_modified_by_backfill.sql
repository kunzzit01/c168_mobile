-- Backfill process.modified_by* for legacy rows where dts_modified changed but modifier was never recorded.
-- Safe to run multiple times (only touches rows still missing modifier ids).

UPDATE process
SET
    modified_by = created_by,
    modified_by_type = created_by_type,
    modified_by_owner_id = created_by_owner_id
WHERE
    modified_by IS NULL
    AND modified_by_owner_id IS NULL
    AND dts_modified <> dts_created
    AND (created_by IS NOT NULL OR created_by_owner_id IS NOT NULL);
