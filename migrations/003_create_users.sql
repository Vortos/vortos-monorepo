CREATE TABLE users (
    id          VARCHAR(36)  NOT NULL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    roles       JSONB        NOT NULL DEFAULT '["ROLE_USER"]',
    status      BOOLEAN      NULL,
    version     INTEGER      NOT NULL DEFAULT 0
);
