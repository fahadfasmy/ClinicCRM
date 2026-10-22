-- 0015_ui_language: each staff member's screen language (English or Arabic, right to left). Patient-facing documents and
-- messages keep their own language settings.
ALTER TABLE staff ADD COLUMN IF NOT EXISTS ui_language ENUM('en','ar') NOT NULL DEFAULT 'en';
