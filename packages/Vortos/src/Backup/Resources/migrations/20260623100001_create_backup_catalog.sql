-- Append-only catalog: a recorded backup's metadata/checksum can never be silently
-- mutated. UPDATE is rejected; DELETE is permitted because retention legitimately
-- prunes pruned backups (the row is removed only after its stored object is deleted).
CREATE OR REPLACE FUNCTION vortos_backup_catalog_no_update()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'vortos_backup_catalog is append-only: UPDATE is prohibited (id=%)', OLD.id;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_backup_catalog_no_update ON vortos_backup_catalog;
CREATE TRIGGER trg_backup_catalog_no_update
    BEFORE UPDATE ON vortos_backup_catalog
    FOR EACH ROW EXECUTE FUNCTION vortos_backup_catalog_no_update();
