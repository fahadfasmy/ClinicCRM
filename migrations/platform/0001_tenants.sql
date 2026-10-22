-- Clinics on the platform and the web addresses each one answers on. No patient data ever lives in this database.
-- Plans, subscriptions, platform admins etc. come with the billing phase.
CREATE TABLE IF NOT EXISTS tenants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(40) NOT NULL,
    name VARCHAR(150) NOT NULL,
    legal_name VARCHAR(200) NULL,
    trn VARCHAR(20) NULL,
    dha_license VARCHAR(40) NULL,
    db_host VARCHAR(100) NULL,                  -- NULL = the config's DB_HOST
    db_name VARCHAR(64) NOT NULL,
    db_user VARCHAR(80) NOT NULL,
    db_pass_enc VARCHAR(255) NOT NULL,          -- sealed with the master key
    data_key_enc VARCHAR(255) NOT NULL,         -- the clinic's data key, sealed with the master key
    key_id VARCHAR(20) NOT NULL,                -- which master key sealed the two values above
    storage_prefix VARCHAR(100) NOT NULL,
    status ENUM('trial','active','past_due','read_only','suspended','cancelled','archived') NOT NULL DEFAULT 'active',
    plan_id INT UNSIGNED NULL,
    trial_ends_at DATETIME NULL,
    owner_email VARCHAR(190) NULL,
    region CHAR(2) NOT NULL DEFAULT 'AE',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug),
    UNIQUE KEY uq_db (db_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_domains (
    tenant_id INT UNSIGNED NOT NULL,
    host VARCHAR(190) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    PRIMARY KEY (host),
    KEY idx_tenant (tenant_id),
    CONSTRAINT fk_domain_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
