<?php

declare(strict_types=1);

return <<<'SQL'
CREATE TABLE object_store_outbox (
    id UUID NOT NULL,
    domain_event_id VARCHAR(255) DEFAULT NULL,
    operation VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    attempt_count INT NOT NULL DEFAULT 0,
    payload JSON NOT NULL,
    last_error TEXT DEFAULT NULL,
    next_attempt_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
    processed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY(id)
);

CREATE UNIQUE INDEX object_store_outbox_domain_event_id_unique
    ON object_store_outbox(domain_event_id)
    WHERE domain_event_id IS NOT NULL;

CREATE INDEX object_store_outbox_pending_idx
    ON object_store_outbox(status, next_attempt_at, created_at);
SQL;
