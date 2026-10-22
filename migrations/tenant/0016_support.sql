-- 0016_support: platform support people get a marked staff record in the clinic while a support grant is open, so every
-- action they take lands in the clinic's own audit log under "Support · name". They can't sign in with a password.
ALTER TABLE staff ADD COLUMN IF NOT EXISTS is_support TINYINT(1) NOT NULL DEFAULT 0;
