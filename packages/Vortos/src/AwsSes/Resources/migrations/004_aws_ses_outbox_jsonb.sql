-- aws_ses_outbox.payload: JSON -> JSONB, for installs created before 002_aws_ses_outbox declared JSONB.
--
-- JSON keeps raw text and re-parses it on every relay read, has no equality operator and cannot be
-- UNIONed with a JSONB column. Nothing reads this payload as text: the relay decodes it by key.
--
-- Guarded on the live column type, so it is a no-op on a fresh install (already JSONB) and on a
-- database without the table. The rewrite is bounded by the migration lock_timeout, and the
-- analyzer measures the table before allowing it (pg.alter.blocking).
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}aws_ses_outbox')
           AND attname = 'payload' AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}aws_ses_outbox ALTER COLUMN payload TYPE JSONB USING payload::jsonb;
    END IF;
END $$;
