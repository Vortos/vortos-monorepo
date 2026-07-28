-- Immutability trigger: prevents UPDATE and DELETE on the manifests table.
-- This travels with the schema, so write-once is enforced regardless of role/grant config.
--
-- Renamed out of the way of the schema provider that shares its old basename. The publisher gives
-- a provider precedence over a same-named raw stub, so this trigger shipped in the repository and
-- reached no database at all. The prefix is substituted at publish time now — the hardcoded
-- 'vortos_' spelling was also wrong on a PostgreSQL install, where framework tables live in a
-- 'vortos.' schema.
CREATE OR REPLACE FUNCTION {vortos}release_manifests_immutable()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'release_build_manifests is append-only: UPDATE and DELETE are prohibited (build_id=%)', OLD.build_id;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_release_manifests_immutable ON {vortos}release_build_manifests;
CREATE TRIGGER trg_release_manifests_immutable
    BEFORE UPDATE OR DELETE ON {vortos}release_build_manifests
    FOR EACH ROW EXECUTE FUNCTION {vortos}release_manifests_immutable();
