-- 0002_saas: selling the platform to clinics. Plans and limits, subscriptions (Stripe), usage per month, platform admins
-- (their own sign-in, 2-step mandatory), support grants approved by a clinic, the platform audit log, legal acceptances,
-- payment-provider webhooks (each stored once), and signups waiting for email verification. No patient data here.

CREATE TABLE IF NOT EXISTS plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(80) NOT NULL,
    price_month_aed DECIMAL(10,2) NOT NULL,
    price_year_aed DECIMAL(10,2) NOT NULL,
    stripe_price_month VARCHAR(100) NULL,
    stripe_price_year VARCHAR(100) NULL,
    limits LONGTEXT NOT NULL,          -- JSON: branches, practitioners, staff_seats, storage_gb, messages_per_month, layouts (null = unlimited)
    features LONGTEXT NOT NULL,        -- JSON: insurance, broadcasts, automations, online_booking, custom_domain, priority_support
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_plan_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Placeholder prices and limits from the brief (confirm before launch; editable in /platform → Plans)
INSERT IGNORE INTO plans (code, name, price_month_aed, price_year_aed, limits, features, sort_order) VALUES
 ('starter', 'Starter', 399, 3990, '{"branches":1,"practitioners":3,"staff_seats":5,"storage_gb":5,"messages_per_month":500,"layouts":3}',
  '{"insurance":false,"broadcasts":false,"automations":false,"online_booking":false,"custom_domain":false,"priority_support":false}', 1),
 ('growth', 'Growth', 899, 8990, '{"branches":3,"practitioners":10,"staff_seats":15,"storage_gb":25,"messages_per_month":3000,"layouts":null}',
  '{"insurance":true,"broadcasts":true,"automations":true,"online_booking":true,"custom_domain":false,"priority_support":false}', 2),
 ('pro', 'Pro', 1799, 17990, '{"branches":10,"practitioners":null,"staff_seats":50,"storage_gb":100,"messages_per_month":10000,"layouts":null}',
  '{"insurance":true,"broadcasts":true,"automations":true,"online_booking":true,"custom_domain":true,"priority_support":true}', 3);

CREATE TABLE IF NOT EXISTS subscriptions (
    tenant_id INT UNSIGNED NOT NULL PRIMARY KEY,
    stripe_customer_id VARCHAR(100) NULL,
    stripe_subscription_id VARCHAR(100) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'trialing',      -- Stripe's status: trialing, active, past_due, paused, canceled, unpaid…
    `interval` ENUM('month','year') NULL,
    current_period_end DATETIME NULL,
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    grace_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sub_customer (stripe_customer_id),
    KEY idx_sub_stripe (stripe_subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_counters (
    tenant_id INT UNSIGNED NOT NULL,
    period CHAR(7) NOT NULL,
    messages_sent INT UNSIGNED NOT NULL DEFAULT 0,
    storage_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    active_staff INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(120) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('owner','support','billing') NOT NULL DEFAULT 'support',
    totp_secret_enc VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_platform_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_grants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    platform_admin_id INT UNSIGNED NULL,          -- NULL = any support person; set when one is named
    reason VARCHAR(500) NOT NULL,
    granted_by_staff_id INT UNSIGNED NULL,        -- the clinic admin (NULL for emergency access)
    granted_by_name VARCHAR(120) NULL,
    mode ENUM('read_only','read_write') NOT NULL DEFAULT 'read_only',
    emergency TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_grant_tenant (tenant_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NULL,
    tenant_id INT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    detail LONGTEXT NULL,
    ip VARCHAR(45) NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_paudit_tenant (tenant_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legal_acceptances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NULL,
    signup_id INT UNSIGNED NULL,
    doc ENUM('terms','privacy','dpa') NOT NULL,
    version VARCHAR(20) NOT NULL,
    accepted_by_email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_legal_tenant (tenant_id, doc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(20) NOT NULL,
    event_id VARCHAR(100) NOT NULL,
    type VARCHAR(80) NOT NULL,
    payload LONGTEXT NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    error VARCHAR(500) NULL,
    UNIQUE KEY uq_webhook_event (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signup_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    email VARCHAR(190) NOT NULL,
    data LONGTEXT NOT NULL,                -- JSON: clinic name, slug, DHA licence, TRN, owner name, mobile
    ip VARCHAR(45) NULL,
    verified_at DATETIME NULL,
    tenant_id INT UNSIGNED NULL,
    error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_signup_token (token_hash),
    KEY idx_signup_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trial_emails (
    tenant_id INT UNSIGNED NOT NULL,
    days_left TINYINT UNSIGNED NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, days_left)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When a paid subscription lapses or a clinic cancels: read-only from this time, archived 90 days after cancelling
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS read_only_since DATETIME NULL,
                    ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL,
                    ADD COLUMN IF NOT EXISTS last_activity_at DATETIME NULL;
