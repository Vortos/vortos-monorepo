<?php

declare(strict_types=1);

return <<<'SQL'
CREATE TABLE paddle_transactions (
    id              VARCHAR(50)  NOT NULL,
    customer_id     VARCHAR(50),
    subscription_id VARCHAR(50),
    status          VARCHAR(20)  NOT NULL,
    currency_code   CHAR(3)      NOT NULL,
    total           VARCHAR(20)  NOT NULL,
    billed_at       TIMESTAMP(0) WITHOUT TIME ZONE,
    created_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);

CREATE INDEX paddle_transactions_customer_idx
    ON paddle_transactions(customer_id);

CREATE INDEX paddle_transactions_subscription_idx
    ON paddle_transactions(subscription_id);

CREATE INDEX paddle_transactions_status_idx
    ON paddle_transactions(status);

CREATE TABLE paddle_adjustments (
    id              VARCHAR(50)  NOT NULL,
    transaction_id  VARCHAR(50)  NOT NULL,
    customer_id     VARCHAR(50)  NOT NULL,
    action          VARCHAR(20)  NOT NULL,
    status          VARCHAR(30)  NOT NULL,
    total           VARCHAR(20)  NOT NULL,
    currency_code   CHAR(3)      NOT NULL,
    reason          TEXT         NOT NULL,
    created_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);

CREATE INDEX paddle_adjustments_transaction_idx
    ON paddle_adjustments(transaction_id);

CREATE INDEX paddle_adjustments_customer_idx
    ON paddle_adjustments(customer_id);
SQL;
