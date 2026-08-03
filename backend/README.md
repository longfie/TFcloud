# TFcloud 开源版后端

基于 PHP 8.4 与 Webman 2.x 的模块化签到后端。开源版提供账号认证、平台插件、签到任务、自动调度、失败重试、邮件通知和管理员审计能力。

本版不包含支付网关、订单、会员续费、卡密、旧数据迁移脚本或迁移状态接口。账号配额仅作为管理员本地配置项，用于限制单个用户绑定的平台账号数量。

## 初始化

```bash
cp .env.example .env
composer install
mysql -u root -p tf_sign < database/schema.sql
php start.php start
```

创建首个管理员（密码不会输出）：

```bash
ADMIN_PASSWORD='replace-with-strong-password' php scripts/create-admin.php --username=admin
```

## 主要接口

```text
GET    /health/live
GET    /health/ready
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/me
GET    /api/plugins
GET    /api/plugin-accounts
POST   /api/plugin-accounts
POST   /api/plugin-accounts/{id}/verify
POST   /api/plugin-accounts/{id}/actions/{action}
GET    /api/sign-tasks
GET    /api/sign-tasks/{taskNo}
GET    /api/sign-tasks/{taskNo}/records
GET    /api/assistant/status
GET    /api/assistant/messages
POST   /api/assistant/messages
DELETE /api/assistant/messages
GET    /api/admin/overview
GET    /api/admin/sign-tasks
GET    /api/admin/audit-logs
GET    /api/admin/users
POST   /api/admin/users
PATCH  /api/admin/users/{id}
GET    /api/admin/plugin-accounts
PATCH  /api/admin/plugin-accounts/{id}
POST   /api/admin/astrbot/test
POST   /api/admin/settings/qq-relay/apply
POST   /api/admin/settings/qq-relay/verify
POST   /api/admin/settings/qq-relay/configure
GET    /.well-known/qq-login-verification.txt
```

## 插件

贴吧、哔哩哔哩、网易云音乐、爱奇艺、小米运动、天翼云盘和哔咔漫画插件均按统一签到接口接入。平台凭据使用 Sodium 加密保存，任务与执行明细写入统一的数据表。

## QQ 快捷登录中转申请

管理员可在后台“QQ 快捷登录”中一键申请天方中转 AppID/AppKey。申请流程会提交当前站点回调地址，并通过 `/.well-known/qq-login-verification.txt`（同时支持 `/api/auth/qq/verification`）提供域名验证挑战。验证成功后 AppKey 只以加密形式保存，回调使用随机 `state`、时间戳和 HMAC-SHA256 签名校验。

如已有中转站为站点生成的凭据，也可以在后台手动填写 AppID 和 AppKey，系统会先向中转服务校验站点域名与回调地址，再加密保存。旧版中转配置仍可兼容使用；切换到独立 AppID 后，已绑定的旧 QQ 账号会在个人资料页提示重新授权绑定。

## 配置与安全

`.env` 只保存在部署服务器。生产环境必须启用 HTTPS；日志中禁止输出 Cookie、Token、密码和凭据密文。插件目录中的第三方许可证和声明文件不可删除。
