CREATE TABLE IF NOT EXISTS feature_flags (
    id          VARCHAR(36)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    description TEXT         NOT NULL DEFAULT '',
    enabled     SMALLINT     NOT NULL DEFAULT 0,
    rules       TEXT         NOT NULL DEFAULT '[]',
    variants    TEXT         DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL,
    updated_at  TIMESTAMP    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE (name)
);
