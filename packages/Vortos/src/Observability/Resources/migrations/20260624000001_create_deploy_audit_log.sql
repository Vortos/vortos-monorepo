-- Append-only invariant at the storage layer: prevents UPDATE and DELETE on the
-- deploy audit ledger regardless of which application code path runs against it.
-- This travels with the schema (hardened-install note: pair with a DB-level
-- REVOKE UPDATE, DELETE on this table from the application role for defense in
-- depth, and a WORM/object-lock archival job per Block 20 when it lands).
CREATE OR REPLACE FUNCTION vortos_observability_deploy_audit_log_immutable()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'observability_deploy_audit_log is append-only: UPDATE and DELETE are prohibited (entry_id=%)', OLD.entry_id;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_observability_deploy_audit_log_immutable ON vortos_observability_deploy_audit_log;
CREATE TRIGGER trg_observability_deploy_audit_log_immutable
    BEFORE UPDATE OR DELETE ON vortos_observability_deploy_audit_log
    FOR EACH ROW EXECUTE FUNCTION vortos_observability_deploy_audit_log_immutable();
