-- Vortos framework tables
-- These are created automatically by: php bin/console vortos:setup:messaging

CREATE TABLE IF NOT EXISTS users (
    id            UUID         PRIMARY KEY,
    name          TEXT         NOT NULL,
    email         TEXT         NOT NULL UNIQUE,
    password_hash TEXT         NOT NULL DEFAULT '',
    roles         JSONB        NOT NULL DEFAULT '["ROLE_USER"]',
    version       INTEGER      NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_users_email ON users (email);
