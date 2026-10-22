-- 0013_reports: the purchase VAT register (supplier tax invoices) for the VAT return's input VAT (boxes 9-10).
-- Finance enters each supplier tax invoice: stock and non-stock purchases (rent, equipment, services).

CREATE TABLE IF NOT EXISTS purchase_vat (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NULL,
    invoice_date DATE NOT NULL,
    supplier VARCHAR(150) NOT NULL,
    supplier_trn VARCHAR(20) NULL,
    invoice_ref VARCHAR(60) NOT NULL,
    net DECIMAL(12,2) NOT NULL,
    vat DECIMAL(12,2) NOT NULL,
    note VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_purchase_vat (supplier, invoice_ref),
    KEY idx_purchase_vat_date (invoice_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
