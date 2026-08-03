# Bilibili 插件来源与迁移说明

本插件的任务划分参考了开源项目
[metowolf/BilibiliHelper](https://github.com/metowolf/BilibiliHelper)（MIT License，
Copyright © metowolf）。当前实现针对天方云签的插件接口、加密凭据、调度器和任务日志重新
编写，没有直接复制其 JavaScript 源码。

已重新实现的可用任务：

- 直播每日礼包领取；
- Web 直播心跳（每次调度只提交一次，不创建常驻挂机进程）；
- 普通扭蛋币使用（默认关闭，必须由用户显式授权）。

天方云签原有的主站观看、分享、投币、直播签到和漫画签到继续保留。


上游项目许可证可在其仓库的
[LICENSE](https://github.com/metowolf/BilibiliHelper/blob/master/LICENSE) 中查看。
