INSERT INTO `TF_settings` (`setting_key`, `setting_value`, `is_secret`, `updated_by`, `updated_at`)
VALUES
  ('site.name', JSON_QUOTE('天方云签'), 0, NULL, NOW()),
  ('site.title', JSON_QUOTE('天方云签'), 0, NULL, NOW()),
  ('mail.from_name', JSON_QUOTE('天方云签'), 0, NULL, NOW())
ON DUPLICATE KEY UPDATE
  `setting_value` = JSON_QUOTE('天方云签'),
  `is_secret` = 0,
  `updated_at` = VALUES(`updated_at`);
