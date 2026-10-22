-- Local dev schema, generated from the production dump (structure only, no data).

SET FOREIGN_KEY_CHECKS=0;

SET NAMES utf8mb4;

CREATE TABLE `activity_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `staff_id` int(10) UNSIGNED DEFAULT NULL,
  `lead_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `old_value` varchar(255) DEFAULT NULL,
  `new_value` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `followups` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `staff_id` int(10) UNSIGNED NOT NULL,
  `channel` enum('whatsapp','phone_call','in_person','email','sms','other') NOT NULL,
  `outcome` enum('interested','needs_info','price_objection','no_answer','wrong_number','not_interested','booked','converted') NOT NULL,
  `remarks` text DEFAULT NULL,
  `budget_discussed` decimal(10,2) DEFAULT NULL,
  `treatments_discussed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`treatments_discussed`)),
  `objections` varchar(255) DEFAULT NULL,
  `next_followup_at` datetime DEFAULT NULL,
  `next_action` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leads` (
  `id` int(10) UNSIGNED NOT NULL,
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
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `budget_range` enum('under_500','500_1000','1000_3000','3000_plus','unknown') DEFAULT 'unknown',
  `urgency` enum('immediate','within_month','3_months','just_browsing','unknown') DEFAULT 'unknown',
  `notes` text DEFAULT NULL,
  `score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `score_updated` datetime DEFAULT NULL,
  `last_contacted` datetime DEFAULT NULL,
  `next_followup` datetime DEFAULT NULL,
  `aging_status` enum('fresh','due','overdue','critical') DEFAULT 'fresh',
  `meta_lead_id` varchar(100) DEFAULT NULL,
  `meta_form_id` varchar(100) DEFAULT NULL,
  `meta_ad_id` varchar(100) DEFAULT NULL,
  `is_duplicate` tinyint(1) DEFAULT 0,
  `duplicate_of` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `followup_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `followup_1_done` tinyint(1) DEFAULT 0,
  `followup_2_done` tinyint(1) DEFAULT 0,
  `followup_3_done` tinyint(1) DEFAULT 0,
  `followup_1_at` datetime DEFAULT NULL,
  `followup_2_at` datetime DEFAULT NULL,
  `followup_3_at` datetime DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lead_phones` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `phone` varchar(30) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `lead_score_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `old_score` tinyint(3) UNSIGNED DEFAULT NULL,
  `new_score` tinyint(3) UNSIGNED DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lead_sources` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(60) NOT NULL,
  `icon` varchar(10) DEFAULT '?',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) UNSIGNED DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lead_treatments` (
  `lead_id` int(10) UNSIGNED NOT NULL,
  `treatment_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `meta_webhook_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `payload` longtext DEFAULT NULL,
  `lead_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('received','processed','duplicate','error') DEFAULT 'received',
  `error_msg` varchar(500) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reminders` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `staff_id` int(10) UNSIGNED NOT NULL,
  `message` varchar(500) NOT NULL,
  `remind_at` datetime NOT NULL,
  `is_sent` tinyint(1) DEFAULT 0,
  `sent_at` datetime DEFAULT NULL,
  `type` enum('followup_due','lead_aging','manual') DEFAULT 'manual',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `staff` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','consultant','receptionist') NOT NULL DEFAULT 'consultant',
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `treatments` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(80) DEFAULT NULL,
  `avg_price` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) UNSIGNED DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lead_id` (`lead_id`),
  ADD KEY `idx_staff_id` (`staff_id`),
  ADD KEY `idx_action` (`action`);

ALTER TABLE `followups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lead_id` (`lead_id`),
  ADD KEY `idx_staff_id` (`staff_id`),
  ADD KEY `idx_next_fu` (`next_followup_at`);

ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stage` (`stage`),
  ADD KEY `idx_source` (`source`),
  ADD KEY `idx_score` (`score`),
  ADD KEY `idx_assigned` (`assigned_to`),
  ADD KEY `idx_next_followup` (`next_followup`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_meta_lead_id` (`meta_lead_id`);

ALTER TABLE `lead_phones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lead_id` (`lead_id`),
  ADD KEY `idx_phone` (`phone`);

ALTER TABLE `lead_score_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lead_id` (`lead_id`);

ALTER TABLE `lead_sources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

ALTER TABLE `lead_treatments`
  ADD PRIMARY KEY (`lead_id`,`treatment_id`),
  ADD KEY `treatment_id` (`treatment_id`);

ALTER TABLE `meta_webhook_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

ALTER TABLE `reminders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lead_id` (`lead_id`),
  ADD KEY `idx_remind_at` (`remind_at`),
  ADD KEY `idx_staff_id` (`staff_id`),
  ADD KEY `idx_is_sent` (`is_sent`);

ALTER TABLE `staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

ALTER TABLE `treatments`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `activity_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `followups`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `leads`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `lead_phones`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `lead_score_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `lead_sources`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `meta_webhook_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `reminders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `staff`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `treatments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `followups`
  ADD CONSTRAINT `followups_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `followups_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE;

ALTER TABLE `leads`
  ADD CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `staff` (`id`) ON DELETE SET NULL;

ALTER TABLE `lead_phones`
  ADD CONSTRAINT `lead_phones_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE;

ALTER TABLE `lead_score_log`
  ADD CONSTRAINT `lead_score_log_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE;

ALTER TABLE `lead_treatments`
  ADD CONSTRAINT `lead_treatments_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lead_treatments_ibfk_2` FOREIGN KEY (`treatment_id`) REFERENCES `treatments` (`id`) ON DELETE CASCADE;

ALTER TABLE `reminders`
  ADD CONSTRAINT `reminders_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reminders_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS=1;
