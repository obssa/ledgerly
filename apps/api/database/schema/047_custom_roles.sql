-- Evenbooks core schema, part 47: custom roles (Module 11).
--
-- A membership with the role 'custom' works only with the permissions stored on it (001, config/permissions.php).
-- Rules here, for everyone (staff included):
--   1. Permissions are a list of permission names, "module.action" or a whole module "module.*"; never "*",
--      which would make a custom role an owner.
--   2. Only a custom role has stored permissions; every other role has exactly those of its name.
-- Who may change a membership at all stays as in 008: owners and admins.

CREATE FUNCTION memberships_permissions_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF jsonb_typeof(NEW.permissions) <> 'array' THEN
        RAISE EXCEPTION 'Permissions are a list' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.role <> 'custom' AND NEW.permissions <> '[]'::jsonb THEN
        RAISE EXCEPTION 'Only a custom role has permissions of its own' USING ERRCODE = 'check_violation';
    END IF;
    IF jsonb_array_length(NEW.permissions) > 100
       OR EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.permissions) p
                   WHERE jsonb_typeof(p) <> 'string' OR NOT (p #>> '{}') ~ '^[a-z]+\.([a-z_]+|\*)$') THEN
        RAISE EXCEPTION 'A permission is "module.action" or "module.*"' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER memberships_permissions_valid BEFORE INSERT OR UPDATE OF role, permissions ON memberships
    FOR EACH ROW EXECUTE FUNCTION memberships_permissions_valid();
