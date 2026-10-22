-- Shared reference data (multi-clinic mode): ICD-10-CM codes, imported from the CMS file with tools/icd10_import.php.
CREATE TABLE IF NOT EXISTS icd10_codes (
    code VARCHAR(10) PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    chapter VARCHAR(10) NULL,
    FULLTEXT KEY ft_description (description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
