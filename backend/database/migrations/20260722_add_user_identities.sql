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
