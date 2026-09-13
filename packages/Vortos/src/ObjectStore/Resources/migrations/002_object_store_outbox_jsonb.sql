-- object_store_outbox.payload: JSON -> JSONB, for installs created before 001_object_store_outbox
-- declared JSONB.
--
-- The relay decodes the payload by key; nothing depends on its raw text. Guarded on the live column
-- type, so it is a no-op on a fresh install and on a database without the table.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}object_store_outbox')
           AND attname = 'payload' AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}object_store_outbox ALTER COLUMN payload TYPE JSONB USING payload::jsonb;
    END IF;
END $$;
