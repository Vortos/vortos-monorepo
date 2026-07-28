-- Append-only catalog: a recorded backup's metadata/checksum can never be silently
-- mutated. UPDATE is rejected; DELETE is permitted because retention legitimately
-- prunes pruned backups (the row is removed only after its stored object is deleted).
--
-- Renamed out of the way of the schema provider that shares its old basename. The publisher gives
-- a provider precedence over a same-named raw stub, so this trigger shipped in the repository and
-- reached no database at all. The prefix is substituted at publish time now — the hardcoded
-- 'vortos_' spelling was also wrong on a PostgreSQL install, where framework tables live in a
-- 'vortos.' schema.
CREATE OR REPLACE FUNCTION {vortos}backup_catalog_no_update()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION '{vortos}backup_catalog is append-only: UPDATE is prohibited (id=%)', OLD.id;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_backup_catalog_no_update ON {vortos}backup_catalog;
CREATE TRIGGER trg_backup_catalog_no_update
    BEFORE UPDATE ON {vortos}backup_catalog
    FOR EACH ROW EXECUTE FUNCTION {vortos}backup_catalog_no_update();
