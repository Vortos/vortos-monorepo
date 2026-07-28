-- Append-only invariant at the storage layer: prevents UPDATE and DELETE on the
-- deploy audit ledger regardless of which application code path runs against it.
--
-- This file used to be 20260624000001_create_deploy_audit_log.sql — the same basename as the
-- schema provider that creates the table. The publisher silently dropped it as a duplicate, so
-- the trigger below existed in the repository and in no database at all. It also hardcoded the
-- 'vortos_' prefix, which is wrong on a PostgreSQL install where the framework tables live in a
-- 'vortos.' schema; {vortos} is substituted at publish time now.
--
-- Hardened-install note: pair with a DB-level REVOKE UPDATE, DELETE on this table from the
-- application role for defence in depth, and a WORM/object-lock archival job per Block 20.
CREATE OR REPLACE FUNCTION {vortos}observability_deploy_audit_log_immutable()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'observability_deploy_audit_log is append-only: UPDATE and DELETE are prohibited (entry_id=%)', OLD.entry_id;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_observability_deploy_audit_log_immutable ON {vortos}observability_deploy_audit_log;
CREATE TRIGGER trg_observability_deploy_audit_log_immutable
    BEFORE UPDATE OR DELETE ON {vortos}observability_deploy_audit_log
    FOR EACH ROW EXECUTE FUNCTION {vortos}observability_deploy_audit_log_immutable();
