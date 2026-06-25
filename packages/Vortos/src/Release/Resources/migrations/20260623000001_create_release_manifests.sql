-- Immutability trigger: prevents UPDATE and DELETE on the manifests table.
-- This travels with the schema, so write-once is enforced regardless of role/grant config.
CREATE OR REPLACE FUNCTION vortos_release_manifests_immutable()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'release_build_manifests is append-only: UPDATE and DELETE are prohibited (build_id=%)', OLD.build_id;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_release_manifests_immutable ON vortos_release_build_manifests;
CREATE TRIGGER trg_release_manifests_immutable
    BEFORE UPDATE OR DELETE ON vortos_release_build_manifests
    FOR EACH ROW EXECUTE FUNCTION vortos_release_manifests_immutable();
