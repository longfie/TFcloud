INSERT INTO `TF_plugins` (`plugin_code`, `name`, `version`, `status`, `installed_at`, `updated_at`)
VALUES ('cloud189', '天翼云盘', '0.1.0', 'enabled', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `version` = VALUES(`version`),
    `status` = 'enabled',
    `updated_at` = VALUES(`updated_at`);
