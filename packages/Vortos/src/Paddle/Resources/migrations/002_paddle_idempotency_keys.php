<?php

declare(strict_types=1);

return <<<'SQL'
CREATE TABLE paddle_idempotency_keys (
    key_id      VARCHAR(36)  NOT NULL,
    operation   VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    expires_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (key_id)
);

CREATE INDEX paddle_idempotency_keys_expires_idx
    ON paddle_idempotency_keys(expires_at);
SQL;
