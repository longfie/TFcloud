ALTER TABLE `TF_users`
  MODIFY COLUMN `password_hash` VARCHAR(255) DEFAULT NULL;

SET @add_password_code_purpose = IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'TF_password_change_codes'
      AND COLUMN_NAME = 'purpose'
  ),
  'SELECT 1',
  'ALTER TABLE `TF_password_change_codes` ADD COLUMN `purpose` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''change'' AFTER `user_id`'
);
PREPARE add_password_code_purpose_statement FROM @add_password_code_purpose;
EXECUTE add_password_code_purpose_statement;
DEALLOCATE PREPARE add_password_code_purpose_statement;

UPDATE `TF_password_change_codes`
SET `purpose` = 'change'
WHERE `purpose` IS NULL OR `purpose` = '';
