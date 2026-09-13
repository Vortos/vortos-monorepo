-- messaging_outbox.metadata and messaging_failed_messages.headers: JSON -> JSONB, for installs created
-- before 001_vortos_outbox and 002_vortos_failed_messages declared JSONB.
--
-- Both are read as maps by key (relay, replay); nothing depends on their raw text or key order.
-- Guarded on the live column type, so each block is a no-op on a fresh install and on a database
-- without the table. One ALTER per block so the analyzer measures each table it rewrites.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}messaging_outbox')
           AND attname = 'metadata' AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}messaging_outbox ALTER COLUMN metadata TYPE JSONB USING metadata::jsonb;
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}messaging_failed_messages')
           AND attname = 'headers' AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}messaging_failed_messages ALTER COLUMN headers DROP DEFAULT;
        ALTER TABLE {vortos}messaging_failed_messages ALTER COLUMN headers TYPE JSONB USING headers::jsonb;
        ALTER TABLE {vortos}messaging_failed_messages ALTER COLUMN headers SET DEFAULT '{}'::jsonb;
    END IF;
END $$;
