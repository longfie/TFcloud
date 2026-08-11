# Bilibili 插件来源与实现说明

本插件是天方云签（TFcloud）的内置插件。

- 作者：龙辉（QQ 1790716272）
- 交流群：701550577
- 官网：https://www.yunsign.net/
- 博客：https://blog.eirds.cn/

主站与基础直播任务的划分参考了
[metowolf/BilibiliHelper](https://github.com/metowolf/BilibiliHelper)（MIT License）。
粉丝牌挂机的任务边界、公开接口请求参数和直播心跳状态含义参考了
[RayWangQvQ/BiliBiliToolPro](https://github.com/RayWangQvQ/BiliBiliToolPro)
（GNU GPL-3.0 License）。

天方云签没有复制或移植上述项目的 JavaScript、C# 业务源码。当前实现根据公开的网络协议行为，
围绕本项目的 PHP/Webman 插件接口、加密凭据、任务审计和数据库状态机重新编写。

当前插件提供主站观看、分享、投币、直播签到、直播每日礼包、有限直播心跳、普通扭蛋、
漫画签到和粉丝牌直播挂机。粉丝牌挂机由独立 Worker 通过事件循环和异步 HTTP 推进：

- 每次 tick 只领取有限数量的到期会话；
- 不使用 `sleep`、`usleep` 或长时间阻塞循环；
- 会话、下一次心跳、失败次数和分布式锁保存在数据库中；
- 进程异常退出后可恢复未完成会话，并自动修正长期显示“执行中”的任务；
- 已知未开播的粉丝牌会被过滤，直播间解析使用直播端接口。

投币和扭蛋会改变账号资产，默认关闭，必须由用户在账号设置中显式授权。

上游许可证请分别查看：

- [BilibiliHelper LICENSE](https://github.com/metowolf/BilibiliHelper/blob/master/LICENSE)
- [BiliBiliToolPro LICENSE](https://github.com/RayWangQvQ/BiliBiliToolPro/blob/main/LICENSE)
