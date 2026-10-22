-- 0000_baseline: the clinic database as of Phase 1 (16 Oct 2026), generated from the local copy.
-- Includes the tables the ensure*Schema() functions create. The first clinic's existing database is marked as already
-- having this (tools/register_existing_tenant.php); new clinics get it from tools/provision_tenant.php.
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int(10) unsigned DEFAULT NULL,
  `lead_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `old_value` varchar(255) DEFAULT NULL,
  `new_value` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_staff_id` (`staff_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(100) NOT NULL,
  `key_prefix` varchar(12) NOT NULL,
  `key_hash` char(64) NOT NULL,
  `assign_to` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_meta` (
  `k` varchar(50) NOT NULL,
  `v` varchar(255) NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `appointment_gcal` (
  `appointment_id` int(10) unsigned NOT NULL,
  `staff_id` int(10) unsigned DEFAULT NULL,
  `event_id` varchar(255) DEFAULT NULL,
  `pending` tinyint(1) NOT NULL DEFAULT 0,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `error` varchar(500) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`appointment_id`),
  KEY `idx_pending` (`pending`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `appointment_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `qty_reserved` decimal(12,3) NOT NULL DEFAULT 0.000,
  `qty_used` decimal(12,3) DEFAULT NULL,
  `variance_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_appt` (`appointment_id`),
  KEY `fk_ai_product` (`product_id`),
  CONSTRAINT `fk_ai_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`),
  CONSTRAINT `fk_ai_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `appointments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` int(10) unsigned NOT NULL,
  `service_id` int(10) unsigned NOT NULL,
  `scheduled_at` datetime NOT NULL,
  `practitioner_id` int(10) unsigned DEFAULT NULL,
  `status` enum('booked','completed','cancelled','no_show') NOT NULL DEFAULT 'booked',
  `notes` text DEFAULT NULL,
  `booked_by` int(10) unsigned DEFAULT NULL,
  `completed_by` int(10) unsigned DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_when` (`scheduled_at`),
  KEY `idx_status` (`status`,`scheduled_at`),
  KEY `fk_appt_service` (`service_id`),
  CONSTRAINT `fk_appt_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`),
  CONSTRAINT `fk_appt_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backup_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `context` varchar(60) NOT NULL DEFAULT '',
  `lead_id` int(10) unsigned DEFAULT NULL,
  `status` enum('sent','error') NOT NULL,
  `error` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_lead` (`lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `followups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` int(10) unsigned NOT NULL,
  `staff_id` int(10) unsigned NOT NULL,
  `channel` enum('whatsapp','phone_call','in_person','email','sms','other') NOT NULL,
  `outcome` enum('interested','needs_info','price_objection','no_answer','wrong_number','not_interested','booked','converted') NOT NULL,
  `remarks` text DEFAULT NULL,
  `budget_discussed` decimal(10,2) DEFAULT NULL,
  `treatments_discussed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`treatments_discussed`)),
  `objections` varchar(255) DEFAULT NULL,
  `next_followup_at` datetime DEFAULT NULL,
  `next_action` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_staff_id` (`staff_id`),
  KEY `idx_next_fu` (`next_followup_at`),
  CONSTRAINT `followups_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `followups_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `integration_settings` (
  `k` varchar(60) NOT NULL,
  `v` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_emails` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` int(10) unsigned NOT NULL,
  `ref` varchar(60) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `to_email` varchar(190) NOT NULL,
  `status` enum('queued','sent','error','expired') NOT NULL DEFAULT 'queued',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `error` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lead_ref` (`lead_id`,`ref`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_phones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` int(10) unsigned NOT NULL,
  `phone` varchar(30) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_phone` (`phone`),
  CONSTRAINT `lead_phones_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `lead_score_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` int(10) unsigned NOT NULL,
  `old_score` tinyint(3) unsigned DEFAULT NULL,
  `new_score` tinyint(3) unsigned DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  CONSTRAINT `lead_score_log_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_sources` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(60) NOT NULL,
  `icon` varchar(10) DEFAULT '?',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_treatments` (
  `lead_id` int(10) unsigned NOT NULL,
  `treatment_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`lead_id`,`treatment_id`),
  KEY `treatment_id` (`treatment_id`),
  CONSTRAINT `lead_treatments_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_treatments_ibfk_2` FOREIGN KEY (`treatment_id`) REFERENCES `treatments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `phone` varchar(25) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `gender` enum('male','female','other','unknown') DEFAULT 'unknown',
  `age_range` enum('18-24','25-34','35-44','45-54','55+','unknown') DEFAULT 'unknown',
  `source` enum('meta_form','whatsapp','instagram','facebook','phone_call','walkin','referral','other') NOT NULL DEFAULT 'other',
  `source_campaign` varchar(150) DEFAULT NULL,
  `referrer_name` varchar(120) DEFAULT NULL,
  `stage` enum('new','contacted','qualified','booked','converted','lost','cold') NOT NULL DEFAULT 'new',
  `lost_reason` varchar(255) DEFAULT NULL,
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `budget_range` enum('under_500','500_1000','1000_3000','3000_plus','unknown') DEFAULT 'unknown',
  `urgency` enum('immediate','within_month','3_months','just_browsing','unknown') DEFAULT 'unknown',
  `notes` text DEFAULT NULL,
  `score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `score_updated` datetime DEFAULT NULL,
  `last_contacted` datetime DEFAULT NULL,
  `next_followup` datetime DEFAULT NULL,
  `aging_status` enum('fresh','due','overdue','critical') DEFAULT 'fresh',
  `meta_lead_id` varchar(100) DEFAULT NULL,
  `meta_form_id` varchar(100) DEFAULT NULL,
  `meta_ad_id` varchar(100) DEFAULT NULL,
  `is_duplicate` tinyint(1) DEFAULT 0,
  `duplicate_of` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `followup_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `followup_1_done` tinyint(1) DEFAULT 0,
  `followup_2_done` tinyint(1) DEFAULT 0,
  `followup_3_done` tinyint(1) DEFAULT 0,
  `followup_1_at` datetime DEFAULT NULL,
  `followup_2_at` datetime DEFAULT NULL,
  `followup_3_at` datetime DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `reenquiry_at` datetime DEFAULT NULL,
  `reenquiry_note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stage` (`stage`),
  KEY `idx_source` (`source`),
  KEY `idx_score` (`score`),
  KEY `idx_assigned` (`assigned_to`),
  KEY `idx_next_followup` (`next_followup`),
  KEY `idx_phone` (`phone`),
  KEY `idx_meta_lead_id` (`meta_lead_id`),
  CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_codes` (
  `staff_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `tries` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `sent_at` datetime NOT NULL,
  PRIMARY KEY (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int(10) unsigned DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `result` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`,`created_at`),
  KEY `idx_ip` (`ip`,`created_at`),
  KEY `idx_staff` (`staff_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `meta_capi_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` int(10) unsigned NOT NULL,
  `stage` varchar(20) NOT NULL,
  `event_name` varchar(40) NOT NULL,
  `status` enum('queued','sent','error') NOT NULL DEFAULT 'queued',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `error` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lead_stage` (`lead_id`,`stage`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `meta_forms` (
  `id` varchar(40) NOT NULL,
  `name` varchar(200) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `questions` text DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `meta_webhook_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payload` longtext DEFAULT NULL,
  `lead_id` int(10) unsigned DEFAULT NULL,
  `status` enum('received','processed','duplicate','error') DEFAULT 'received',
  `via` varchar(12) NOT NULL DEFAULT 'webhook',
  `error_msg` varchar(500) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `opened_containers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `lot_id` int(10) unsigned NOT NULL,
  `qty_initial` decimal(12,3) NOT NULL,
  `qty_left` decimal(12,3) NOT NULL,
  `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `opened_by` int(10) unsigned DEFAULT NULL,
  `use_by` datetime NOT NULL,
  `status` enum('open','finished','discarded') NOT NULL DEFAULT 'open',
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_open` (`product_id`,`status`,`use_by`),
  KEY `idx_lot` (`lot_id`),
  CONSTRAINT `fk_oc_lot` FOREIGN KEY (`lot_id`) REFERENCES `stock_lots` (`id`),
  CONSTRAINT `fk_oc_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `token_hash` char(64) NOT NULL,
  `staff_id` int(10) unsigned NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_hash`),
  KEY `idx_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `category` varchar(60) DEFAULT NULL,
  `sku` varchar(60) DEFAULT NULL,
  `gtin` char(14) DEFAULT NULL COMMENT 'GS1 product code, 14 digits',
  `supplier_id` int(10) unsigned DEFAULT NULL COMMENT 'usual supplier',
  `stock_unit` varchar(30) NOT NULL DEFAULT 'piece',
  `usage_unit` varchar(30) NOT NULL DEFAULT 'piece',
  `units_per_stock` decimal(12,3) NOT NULL DEFAULT 1.000,
  `storage` enum('room','fridge','freezer') NOT NULL DEFAULT 'room',
  `prescription_only` tinyint(1) NOT NULL DEFAULT 0,
  `track_lots` tinyint(1) NOT NULL DEFAULT 1,
  `opened_use_hours` smallint(5) unsigned DEFAULT NULL COMMENT 'multi-dose: hours usable after opening; NULL = not tracked',
  `reorder_level` decimal(12,3) NOT NULL DEFAULT 0.000,
  `reorder_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `qty_reserved` decimal(12,3) NOT NULL DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gtin` (`gtin`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_lines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `po_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `qty_ordered` decimal(12,3) NOT NULL COMMENT 'stock units',
  `qty_received` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(12,2) DEFAULT NULL COMMENT 'per stock unit',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_po_product` (`po_id`,`product_id`),
  KEY `fk_pol_product` (`product_id`),
  CONSTRAINT `fk_pol_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pol_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` int(10) unsigned NOT NULL,
  `status` enum('draft','ordered','partial','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  `expected_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ordered_by` int(10) unsigned DEFAULT NULL,
  `ordered_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `fk_po_supplier` (`supplier_id`),
  CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reminders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` int(10) unsigned NOT NULL,
  `staff_id` int(10) unsigned NOT NULL,
  `message` varchar(500) NOT NULL,
  `remind_at` datetime NOT NULL,
  `is_sent` tinyint(1) DEFAULT 0,
  `sent_at` datetime DEFAULT NULL,
  `type` enum('followup_due','lead_aging','manual') DEFAULT 'manual',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  KEY `idx_remind_at` (`remind_at`),
  KEY `idx_staff_id` (`staff_id`),
  KEY `idx_is_sent` (`is_sent`),
  CONSTRAINT `reminders_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reminders_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `qty` decimal(12,3) NOT NULL,
  `qty_min` decimal(12,3) DEFAULT NULL,
  `qty_max` decimal(12,3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_product` (`service_id`,`product_id`),
  KEY `fk_si_product` (`product_id`),
  CONSTRAINT `fk_si_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_si_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_treatments` (
  `service_id` int(10) unsigned NOT NULL,
  `treatment_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`service_id`,`treatment_id`),
  KEY `idx_treatment` (`treatment_id`),
  CONSTRAINT `fk_st_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `services` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `category` varchar(80) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `duration_min` smallint(5) unsigned NOT NULL DEFAULT 30,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','consultant','receptionist','inventory','practitioner') NOT NULL DEFAULT 'consultant',
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `session_version` int(10) unsigned NOT NULL DEFAULT 0,
  `password_changed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_google` (
  `staff_id` int(10) unsigned NOT NULL,
  `google_email` varchar(190) DEFAULT NULL,
  `refresh_token` text NOT NULL,
  `access_token` text DEFAULT NULL,
  `access_expires` int(10) unsigned DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `connected_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_permissions` (
  `staff_id` int(10) unsigned NOT NULL,
  `permission` varchar(40) NOT NULL,
  `allowed` tinyint(1) NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`staff_id`,`permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_count_lines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `count_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `lot_id` int(10) unsigned NOT NULL,
  `expected_qty` decimal(12,3) DEFAULT NULL COMMENT 'system quantity when the count was submitted',
  `counted_qty` decimal(12,3) DEFAULT NULL,
  `diff_qty` decimal(12,3) DEFAULT NULL,
  `diff_value` decimal(12,2) DEFAULT NULL,
  `reason` varchar(40) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `status` enum('open','no_difference','posted','pending','approved','rejected') NOT NULL DEFAULT 'open',
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` varchar(255) DEFAULT NULL,
  `movement_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_count_lot` (`count_id`,`lot_id`),
  KEY `idx_status` (`status`),
  KEY `fk_scl_lot` (`lot_id`),
  CONSTRAINT `fk_scl_count` FOREIGN KEY (`count_id`) REFERENCES `stock_counts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_scl_lot` FOREIGN KEY (`lot_id`) REFERENCES `stock_lots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_counts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `status` enum('counting','awaiting_approval','closed','abandoned') NOT NULL DEFAULT 'counting',
  `category` varchar(60) DEFAULT NULL COMMENT 'NULL = every category',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_by` int(10) unsigned DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_locations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_lots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `location_id` int(10) unsigned NOT NULL DEFAULT 1,
  `lot_number` varchar(80) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `supplier` varchar(150) DEFAULT NULL,
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `invoice_ref` varchar(80) DEFAULT NULL,
  `po_line_id` int(10) unsigned DEFAULT NULL,
  `unit_cost` decimal(12,2) DEFAULT NULL COMMENT 'cost per stock unit (e.g. per vial)',
  `qty_received` decimal(12,3) NOT NULL DEFAULT 0.000,
  `qty_on_hand` decimal(12,3) NOT NULL DEFAULT 0.000,
  `status` enum('available','quarantined','recalled') NOT NULL DEFAULT 'available',
  `cold_chain_ok` tinyint(1) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `received_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`,`status`,`expiry_date`),
  KEY `idx_lot_number` (`lot_number`),
  CONSTRAINT `fk_lot_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `lot_id` int(10) unsigned DEFAULT NULL,
  `container_id` int(10) unsigned DEFAULT NULL,
  `type` enum('receive','reserve','release','consume','adjust','waste','count','return') NOT NULL,
  `qty_change` decimal(12,3) NOT NULL DEFAULT 0.000,
  `reserved_change` decimal(12,3) NOT NULL DEFAULT 0.000,
  `appointment_id` int(10) unsigned DEFAULT NULL,
  `reason` varchar(60) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `staff_id` int(10) unsigned DEFAULT NULL,
  `reverses_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`,`created_at`),
  KEY `idx_lot` (`lot_id`),
  KEY `idx_appt` (`appointment_id`),
  KEY `idx_container` (`container_id`),
  CONSTRAINT `fk_mv_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `contact_name` varchar(100) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `lead_time_days` smallint(5) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `treatments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category` varchar(80) DEFAULT NULL,
  `avg_price` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trusted_devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `last_used` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Defaults (never demo data)
INSERT IGNORE INTO lead_sources (name, slug, icon, sort_order) VALUES
('Meta Form','meta_form','🎯',1), ('WhatsApp','whatsapp','💬',2), ('Instagram','instagram','📸',3), ('Facebook','facebook','👍',4),
('Phone Call','phone_call','📞',5), ('Walk-in','walkin','🚶',6), ('Referral','referral','🤝',7), ('Other','other','📌',8);
-- The tables above already match inventory schema version 3, so ensureInventorySchema() has nothing to add
INSERT IGNORE INTO app_meta (k, v) VALUES ('inventory_schema', '3');
SET FOREIGN_KEY_CHECKS=1;
