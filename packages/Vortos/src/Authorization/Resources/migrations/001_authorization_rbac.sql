CREATE TABLE IF NOT EXISTS role_permissions (
    role VARCHAR(150) NOT NULL,
    permission VARCHAR(190) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role, permission)
);

CREATE INDEX IF NOT EXISTS idx_role_permissions_role ON role_permissions (role);
CREATE INDEX IF NOT EXISTS idx_role_permissions_permission ON role_permissions (permission);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id VARCHAR(190) NOT NULL,
    role VARCHAR(150) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role)
);

CREATE INDEX IF NOT EXISTS idx_user_roles_user ON user_roles (user_id);
CREATE INDEX IF NOT EXISTS idx_user_roles_role ON user_roles (role);
