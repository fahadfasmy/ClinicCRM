-- Phase 6: consent forms and clinical photos. Consent templates (English/Arabic, versioned: a signed consent keeps a
-- frozen copy of the exact text it showed). Staff hand a device to the patient through a one-use token that expires
-- in 15 minutes (consent_handoffs). Services can require a consent. Photos (before/after/progress) are stored outside
-- the web root and only served through pages/download.php.

CREATE TABLE IF NOT EXISTS consent_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    name_ar VARCHAR(150) NULL,
    body_en MEDIUMTEXT NOT NULL,
    body_ar MEDIUMTEXT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_consents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    template_version INT UNSIGNED NOT NULL,
    encounter_id INT UNSIGNED NULL,
    appointment_id INT UNSIGNED NULL,
    snapshot_html MEDIUMTEXT NOT NULL,
    signature_path VARCHAR(255) NOT NULL,
    witness_name VARCHAR(150) NULL,
    witness_signature_path VARCHAR(255) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    collected_by INT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    revoked_by INT UNSIGNED NULL,
    revoke_reason VARCHAR(255) NULL,
    KEY idx_patient (patient_id, template_id),
    KEY idx_appointment (appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consent_handoffs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    patient_id INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NULL,
    encounter_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    consent_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token_hash),
    KEY idx_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_photos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    encounter_id INT UNSIGNED NULL,
    area VARCHAR(80) NOT NULL,
    stage ENUM('before','after','progress') NOT NULL DEFAULT 'before',
    path VARCHAR(255) NOT NULL,
    mime VARCHAR(50) NOT NULL,
    size INT UNSIGNED NOT NULL,
    taken_at DATE NOT NULL,
    consent_id INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    uploaded_by INT UNSIGNED NULL,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_patient (patient_id, area, taken_at),
    KEY idx_encounter (encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A service can require one consent form; the doctor and reception are warned when it hasn't been signed
ALTER TABLE services ADD COLUMN IF NOT EXISTS requires_consent_template_id INT UNSIGNED NULL;

-- Two starter templates the clinic edits before use (plain text; {patient_name}, {procedure}, {date}, {practitioner})
INSERT INTO consent_templates (name, name_ar, body_en, body_ar)
SELECT 'General treatment consent', 'موافقة عامة على العلاج',
'I, {patient_name}, agree to receive {procedure} on {date} from {practitioner}.\n\nThe treatment, its expected results, risks, side effects and alternatives have been explained to me, and my questions have been answered. I have told the clinic about my medical history, medicines and allergies truthfully.\n\nI understand that results vary from person to person and are not guaranteed, and that I may withdraw this consent at any time before the treatment.',
'أنا، {patient_name}، أوافق على تلقي {procedure} بتاريخ {date} من قبل {practitioner}.\n\nتم شرح العلاج ونتائجه المتوقعة ومخاطره وآثاره الجانبية والبدائل المتاحة لي، وتمت الإجابة على أسئلتي. لقد أبلغت العيادة بتاريخي الطبي والأدوية التي أتناولها والحساسية لدي بصدق.\n\nأدرك أن النتائج تختلف من شخص لآخر وأنها غير مضمونة، وأنه يحق لي سحب هذه الموافقة في أي وقت قبل العلاج.'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM consent_templates);

INSERT INTO consent_templates (name, name_ar, body_en, body_ar)
SELECT 'Clinical photography consent', 'موافقة على التصوير الطبي',
'I, {patient_name}, agree that the clinic may take before, during and after photographs of the treated area for my medical record.\n\nThe photographs are kept confidential in my record and will not be shared or used for marketing without my separate written permission.',
'أنا، {patient_name}، أوافق على أن تلتقط العيادة صوراً للمنطقة المعالجة قبل العلاج وأثناءه وبعده لسجلي الطبي.\n\nتُحفظ الصور بسرية في سجلي ولن تتم مشاركتها أو استخدامها لأغراض تسويقية دون إذن كتابي منفصل مني.'
FROM DUAL WHERE (SELECT COUNT(*) FROM consent_templates) = 1;
