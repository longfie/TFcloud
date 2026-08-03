-- Enable the Zepp Life implementation for existing installations.
UPDATE `TF_plugins`
SET `name` = '小米运动',
    `version` = '0.2.0',
    `status` = 'enabled',
    `updated_at` = NOW()
WHERE `plugin_code` = 'sport';

INSERT INTO `TF_plugins` (`plugin_code`, `name`, `version`, `status`, `installed_at`, `updated_at`)
SELECT 'sport', '小米运动', '0.2.0', 'enabled', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `TF_plugins` WHERE `plugin_code` = 'sport'
);
