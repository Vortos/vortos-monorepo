<?php

declare(strict_types=1);

return <<<'SQL'
CREATE TABLE paddle_subscriptions (
    id              VARCHAR(50)  NOT NULL,
    customer_id     VARCHAR(50)  NOT NULL,
    status          VARCHAR(20)  NOT NULL,
    currency_code   CHAR(3)      NOT NULL,
    next_billed_at  TIMESTAMP(0) WITHOUT TIME ZONE,
    paused_at       TIMESTAMP(0) WITHOUT TIME ZONE,
    canceled_at     TIMESTAMP(0) WITHOUT TIME ZONE,
    created_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);

CREATE INDEX paddle_subscriptions_customer_idx
    ON paddle_subscriptions(customer_id);

CREATE INDEX paddle_subscriptions_status_idx
    ON paddle_subscriptions(status);

CREATE TABLE paddle_subscription_items (
    subscription_id VARCHAR(50)  NOT NULL,
    price_id        VARCHAR(50)  NOT NULL,
    quantity        INTEGER      NOT NULL DEFAULT 1,
    status          VARCHAR(20)  NOT NULL,
    created_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (subscription_id, price_id)
);

CREATE INDEX paddle_subscription_items_price_idx
    ON paddle_subscription_items(price_id);
SQL;
