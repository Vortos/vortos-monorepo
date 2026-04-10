CREATE TABLE IF NOT EXISTS vortos_failed_messages (
    id              UUID         PRIMARY KEY,
    transport_name  VARCHAR(255) NOT NULL,
    event_class     VARCHAR(512) NOT NULL,
    payload         TEXT         NOT NULL,
    headers         JSONB        NOT NULL DEFAULT '{}',
    failure_reason  TEXT         NOT NULL,
    exception_class VARCHAR(512) NOT NULL,
    attempt_count   INTEGER      NOT NULL DEFAULT 0,
    failed_at       TIMESTAMP    NOT NULL DEFAULT NOW(),
    replayed_at     TIMESTAMP,
    status          VARCHAR(20)  NOT NULL DEFAULT 'failed'
);

CREATE INDEX IF NOT EXISTS idx_vortos_failed_messages_status
    ON vortos_failed_messages (status, failed_at)
    WHERE status = 'failed';