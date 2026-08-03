# 天方云签前端

天方云签前端是面向用户和管理员的 Web 控制台，使用 React 19、TypeScript、Vite、Tailwind CSS、HeroUI 和 React Router 构建，与 Webman 后端 API 配合提供完整的签到管理体验。

## 产品特性

- 用户注册、用户名/邮箱登录、邮箱验证码登录和 QQ 快捷登录。
- 平台账号绑定、凭据验证、签到任务创建和执行记录查看。
- 管理员后台、用户管理、平台账号管理、任务筛选、日志审计和邮件设置。
- 版本信息、作者信息、程序说明和站点版权信息展示。
- 响应式布局，支持桌面端和移动端访问。

## 技术栈

- React 19
- TypeScript
- Vite 6
- HeroUI
- Tailwind CSS
- React Router 7
- Axios
- pnpm

## 本地运行

先启动监听 `127.0.0.1:8787` 的 Webman 后端，然后执行：

```bash
pnpm install
pnpm dev
```

开发服务器会将 `/api`、`/health` 和 `/.well-known` 请求代理到后端。

## 构建生产版本

```bash
pnpm install --frozen-lockfile
pnpm typecheck
pnpm build
```

生产构建输出到 `dist/`。使用 Nginx 部署时，将 `/api/`、`/health/` 和 `/.well-known/` 反向代理到 Webman 后端，其余请求由前端静态文件处理。Docker 部署可直接使用仓库根目录的 `docker-compose.yml`。

## 作者信息

作者：龙辉（QQ 1790716272）<br />
交流群：701550577<br />
官网：[www.yunsign.net](https://www.yunsign.net/)  ·  博客：[blog.eirds.cn](https://blog.eirds.cn/)

## 许可说明

天方云签原创业务代码采用仓库根目录的 AGPL-3.0-or-later 协议。第三方依赖和组件继续遵循各自的原始许可证。
