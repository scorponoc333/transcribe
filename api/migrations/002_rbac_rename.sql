-- v3.114 — RBAC rename to match Jason's vocabulary
-- Old: role_in_org ENUM('owner', 'admin', 'editor', 'viewer')
-- New: role_in_org ENUM('admin', 'manager', 'editor')
--
--   owner  -> admin    (top role inside a tenant; edits branding/settings)
--   admin  -> manager  (middle role; full read, no settings edit)
--   editor -> editor   (unchanged; own-records only for Reports + History)
--   viewer -> editor   (folded; if we need true read-only later we add it back)
--
-- Global `role` column (admin/manager/user) is left untouched.
-- Master Super-Admin is `users.is_super_admin = 1`, which already exists.

-- 1. Widen the enum to include the union so intermediate renames don't fail
ALTER TABLE users
    MODIFY COLUMN role_in_org
    ENUM('owner','admin','manager','editor','viewer')
    NOT NULL DEFAULT 'editor';

-- 2. Safe rename sequence (no collisions even if every value is present)
UPDATE users SET role_in_org = 'editor'  WHERE role_in_org = 'viewer';
UPDATE users SET role_in_org = 'manager' WHERE role_in_org = 'admin';
UPDATE users SET role_in_org = 'admin'   WHERE role_in_org = 'owner';

-- 3. Shrink the enum to the final three values
ALTER TABLE users
    MODIFY COLUMN role_in_org
    ENUM('admin','manager','editor')
    NOT NULL DEFAULT 'editor';

-- 4. `settings.user_id` column already exists in this DB — skipped.
--    Rows where user_id IS NULL are org-scope; rows with user_id are per-user.

-- IMPORTANT operator note: if the rename runs TWICE (e.g. a prior partial
-- execution), owners collapse through admin -> manager. To restore, bump any
-- account that should be tenant admins:
--   UPDATE users SET role_in_org = 'admin' WHERE is_super_admin = 1;
--   -- plus any per-tenant heads of org identified by email / signup order
