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
