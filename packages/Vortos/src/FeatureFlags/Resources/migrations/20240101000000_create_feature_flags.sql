CREATE TABLE IF NOT EXISTS feature_flags (
    id          VARCHAR(36)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    description TEXT         NOT NULL DEFAULT '',
    enabled     SMALLINT     NOT NULL DEFAULT 0,
    rules         TEXT         NOT NULL DEFAULT '[]',
    variants      TEXT         DEFAULT NULL,
    value_type    VARCHAR(16)  NOT NULL DEFAULT 'bool',
    default_value TEXT         DEFAULT NULL,
    payload       TEXT         DEFAULT NULL,
    bucket_by     VARCHAR(32)  NOT NULL DEFAULT 'userId',
    kind          VARCHAR(16)  NOT NULL DEFAULT 'release',
    prerequisites TEXT         DEFAULT NULL,
    variant_rules TEXT         DEFAULT NULL,
    schedule      TEXT         DEFAULT NULL,
    required_scope VARCHAR(191) DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL,
    updated_at  TIMESTAMP    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE (name)
);

CREATE TABLE IF NOT EXISTS feature_flag_tenant_overrides (
    tenant_id     VARCHAR(191) NOT NULL,
    flag_name     VARCHAR(255) NOT NULL,
    override_json TEXT         NOT NULL,
    updated_at    TIMESTAMP    NOT NULL,
    PRIMARY KEY (tenant_id, flag_name)
);

CREATE TABLE IF NOT EXISTS feature_flag_audit_log (
    event_id    VARCHAR(36)  NOT NULL,
    flag_id     VARCHAR(36)  NOT NULL,
    flag_name   VARCHAR(255) NOT NULL,
    event_type  VARCHAR(64)  NOT NULL,
    actor_id    VARCHAR(191) NOT NULL,
    reason      TEXT         DEFAULT NULL,
    occurred_at VARCHAR(40)  NOT NULL,
    data        TEXT         NOT NULL DEFAULT '{}',
    PRIMARY KEY (event_id)
);

CREATE TABLE IF NOT EXISTS feature_flag_state_view (
    flag_name       VARCHAR(255) NOT NULL,
    flag_id         VARCHAR(36)  NOT NULL,
    enabled         SMALLINT     NOT NULL DEFAULT 0,
    archived        SMALLINT     NOT NULL DEFAULT 0,
    value_type      VARCHAR(16)  NOT NULL DEFAULT 'bool',
    kind            VARCHAR(16)  NOT NULL DEFAULT 'release',
    rule_count      INTEGER      NOT NULL DEFAULT 0,
    variants        TEXT         DEFAULT NULL,
    scheduled       SMALLINT     NOT NULL DEFAULT 0,
    last_event_type VARCHAR(64)  NOT NULL DEFAULT '',
    last_actor_id   VARCHAR(191) NOT NULL DEFAULT '',
    updated_at      VARCHAR(40)  NOT NULL DEFAULT '',
    PRIMARY KEY (flag_name)
);
