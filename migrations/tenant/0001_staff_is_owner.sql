-- The clinic's owner (the account created at signup or provisioning). Owners can't be removed by other admins (later
-- phases) and receive billing notices. One statement, so it is safe if the run stops half-way.
ALTER TABLE staff ADD COLUMN is_owner TINYINT(1) NOT NULL DEFAULT 0 AFTER role;
