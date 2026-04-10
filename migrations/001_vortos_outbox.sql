CREATE TABLE IF NOT EXISTS vortos_outbox (
    id              UUID        PRIMARY KEY,
    transport_name  VARCHAR(255) NOT NULL,
    event_class     VARCHAR(512) NOT NULL,
    payload         TEXT         NOT NULL,
    headers         JSONB        NOT NULL DEFAULT '{}',
    status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
    attempt_count   INTEGER      NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
    published_at    TIMESTAMP,
    next_attempt_at TIMESTAMP,
    failure_reason  TEXT
);

CREATE INDEX IF NOT EXISTS idx_vortos_outbox_status_created
    ON vortos_outbox (status, created_at)
    WHERE status = 'pending';