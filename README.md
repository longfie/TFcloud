<a href="https://www.yunsign.net/"><img src="https://raw.githubusercontent.com/longfie/TFcloud/main/frontend/public/favicon.svg" width="128" height="128" alt="天方云签 Logo" align="right" /></a>

<div align="center">

# 天方云签 · TFcloud

*面向个人与团队的多平台自动签到系统*

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL--3.0--or--later-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![React](https://img.shields.io/badge/React-19-61DAFB.svg?logo=react&logoColor=black)](https://react.dev/)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED.svg?logo=docker&logoColor=white)](https://www.docker.com/)

> 把重复的签到交给程序，把时间留给真正重要的事情。

</div>

---

## 项目简介

天方云签（TFcloud）是一个基于 PHP 8.4、Webman、React 和 MySQL 的多平台自动签到系统，提供统一的账号管理、任务调度、执行记录和管理员后台。

本仓库是天方云签的开源版本，适合个人自用、学习研究和社区协作。开源版不包含支付页面、支付网关、卡密、会员续费、旧系统迁移工具及迁移状态页面；所有配额和站点设置均由管理员在本地配置。

## 主要功能

- **多平台插件签到**：贴吧、哔哩哔哩、网易云音乐、爱奇艺、小米运动、天翼云盘、哔咔漫画等插件统一管理。
- **任务调度与失败重试**：支持定时签到、任务执行明细、状态筛选和已执行任务的完整重试。
- **贴吧补签机制**：仅对第三方平台请求失败的任务进行后续补签；已成功签到的任务当天不会重复执行，已关闭贴吧不会造成无限重试。
- **账号与登录**：支持用户名、邮箱登录，邮箱验证码登录，以及 QQ 快捷登录中转配置。
- **安全凭据管理**：平台 Cookie、Token 等敏感凭据使用 Sodium 加密保存，安装时生成应用密钥并写入持久化配置。
- **安装向导**：检测 PHP、扩展、数据库和目录权限，并根据当前 CPU 与内存推荐 Webman 和任务进程数。
- **管理员后台**：用户、平台账号、任务、日志、邮件、版本信息、QQ 快捷登录和站点设置统一管理。
- **版本检查**：后台与远程版本信息对比，只显示当前是否为最新版本，不强制自动覆盖安装。
- **Docker 部署**：支持 MySQL、后端、前端一键编排；Composer 生产依赖在后端首次启动时自动安装。

## 目录结构

```text
backend/                    PHP 8.4 + Webman API、插件和任务调度
frontend/                   React 19 + Vite 用户端和管理员后台
scripts/docker-install.sh  本地 Docker 安装脚本
scripts/install-global.sh   海外版远程一键安装脚本
scripts/install-cn.sh       国内版 ghp.ci 镜像一键安装脚本
scripts/install.sh          一键安装核心脚本
docker-compose.yml          前端、后端、MySQL 编排文件
```

## 快速开始

首次使用建议先阅读对应的安装方式。生产环境请使用 HTTPS，并妥善保存数据库、应用密钥和运行时数据。

### 方式一：Docker 一键安装（推荐）

要求服务器已安装 Docker 和 Docker Compose v2。在仓库根目录执行：

```bash
./scripts/docker-install.sh \
  --site-url https://example.com \
  --admin-username admin
```

脚本会自动生成数据库密码和应用密钥，构建前后端镜像，启动 MySQL，首次启动后端时自动安装 Composer 生产依赖，并创建管理员账号。未填写 `--password` 时会在终端安全地提示输入密码。

只启动服务、稍后从安装页面配置：

```bash
./scripts/docker-install.sh --no-install
```

启动后访问：

```text
http://服务器IP:8080/install
```

#### 远程一键安装

海外服务器直接使用 GitHub：

```bash
curl -fsSL https://raw.githubusercontent.com/longfie/TFcloud/main/scripts/install-global.sh | \
  bash -s -- --site-url https://example.com --admin-username admin
```

国内服务器使用 `ghp.ci` 镜像：

```bash
curl -fsSL https://ghp.ci/https://raw.githubusercontent.com/longfie/TFcloud/main/scripts/install-cn.sh | \
  bash -s -- --site-url https://example.com --admin-username admin
```

国内镜像不可用时，可切换到海外版脚本。两种脚本都会把依赖安装、项目文件和 Docker 数据保存在服务器本地。

远程脚本默认安装到 `/www/docker/tfcloud`，支持自定义目录和端口：

```bash
curl -fsSL https://raw.githubusercontent.com/longfie/TFcloud/main/scripts/install.sh | \
  bash -s -- --install-dir /opt/tfcloud --port 8080 --site-url https://example.com
```

首次启动必须能够访问 Composer/GitHub 依赖源。依赖安装在 `tf_vendor` volume 中，后续重启不会重复下载。

#### 宝塔 Docker 面板

1. 在宝塔安装 Docker 管理器。
2. 打开宝塔终端执行上面的远程一键安装命令，或将仓库上传到 `/www/docker/tfcloud`。
3. 在 **Docker → 容器编排** 中选择该目录的 `docker-compose.yml`，构建并启动。
4. 在 **网站 → 反向代理** 中将域名代理到 `http://127.0.0.1:8080`。
5. 申请 SSL 后访问 `https://你的域名/install` 完成安装。

宝塔官方参考：[Docker 应用部署](https://docs.bt.cn/user-guide/docker/deployment/) · [容器编排](https://docs.bt.cn/user-guide/docker/compose/backup-restore)

Docker 运行数据保存在以下 volumes 中：

```text
tf_mysql    MySQL 数据
tf_runtime  安装锁、环境配置和运行日志
tf_vendor   Composer 生产依赖
```

不要删除 `tf_mysql` 和 `tf_runtime`。删除 `tf_vendor` 后可以在下一次启动时自动重新安装依赖。

### 方式二：手动安装

#### 环境要求

- PHP 8.4，扩展：`curl`、`mbstring`、`openssl`、`pdo_mysql`、`pcntl`、`sodium`
- Composer 2
- MySQL 8.0 或更高版本
- Node.js 20+、pnpm 10（仅构建前端需要）
- Nginx 或 Apache（生产环境推荐 Nginx）

#### 1. 获取代码

```bash
git clone https://github.com/longfie/TFcloud.git
cd TFcloud
```

#### 2. 创建数据库

```bash
mysql -u root -p -e \
  "CREATE DATABASE tf_sign CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p tf_sign < backend/database/schema.sql
```

#### 3. 配置后端

```bash
cd backend
cp .env.example .env
composer install --no-dev --optimize-autoloader
```

编辑 `.env`，至少填写数据库连接和以下密钥：

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=tf_sign
DB_USER=tf_sign
DB_PASSWORD=你的数据库密码
APP_KEY=base64:随机生成的32字节密钥
CREDENTIAL_FINGERPRINT_KEY=另一组独立随机密钥
CORS_ALLOWED_ORIGINS=https://你的域名
SESSION_SECURE=true
```

可以使用 PHP 生成随机值：

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

#### 4. 启动后端

```bash
php start.php start
```

后端默认监听 `127.0.0.1:8787`。生产环境请使用 Supervisor、systemd 或宝塔进程守护保持运行。

#### 5. 构建前端

```bash
cd ../frontend
pnpm install --frozen-lockfile
pnpm build
```

将 `frontend/dist` 配置为 Nginx 网站根目录，并把以下路径反向代理到 `http://127.0.0.1:8787`：

```text
/api/
/health/
/.well-known/
```

可参考仓库中的 [frontend/nginx.conf](frontend/nginx.conf)。手动部署时，将其中的 `backend:8787` 改为 `127.0.0.1:8787`。

#### 6. 完成安装

浏览器访问：

```text
https://你的域名/install
```

按照向导检测环境、填写数据库和创建管理员。安装完成后重启 Webman 进程，使应用密钥和后台任务配置生效。

也可以通过命令行创建管理员：

```bash
ADMIN_PASSWORD='至少8位的强密码' \
  php backend/scripts/create-admin.php --username=admin
```

## 配置与安全建议

- 不要提交 `.env`、`.env.docker`、Cookie、Token、私钥、数据库备份和运行日志。
- 生产环境必须启用 HTTPS，并将 `SESSION_SECURE=true`。
- 不要把 MySQL `3306` 和 Webman `8787` 暴露到公网，只开放网站反向代理端口。
- 安装锁会防止已安装站点被覆盖安装；如需重装，请先备份并由管理员明确删除运行时安装锁。
- 修改 `composer.lock` 后，Docker 启动脚本会检测哈希并重新安装生产依赖。
- 贴吧补签只针对第三方请求失败，不会因为关闭的贴吧无限执行。

## QQ 快捷登录中转

管理员可以在后台“QQ 快捷登录”中一键申请 AppID 和 AppKey，也可以手动填写已有中转凭据。流程包括：

1. 提交站点名称、当前域名、回调地址和持久化安装 UUID。
2. 将挑战内容发布到 `/.well-known/qq-login-verification.txt`。
3. 完成域名验证后保存加密凭据。
4. 登录时使用随机 `state`，并校验 `issued_at`、HMAC-SHA256 签名和 `subject`。

请确保站点的回调域名、HTTPS 和反向代理配置正确。

## 开发与验证

```bash
# 后端
cd backend
php tests/run.php

# 前端
cd ../frontend
pnpm typecheck
pnpm build
```

## 作者与社区

<a href="https://q1.qlogo.cn/g?b=qq&nk=1790716272&s=640"><img src="https://q1.qlogo.cn/g?b=qq&nk=1790716272&s=640" width="72" height="72" alt="龙辉 QQ 头像" align="left" /></a>

**作者：龙辉**<br />
QQ：1790716272  · 交流群：701550577<br />
官网：[www.yunsign.net](https://www.yunsign.net/)  ·  博客：[blog.eirds.cn](https://blog.eirds.cn/)

如果遇到问题，请先查看安装日志、健康检查和任务执行详情，再提交 Issue。提交问题时请隐藏密码、Cookie、Token、邮箱验证码和数据库信息。

## 开源协议

TFcloud 原创业务代码采用 **GNU Affero General Public License v3.0 or later（AGPL-3.0-or-later）**，详见 [LICENSE](LICENSE)。选择 AGPL 是为了让基于本项目修改并通过网络提供服务的版本，也能向用户提供对应源代码。

AGPL 不禁止商业使用；它要求发布修改版或网络服务版时履行相应的源码提供义务。如果你需要禁止商业使用，应使用独立的商业授权或 source-available 协议，而不能将其称为标准开源协议。

以下内容不因本项目协议改变：

1. `backend/`、`frontend/` 中已有的上游版权声明和许可证继续有效。
2. Composer、npm/pnpm 依赖及平台插件遵循各自的原始许可证。
3. 天方云签、第三方平台名称、Logo、接口和商标归各自权利人所有。
4. 使用本项目时请遵守所在地法律法规和各平台服务条款；因违规使用产生的责任由使用者承担。

Copyright © 2016–2026 龙辉 / 天方云签。
