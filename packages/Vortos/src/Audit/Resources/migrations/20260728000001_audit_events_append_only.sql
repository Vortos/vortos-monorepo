-- Append-only enforcement for the unified audit ledger, in the database rather than in the code
-- that writes to it.
--
-- Until now audit_events had tamper EVIDENCE — a per-chain hash chain plus an HMAC signature, so
-- an alteration can be DETECTED by verifying the chain. It had no tamper PREVENTION: a stray
-- UPDATE, a hand-run DELETE, an ORM flush against the wrong entity, all succeeded, and the damage
-- surfaced only when somebody next ran a verification. The table this ledger replaced
-- (public.org_audit_log, dropped when audit unified into the spine) did have PostgreSQL triggers,
-- so the unification quietly traded prevention for evidence. This restores it.
--
-- DELETE is not blocked outright, because retention legitimately purges. It is constrained to
-- exactly what retention is allowed to do. AuditRetentionSweeper's documented ordering is
-- archive -> checkpoint -> delete, and it only ever purges a contiguous prefix that has already
-- been written to cold storage and recorded in audit_checkpoints. That invariant was previously
-- upheld by one class being careful. Below, the database upholds it: a row may only be deleted if
-- a checkpoint for its chain already covers its sequence. Nothing that has not been archived can
-- leave this table, no matter who asks or how.
--
-- Deliberately NOT a session flag (current_setting('...') set by the sweeper). A flag only asks
-- "did the caller claim to be retention", which any caller can claim. The checkpoint test asks
-- "is this row safe to lose", which is the property actually worth enforcing.
--
-- Hardened-install note: pair with REVOKE UPDATE, DELETE ON {vortos}audit_events FROM the
-- application role. A trigger stops accidents and casual tampering; it cannot stop a superuser,
-- who can drop it. The archive in object storage with a retention lock is the backstop.

CREATE OR REPLACE FUNCTION {vortos}audit_events_no_update()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION
        'audit_events is append-only: UPDATE is prohibited (id=%, chain_key=%, sequence=%)',
        OLD.id, OLD.chain_key, OLD.sequence
        USING ERRCODE = 'restrict_violation';
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION {vortos}audit_events_purge_guard()
RETURNS TRIGGER AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM {vortos}audit_checkpoints c
         WHERE c.chain_key = OLD.chain_key
           AND c.last_sequence >= OLD.sequence
    ) THEN
        RAISE EXCEPTION
            'audit_events row is not archived: DELETE refused (id=%, chain_key=%, sequence=%). '
            'Retention must archive and checkpoint a record before purging it.',
            OLD.id, OLD.chain_key, OLD.sequence
            USING ERRCODE = 'restrict_violation';
    END IF;

    RETURN OLD;
END;
$$ LANGUAGE plpgsql;

-- TRUNCATE bypasses row-level triggers entirely, so it needs a statement-level one of its own.
-- Without this the whole ledger can be erased in one statement past both guards above.
CREATE OR REPLACE FUNCTION {vortos}audit_events_no_truncate()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'audit_events is append-only: TRUNCATE is prohibited'
        USING ERRCODE = 'restrict_violation';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_audit_events_no_update ON {vortos}audit_events;
CREATE TRIGGER trg_audit_events_no_update
    BEFORE UPDATE ON {vortos}audit_events
    FOR EACH ROW EXECUTE FUNCTION {vortos}audit_events_no_update();

DROP TRIGGER IF EXISTS trg_audit_events_purge_guard ON {vortos}audit_events;
CREATE TRIGGER trg_audit_events_purge_guard
    BEFORE DELETE ON {vortos}audit_events
    FOR EACH ROW EXECUTE FUNCTION {vortos}audit_events_purge_guard();

DROP TRIGGER IF EXISTS trg_audit_events_no_truncate ON {vortos}audit_events;
CREATE TRIGGER trg_audit_events_no_truncate
    BEFORE TRUNCATE ON {vortos}audit_events
    FOR EACH STATEMENT EXECUTE FUNCTION {vortos}audit_events_no_truncate();
