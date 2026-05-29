<?php

declare(strict_types=1);

return <<<'SQL'
CREATE TABLE paddle_audit_log (
    id               BIGSERIAL PRIMARY KEY,
    event_type       VARCHAR(255) NOT NULL,
    paddle_event_id  VARCHAR(255) NOT NULL,
    entity_type      VARCHAR(100) NOT NULL,
    entity_id        VARCHAR(255) NOT NULL,
    actor            VARCHAR(255),
    occurred_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    recorded_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
);

CREATE INDEX paddle_audit_log_entity_idx ON paddle_audit_log(entity_type, entity_id);
CREATE INDEX paddle_audit_log_occurred_idx ON paddle_audit_log(occurred_at);
SQL;
