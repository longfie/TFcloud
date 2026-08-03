SET NAMES utf8mb4;

-- 权限只由 TF_users.role 决定，不再根据任何固定 UID 推断管理员身份。
UPDATE `TF_users`
SET `role` = 'user', `updated_at` = NOW()
WHERE `role` IS NULL OR `role` NOT IN ('user', 'admin');

ALTER TABLE `TF_users`
  ADD KEY `idx_role_status` (`role`, `status`),
  ADD CONSTRAINT `chk_users_role` CHECK (`role` IN ('user', 'admin'));
