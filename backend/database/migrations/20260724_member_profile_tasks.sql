CREATE TABLE IF NOT EXISTS `TF_password_change_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `code_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_password_code_user` (`user_id`, `consumed_at`, `expires_at`, `id`),
  CONSTRAINT `fk_password_codes_user` FOREIGN KEY (`user_id`) REFERENCES `TF_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET @add_paid_months = IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TF_orders' AND COLUMN_NAME = 'paid_months'
  ),
  'SELECT 1',
  'ALTER TABLE `TF_orders` ADD COLUMN `paid_months` SMALLINT UNSIGNED DEFAULT NULL AFTER `entitlement_days`'
);
PREPARE add_paid_months_statement FROM @add_paid_months;
EXECUTE add_paid_months_statement;
DEALLOCATE PREPARE add_paid_months_statement;

SET @add_bonus_months = IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TF_orders' AND COLUMN_NAME = 'bonus_months'
  ),
  'SELECT 1',
  'ALTER TABLE `TF_orders` ADD COLUMN `bonus_months` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `paid_months`'
);
PREPARE add_bonus_months_statement FROM @add_bonus_months;
EXECUTE add_bonus_months_statement;
DEALLOCATE PREPARE add_bonus_months_statement;

ALTER TABLE `TF_orders`
  MODIFY COLUMN `entitlement_days` INT UNSIGNED DEFAULT NULL;

UPDATE `TF_plugin_accounts`
SET `settings_json` = JSON_SET(
      COALESCE(`settings_json`, JSON_OBJECT()),
      '$.schedule_mode',
      IF(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(`settings_json`, JSON_OBJECT()), '$.schedule_enabled')) = 'true', 'fixed', 'auto'),
      '$.schedule_enabled',
      TRUE
    ),
    `next_run_at` = CASE
      WHEN `status` IN ('active', 'pending_verification') AND `next_run_at` IS NULL
      THEN DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 1 DAY), INTERVAL (300 + MOD(`id`, 180)) MINUTE)
      ELSE `next_run_at`
    END,
    `updated_at` = NOW()
WHERE `deleted_at` IS NULL
  AND JSON_EXTRACT(COALESCE(`settings_json`, JSON_OBJECT()), '$.schedule_mode') IS NULL;
