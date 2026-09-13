-- payhere_ipn_inbox.payload: JSON -> JSONB, for installs created before 001_payhere_ipn_inbox declared
-- JSONB.
--
-- Safe because the notification's md5sig is verified in the controller before the row is written,
-- and it is computed from named fields rather than the raw body; the stored payload is decoded by
-- key only. Guarded on the live column type, so it is a no-op on a fresh install and on a database
-- without the table.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}payhere_ipn_inbox')
           AND attname = 'payload' AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}payhere_ipn_inbox ALTER COLUMN payload TYPE JSONB USING payload::jsonb;
    END IF;
END $$;
