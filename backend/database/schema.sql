SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `TF_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `display_name` VARCHAR(191) NOT NULL DEFAULT '',
  `email` VARCHAR(191) DEFAULT NULL,
  `qq` VARCHAR(32) DEFAULT NULL,
  `role` VARCHAR(32) NOT NULL DEFAULT 'user',
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `quota` INT UNSIGNED NOT NULL DEFAULT 2,
  `daily_sign_email` TINYINT(1) NOT NULL DEFAULT 0,
  `push_channel` VARCHAR(32) NOT NULL DEFAULT 'none',
  `push_token` VARCHAR(255) DEFAULT NULL,
  `password_migrated_at` DATETIME DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`),
  UNIQUE KEY `uniq_email` (`email`),
  UNIQUE KEY `uniq_qq` (`qq`),
  KEY `idx_role_status` (`role`, `status`),
  CONSTRAINT `chk_users_role` CHECK (`role` IN ('user', 'admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `last_seen_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  KEY `idx_user_active` (`user_id`, `revoked_at`, `expires_at`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_password_change_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `purpose` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'change',
  `email` VARCHAR(191) NOT NULL,
  `code_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_password_code_user` (`user_id`, `purpose`, `consumed_at`, `expires_at`, `id`),
  CONSTRAINT `fk_password_codes_user` FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_email_verification_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(191) NOT NULL,
  `purpose` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'register',
  `code_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ip_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_code` (`email`, `purpose`, `consumed_at`, `expires_at`, `id`),
  KEY `idx_email_code_ip` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_user_identities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `provider` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `external_subject` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `display_name` VARCHAR(191) NOT NULL DEFAULT '',
  `profile_json` JSON DEFAULT NULL,
  `linked_at` DATETIME NOT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_subject` (`provider`, `external_subject`),
  UNIQUE KEY `uniq_user_provider` (`user_id`, `provider`),
  KEY `idx_identity_user` (`user_id`),
  CONSTRAINT `fk_user_identities_user` FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_plugins` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `version` VARCHAR(32) NOT NULL DEFAULT '0.1.0',
  `status` VARCHAR(32) NOT NULL DEFAULT 'enabled',
  `config_json` JSON DEFAULT NULL,
  `installed_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_plugin_code` (`plugin_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_plugin_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plugin_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `external_user_id` VARCHAR(191) DEFAULT NULL,
  `display_name` VARCHAR(191) NOT NULL DEFAULT '',
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `settings_json` JSON DEFAULT NULL,
  `profile_json` JSON DEFAULT NULL,
  `last_verified_at` DATETIME DEFAULT NULL,
  `last_run_at` DATETIME DEFAULT NULL,
  `next_run_at` DATETIME DEFAULT NULL,
  `last_error_code` VARCHAR(64) DEFAULT NULL,
  `last_error_message` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_plugin_external` (`user_id`, `plugin_code`, `external_user_id`),
  KEY `idx_plugin_schedule` (`plugin_code`, `status`, `next_run_at`),
  KEY `idx_user_plugin` (`user_id`, `plugin_code`, `deleted_at`),
  CONSTRAINT `fk_plugin_accounts_user` FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_plugin_credentials` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `credential_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'cookie',
  `payload_cipher` MEDIUMBLOB NOT NULL,
  `nonce` VARBINARY(24) NOT NULL,
  `key_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `rotated_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account_credential` (`account_id`, `credential_type`),
  KEY `idx_fingerprint` (`fingerprint`),
  KEY `idx_expiration` (`expires_at`),
  CONSTRAINT `fk_credentials_account` FOREIGN KEY (`account_id`) REFERENCES `TF_plugin_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_platform_auth_flows` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flow_no` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `platform_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `method` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'waiting',
  `context_cipher` MEDIUMBLOB NOT NULL,
  `nonce` VARBINARY(24) NOT NULL,
  `key_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_platform_auth_flow` (`flow_no`),
  KEY `idx_platform_auth_owner` (`user_id`, `platform_code`, `status`, `expires_at`),
  KEY `idx_platform_auth_expiration` (`expires_at`),
  CONSTRAINT `fk_platform_auth_user` FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_sign_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_no` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `plugin_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'daily_sign',
  `trigger_type` VARCHAR(32) NOT NULL DEFAULT 'schedule',
  `idempotency_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `schedule_date` DATE DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `total_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `success_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `already_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `summary_json` JSON DEFAULT NULL,
  `account_snapshot_json` JSON DEFAULT NULL,
  `last_error_code` VARCHAR(64) DEFAULT NULL,
  `last_error_message` VARCHAR(500) DEFAULT NULL,
  `scheduled_at` DATETIME(3) DEFAULT NULL,
  `available_at` DATETIME(3) DEFAULT NULL,
  `locked_at` DATETIME(3) DEFAULT NULL,
  `locked_by` VARCHAR(128) DEFAULT NULL,
  `started_at` DATETIME(3) DEFAULT NULL,
  `finished_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_task_no` (`task_no`),
  UNIQUE KEY `uniq_idempotency_key` (`idempotency_key`),
  KEY `idx_dispatch` (`status`, `available_at`, `priority`, `id`),
  KEY `idx_account_created` (`account_id`, `created_at`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_plugin_status_created` (`plugin_code`, `status`, `created_at`),
  KEY `idx_schedule` (`plugin_code`, `schedule_date`, `status`),
  CONSTRAINT `fk_sign_tasks_user` FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`),
  CONSTRAINT `fk_sign_tasks_account` FOREIGN KEY (`account_id`) REFERENCES `TF_plugin_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_sign_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_no` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `task_id` BIGINT UNSIGNED NOT NULL,
  `attempt_no` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `plugin_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `plugin_version` VARCHAR(32) DEFAULT NULL,
  `worker_id` VARCHAR(128) DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'running',
  `total_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `success_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `already_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_code` VARCHAR(64) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `exception_class` VARCHAR(191) DEFAULT NULL,
  `summary_json` JSON DEFAULT NULL,
  `started_at` DATETIME(3) NOT NULL,
  `finished_at` DATETIME(3) DEFAULT NULL,
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_run_no` (`run_no`),
  UNIQUE KEY `uniq_task_attempt` (`task_id`, `attempt_no`),
  KEY `idx_plugin_status_created` (`plugin_code`, `status`, `created_at`),
  KEY `idx_worker_created` (`worker_id`, `created_at`),
  CONSTRAINT `fk_sign_runs_task` FOREIGN KEY (`task_id`) REFERENCES `TF_sign_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_sign_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` BIGINT UNSIGNED NOT NULL,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `parent_record_id` BIGINT UNSIGNED DEFAULT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED DEFAULT NULL,
  `plugin_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `record_key` VARCHAR(191) NOT NULL,
  `sequence_no` INT UNSIGNED NOT NULL DEFAULT 0,
  `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `action` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_type` VARCHAR(64) DEFAULT NULL,
  `target_id` VARCHAR(191) DEFAULT NULL,
  `target_name` VARCHAR(255) DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL,
  `result_code` VARCHAR(64) DEFAULT NULL,
  `message` VARCHAR(500) DEFAULT NULL,
  `reward_json` JSON DEFAULT NULL,
  `metrics_json` JSON DEFAULT NULL,
  `safe_result_json` JSON DEFAULT NULL,
  `started_at` DATETIME(3) DEFAULT NULL,
  `finished_at` DATETIME(3) DEFAULT NULL,
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_run_record` (`run_id`, `record_key`),
  KEY `idx_task_sequence` (`task_id`, `sequence_no`, `id`),
  KEY `idx_account_created` (`account_id`, `created_at`),
  KEY `idx_plugin_status_created` (`plugin_code`, `status`, `created_at`),
  KEY `idx_target` (`plugin_code`, `target_type`, `target_id`),
  KEY `idx_parent` (`parent_record_id`),
  CONSTRAINT `fk_sign_records_task` FOREIGN KEY (`task_id`) REFERENCES `TF_sign_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sign_records_run` FOREIGN KEY (`run_id`) REFERENCES `TF_sign_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_sign_record_payloads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_id` BIGINT UNSIGNED NOT NULL,
  `payload_type` VARCHAR(32) NOT NULL DEFAULT 'response',
  `payload_cipher` MEDIUMBLOB NOT NULL,
  `nonce` VARBINARY(24) NOT NULL,
  `key_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `content_type` VARCHAR(128) DEFAULT NULL,
  `payload_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_record` (`record_id`),
  KEY `idx_expiration` (`expires_at`),
  CONSTRAINT `fk_sign_payload_record` FOREIGN KEY (`record_id`) REFERENCES `TF_sign_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_settings` (
  `setting_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `setting_value` JSON DEFAULT NULL,
  `is_secret` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_astrbot_conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_no` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `astrbot_session_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `last_message_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_astrbot_conversation_no` (`conversation_no`),
  UNIQUE KEY `uniq_astrbot_conversation_user` (`user_id`),
  UNIQUE KEY `uniq_astrbot_session_id` (`astrbot_session_id`),
  KEY `idx_astrbot_conversation_activity` (`last_message_at`, `id`),
  CONSTRAINT `fk_astrbot_conversation_user`
    FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_astrbot_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'completed',
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_astrbot_message_conversation` (`conversation_id`, `id`),
  KEY `idx_astrbot_message_user_created` (`user_id`, `created_at`, `role`),
  KEY `idx_astrbot_message_status` (`status`, `created_at`),
  CONSTRAINT `fk_astrbot_message_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `TF_astrbot_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_astrbot_message_user`
    FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(128) NOT NULL,
  `resource_type` VARCHAR(64) DEFAULT NULL,
  `resource_id` VARCHAR(191) DEFAULT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `context_json` JSON DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_resource_created` (`resource_type`, `resource_id`, `created_at`),
  KEY `idx_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_mail_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_no` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `template_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `context_json` JSON DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `total_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `success_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `scheduled_at` DATETIME(3) DEFAULT NULL,
  `started_at` DATETIME(3) DEFAULT NULL,
  `finished_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mail_task_no` (`task_no`),
  KEY `idx_mail_dispatch` (`status`, `scheduled_at`, `priority`, `id`),
  KEY `idx_mail_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_mail_tasks_user` FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_mail_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` BIGINT UNSIGNED DEFAULT NULL,
  `message_no` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recipient` VARCHAR(191) NOT NULL,
  `recipient_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `provider_message_id` VARCHAR(191) DEFAULT NULL,
  `last_error_code` VARCHAR(64) DEFAULT NULL,
  `last_error_message` VARCHAR(500) DEFAULT NULL,
  `available_at` DATETIME(3) DEFAULT NULL,
  `sent_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mail_message_no` (`message_no`),
  KEY `idx_mail_record_dispatch` (`status`, `available_at`, `id`),
  KEY `idx_mail_task` (`task_id`, `id`),
  KEY `idx_mail_recipient` (`recipient_hash`, `created_at`),
  CONSTRAINT `fk_mail_records_task` FOREIGN KEY (`task_id`) REFERENCES `TF_mail_tasks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `TF_notification_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `mail_task_id` BIGINT UNSIGNED DEFAULT NULL,
  `event_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
  `payload_json` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_notification_event_key` (`event_key`),
  KEY `idx_notification_user_type` (`user_id`, `event_type`, `created_at`),
  KEY `idx_notification_task` (`mail_task_id`),
  CONSTRAINT `fk_notification_events_user` FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_events_task` FOREIGN KEY (`mail_task_id`) REFERENCES `TF_mail_tasks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `TF_plugins` (`plugin_code`, `name`, `version`, `status`, `installed_at`, `updated_at`) VALUES
('tieba', '百度贴吧', '0.1.0', 'enabled', NOW(), NOW()),
('bilibili', '哔哩哔哩', '0.2.0', 'enabled', NOW(), NOW()),
('netease', '网易云音乐', '0.2.0', 'enabled', NOW(), NOW()),
('iqiyi', '爱奇艺', '0.2.0', 'enabled', NOW(), NOW()),
('sport', '小米运动', '0.2.0', 'enabled', NOW(), NOW()),
('cloud189', '天翼云盘', '0.1.0', 'enabled', NOW(), NOW()),
('picacomic', '哔咔漫画', '0.1.0', 'enabled', NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `version` = VALUES(`version`), `updated_at` = VALUES(`updated_at`);

SET FOREIGN_KEY_CHECKS = 1;
