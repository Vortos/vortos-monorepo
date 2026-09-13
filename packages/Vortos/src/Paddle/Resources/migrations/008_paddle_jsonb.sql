-- paddle_outbox.payload, paddle_webhook_inbox.payload and .completed_handlers: JSON -> JSONB, for
-- installs created before 005_paddle_outbox and 007_paddle_webhook_inbox declared JSONB.
--
-- Safe for the inbox because the webhook signature is verified against the raw request body in the
-- controller, BEFORE the row is written; the stored payload is only ever decoded by key by the inbox
-- handlers and is never re-verified. Guarded on the live column type, so each block is a no-op on a
-- fresh install and on a database without the table.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}paddle_outbox')
           AND attname = 'payload' AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}paddle_outbox ALTER COLUMN payload TYPE JSONB USING payload::jsonb;
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}paddle_webhook_inbox')
           AND attname IN ('payload', 'completed_handlers') AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}paddle_webhook_inbox
            ALTER COLUMN payload TYPE JSONB USING payload::jsonb,
            ALTER COLUMN completed_handlers TYPE JSONB USING completed_handlers::jsonb;
    END IF;
END $$;
