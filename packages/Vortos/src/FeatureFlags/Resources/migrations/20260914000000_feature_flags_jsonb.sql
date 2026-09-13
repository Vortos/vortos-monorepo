-- Feature-flag JSON columns -> JSONB, for installs created before their providers declared JSONB:
-- change requests (payload, approvals, rejections), guardrail policies (conditions), layers (members),
-- and the compliance tables (export manifest filter, SCIM user emails/groups/roles, SCIM group members).
--
-- Every one is decoded by key or is a list whose order JSONB keeps; guardrail conditions carry an
-- explicit sort_order. Guarded on the live column type, so each block is a no-op on a fresh install
-- and on a database without the table. One ALTER per block so the analyzer measures each table.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}feature_flag_change_requests')
           AND attname IN ('payload', 'approvals', 'rejections') AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}feature_flag_change_requests
            ALTER COLUMN payload TYPE JSONB USING payload::jsonb,
            ALTER COLUMN approvals TYPE JSONB USING approvals::jsonb,
            ALTER COLUMN rejections TYPE JSONB USING rejections::jsonb;
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}feature_flag_guardrail_policies')
           AND attname = 'conditions' AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}feature_flag_guardrail_policies ALTER COLUMN conditions TYPE JSONB USING conditions::jsonb;
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}ff_layers')
           AND attname = 'members' AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}ff_layers ALTER COLUMN members TYPE JSONB USING members::jsonb;
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}ff_export_manifests')
           AND attname = 'filter_json' AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}ff_export_manifests ALTER COLUMN filter_json TYPE JSONB USING filter_json::jsonb;
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}ff_scim_users')
           AND attname IN ('emails', 'groups', 'roles') AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}ff_scim_users
            ALTER COLUMN emails TYPE JSONB USING emails::jsonb,
            ALTER COLUMN groups TYPE JSONB USING groups::jsonb,
            ALTER COLUMN roles TYPE JSONB USING roles::jsonb;
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = to_regclass('{vortos}ff_scim_groups')
           AND attname = 'member_ids' AND atttypid = 'json'::regtype AND NOT attisdropped
    ) THEN
        ALTER TABLE {vortos}ff_scim_groups ALTER COLUMN member_ids TYPE JSONB USING member_ids::jsonb;
    END IF;
END $$;
