<?php

declare(strict_types=1);

return <<<'SQL'
CREATE TABLE paddle_outbox (
    id                  BIGSERIAL PRIMARY KEY,
    operation           VARCHAR(255) NOT NULL,
    payload             JSONB        NOT NULL,
    idempotency_key     VARCHAR(36)  NOT NULL UNIQUE,
    attempts            SMALLINT     NOT NULL DEFAULT 0,
    last_attempted_at   TIMESTAMP(0) WITHOUT TIME ZONE,
    next_attempt_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    failed_at           TIMESTAMP(0) WITHOUT TIME ZONE,
    created_at          TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
);

CREATE INDEX paddle_outbox_pending_idx
    ON paddle_outbox(next_attempt_at)
    WHERE failed_at IS NULL;
SQL;
