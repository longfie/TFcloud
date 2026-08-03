ALTER TABLE `TF_sign_tasks`
  MODIFY COLUMN `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 24;

UPDATE `TF_sign_tasks`
SET `max_attempts` = 24,
    `updated_at` = NOW()
WHERE `max_attempts` < 24;
