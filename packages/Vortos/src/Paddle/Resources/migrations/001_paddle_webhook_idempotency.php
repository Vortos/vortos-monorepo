<?php

declare(strict_types=1);

return <<<'SQL'
CREATE TABLE paddle_webhook_idempotency (
    event_id    VARCHAR(255) NOT NULL,
    event_type  VARCHAR(255) NOT NULL,
    received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    expires_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (event_id)
);

CREATE INDEX paddle_webhook_idempotency_expires_idx
    ON paddle_webhook_idempotency(expires_at);
SQL;
