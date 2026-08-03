# 数据模型说明

所有业务表统一使用 `TF_` 前缀。开源版只包含用户、平台账号、凭据、签到任务、执行明细、审计、邮件和智能助手相关数据表。

- `TF_plugin_accounts`：用户的平台账号、显示名、调度配置和健康状态。
- `TF_plugin_credentials`：凭据保险箱，只保存加密密文、随机 Nonce、密钥版本和指纹。
- `TF_sign_tasks`、`TF_sign_runs`、`TF_sign_records`：任务、执行尝试和签到明细。
- `TF_audit_logs`：管理员操作审计记录。
- `TF_mail_tasks`、`TF_mail_records`、`TF_notification_events`：邮件通知队列和发送记录。

开源版不创建支付订单、支付交易、卡密、旧数据映射或迁移检查点表。
